<?php
namespace local_unics\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Данные ученического вида страницы курса (course/view.php, формат topics).
 * local_unics ВЫЧИСЛЯЕТ; theme_unics стилизует; AMD local_unics/course_child дополняет DOM.
 */
class course_view {

    /** modname -> [ключ типа, lang-ключ метки]. Прочие -> нейтральный 'other'. */
    private const TYPES = [
        'page'       => ['material', 'type_material'],
        'resource'   => ['material', 'type_material'],
        'book'       => ['material', 'type_material'],
        'url'        => ['material', 'type_material'],
        'quiz'       => ['quiz',     'type_quiz'],
        'assign'     => ['task',     'type_task'],
        'customcert' => ['cert',     'type_cert'],
        'scorm'      => ['quiz',     'type_quiz'],
        'forum'      => ['other',    'type_other'],
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

    /** @return array{type:string,typeLabel:string,sub:?string} */
    private static function activity_type_meta(\cm_info $cm): array {
        if ($cm->modname === 'resource') {
            [$type, $labelkey] = self::detect_resource_type($cm);
        } else {
            [$type, $labelkey] = self::TYPES[$cm->modname] ?? ['other', 'type_other'];
        }
        return [
            'type'      => $type,
            'typeLabel' => get_string($labelkey, 'local_unics'),
            'sub'       => self::activity_sub($cm),
        ];
    }

    /**
     * Уточнение под меткой типа («Тест - 10 вопросов», «Задание - с проверкой») или null,
     * если у типа уточнять нечего (материал/аудио/видео/сертификат: метка типа уже
     * говорящая). Строки серверные, форма числительного - общая {@see plural::form()}.
     */
    private static function activity_sub(\cm_info $cm): ?string {
        if ($cm->modname === 'assign') {
            return get_string('sub_assign', 'local_unics');
        }
        if ($cm->modname !== 'quiz') {
            return null;
        }
        $count = self::quiz_question_count($cm);
        if ($count === null || $count <= 0) {
            return null;
        }
        return get_string('sub_quiz_' . plural::form($count), 'local_unics', $count);
    }

    /**
     * Число вопросов теста или null, если посчитать не удалось.
     * Считаем строки {quiz_slots}: слот - ровно один вопрос, который увидит ребенок
     * (случайный вопрос тоже занимает один слот), то же множество слотов разбирает
     * mod_quiz\structure. Прямой count_records идет по уникальному индексу quizid-slot,
     * то есть один дешевый запрос; полный structure::create_for_quiz() ради одного
     * числа поднял бы quiz_settings и qbank_helper с join'ами по банку вопросов, а
     * метод зовется на каждую активность курса.
     * Устойчиво: любая ошибка -> null, подписи просто не будет, страница не ломается.
     */
    private static function quiz_question_count(\cm_info $cm): ?int {
        global $DB;
        try {
            return (int)$DB->count_records('quiz_slots', ['quizid' => (int)$cm->instance]);
        } catch (\Throwable $e) {
            debugging('local_unics course_view: подавленное исключение: ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
            return null;
        }
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
     * @return array{0:string,1:string} [тип, lang-ключ] - формат TYPES.
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
                    return ['audio', 'type_audio'];
                }
                if (strpos($mime, 'video/') === 0) {
                    return ['video', 'type_video'];
                }
            }
        } catch (\Throwable $e) {
            // Контекст/хранилище недоступны - тихо остаемся material, страница не должна падать.
            debugging('local_unics course_view: подавленное исключение: ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
        }
        return self::TYPES['resource'];
    }

    /**
     * done | todo | locked | open. $userid - чей прогресс считаем (ребенок).
     * get_data() зовем в пакетном режиме ($wholecourse = true) - ядро на этой же
     * странице делает так же: первый вызов поднимает выполнение всего курса одним
     * запросом, остальные активности берутся из кеша (поштучный путь дал бы N запросов).
     */
    private static function activity_status(\cm_info $cm, \completion_info $ci, int $userid): string {
        if (!$cm->available) {
            return 'locked';
        }
        if (!$ci->is_enabled($cm)) {
            return 'open';
        }
        $data = $ci->get_data($cm, true, $userid);
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
                // Тот же modinfo, из которого пришел $cm (уже построен для нужного
                // пользователя) - без повторного get_course()/get_fast_modinfo().
                $modinfo = $cm->get_modinfo();
                // get_cm() бросает исключение, если cm не найден (а не возвращает null) -
                // зависимость могла быть удалена; в этом случае - общий фолбэк ниже.
                try {
                    $dep = $modinfo->get_cm((int)$c['cm']);
                } catch (\Throwable $e) {
                    $dep = null;
                }
                if ($dep) {
                    return get_string('lock_completion', 'local_unics', self::plain_name($dep));
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

    /**
     * Имя активности для payload - БЕЗ html-экранирования: AMD вставляет тексты только
     * через textContent (экранирование там и так не нужно, а «&» приехал бы к ребенку
     * как «&amp;»). Фильтры при этом отрабатывают как обычно.
     */
    private static function plain_name(\cm_info $cm): string {
        return $cm->get_formatted_name(['escape' => false]);
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
     * availability-показ «серым с текстом»). Дополнительно отсекаем модули без своей
     * страницы (mod_label и подобные с FEATURE_NO_VIEW_LINK): открывать у них нечего,
     * карточка с чипом «Открыть» была бы обманом и ломала бы сетку.
     * Один и тот же фильтр используется везде (payload cms, прогресс секции, next-step) -
     * иначе «N из M» разойдется с числом видимых карточек, а next-step может указать на
     * активность, узла которой на странице нет.
     */
    private static function visible_to_child(\cm_info $cm): bool {
        return $cm->has_view() && $cm->is_visible_on_course_page();
    }

    /**
     * Показываемые ребенку активности секции с включенным completion.
     * @param \cm_info[] $visible уже отфильтрованные активности курса в порядке курса
     * @return \cm_info[]
     */
    private static function tracked_cms_in_section(\section_info $section, array $visible, \completion_info $ci): array {
        $res = [];
        foreach ($visible as $cm) {
            if ((int)$cm->sectionnum === (int)$section->section && $ci->is_enabled($cm)) {
                $res[] = $cm;
            }
        }
        return $res;
    }

    /** Готовая строка прогресса курса с правильной русской формой числительного. */
    private static function course_progress_label(int $done, int $total): string {
        $key = 'course_progress_' . plural::form($done);
        return get_string($key, 'local_unics', (object)['done' => $done, 'total' => $total]);
    }

    public static function build_payload(\stdClass $course, int $userid): array {
        $modinfo = get_fast_modinfo($course, $userid);
        $ci = new \completion_info($course);

        // Один общий список показываемых ребенку активностей (в порядке курса) и одна
        // карта статусов: activity_status() зовется ровно по разу на активность, а
        // карточки, прогресс секций и next-step берут готовый результат.
        $visible = [];
        $statuses = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!self::visible_to_child($cm)) {
                continue;
            }
            $visible[] = $cm;
            $statuses[(int)$cm->id] = self::activity_status($cm, $ci, $userid);
        }

        $cms = [];
        foreach ($visible as $cm) {
            $meta = self::activity_type_meta($cm);
            $status = $statuses[(int)$cm->id];
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
            $tracked = self::tracked_cms_in_section($section, $visible, $ci);
            if (!$tracked) {
                continue;
            }
            $done = 0;
            foreach ($tracked as $cm) {
                if ($statuses[(int)$cm->id] === 'done') {
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

        $next = self::next_step($visible, $ci, $statuses);

        return [
            'strings' => [
                'done' => get_string('status_done', 'local_unics'), 'todo' => get_string('status_todo', 'local_unics'),
                'locked' => get_string('status_locked', 'local_unics'), 'continue' => get_string('status_continue', 'local_unics'),
                'open' => get_string('status_open', 'local_unics'), 'themeDone' => get_string('theme_done', 'local_unics'),
                'courseDone' => get_string('course_done', 'local_unics'),
                'progressCourseName' => get_string('progress_course_name', 'local_unics'),
                'progressSectionName' => get_string('progress_section_name', 'local_unics'),
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

    /**
     * Первая доступная невыполненная активность в порядке курса.
     * @param \cm_info[] $visible показываемые ребенку активности (тот же фильтр, что у cms -
     *        иначе next-step мог бы указать на активность, узла которой на странице нет)
     * @param array<int,string> $statuses готовая карта cmid -> статус
     * @return array{cmid:int,label:string}|null
     */
    private static function next_step(array $visible, \completion_info $ci, array $statuses): ?array {
        foreach ($visible as $cm) {
            if (!$cm->uservisible || !$cm->available || !$ci->is_enabled($cm)) {
                continue;
            }
            if (($statuses[(int)$cm->id] ?? null) === 'todo') {
                return ['cmid' => (int)$cm->id, 'label' => get_string('continue_to', 'local_unics', self::plain_name($cm))];
            }
        }
        return null;
    }
}
