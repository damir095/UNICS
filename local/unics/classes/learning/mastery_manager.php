<?php
namespace local_unics\learning;

use local_unics\adaptive\mastery_state;
use local_unics\adaptive\mastery_estimator;
use local_unics\codifier_attribution;

defined('MOODLE_INTERNAL') || die();

/**
 * Оркестратор адаптива по навыкам (S1) [[adaptive-ai-design]]. На оцененной попытке:
 * cmid -> элементы кодификатора (unics_codifier_link) -> оценщик (за швом
 * mastery_estimator) -> запись unics_skill_mastery + unics_mastery_history -> глобальный
 * rollup (делегируем существующему adaptive_engine::evaluate_student - DRY, без регресса).
 *
 * Краевые случаи: тест без привязки -> навыкам ничего, только глобальный пересчет;
 * нет оценки по cmid -> навыкам ничего.
 */
class mastery_manager {

    /**
     * Текущий оценщик. Имена реализаций живут в estimator_factory: ядро о них не знает,
     * подплагины типа unicsest подключаются без правки этого файла.
     */
    private static function estimator(): mastery_estimator {
        return \local_unics\adaptive\estimator_factory::make();
    }

    /** Текущий рекомендатель. ML-фаза подменяет реализацию шва здесь по флагу. */
    private static function recommender(): \local_unics\adaptive\recommender {
        if ((int)get_config('local_unics', 'adaptive_recommender_ml') === 1) {
            return new \local_unics\adaptive\service_recommender();
        }
        return new \local_unics\adaptive\rule_recommender();
    }

    /**
     * Реакция на оцененную попытку теста. Идемпотентность не гарантируется (повторный
     * вызов на тот же грейд = еще одна «попытка» в EWMA) - вызывать один раз на событие
     * attempt_graded (так и делает observer).
     */
    public static function on_attempt(int $cmid, int $userid, ?int $attemptid = null): void {
        global $DB;

        $student = $DB->get_record('unics_students', ['mdl_user_id' => $userid], 'id, mdl_user_id');
        if (!$student) {
            return;
        }
        $sid = (int)$student->id;

        // Ответы по отдельным заданиям {a,b,correct} собираем, ТОЛЬКО если активный оценщик
        // их потребляет (маркер item_response_consumer). Ядро не знает имен реализаций, а
        // лишний запрос на каждую оцененную попытку не делается зря.
        $irtmap = [];
        if ($attemptid && self::estimator() instanceof \local_unics\adaptive\item_response_consumer) {
            $irtmap = \local_unics\irt_attribution::element_responses_for_attempt((int)$attemptid);
        }

        // Фаза 2: если есть id попытки - атрибутируем per-question (приоритет) с cmid
        // фолбэком по элементу через единый движок. Иначе - прежний cmid-путь.
        $scores = $attemptid ? \local_unics\codifier_attribution::element_scores_for_attempt((int)$attemptid) : [];
        if ($scores) {
            foreach ($scores as $eid => $pct) {
                self::apply_to_element($sid, (int)$eid, (float)$pct, $cmid, null, $irtmap[(int)$eid] ?? []);
            }
            self::generate_suggestions($sid);
        } else {
            $pct = self::attempt_pct($cmid, $userid);
            $links = self::element_links_for_cmid($cmid);
            // Атрибуция по навыкам - только если есть оценка И привязки. Иначе откат к
            // глобальному поведению (не ломаемся).
            if ($pct !== null && $links) {
                foreach ($links as $lnk) {
                    self::apply_to_element($sid, (int)$lnk['element_id'], $pct, $cmid,
                        $lnk['weight'], $irtmap[(int)$lnk['element_id']] ?? []);
                }
                // S2: пробелы/освоенное -> предложения педагогу (дедуп/уведомление - в suggestion_service).
                self::generate_suggestions($sid);
            }
        }

        // Глобальный rollup: difficulty_level + unics_level_history + уведомления.
        // S2: через гибридный гейт (gate_level_change) - смена уровня становится
        // предложением педагогу или применяется сразу при N=0.
        try {
            adaptive_engine::gate_level_change($sid);
        } catch (\Throwable $e) {
            debugging('local_unics: глобальный rollup не удался: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /** S2: прогнать рекомендатель и создать предложения (дедуп/уведомление - в suggestion_service). */
    private static function generate_suggestions(int $student_id): void {
        $days = (int)get_config('local_unics', 'adaptive_autoapply_days');
        $auto_after = $days > 0 ? time() + $days * 86400 : null;
        try {
            $cands = self::drop_unsupported_suggestions($student_id,
                self::recommender()->recommend($student_id));
            foreach ($cands as $c) {
                suggestion_service::create(
                    $student_id,
                    (int)$c['kind'],
                    isset($c['element_id']) ? (int)$c['element_id'] : null,
                    json_encode(['target_level' => $c['target_level'] ?? null, 'reason' => $c['reason'] ?? '']),
                    $auto_after,
                    null
                );
            }
        } catch (\Throwable $e) {
            debugging('local_unics: генерация предложений не удалась: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Убрать предложения, которые не выдержаны оценкой, и оговорить оставшиеся.
     *
     * Честность оценки была доведена до экранов, но не до потребителя: рекомендатель предлагал
     * «навык освоен, можно продвигаться» по полосе из ОБОРВАННОЙ проверки, и ребенка вели дальше
     * по теме, которую на деле не измерили ([[provisional-suggestions]]).
     *
     * Асимметрия намеренная: лишнее повторение ребенку безвредно, поэтому remediation остается -
     * с пометкой для педагога; продвижение по неизмеренному вредно и снимается.
     *
     * Фильтр стоит ЗДЕСЬ, а не внутри рекомендателей: их два (сервисный и запасной по правилам),
     * и правило честности должно быть общим - иначе запасной путь унаследует дыру.
     *
     * @param array $cands кандидаты рекомендателя
     * @return array отфильтрованные кандидаты
     */
    public static function drop_unsupported_suggestions(int $student_id, array $cands): array {
        $out = [];
        foreach ($cands as $c) {
            $eid = isset($c['element_id']) ? (int)$c['element_id'] : 0;
            if ($eid <= 0) {
                // Предложение без привязки к элементу проверять не по чему.
                $out[] = $c;
                continue;
            }
            if (!\local_unics\adaptive\estimate_precision::is_element_provisional($student_id, $eid)) {
                $out[] = $c;
                continue;
            }
            if ((int)($c['kind'] ?? 0) === suggestion_service::KIND_ADVANCEMENT) {
                continue;
            }
            $c['reason'] = trim((string)($c['reason'] ?? ''));
            $c['reason'] .= ($c['reason'] !== '' ? '. ' : '') . 'Оценка предварительная';
            $out[] = $c;
        }
        return $out;
    }
    /** Текущая строка владения по паре (ученик, элемент) или null. */
    public static function current_mastery(int $student_id, int $element_id): ?object {
        global $DB;
        return $DB->get_record('unics_skill_mastery',
            ['student_id' => $student_id, 'element_id' => $element_id]) ?: null;
    }

    /**
     * Записать итог CAT-сессии как владение навыком (advisory): score = проекция theta,
     * полоса по числу выданных вопросов; пишет skill_mastery + историю + глобальный rollup.
     * НЕ генерирует предложения (CAT v1 advisory; обычные попытки это делают сами).
     */
    public static function record_cat_mastery(int $student_id, int $element_id, float $theta,
                                              float $se, int $items): void {
        global $DB;
        $score = \local_unics\adaptive\theta_scale::project($theta);
        $band = \local_unics\adaptive\mastery_bands::band_for($score, max(1, $items));
        $now = time();
        $row = self::current_mastery($student_id, $element_id);
        if ($row) {
            $changed = (abs((float)$row->score - $score) > 0.001) || ((int)$row->band !== $band);
            $DB->update_record('unics_skill_mastery', (object)[
                'id'         => $row->id,
                'score'      => $score,
                'band'       => $band,
                'attempts_n' => (int)$row->attempts_n + 1,
                'last_score' => $score,
                'updated_at' => $now,
                'theta'      => round($theta, 4),
                'theta_se'   => round($se, 4),
            ]);
            if ($changed) {
                self::log_history($student_id, $element_id, (float)$row->score, $score,
                    (int)$row->band, $band, 0, $now);
            }
        } else {
            $DB->insert_record('unics_skill_mastery', (object)[
                'student_id'        => $student_id,
                'element_id'        => $element_id,
                'score'             => $score,
                'band'              => $band,
                'attempts_n'        => 1,
                'last_score'        => $score,
                'last_attempt_cmid' => null,
                'updated_at'        => $now,
                'theta'             => round($theta, 4),
                'theta_se'          => round($se, 4),
            ]);
            self::log_history($student_id, $element_id, -1.0, $score, 0, $band, 0, $now);
        }
    }

    /**
     * Карта владения ученика:
     * [element_id => object{score, band, attempts_n, last_score, updated_at, theta, theta_se}].
     * Один запрос. Источник колонки «Владение» в codifier_report. theta/theta_se - nullable
     * (заполнены только для IRT-строк; rolling_avg-строки и старые записи = null).
     */
    public static function get_student_mastery_map(int $student_id): array {
        global $DB;
        $rows = $DB->get_records('unics_skill_mastery', ['student_id' => $student_id]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->element_id] = (object)[
                'score'      => (float)$r->score,
                'band'       => (int)$r->band,
                'attempts_n' => (int)$r->attempts_n,
                'last_score' => $r->last_score !== null ? (float)$r->last_score : null,
                'updated_at' => (int)$r->updated_at,
                'theta'      => $r->theta !== null ? (float)$r->theta : null,
                'theta_se'   => $r->theta_se !== null ? (float)$r->theta_se : null,
            ];
        }
        return $out;
    }

    /**
     * Топ слабых элементов ученика (band=GAP) с названием, по возрастанию score.
     * Для виджета «Стоит повторить» на дашборде. @return object[] {element_id,score,band,attempts_n,code,title}
     */
    public static function get_weak_elements(int $student_id, int $limit = 3): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT sm.element_id, sm.score, sm.band, sm.attempts_n, e.code, e.title
               FROM {unics_skill_mastery} sm
               JOIN {unics_codifier_element} e ON e.id = sm.element_id
              WHERE sm.student_id = :sid AND sm.band = :gap
           ORDER BY sm.score ASC",
            ['sid' => $student_id, 'gap' => \local_unics\adaptive\mastery_bands::BAND_GAP],
            0, $limit));
    }

    /**
     * Подпись полосы владения: [текст, bootstrap-класс]. $child - детский вид (слова).
     * Делегат к mastery_bands - единственному источнику правды о полосах. Метод оставлен,
     * потому что на него завязаны отчет, виджет и сьют.
     *
     * @return array{0:string,1:string}
     */
    public static function band_label(int $band, bool $child = false): array {
        return \local_unics\adaptive\mastery_bands::label($band, $child);
    }

    /**
     * Пересчитать и записать владение одним навыком по одной попытке.
     * Пишет историю только при изменении score или band.
     */
    private static function apply_to_element(int $student_id, int $element_id, float $pct,
                                             int $cmid, ?int $weight, array $responses = []): void {
        global $DB;

        $row = self::current_mastery($student_id, $element_id);
        $prior = $row
            ? new mastery_state((float)$row->score, (int)$row->band, (int)$row->attempts_n,
                isset($row->theta) && $row->theta !== null ? (float)$row->theta : null,
                isset($row->theta_se) && $row->theta_se !== null ? (float)$row->theta_se : null)
            : null;

        $new = self::estimator()->estimate($prior,
            ['pct' => $pct, 'weight' => $weight, 'cmid' => $cmid, 'responses' => $responses]);
        $now = time();

        if ($row) {
            $changed = (abs((float)$row->score - $new->score) > 0.001) || ((int)$row->band !== $new->band);
            $DB->update_record('unics_skill_mastery', (object)[
                'id'                => $row->id,
                'score'             => $new->score,
                'band'              => $new->band,
                'attempts_n'        => $new->attempts_n,
                'last_score'        => round($pct, 2),
                'last_attempt_cmid' => $cmid,
                'updated_at'        => $now,
                'theta'             => $new->theta ?? $row->theta,
                'theta_se'          => $new->theta_se ?? $row->theta_se,
            ]);
            if ($changed) {
                self::log_history($student_id, $element_id, (float)$row->score, $new->score,
                    (int)$row->band, $new->band, $cmid, $now);
            }
        } else {
            $DB->insert_record('unics_skill_mastery', (object)[
                'student_id'        => $student_id,
                'element_id'        => $element_id,
                'score'             => $new->score,
                'band'              => $new->band,
                'attempts_n'        => $new->attempts_n,
                'last_score'        => round($pct, 2),
                'last_attempt_cmid' => $cmid,
                'updated_at'        => $now,
                'theta'             => $new->theta,
                'theta_se'          => $new->theta_se,
            ]);
            // Первая фиксация навыка - тоже история (old_score=null = «нет данных»).
            self::log_history($student_id, $element_id, -1.0, $new->score, 0, $new->band, $cmid, $now);
        }
    }

    /** Запись в append-only лог истории владения (нефатально). */
    private static function log_history(int $student_id, int $element_id, float $old_score,
            float $new_score, int $old_band, int $new_band, int $cmid, int $when): void {
        global $DB;
        try {
            $DB->insert_record('unics_mastery_history', (object)[
                'student_id'   => $student_id,
                'element_id'   => $element_id,
                'old_score'    => $old_score < 0 ? null : round($old_score, 2),
                'new_score'    => round($new_score, 2),
                'old_band'     => $old_band,
                'new_band'     => $new_band,
                'trigger_cmid' => $cmid,
                'changed_at'   => $when,
            ]);
        } catch (\Throwable $e) {
            debugging('local_unics: запись unics_mastery_history не удалась: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * % попытки по cmid (обобщенный grade-запрос на любой модуль с оценкой), либо null.
     * Зеркалит подход codifier_analytics/adaptive_engine.
     */
    private static function attempt_pct(int $cmid, int $userid): ?float {
        global $DB;
        $rec = $DB->get_record_sql(
            "SELECT g.finalgrade, gi.grademax
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {grade_items} gi
                 ON gi.itemtype = 'mod' AND gi.itemmodule = m.name AND gi.iteminstance = cm.instance
               JOIN {grade_grades} g ON g.itemid = gi.id AND g.userid = :uid
              WHERE cm.id = :cmid AND g.finalgrade IS NOT NULL AND gi.grademax > 0",
            ['uid' => $userid, 'cmid' => $cmid]);
        if (!$rec) {
            return null;
        }
        return (float)$rec->finalgrade / (float)$rec->grademax * 100;
    }

    /**
     * Привязки активности к элементам: [['element_id'=>int,'weight'=>int|null], ...].
     * Только target_type = активность (TYPE_ACTIVITY). Вопросы (questionid) - ML-фаза.
     */
    private static function element_links_for_cmid(int $cmid): array {
        global $DB;
        $rows = $DB->get_records('unics_codifier_link',
            ['target_type' => \local_unics\codifier_link_manager::TYPE_ACTIVITY, 'target_id' => $cmid],
            '', 'id, element_id, weight');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'element_id' => (int)$r->element_id,
                'weight'     => $r->weight !== null ? (int)$r->weight : null,
            ];
        }
        return $out;
    }
}
