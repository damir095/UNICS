<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Метаданные активности курса (таблица unics_activity_meta).
 *
 * B5: флаг is_final — помечает quiz как «итоговый экзамен» курса. Сам по себе
 * поведения не меняет; используется будущими B6 (сертификат) и B7 (автопересдача),
 * а также может усиливать критерий завершения курса (B8).
 * B4 (задел): is_milestone — промежуточная контрольная точка.
 *
 * Хранение отдельной таблицей (а не в course_modules) — чтобы не трогать ядро
 * и не конфликтовать с другими потребителями idnumber.
 */
class activity_meta {

    /** Запись метаданных по cmid (или false). */
    public static function get(int $cmid) {
        global $DB;
        return $DB->get_record('unics_activity_meta', ['cmid' => $cmid]);
    }

    public static function is_final(int $cmid): bool {
        $rec = self::get($cmid);
        return $rec && (int)$rec->is_final === 1;
    }

    public static function is_milestone(int $cmid): bool {
        $rec = self::get($cmid);
        return $rec && (int)$rec->is_milestone === 1;
    }

    public static function is_diagnostic(int $cmid): bool {
        $rec = self::get($cmid);
        return $rec && (int)$rec->is_diagnostic === 1;
    }

    /**
     * Установить/снять флаги. null = не менять данный флаг.
     * Создаёт строку при первом обращении, иначе обновляет. Если в результате
     * оба флага оказываются сняты, строка не создаётся / удаляется — таблица
     * не должна накапливать служебных (0,0) записей (по строке на каждый тест).
     */
    public static function set_flags(int $cmid, ?bool $is_final = null, ?bool $is_milestone = null,
                                     ?bool $is_diagnostic = null): void {
        global $DB;
        $now = time();
        $rec = self::get($cmid);
        if (!$rec) {
            // Нечего хранить, если ни один флаг не выставляется в 1.
            if (!$is_final && !$is_milestone && !$is_diagnostic) {
                return;
            }
            $DB->insert_record('unics_activity_meta', (object)[
                'cmid'          => $cmid,
                'is_final'      => $is_final      ? 1 : 0,
                'is_milestone'  => $is_milestone  ? 1 : 0,
                'is_diagnostic' => $is_diagnostic ? 1 : 0,
                'timecreated'   => $now,
                'timemodified'  => $now,
            ]);
            return;
        }
        if ($is_final !== null) {
            $rec->is_final = $is_final ? 1 : 0;
        }
        if ($is_milestone !== null) {
            $rec->is_milestone = $is_milestone ? 1 : 0;
        }
        if ($is_diagnostic !== null) {
            $rec->is_diagnostic = $is_diagnostic ? 1 : 0;
        }
        // Все флаги сняты — метаданные больше не несут смысла, удаляем строку.
        if ((int)$rec->is_final === 0 && (int)$rec->is_milestone === 0 && (int)$rec->is_diagnostic === 0) {
            $DB->delete_records('unics_activity_meta', ['id' => $rec->id]);
            return;
        }
        $rec->timemodified = $now;
        $DB->update_record('unics_activity_meta', $rec);
    }

    /**
     * Пометить cmid как итоговый экзамен; снять флаг с прочих активностей курса
     * (итоговый экзамен в курсе один). $course_id нужен, чтобы знать, с кого снимать.
     */
    public static function set_final(int $cmid, int $course_id): void {
        global $DB;
        // Снять is_final со всех активностей этого курса.
        $others = $DB->get_records_sql(
            "SELECT am.cmid
               FROM {unics_activity_meta} am
               JOIN {course_modules} cm ON cm.id = am.cmid
              WHERE cm.course = :course AND am.is_final = 1 AND am.cmid <> :cmid",
            ['course' => $course_id, 'cmid' => $cmid]
        );
        foreach ($others as $o) {
            // Через set_flags: если активность была только итоговой — строка
            // удалится; если она ещё и контрольная точка — останется с is_milestone.
            self::set_flags((int)$o->cmid, false, null);
        }
        self::set_flags($cmid, true, null);
    }

    /** cmid итогового экзамена курса (или null). */
    public static function get_final_cmid(int $course_id): ?int {
        global $DB;
        $cmid = $DB->get_field_sql(
            "SELECT am.cmid
               FROM {unics_activity_meta} am
               JOIN {course_modules} cm ON cm.id = am.cmid
              WHERE cm.course = :course AND am.is_final = 1",
            ['course' => $course_id], IGNORE_MULTIPLE
        );
        return $cmid ? (int)$cmid : null;
    }

    /**
     * cmid'ы контрольных точек курса (B4). В отличие от итогового экзамена,
     * контрольных точек может быть несколько.
     *
     * @return int[] список cmid с is_milestone = 1
     */
    public static function get_milestone_cmids(int $course_id): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT am.cmid
               FROM {unics_activity_meta} am
               JOIN {course_modules} cm ON cm.id = am.cmid
              WHERE cm.course = :course AND am.is_milestone = 1",
            ['course' => $course_id]
        );
        return array_map('intval', array_keys($rows));
    }

    /**
     * Пометить cmid как входной диагностический тест; снять флаг с прочих
     * активностей курса (входная диагностика в курсе одна). Зеркалит set_final.
     */
    public static function set_diagnostic(int $cmid, int $course_id): void {
        global $DB;
        $others = $DB->get_records_sql(
            "SELECT am.cmid
               FROM {unics_activity_meta} am
               JOIN {course_modules} cm ON cm.id = am.cmid
              WHERE cm.course = :course AND am.is_diagnostic = 1 AND am.cmid <> :cmid",
            ['course' => $course_id, 'cmid' => $cmid]
        );
        foreach ($others as $o) {
            self::set_flags((int)$o->cmid, null, null, false);
        }
        self::set_flags($cmid, null, null, true);
    }

    /** cmid входного диагностического теста курса (или null). */
    public static function get_diagnostic_cmid(int $course_id): ?int {
        global $DB;
        $cmid = $DB->get_field_sql(
            "SELECT am.cmid
               FROM {unics_activity_meta} am
               JOIN {course_modules} cm ON cm.id = am.cmid
              WHERE cm.course = :course AND am.is_diagnostic = 1",
            ['course' => $course_id], IGNORE_MULTIPLE
        );
        return $cmid ? (int)$cmid : null;
    }

    /** Удалить метаданные cmid (при удалении активности). */
    public static function delete(int $cmid): void {
        global $DB;
        $DB->delete_records('unics_activity_meta', ['cmid' => $cmid]);
    }
}
