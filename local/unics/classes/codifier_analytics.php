<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Сквозная аналитика «% по элементам содержания»: активности элемента лежат в РАЗНЫХ
 * курсах одной дисциплины, процент агрегируется через курсы (чего нет в ядре Moodle).
 * [[codifier-design]]. Родитель = агрегат всего поддерева (через path).
 */
class codifier_analytics {

    /**
     * % пользователя по набору cmid: [cmid => pct] только для активностей с оценкой.
     * Обобщение grade-запроса adaptive_engine на любой модуль (cm -> modules.name ->
     * grade_items(itemmodule,iteminstance) -> grade_grades(userid)).
     *
     * @param int[] $cmids
     * @return array<int,float>
     */
    private static function pct_by_cmid(int $mdl_user_id, array $cmids): array {
        global $DB;
        if (!$cmids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $params['uid'] = $mdl_user_id;
        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, g.finalgrade, gi.grademax
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {grade_items} gi
                 ON gi.itemtype = 'mod' AND gi.itemmodule = m.name AND gi.iteminstance = cm.instance
               JOIN {grade_grades} g ON g.itemid = gi.id AND g.userid = :uid
              WHERE cm.id $insql
                AND g.finalgrade IS NOT NULL AND gi.grademax > 0",
            $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->cmid] = (float)$r->finalgrade / (float)$r->grademax * 100;
        }
        return $out;
    }

    /**
     * Per-question дроби (в %) по элементам для ученика, по ПОСЛЕДНЕЙ завершённой попытке
     * каждого теста. Ограничено набором element_id. @return array<int,array{0:float,1:int}>
     * [element_id => [sum_pct, cnt]] (cnt = число привязанных вопросов-наблюдений).
     */
    private static function question_scores(int $mdl_user_id, array $element_ids): array {
        global $DB;
        if (!$element_ids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($element_ids, SQL_PARAMS_NAMED, 'e');
        $params['uid'] = $mdl_user_id;
        $params['tq']  = codifier_link_manager::TYPE_QUESTION;
        $rs = $DB->get_recordset_sql(
            "SELECT l.id AS linkid, l.element_id, qas.fraction
               FROM {quiz_attempts} quiza
               JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
               JOIN {question_versions} qv ON qv.questionid = qa.questionid
               JOIN {unics_codifier_link} l
                    ON l.target_type = :tq AND l.target_id = qv.questionbankentryid
                   AND l.element_id $insql
               JOIN {question_attempt_steps} qas ON qas.id = (
                       SELECT s.id FROM {question_attempt_steps} s
                        WHERE s.questionattemptid = qa.id AND s.fraction IS NOT NULL
                     ORDER BY s.sequencenumber DESC
                        LIMIT 1)
              WHERE quiza.userid = :uid AND quiza.state = 'finished'
                AND quiza.attempt = (
                    SELECT MAX(a2.attempt) FROM {quiz_attempts} a2
                     WHERE a2.quiz = quiza.quiz AND a2.userid = quiza.userid
                       AND a2.state = 'finished')",
            $params);
        $acc = [];
        foreach ($rs as $r) {
            $eid = (int)$r->element_id;
            if (!isset($acc[$eid])) {
                $acc[$eid] = [0.0, 0];
            }
            $acc[$eid][0] += (float)$r->fraction * 100;
            $acc[$eid][1]++;
        }
        $rs->close();
        return $acc;
    }

    /**
     * Средний % ученика по активностям элемента (вкл. поддерево), либо null если данных нет.
     */
    public static function element_grade(int $mdl_user_id, int $element_id): ?float {
        global $DB;
        // Идентификаторы элементов поддерева (включая сам), как в get_*_for_element.
        $el = $DB->get_record('unics_codifier_element', ['id' => $element_id],
            'id, codifier_id, path', MUST_EXIST);
        if ($el->path) {
            $subids = $DB->get_fieldset_select('unics_codifier_element', 'id',
                'codifier_id = :cid AND ' . $DB->sql_like('path', ':p'),
                ['cid' => $el->codifier_id, 'p' => $el->path . '%']);
        } else {
            $subids = [(int)$el->id];
        }
        // Per-question приоритет: пуловое среднее дробей вопросов поддерева.
        $q = self::question_scores($mdl_user_id, array_map('intval', $subids));
        $sum = 0.0;
        $cnt = 0;
        foreach ($q as $sc) {
            $sum += $sc[0];
            $cnt += $sc[1];
        }
        if ($cnt > 0) {
            return round($sum / $cnt, 1);
        }
        // cmid фолбэк (поведение фазы 1).
        $cmids = codifier_link_manager::get_activities_for_element($element_id, true);
        $pcts = self::pct_by_cmid($mdl_user_id, $cmids);
        if (!$pcts) {
            return null;
        }
        return round(array_sum($pcts) / count($pcts), 1);
    }

    /**
     * Дерево прогресса ученика по кодификатору: на каждый элемент - pct (или null = не покрыт)
     * и n (число оценённых активностей в поддереве). Один проход по оценкам.
     *
     * @return array<int,object> список {id,code,title,parent_id,path,pct,n,linked}
     */
    public static function student_element_progress(int $mdl_user_id, int $codifier_id): array {
        global $DB;
        $ordered = codifier_manager::get_tree($codifier_id);
        if (!$ordered) {
            return [];
        }
        $elements = [];
        foreach ($ordered as $e) {
            $elements[(int)$e->id] = $e;
        }
        $elementIds = array_keys($elements);

        // 1. Per-question прямые баллы (приоритет): [eid => [sum, cnt]].
        $q = self::question_scores($mdl_user_id, $elementIds);

        // 2. cmid прямые баллы (фолбэк) для элементов БЕЗ вопрос-покрытия.
        list($insql, $params) = $DB->get_in_or_equal($elementIds, SQL_PARAMS_NAMED);
        $params['t'] = codifier_link_manager::TYPE_ACTIVITY;
        $links = $DB->get_records_select('unics_codifier_link',
            "target_type = :t AND element_id $insql", $params, '', 'id, element_id, target_id');
        $directCmids = [];
        $allCmids = [];
        foreach ($links as $l) {
            $directCmids[(int)$l->element_id][] = (int)$l->target_id;
            $allCmids[(int)$l->target_id] = true;
        }
        $pcts = self::pct_by_cmid($mdl_user_id, array_keys($allCmids));

        // 3. Прямые sum/cnt на элемент: per-question приоритет, иначе cmid.
        $directSum = [];
        $directN = [];
        $linked = [];
        foreach ($elementIds as $eid) {
            if (isset($q[$eid]) && $q[$eid][1] > 0) {
                $directSum[$eid] = $q[$eid][0];
                $directN[$eid] = $q[$eid][1];
                $linked[$eid] = true;
            } else if (!empty($directCmids[$eid])) {
                $s = 0.0;
                $c = 0;
                foreach ($directCmids[$eid] as $cmid) {
                    if (isset($pcts[$cmid])) {
                        $s += $pcts[$cmid];
                        $c++;
                    }
                }
                $directSum[$eid] = $s;
                $directN[$eid] = $c;
                $linked[$eid] = true;
            } else {
                $directSum[$eid] = 0.0;
                $directN[$eid] = 0;
                $linked[$eid] = isset($q[$eid]) || isset($directCmids[$eid]);
            }
        }

        // 4. Роллап по поддереву (через path) + сборка строк.
        $out = [];
        foreach ($elements as $e) {
            $sum = 0.0;
            $cnt = 0;
            foreach ($elements as $d) {
                if (strpos((string)$d->path, (string)$e->path) !== 0) {
                    continue;
                }
                $sum += $directSum[(int)$d->id] ?? 0.0;
                $cnt += $directN[(int)$d->id] ?? 0;
            }
            $out[(int)$e->id] = (object)[
                'id'        => (int)$e->id,
                'code'      => $e->code,
                'title'     => $e->title,
                'parent_id' => $e->parent_id ? (int)$e->parent_id : null,
                'path'      => $e->path,
                'depth'     => (int)($e->depth ?? 0),
                'pct'       => $cnt > 0 ? round($sum / $cnt, 1) : null,
                'n'         => $cnt,
                'linked'    => !empty($linked[(int)$e->id]),
            ];
        }
        return array_values($out);
    }

    /**
     * Групповой срез: средний % по элементам для набора учеников (mdl_user_id).
     * Пуловое среднее по всем оценённым парам (ученик, активность) поддерева.
     *
     * @param int[] $mdl_user_ids
     * @return array<int,object> как student_element_progress, pct = пуловое среднее, n = пар
     */
    public static function cohort_element_progress(array $mdl_user_ids, int $codifier_id): array {
        if (!$mdl_user_ids) {
            return [];
        }
        $acc = []; // element_id => аккумулятор
        foreach ($mdl_user_ids as $uid) {
            foreach (self::student_element_progress((int)$uid, $codifier_id) as $row) {
                if (!isset($acc[$row->id])) {
                    $acc[$row->id] = (object)[
                        'id' => $row->id, 'code' => $row->code, 'title' => $row->title,
                        'parent_id' => $row->parent_id, 'path' => $row->path, 'depth' => $row->depth,
                        'sum' => 0.0, 'cnt' => 0, 'linked' => $row->linked,
                    ];
                }
                if ($row->pct !== null && $row->n > 0) {
                    $acc[$row->id]->sum += $row->pct * $row->n;
                    $acc[$row->id]->cnt += $row->n;
                }
            }
        }
        $out = [];
        foreach ($acc as $a) {
            $out[] = (object)[
                'id' => $a->id, 'code' => $a->code, 'title' => $a->title,
                'parent_id' => $a->parent_id, 'path' => $a->path, 'depth' => $a->depth,
                'linked' => $a->linked,
                'pct' => $a->cnt > 0 ? round($a->sum / $a->cnt, 1) : null,
                'n'   => $a->cnt,
            ];
        }
        return $out;
    }

    /**
     * Готовность банка к CAT по элементам кодификатора (read-only индикатор).
     * На каждый элемент: сколько вопросов поддерева протегировано (type=2), сколько из
     * них калибровано (есть строка в unics_item_irt) и сколько 2PL (model='2pl'), плюс
     * вердикт по настройке cat_min_items. Роллап по поддереву через path, как
     * cohort_element_progress. [[cat-readiness-indicator-design]]. Read-only.
     *
     * @return array<int,object> {id,code,title,parent_id,path,depth,tagged_n,calibrated_n,ready_2pl_n,verdict}
     *         verdict in {'no_tags','low_calib','ready'}.
     */
    public static function element_bank_readiness(int $codifier_id): array {
        global $DB;
        $ordered = codifier_manager::get_tree($codifier_id);
        if (!$ordered) {
            return [];
        }
        $elements = [];
        foreach ($ordered as $e) {
            $elements[(int)$e->id] = $e;
        }
        $elementIds = array_keys($elements);

        // Direct-счётчики на элемент: тегированные вопросы (type=2) + калибровка из item_irt.
        list($insql, $params) = $DB->get_in_or_equal($elementIds, SQL_PARAMS_NAMED);
        $params['tq'] = codifier_link_manager::TYPE_QUESTION;
        // Готовым к 2PL считается задание, у которого дискриминация ДЕЙСТВИТЕЛЬНО оценена.
        // Одной метки model = '2pl' мало: живой зонд показал, что сервис ставит ее и при шести
        // наблюдениях, отдавая a = 1.000 у всех заданий, - то есть 2PL вырождается в модель Раша,
        // а методисту при этом рисовалось «готово к CAT». Поэтому требуем и порог наблюдений
        // item_pool::MIN_CALIBRATED_N, и отличие a от единицы.
        $params['mincal']  = item_irt_manager::MIN_CALIBRATED_N;
        $params['mincal2'] = item_irt_manager::MIN_CALIBRATED_N;
        $rows = $DB->get_records_sql(
            "SELECT l.id AS linkid, l.element_id,
                    CASE WHEN i.id IS NOT NULL AND i.calibrated_n >= :mincal2
                         THEN 1 ELSE 0 END AS calibrated,
                    CASE WHEN i.model = '2pl' AND i.calibrated_n >= :mincal
                              AND ABS(i.a - 1) > 0.01 THEN 1 ELSE 0 END AS is2pl
               FROM {unics_codifier_link} l
               LEFT JOIN {unics_item_irt} i ON i.item_ref = l.target_id
              WHERE l.target_type = :tq AND l.element_id $insql",
            $params);

        $directTagged = [];
        $directCalib  = [];
        $direct2pl    = [];
        foreach ($elementIds as $eid) {
            $directTagged[$eid] = 0;
            $directCalib[$eid]  = 0;
            $direct2pl[$eid]    = 0;
        }
        foreach ($rows as $r) {
            $eid = (int)$r->element_id;
            $directTagged[$eid]++;
            if ((int)$r->calibrated === 1) {
                $directCalib[$eid]++;
            }
            if ((int)$r->is2pl === 1) {
                $direct2pl[$eid]++;
            }
        }

        $minitems = (int)get_config('local_unics', 'cat_min_items');
        if ($minitems <= 0) {
            $minitems = 5;
        }

        // Роллап по поддереву (через path) + вердикт.
        $out = [];
        foreach ($elements as $e) {
            $tagged = 0;
            $calib  = 0;
            $r2pl   = 0;
            foreach ($elements as $d) {
                if (strpos((string)$d->path, (string)$e->path) !== 0) {
                    continue;
                }
                $tagged += $directTagged[(int)$d->id];
                $calib  += $directCalib[(int)$d->id];
                $r2pl   += $direct2pl[(int)$d->id];
            }
            if ($tagged === 0) {
                $verdict = 'no_tags';
            } else if ($calib < $minitems) {
                $verdict = 'low_calib';
            } else {
                $verdict = 'ready';
            }
            $out[] = (object)[
                'id'           => (int)$e->id,
                'code'         => $e->code,
                'title'        => $e->title,
                'parent_id'    => $e->parent_id ? (int)$e->parent_id : null,
                'path'         => $e->path,
                'depth'        => (int)($e->depth ?? 0),
                'tagged_n'     => $tagged,
                'calibrated_n' => $calib,
                'ready_2pl_n'  => $r2pl,
                'verdict'      => $verdict,
            ];
        }
        return $out;
    }
}
