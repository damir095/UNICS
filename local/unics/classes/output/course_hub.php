<?php
namespace local_unics\output;

use local_unics\access;

defined('MOODLE_INTERNAL') || die();

/**
 * Хаб-страница курса: сводка «Требует внимания» + плитки инструментов УНИКС.
 *
 * Меню «Ещe» в Boost плоское (вложенный flyout тема не делает), поэтому девять пунктов
 * «УНИКС: ...» схлопнуты в один пункт-ссылку на эту страницу - тот же прием, которым ядро
 * показывает «Отчеты» и «Банки вопросов». Группировка живет здесь, а не в меню.
 *
 * ЕДИНСТВЕННЫЙ источник правды по доступности плиток: {@see self::tiles()} зовут и страница,
 * и навигация ({@see navigation::extend_navigation_course()}). Вторая копия предиката где-либо
 * еще - дефект: меню и хаб разъедутся, и пункт поведет в отказ.
 *
 * Гейт каждой плитки повторяет гейт ЕЕ страницы. Историческое расхождение было ровно одно и
 * чинится здесь: пункт «Кодификатор» показывался по grade:viewall, а codifier_tag.php требует
 * moodle/course:manageactivities - non-editing педагог видел пункт и получал «Недостаточно прав».
 */
class course_hub {

    /**
     * Группы плиток, доступные смотрящему; группы без плиток отброшены.
     *
     * $userid ОБЯЗАН соблюдаться во всех предикатах - ЛОВУШКА [[course-variants-design]]:
     * setUser($X) + $X->id не проверяет соблюдение, тест зеленеет и при обращении к $USER.
     *
     * @param ?int $userid null = текущий пользователь
     * @return array<int,array{key:string,title:string,tiles:array<int,array{label:string,desc:string,url:string,icon:string}>}>
     */
    public static function tiles(\stdClass $course, \context_course $context, ?int $userid = null): array {
        global $USER;
        if ($userid === null) {
            $userid = (int)$USER->id;
        }
        $courseid = (int)$course->id;

        $manage     = has_capability('local/unics:manage', \context_system::instance(), $userid);
        $methodist  = access::is_methodist($userid);
        $activities = has_capability('moodle/course:manageactivities', $context, $userid);

        // Наблюдение за классом: те же права, что у course_report/course_students/course_levels.
        $staff = $manage || $methodist || has_capability('moodle/grade:viewall', $context, $userid);
        // Создание контента: non-editing педагог (роль 6) контент не создает.
        $edit = !access::is_nonediting_teacher($userid) && ($manage || $activities || $methodist);
        // Тегирование: гейт codifier_tag.php дословно - БЕЗ !is_nonediting_teacher (страница
        // такого условия не ставит, а без manageactivities non-editing педагог сюда и не попадет).
        $tag = $manage || $methodist || $activities;

        // Определения в порядке отрисовки: [ключ строки, иконка, путь, параметры, гейт].
        $progressdefs = [
            ['hub_gradebook', 'i/grades', '/local/unics/pages/gradebook.php',       ['course_id' => $courseid], $staff],
            ['hub_report',    'i/report', '/local/unics/pages/course_report.php',   ['course_id' => $courseid], $staff],
            ['hub_students',  'i/users',  '/local/unics/pages/course_students.php', ['course_id' => $courseid], $staff],
            ['hub_levels',    'i/scales', '/local/unics/pages/course_levels.php',   ['course_id' => $courseid], $staff],
        ];
        // ВНИМАНИЕ: у кодификатора параметр называется courseid, у остальных восьми - course_id.
        $setupdefs = [
            ['hub_umk',        'i/edit',         '/local/unics/pages/generate_umk.php',      ['course_id' => $courseid], $edit],
            ['hub_codifier',   'i/competencies', '/local/unics/pages/codifier_tag.php',      ['courseid'  => $courseid], $tag],
            ['hub_diagnostic', 'i/preview',      '/local/unics/pages/course_diagnostic.php', ['course_id' => $courseid], $edit],
            ['hub_exam',       'i/badge',        '/local/unics/pages/course_final_exam.php', ['course_id' => $courseid], $edit],
            ['hub_milestones', 'i/flagged',      '/local/unics/pages/course_milestones.php', ['course_id' => $courseid], $edit],
        ];

        $groups = [];
        foreach ([['progress', 'hub_group_progress', $progressdefs],
                  ['setup', 'hub_group_setup', $setupdefs]] as [$key, $titlekey, $defs]) {
            $tiles = [];
            foreach ($defs as [$strkey, $icon, $path, $params, $allowed]) {
                if ($allowed) {
                    $tiles[] = [
                        'label' => get_string($strkey, 'local_unics'),
                        'desc'  => get_string($strkey . '_desc', 'local_unics'),
                        'url'   => (new \moodle_url($path, $params))->out(false),
                        'icon'  => $icon,
                    ];
                }
            }
            if ($tiles) {
                $groups[] = ['key' => $key, 'title' => get_string($titlekey, 'local_unics'), 'tiles' => $tiles];
            }
        }
        return $groups;
    }
}
