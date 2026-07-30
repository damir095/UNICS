<?php
namespace local_unics\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Данные ученического вида страницы курса (course/view.php, формат topics).
 * local_unics ВЫЧИСЛЯЕТ; theme_unics стилизует; AMD local_unics/course_child дополняет DOM.
 */
class course_view {

    /** modname -> [ключ типа, css-класс, lang-ключ метки]. Прочие -> нейтральный 'other'. */
    private const TYPES = [
        'page'       => ['material', 'material', 'type_material'],
        'resource'   => ['material', 'material', 'type_material'],
        'book'       => ['material', 'material', 'type_material'],
        'url'        => ['material', 'material', 'type_material'],
        'quiz'       => ['quiz',     'quiz',     'type_quiz'],
        'assign'     => ['task',     'task',     'type_task'],
        'customcert' => ['cert',     'cert',     'type_cert'],
        'scorm'      => ['quiz',     'quiz',     'type_quiz'],
        'forum'      => ['other',    'other',    'type_other'],
    ];

    /**
     * Гейт «ученический вид»: текущий пользователь - ребенок (запись unics_students)
     * и страница не в режиме редактирования. Педагог/методист/админ и режим
     * редактирования - штатный Moodle, ничего не подменяем.
     */
    public static function is_child_view(\stdClass $course): bool {
        global $PAGE;
        if ($PAGE->user_is_editing()) {
            return false;
        }
        return \local_unics\access::student_record() !== null;
    }

    /** @return array{type:string,cssclass:string,typeLabel:string,sub:?string} */
    private static function activity_type_meta(\cm_info $cm): array {
        if ($cm->modname === 'resource') {
            [$type, $css, $labelkey] = self::detect_resource_type($cm);
        } else {
            [$type, $css, $labelkey] = self::TYPES[$cm->modname] ?? ['other', 'other', 'type_other'];
        }
        return [
            'type'      => $type,
            'cssclass'  => $css,
            'typeLabel' => get_string($labelkey, 'local_unics'),
            'sub'       => null,
        ];
    }

    /**
     * Уточняет тип mod_resource по mimetype главного файла ресурса: audio/* -> 'audio',
     * video/* -> 'video', иначе (или файл не найден/недоступен) - обычный material.
     * Обращение к file storage происходит ТОЛЬКО для этого modname (activity_type_meta
     * вызывается на каждую активность курса build_payload() - для остальных типов
     * (page/book/url и т.д.) дорогого обращения к файловому хранилищу нет).
     * Главный файл - тот же принцип, что в mod/resource/locallib.php
     * (resource_get_file_details): сортировка 'sortorder DESC, id ASC', у типичного
     * ресурса sortorder=1 у главного файла и 0 у остальных.
     * Устойчиво: любая проблема (нет контекста, нет файлов, исключение) -> material.
     * @return array{0:string,1:string,2:string} [тип, css-класс, lang-ключ] - формат TYPES.
     */
    private static function detect_resource_type(\cm_info $cm): array {
        try {
            $context = \context_module::instance($cm->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
            $mainfile = $files ? reset($files) : null;
            if ($mainfile) {
                $mime = strtolower((string)$mainfile->get_mimetype());
                if (strpos($mime, 'audio/') === 0) {
                    return ['audio', 'audio', 'type_audio'];
                }
                if (strpos($mime, 'video/') === 0) {
                    return ['video', 'video', 'type_video'];
                }
            }
        } catch (\Throwable $e) {
            // Контекст/хранилище недоступны - тихо остаемся material, страница не должна падать.
        }
        return self::TYPES['resource'];
    }

    /** done | todo | locked | open. $userid - чей прогресс считаем (ребенок). */
    private static function activity_status(\cm_info $cm, \completion_info $ci, int $userid): string {
        if (!$cm->available) {
            return 'locked';
        }
        if (!$ci->is_enabled($cm)) {
            return 'open';
        }
        $data = $ci->get_data($cm, false, $userid);
        return in_array((int)$data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)
            ? 'done' : 'todo';
    }

    /** Человеческая причина для заблокированной активности или null. */
    private static function humanize_lock(\cm_info $cm): ?string {
        if ($cm->available) {
            return null;
        }
        $tree = !empty($cm->availability) ? json_decode($cm->availability, true) : null;
        // Разбираем ОДНО простое условие частых типов; иначе - общий фолбэк.
        $conds = self::flatten_conditions($tree);
        if (count($conds) === 1) {
            $c = $conds[0];
            if (($c['type'] ?? '') === 'completion' && !empty($c['cm'])) {
                $modinfo = get_fast_modinfo($cm->course);
                // get_cm() бросает исключение, если cm не найден (а не возвращает null) -
                // зависимость могла быть удалена; в этом случае - общий фолбэк ниже.
                try {
                    $dep = $modinfo->get_cm((int)$c['cm']);
                } catch (\Throwable $e) {
                    $dep = null;
                }
                if ($dep) {
                    return get_string('lock_completion', 'local_unics', $dep->get_formatted_name());
                }
            }
            if (($c['type'] ?? '') === 'date') {
                return get_string('lock_date', 'local_unics', userdate($c['t'] ?? time(), get_string('strftimedate')));
            }
            if (($c['type'] ?? '') === 'grade') {
                return get_string('lock_grade', 'local_unics');
            }
            if (($c['type'] ?? '') === 'profile') {
                return get_string('lock_level', 'local_unics');
            }
        }
        return get_string('lock_generic', 'local_unics');
    }

    /** Собрать листовые условия из дерева availability (op &/|, c[]). */
    private static function flatten_conditions(?array $tree): array {
        if (!$tree || empty($tree['c'])) {
            return [];
        }
        $out = [];
        foreach ($tree['c'] as $child) {
            if (isset($child['c'])) {
                $out = array_merge($out, self::flatten_conditions($child));
            } else {
                $out[] = $child;
            }
        }
        return $out;
    }

    /**
     * Активность показывается ребенку карточкой на странице курса: обычная (доступна)
     * или заблокированная с человекочитаемой причиной (уже вычислено ядром в
     * cm_info::is_visible_on_course_page() - учитывает и полное скрытие, и
     * availability-показ «серым с текстом»). Используется и для payload cms, и для
     * подсчета прогресса секции - иначе «N из M» разойдется с числом видимых карточек
     * (у заблокированной активности uservisible=false, но карточка на странице есть).
     */
    private static function visible_to_child(\cm_info $cm): bool {
        return $cm->is_visible_on_course_page();
    }

    /** Видимые ребенку активности секции с включенным completion. @return \cm_info[] */
    private static function tracked_cms_in_section(\section_info $section, \course_modinfo $modinfo, \completion_info $ci): array {
        $res = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ((int)$cm->sectionnum === (int)$section->section && self::visible_to_child($cm) && $ci->is_enabled($cm)) {
                $res[] = $cm;
            }
        }
        return $res;
    }

    /**
     * Русская форма числительного для строк course_progress_{one,few,many}
     * («Пройдена 1 тема» / «Пройдено 2 темы» / «Пройдено 5 тем»).
     * Правило: последняя цифра 1 (кроме ...11) - one; 2-4 (кроме ...12-14) - few; иначе many.
     */
    private static function plural_form(int $n): string {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'one';
        }
        if (in_array($mod10, [2, 3, 4], true) && !in_array($mod100, [12, 13, 14], true)) {
            return 'few';
        }
        return 'many';
    }

    /** Готовая строка прогресса курса с правильной русской формой числительного. */
    private static function course_progress_label(int $done, int $total): string {
        $key = 'course_progress_' . self::plural_form($done);
        return get_string($key, 'local_unics', (object)['done' => $done, 'total' => $total]);
    }

    public static function build_payload(\stdClass $course, int $userid): array {
        $modinfo = get_fast_modinfo($course, $userid);
        $ci = new \completion_info($course);

        $cms = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!self::visible_to_child($cm)) {
                continue;
            }
            $meta = self::activity_type_meta($cm);
            $status = self::activity_status($cm, $ci, $userid);
            $cms[(string)$cm->id] = [
                'type' => $meta['type'], 'typeLabel' => $meta['typeLabel'], 'sub' => $meta['sub'],
                'status' => $status,
                'lockWhy' => $status === 'locked' ? self::humanize_lock($cm) : null,
            ];
        }

        $sections = [];
        $themesdone = 0;
        $themestotal = 0;
        foreach ($modinfo->get_section_info_all() as $section) {
            $tracked = self::tracked_cms_in_section($section, $modinfo, $ci);
            if (!$tracked) {
                continue;
            }
            $done = 0;
            foreach ($tracked as $cm) {
                if (self::activity_status($cm, $ci, $userid) === 'done') {
                    $done++;
                }
            }
            $complete = ($done === count($tracked));
            $total = count($tracked);
            $a = (object)['done' => $done, 'total' => $total];
            $sections[(string)$section->section] = [
                'done' => $done, 'total' => $total, 'complete' => $complete,
                'label' => get_string('section_progress', 'local_unics', $a),
                'aria' => get_string('section_progress_aria', 'local_unics', $a),
            ];
            $themestotal++;
            if ($complete) {
                $themesdone++;
            }
        }

        $next = self::next_step($modinfo, $ci, $userid);

        return [
            'strings' => [
                'done' => get_string('status_done', 'local_unics'), 'todo' => get_string('status_todo', 'local_unics'),
                'locked' => get_string('status_locked', 'local_unics'), 'continue' => get_string('status_continue', 'local_unics'),
                'open' => get_string('status_open', 'local_unics'), 'themeDone' => get_string('theme_done', 'local_unics'),
                'courseDone' => get_string('course_done', 'local_unics'),
            ],
            'course' => [
                'done' => $themesdone, 'total' => $themestotal,
                'label' => self::course_progress_label($themesdone, $themestotal),
                'encourage' => get_string('course_encourage', 'local_unics'),
            ],
            'next' => $next,
            'sections' => $sections,
            'cms' => $cms,
        ];
    }

    /** @return array{cmid:int,label:string}|null */
    private static function next_step(\course_modinfo $modinfo, \completion_info $ci, int $userid): ?array {
        foreach ($modinfo->get_cms() as $cm) {   // get_cms() в порядке курса
            if (!$cm->uservisible || !$cm->available || !$ci->is_enabled($cm)) {
                continue;
            }
            if (self::activity_status($cm, $ci, $userid) === 'todo') {
                return ['cmid' => (int)$cm->id, 'label' => get_string('continue_to', 'local_unics', $cm->get_formatted_name())];
            }
        }
        return null;
    }
}
