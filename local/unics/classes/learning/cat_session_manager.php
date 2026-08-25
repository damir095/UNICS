<?php
namespace local_unics\learning;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Оркестратор адаптивной проверки (CAT) по одному навыку [[cat-design]]. Владеет Moodle
 * question_usage (рендер/оценка - ядром), а отбор следующего вопроса делегирует Python-сервису
 * (/cat/next) за швом irt_client. Состояние персистится: unics_cat_session(+qubaid) + unics_cat_step.
 */
class cat_session_manager {

    const STATUS_ACTIVE = 0;
    const STATUS_FINISHED = 1;
    const STATUS_ABANDONED = 2;

    /** Элементы кодификатора, у которых есть калиброванные (unics_item_irt) привязанные вопросы. */
    public static function eligible_elements(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT e.id AS element_id, e.code, e.title, COUNT(DISTINCT p.item_ref) AS n
               FROM {unics_codifier_link} l
               JOIN {unics_codifier_element} e ON e.id = l.element_id
               JOIN {unics_item_irt} p ON p.item_ref = l.target_id
              WHERE l.target_type = :tq
           GROUP BY e.id, e.code, e.title
             HAVING COUNT(DISTINCT p.item_ref) > 0
           ORDER BY e.path ASC",
            ['tq' => \local_unics\codifier_link_manager::TYPE_QUESTION]);
        $out = [];
        foreach ($rows as $r) {
            $eid = (int)$r->element_id;
            // Считаем и отсеиваем ОДНОЙ меркой - самим банком: запрос выше знает лишь о наличии
            // строки параметров, а годность решает достоверность калибровки. Без этого тема с
            // недостоверными трудностями предлагалась ребенку и открывалась пустой.
            $n = count(self::bank($eid));
            if ($n === 0) {
                continue;
            }
            $out[] = ['element_id' => $eid, 'code' => $r->code, 'title' => $r->title, 'n' => $n];
        }
        return $out;
    }

    public static function active_session(int $student_id, int $element_id): ?object {
        global $DB;
        return $DB->get_record('unics_cat_session',
            ['student_id' => $student_id, 'element_id' => $element_id,
             'status' => self::STATUS_ACTIVE]) ?: null;
    }

    /** Последняя завершенная сессия по (ученик, элемент) или null. */
    public static function latest_finished(int $student_id, int $element_id): ?object {
        global $DB;
        // id DESC как второй ключ: две проверки могут закончиться в одну секунду (ребенок
        // прошел тему заново на маленьком банке), и без него база вправе вернуть любую -
        // а от этой строки теперь зависит вердикт «предварительная оценка» и маршрут.
        $rows = $DB->get_records('unics_cat_session',
            ['student_id' => $student_id, 'element_id' => $element_id, 'status' => self::STATUS_FINISHED],
            'finished_at DESC, id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /** Калиброванный банк элемента (+поддерево): [item_ref => ['a'=>,'b'=>]]. */
    private static function bank(int $element_id): array {
        $entries = \local_unics\codifier_link_manager::get_questions_for_element($element_id, true);
        // ТОЛЬКО достоверная калибровка: живой заход 2026-08-17 показал, что иначе ребенку
        // достается задание с b = -3.892, снятой с шести ответов, а адаптация подбирает
        // следующее задание под оценку, которой на деле нет.
        return $entries ? \local_unics\item_irt_manager::get_ab_for_entries($entries, true) : [];
    }

    /** bankentryid -> последний questionid версии. */
    private static function questionid_for_entry(int $bankentryid): ?int {
        global $DB;
        $qid = $DB->get_field_sql(
            "SELECT qv.questionid FROM {question_versions} qv
              WHERE qv.questionbankentryid = :beid
           ORDER BY qv.version DESC", ['beid' => $bankentryid], IGNORE_MULTIPLE);
        return $qid ? (int)$qid : null;
    }

    public static function load_quba(object $session): \question_usage_by_activity {
        return \question_engine::load_questions_usage_by_activity((int)$session->qubaid);
    }

    /** item_ref'ы уже выданных вопросов сессии (из лога шагов). */
    private static function administered_refs(int $session_id): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select('unics_cat_step', 'item_ref',
            'session_id = :sid', ['sid' => $session_id]));
    }

    /** Тройки ответов сессии {a,b,correct} (из лога шагов + параметров банка). */
    private static function responses(object $session): array {
        global $DB;
        $steps = $DB->get_records('unics_cat_step', ['session_id' => $session->id], 'id ASC');
        if (!$steps) {
            return [];
        }
        $params = self::bank((int)$session->element_id);
        $out = [];
        foreach ($steps as $s) {
            $ab = $params[(int)$s->item_ref] ?? ['a' => 1.0, 'b' => 0.0];
            $out[] = ['a' => $ab['a'], 'b' => $ab['b'], 'correct' => (int)$s->correct];
        }
        return $out;
    }

    /** Слот незавершённого (текущего) вопроса в usage, либо null. */
    public static function current_slot(object $session): ?int {
        if (!$session->qubaid) {
            return null;
        }
        $quba = self::load_quba($session);
        foreach ($quba->get_slots() as $slot) {
            $state = $quba->get_question_state($slot);
            if (!$state->is_finished()) {
                return (int)$slot;
            }
        }
        return null;
    }

    /** Добавить вопрос item_ref в usage, стартовать, сохранить; вернуть slot или null. */
    private static function add_item(\question_usage_by_activity $quba, int $item_ref): ?int {
        $qid = self::questionid_for_entry($item_ref);
        if (!$qid) {
            return null;
        }
        $question = \question_bank::load_question($qid);
        $slot = $quba->add_question($question);
        $quba->start_question($slot);
        \question_engine::save_questions_usage_by_activity($quba);
        return (int)$slot;
    }

    /**
     * Старт сессии: создать usage, записать строку, выбрать и добавить первый вопрос.
     * @throws \moodle_exception если банк пуст или сервис недоступен.
     */
    public static function start(int $student_id, int $element_id): object {
        global $DB;
        $bank = self::bank($element_id);
        if (!$bank) {
            throw new \moodle_exception('cat_no_items', 'local_unics');
        }
        // Первый отбор (responses=[]) - сервис вернёт стартовый item.
        $candidates = [];
        foreach ($bank as $ref => $ab) {
            $candidates[] = ['item_ref' => (int)$ref, 'a' => $ab['a'], 'b' => $ab['b']];
        }
        $cfg = self::config();
        $res = \local_unics\adaptive\irt_client::cat_next([], $candidates,
            $cfg['se'], $cfg['min'], $cfg['max']);
        if ($res === null || $res['next_item_ref'] === null) {
            throw new \moodle_exception('cat_service_down', 'local_unics');
        }

        $quba = \question_engine::make_questions_usage_by_activity('local_unics',
            \context_system::instance());
        // deferredfeedback: ядро НЕ рисует свою кнопку «Проверить» (мы грейдим сами через
        // finish_question), поэтому на странице остается только наша кнопка «Ответить».
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);

        $now = time();
        $session = (object)[
            'student_id' => $student_id, 'element_id' => $element_id,
            'qubaid' => $quba->get_id(), 'status' => self::STATUS_ACTIVE,
            'theta' => null, 'theta_se' => null, 'items_administered' => 0,
            'started_at' => $now, 'finished_at' => null,
        ];
        $session->id = (int)$DB->insert_record('unics_cat_session', $session);

        $slot = self::add_item($quba, (int)$res['next_item_ref']);
        if ($slot === null) {
            self::abandon((int)$session->id);
            throw new \moodle_exception('cat_service_down', 'local_unics');
        }
        // Запомнить, какой item на каком слоте (через лог не пишем до ответа - храним в qa).
        return $session;
    }

    /** Текущие настройки CAT (с дефолтами). */
    private static function config(): array {
        // Порог точности берем из estimate_precision - там же его читает признак
        // предварительной оценки. Два своих значения по умолчанию означали бы, что проверка
        // останавливается по одной планке, а честность результата меряется по другой.
        $min = (int)get_config('local_unics', 'cat_min_items');
        $max = (int)get_config('local_unics', 'cat_max_items');
        return ['se' => \local_unics\adaptive\estimate_precision::threshold(),
                'min' => $min > 0 ? $min : 5, 'max' => $max > 0 ? $max : 20];
    }

    /** item_ref вопроса, стоящего на слоте (по questionbankentryid версии вопроса слота). */
    private static function item_ref_for_slot(\question_usage_by_activity $quba, int $slot): ?int {
        global $DB;
        $qid = $quba->get_question($slot)->id;
        $ref = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $qid]);
        return $ref ? (int)$ref : null;
    }

    /**
     * Обработать отправленный ответ: грейд текущего слота, лог шага, зов сервиса, добавление
     * следующего вопроса или финал. Вызывать после require_sesskey() на POST страницы.
     */
    public static function answer(object $session): void {
        global $DB;
        $quba = self::load_quba($session);
        $slot = self::current_slot($session);
        if ($slot === null) {
            return; // нечего обрабатывать (защита от двойного submit)
        }
        $quba->process_all_actions(time());
        $quba->finish_question($slot, time());
        \question_engine::save_questions_usage_by_activity($quba);

        $fraction = $quba->get_question_attempt($slot)->get_fraction();
        $correct = ($fraction !== null && (float)$fraction >= 0.5) ? 1 : 0;
        $item_ref = self::item_ref_for_slot($quba, $slot);

        // Лог шага (theta_after заполним после зова сервиса).
        $stepid = (int)$DB->insert_record('unics_cat_step', (object)[
            'session_id' => $session->id, 'slot' => $slot, 'item_ref' => $item_ref,
            'correct' => $correct, 'theta_after' => null, 'se_after' => null,
            'created_at' => time(),
        ]);

        // Зов сервиса: переоценка theta + следующий item.
        $responses = self::responses($session);
        $administered = self::administered_refs((int)$session->id);
        $bank = self::bank((int)$session->element_id);
        $candidates = [];
        foreach ($bank as $ref => $ab) {
            if (!in_array((int)$ref, $administered, true)) {
                $candidates[] = ['item_ref' => (int)$ref, 'a' => $ab['a'], 'b' => $ab['b']];
            }
        }
        $cfg = self::config();
        $res = \local_unics\adaptive\irt_client::cat_next($responses, $candidates,
            $cfg['se'], $cfg['min'], $cfg['max']);

        $items = count($responses);
        if ($res === null) {
            // Сервис упал в середине: НЕ финишируем, сессия живёт - продолжит при возврате.
            $DB->update_record('unics_cat_session', (object)[
                'id' => $session->id, 'items_administered' => $items]);
            return;
        }

        $DB->update_record('unics_cat_step', (object)[
            'id' => $stepid, 'theta_after' => round($res['theta'], 4),
            'se_after' => round($res['se'], 4)]);
        $DB->update_record('unics_cat_session', (object)[
            'id' => $session->id, 'theta' => round($res['theta'], 4),
            'theta_se' => round($res['se'], 4), 'items_administered' => $items]);

        if ($res['stop'] || $res['next_item_ref'] === null) {
            // Причину остановки называет сам сервис - сохраняем ее, а не выводим потом
            // сравнением с настройкой ([[cat-honest-precision]], раздел 3).
            self::finish($session, (float)$res['theta'], (float)$res['se'], $items,
                (string)($res['reason'] ?? ''), (float)$cfg['se']);
        } else {
            self::add_item($quba, (int)$res['next_item_ref']);
        }
    }

    /**
     * Финал: status=1, запись владения (advisory).
     *
     * @param string $reason причина остановки от сервиса; пустая - если сервис ее не назвал
     * @param float $threshold порог точности, действовавший в этой проверке
     */
    public static function finish(object $session, float $theta, float $se, int $items,
                                  string $reason = '', ?float $threshold = null): void {
        global $DB;
        $DB->update_record('unics_cat_session', (object)[
            'id' => $session->id, 'status' => self::STATUS_FINISHED,
            'theta' => round($theta, 4), 'theta_se' => round($se, 4),
            'items_administered' => $items, 'finished_at' => time(),
            'stop_reason' => $reason !== '' ? $reason : null,
            'se_threshold' => $threshold !== null ? round($threshold, 3) : null]);
        mastery_manager::record_cat_mastery((int)$session->student_id,
            (int)$session->element_id, $theta, $se, $items);

        // Пересчет предложений - здесь же, где меняется оценка ([[cat-finish-suggestions-design]]).
        //
        // Фильтр честности снимает продвижение, пока оценка предварительная
        // ([[provisional-suggestions]]), а вернуть его должна очередная проверка, доведенная до
        // точности. Но рекомендатель запускался ТОЛЬКО из mastery_manager::on_attempt(), то есть
        // по попытке обычного теста Moodle: завершение CAT-сессии его не запускало, ночная
        // задача тоже. Выходило наоборот - именно та проверка, которая обязана разблокировать
        // продвижение, его и не разблокировала, и ребенок оставался ниже своего уровня до
        // ближайшего обычного теста по этому элементу (найдено ревью 2026-08-24).
        //
        // Повторные вызовы безопасны: suggestion_service::create() молча выходит, если открытое
        // предложение той же пары уже есть, и уведомление педагогу уходит только при вставке.
        mastery_manager::regenerate_suggestions((int)$session->student_id);

        // Вторая половина того же: on_attempt() после генерации предложений зовет глобальный
        // гейт уровня, и без него ребенок, который проверяется ТОЛЬКО через CAT, копит освоенные
        // элементы, получает карточки продвижения, но предложения сменить общий уровень
        // сложности не видит никогда (найдено ревью 2026-08-25).
        try {
            adaptive_engine::gate_level_change((int)$session->student_id);
        } catch (\Throwable $e) {
            debugging('local_unics: глобальный rollup после CAT не удался: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
    }

    public static function abandon(int $session_id): void {
        global $DB;
        $DB->set_field('unics_cat_session', 'status', self::STATUS_ABANDONED, ['id' => $session_id]);
    }
}
