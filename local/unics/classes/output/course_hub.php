<?php
namespace local_unics\output;

use local_unics\access;

defined('MOODLE_INTERNAL') || die();

/**
 * Хаб-страница курса: сводка «Требует внимания» + плитки инструментов УНИКС.
 *
 * Меню «Еще» в Boost плоское (вложенный flyout тема не делает), поэтому девять пунктов
 * «УНИКС: ...» схлопнуты в один пункт-ссылку на эту страницу - тот же прием, которым ядро
 * показывает «Отчеты» и «Банки вопросов». Группировка живет здесь, а не в меню.
 *
 * ЕДИНСТВЕННЫЙ источник правды по доступности плиток: {@see self::tiles()} зовут и страница,
 * и навигация ({@see navigation::extend_navigation_course()}). Вторая копия предиката где-либо
 * еще - дефект: меню и хаб разъедутся, и пункт поведет в отказ.
 *
 * Гейт каждой плитки повторяет гейт ее страницы. Расхождений между ними найдено три:
 *
 * (а) «Кодификатор» - жесткий тупик, ПОЧИНЕН здесь: пункт показывался по grade:viewall, а
 *     codifier_tag.php требует moodle/course:manageactivities - non-editing педагог видел
 *     пункт и получал «Недостаточно прав».
 * (б) «Журнал курса» - мягкий тупик, ПРИПАРКОВАН: у gradebook.php нет жесткого гейта, он
 *     сам фильтрует курсы по grade:viewall, поэтому методист без этого права на курсе просто
 *     не находит курс в списке. Отказа нет, чинить надо страницу, а не гейт плитки.
 * (в) «Сгенерировать УМК» - припарковано: generate_umk.php гейтится на системном
 *     local/unics:manage || local/unics:viewstudents, а предикат EDIT здесь - на курсовом
 *     moodle/course:manageactivities. При штатном назначении роли editingteacher только в
 *     контексте курса (Участники -> Записать пользователей) получится manageactivities без
 *     системного viewstudents: плитка покажется, а страница ответит жесткой
 *     required_capability_exception. Собственный провижининг проекта сюда не попадает -
 *     user_manager::create_user() назначает роли на системном контексте
 *     (user_manager.php:114-115) - но штатные средства Moodle такую конфигурацию допускают.
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

        // Родитель - не персонал курса: Moodle-роль parent несет права уровня курса, поэтому без
        // явной проверки родитель прошел бы гейт STAFF и увидел журнал, отчет и состав класса -
        // чужие персональные данные. Зеркалит {@see course_staff_view::is_staff_view()}, где
        // родитель исключен той же строкой. Сотрудник, который заодно родитель, доступ сохраняет:
        // разбор признаков - в докблоке {@see access::is_staff_person()}.
        if (access::is_parent($userid) && !access::is_staff_person($userid, $context)) {
            return [];
        }

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

    /**
     * Контекст шаблона local_unics/course_hub.
     *
     * Контекст курса выводим ЗДЕСЬ, а не принимаем параметром: так ни страница, ни навигация
     * не могут передать сюда чужой контекст. Прием «метод output-класса принимает
     * \renderer_base» уже есть в {@see shell::render_navbar()} - pix_icon с доп. классом
     * хелпером {{#pix}} не собрать.
     */
    public static function build_context(\renderer_base $output, \stdClass $course, int $userid): array {
        $context = \context_course::instance((int)$course->id);

        $groups = [];
        foreach (self::tiles($course, $context, $userid) as $g) {
            $tiles = [];
            foreach ($g['tiles'] as $t) {
                $tiles[] = [
                    'url'       => $t['url'],
                    'label'     => $t['label'],
                    'desc'      => $t['desc'],
                    'icon_html' => $output->pix_icon($t['icon'], '', 'moodle',
                        ['class' => 'icon unics-action-card__icon']),
                ];
            }
            $groups[] = ['title' => $g['title'], 'tiles' => $tiles];
        }

        return [
            'back_url'   => (new \moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false),
            'back_label' => get_string('hub_back_to_course', 'local_unics'),
            'heading'    => get_string('hub_title', 'local_unics'),
            'attention'  => self::attention($output, $course, $context, $userid),
            'groups'     => $groups,
        ];
    }

    /**
     * Сводка «Требует внимания» - те же три сигнала, что педагог видел на странице курса.
     * Ничего не считаем заново: числа обязаны совпадать, а отдельный расчет разъедется.
     *
     * Гейт - moodle/course:viewparticipants, тот же предикат, что у course_staff_view::is_staff_view();
     * саму is_staff_view() звать НЕЛЬЗЯ - в ней есть проверка $PAGE->user_is_editing(), относящаяся
     * к странице курса, а не к нашей.
     *
     * orphans показываем НЕЗАВИСИМО от размера класса - в отличие от lib.php, где вся отрисовка
     * педагогского вида гейтится через classSize > 0. Там это было упрощение вызова AMD, а не
     * смысловое утверждение: «мертвый вариант» - свойство КОНФИГУРАЦИИ КУРСА, а не класса
     * смотрящего (см. докблок course_variants: «аудитория - это ученики КУРСА в группе»).
     *
     * @return ?array null, если показывать нечего - тогда шаблон блок не рисует
     */
    private static function attention(\renderer_base $output, \stdClass $course,
                                      \context_course $context, int $userid): ?array {
        if (!has_capability('moodle/course:viewparticipants', $context, $userid)) {
            return null;
        }

        $payload = course_staff_view::build_payload($course, $userid);
        $signals = [
            [$payload['attention']['grading'] ?? null, 'i/marker',   'info'],
            [$payload['attention']['stuck'] ?? null,   'i/duration', 'warning'],
            [course_variants::build($course, $userid)['orphans'], 'i/group', 'warning'],
        ];

        $cards = [];
        foreach ($signals as [$signal, $icon, $tone]) {
            if (!$signal) {
                continue;
            }
            $cards[] = [
                'url'        => $signal['url'],
                'label'      => $signal['label'],
                'badge'      => $signal['count'],
                'tone_class' => 'unics-attention-card--' . $tone,
                'icon_html'  => $output->pix_icon($icon, '', 'moodle',
                    ['class' => 'icon unics-attention-card__icon']),
            ];
        }
        // Пустого состояния нет: нечего показать - блока нет (как attention_ctx() на дашборде).
        return $cards ? ['title' => get_string('hub_attention', 'local_unics'), 'cards' => $cards] : null;
    }
}
