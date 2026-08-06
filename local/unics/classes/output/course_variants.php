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
            // Вердикт "сирота" по сумме аудиторий групп-условий корректен ТОЛЬКО для однозначной
            // формы: корневой оператор дерева НЕ отрицательный ('&' или '|', не '!&'/'!|') И
            // групповое условие ровно одно - тогда AND/OR с одним операндом совпадают, и "сумма"
            // это просто аудитория этого единственного условия. При двух и более группах под '&'
            // реальная аудитория - ПЕРЕСЕЧЕНИЕ (нужен только тот, кто в обеих группах сразу), а
            // не сумма, и мы легко получим ложный "не сирота" там, где на самом деле никто не
            // удовлетворяет обоим условиям. При отрицании '!&'/'!|' условие означает «все, КРОМЕ
            // этой группы» - аудитория группы-условия тут вообще не то число, которое нужно
            // (нужна была бы аудитория курса МИНУС группа), и "не видит никто" превратилось бы в
            // свою противоположность. course_builder такой формы не порождает (везде одна группа
            // и "op":"&"), но педагог может собрать ограничения руками - тогда пометка ниже
            // перечисляет группы как обычно (это информативно при любом операторе), а вот вердикт
            // "сирота" и вклад в сводку «Требует внимания» для неоднозначной формы НЕ выставляем.
            $rootop = availability_tree::root_op($cm->availability);
            $unambiguous = ($rootop === '&' || $rootop === '|') && count($gids) === 1;

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
            $nobody = $unambiguous && ($audience === 0);
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
     *
     * u.deleted намеренно НЕ фильтруем: ядро физически удаляет членство пользователя из
     * groups_members при удалении самого пользователя, поэтому удаленный пользователь и без
     * явного фильтра не может попасть в этот подсчет.
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
     * группу пропускаем: показывать «для группы <нет такой>» бессмысленно. Персональная УМК-группа
     * участвует в пометке, но имя ученика в нее не попадает - {@see self::who_label()}.
     * @param array<int,\stdClass> $groups известные группы курса
     * @return int[]
     */
    private static function restriction_group_ids(\cm_info $cm, array $groups): array {
        $out = [];
        foreach (availability_tree::leaves($cm->availability) as $cond) {
            // type:'grouping' («Любая группа из…») сознательно вне scope этой задачи - у него нет
            // одной аудитории для подсчета, это отдельная тема; помечаем только type:'group'.
            if (($cond['type'] ?? '') !== 'group' || empty($cond['id'])) {
                continue;
            }
            $gid = (int)$cond['id'];
            if (!isset($groups[$gid])) {
                continue;
            }
            if (!in_array($gid, $out, true)) {
                $out[] = $gid;
            }
        }
        return $out;
    }

    /**
     * Персональная УМК-группа адресной выдачи ученику
     * {@see \local_unics\ai\course_builder::get_or_create_student_group()}: idnumber вида
     * umk_s{uid}_c{courseid}, а ИМЯ группы - «УМК: <ФИО ученика>». В отличие от уровневой группы,
     * эта не про уровень, а про одного конкретного ребенка. Запрет касается ИМЕНИ, а не самой
     * пометки: активность с индивидуальным УМК без пометки читалась бы как «для всех»
     * ([[umk-per-student-design]], раздел 9), поэтому пометка выводится с нейтральной подписью.
     */
    private static function is_personal_umk_group(\stdClass $group): bool {
        return (bool)preg_match('/^umk_s\d+_c\d+$/', (string)$group->idnumber);
    }

    /** «Стандартный» для уровневой группы, «для группы 7А класс» - для обычной. */
    private static function who_label(\stdClass $group): string {
        // Персональная группа несет ФИО в имени - показываем нейтральную подпись.
        if (self::is_personal_umk_group($group)) {
            return get_string('variant_personal', 'local_unics');
        }
        $level = self::level_from_idnumber((string)$group->idnumber);
        if ($level === null) {
            // escape=>false: значение уходит в payload и вставляется AMD через textContent, а не
            // innerHTML - обычный format_string() экранировал бы «&» в «&amp;» и ребенок увидел бы
            // escape-код буквально. Образец - course_view::plain_name().
            $name = format_string($group->name, true, ['escape' => false]);
            return get_string('variant_group', 'local_unics', $name);
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
