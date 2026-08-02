<?php
namespace local_unics\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Пометка аудитории уровневых вариантов активностей для педагогского вида страницы курса:
 * какому уровню принадлежит строка, сколько учеников ее видит, и не осталась ли она сиротой.
 *
 * Дифференциацию делает УМК-построитель {@see \local_unics\ai\course_builder}: он заводит группу
 * с idnumber вида umk_lvl{уровень}_c{курс}_{хеш темы} и вешает на активность ограничение по этой
 * группе. Уровень читаем из idnumber, а не из имени группы: имя педагог может переименовать,
 * idnumber - машинный ключ.
 *
 * ВАЖНО про набор строк: пометка рисуется на ВСЕХ активностях, которые видит педагог, включая
 * скрытые от учеников - именно на них она нужнее всего. Это НЕ тот набор, что у чипов
 * {@see course_staff_view} («сделали / на проверке / застряли»), которые сознательно живут только
 * на видимых ученикам активностях.
 *
 * ВАЖНО про число: аудитория - это ученики КУРСА в группе, а не класс смотрящего. Вопрос здесь про
 * конфигурацию курса, а не про моих учеников; вариант, где нет моих, но есть чужие, не сирота.
 */
class course_variants {

    /** Уровни, которые умеет заводить course_builder; прочие номера - общая формулировка. */
    private const KNOWN_LEVELS = [1, 2, 3];

    /**
     * @return array{variants:array<string,array{label:string,orphan:bool}>,
     *               orphans:?array{count:int,label:string,url:string}}
     */
    public static function build(\stdClass $course, int $viewerid): array {
        $groups = self::group_audience((int)$course->id);
        // Видимость считаем от ЯВНОГО смотрящего, а не от глобального $USER: скрытая от учеников
        // активность попадает в пометку только потому, что у педагога есть право видеть скрытое.
        $modinfo = get_fast_modinfo($course, $viewerid);

        $variants = [];
        $orphangroups = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->has_view() || !$cm->uservisible) {
                continue;
            }
            $gids = self::restriction_group_ids($cm, $groups);
            if (!$gids) {
                continue;
            }
            // Аудитория активности - сумма аудиторий ВСЕХ групп-условий (условия группы внутри
            // одного узла availability - это OR, любой ученик любой из групп открывает активность).
            // Сумма используется ТОЛЬКО для сироты/скрытия варианта целиком.
            $audience = 0;
            // Состояние строим ПО КАЖДОЙ группе своим "who · state" - ЛОВУШКА, найдена при
            // самопроверке: если считать одно усредненное состояние на объединенную аудиторию
            // (как для orphan-флага), то у активности с двумя группами - одна полная, одна
            // пустая - подпись "Стандартный, Базовый · 1 ученик" читается так, будто обоих
            // уровней видит один и тот же ученик, и пропадает сигнал, что именно второй вариант
            // пуст. Педагогу нужно видеть аудиторию КАЖДОГО уровня отдельно.
            $parts = [];
            foreach ($gids as $gid) {
                $group = $groups[$gid];
                $groupaudience = (int)$group->audience;
                $audience += $groupaudience;
                $parts[] = get_string('variant_label', 'local_unics', (object)[
                    'who' => self::who_label($group),
                    'state' => self::state_label($groupaudience === 0, (bool)$cm->visible, $groupaudience),
                ]);
            }
            $nobody = ($audience === 0);
            if ($nobody) {
                foreach ($gids as $gid) {
                    $orphangroups[$gid] = true;
                }
            }
            $variants[(string)$cm->id] = [
                'label' => implode(', ', $parts),
                'orphan' => $nobody || !$cm->visible,
            ];
        }

        return ['variants' => $variants, 'orphans' => self::orphans_summary($course, count($orphangroups))];
    }

    /**
     * Группы курса с аудиторией - ОДИН запрос. Аудитория считается коррелированным подзапросом:
     * активные незаархивированные ученики, записанные на курс И состоящие в группе. Группы без
     * учеников тоже возвращаются (с нулем) - именно они и есть кандидаты в сироты.
     * @return array<int,\stdClass> id -> {id, name, idnumber, audience}
     */
    private static function group_audience(int $courseid): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT g.id, g.name, g.idnumber,
                    (SELECT COUNT(DISTINCT s.mdl_user_id)
                       FROM {groups_members} gm
                       JOIN {unics_students} s
                            ON s.mdl_user_id = gm.userid AND s.archived_at IS NULL
                       JOIN {user_enrolments} ue ON ue.userid = gm.userid AND ue.status = 0
                       JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :cid2
                      WHERE gm.groupid = g.id) AS audience
               FROM {groups} g
              WHERE g.courseid = :cid",
            ['cid' => $courseid, 'cid2' => $courseid]
        );
    }

    /**
     * Id групп из ограничений доступа активности, в порядке условий. Ограничение на удаленную
     * группу пропускаем: показывать «для группы <нет такой>» бессмысленно.
     * @param array<int,\stdClass> $groups известные группы курса
     * @return int[]
     */
    private static function restriction_group_ids(\cm_info $cm, array $groups): array {
        $out = [];
        foreach (availability_tree::leaves($cm->availability) as $cond) {
            if (($cond['type'] ?? '') !== 'group' || empty($cond['id'])) {
                continue;
            }
            $gid = (int)$cond['id'];
            if (isset($groups[$gid]) && !in_array($gid, $out, true)) {
                $out[] = $gid;
            }
        }
        return $out;
    }

    /** «Стандартный» для уровневой группы, «для группы 7А класс» - для обычной. */
    private static function who_label(\stdClass $group): string {
        $level = self::level_from_idnumber((string)$group->idnumber);
        if ($level === null) {
            return get_string('variant_group', 'local_unics', $group->name);
        }
        return in_array($level, self::KNOWN_LEVELS, true)
            ? get_string('level_name_' . $level, 'local_unics')
            : get_string('level_name_other', 'local_unics', $level);
    }

    /** Номер уровня из idnumber УМК-группы или null, если конвенция не совпала. */
    private static function level_from_idnumber(string $idnumber): ?int {
        return preg_match('/^umk_lvl(\d+)_c\d+_/', $idnumber, $m) ? (int)$m[1] : null;
    }

    /**
     * Правая часть пометки. Пустая группа - более сильная причина, чем скрытие: даже открой
     * активность, аудитории все равно нет.
     */
    private static function state_label(bool $nobody, bool $visible, int $audience): string {
        if ($nobody) {
            return get_string('variant_nobody', 'local_unics');
        }
        if (!$visible) {
            return get_string('variant_hidden', 'local_unics');
        }
        return get_string('variant_audience_' . plural::form($audience), 'local_unics', $audience);
    }

    /**
     * Сводка для карточки «Требует внимания». Считаем РАЗНЫЕ ГРУППЫ с нулевой аудиторией, то есть
     * мертвые ВАРИАНТЫ, а не активности: пять активностей одной пустой группы - это одна проблема.
     * Активности, ставшие сиротами только из-за скрытия, сюда не входят - это осознанное действие
     * педагога, и ядро уже помечает их своим бейджем.
     * @return ?array{count:int,label:string,url:string}
     */
    private static function orphans_summary(\stdClass $course, int $count): ?array {
        if ($count <= 0) {
            return null;
        }
        return [
            'count' => $count,
            'label' => get_string('variant_orphans_' . plural::form($count), 'local_unics', $count),
            'url' => (new \moodle_url('/local/unics/pages/course_levels.php',
                ['course_id' => (int)$course->id]))->out(false),
        ];
    }
}
