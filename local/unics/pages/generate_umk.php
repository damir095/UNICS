<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/ai/ai_generator.php');

require_login();

global $USER, $DB;

local_unics_require_not_student();

// Педагог без права редактирования (роль 6, non-editing) контент НЕ создаёт —
// генерация УМК ему недоступна (наши страницы для него read-only).
if (local_unics_is_nonediting_teacher()) {
    redirect(new moodle_url('/local/unics/pages/dashboard.php'),
        'Генерация УМК доступна только педагогам, создающим курсы.', null,
        \core\output\notification::NOTIFY_WARNING);
}

$is_admin   = has_capability('local/unics:manage', context_system::instance());
$is_teacher = has_capability('local/unics:viewstudents', context_system::instance());

if (!$is_admin && !$is_teacher) {
    require_capability('local/unics:viewstudents', context_system::instance());
}

$is_methodist = $is_teacher && !$is_admin && local_unics_is_methodist();

// teacher_record используется для фильтрации по unics_teacher_student.
// У методиста запись в unics_teachers тоже есть (там org-привязка),
// но он НЕ должен фильтроваться через teacher_student - он видит всех учащихся
// своей организации.
$teacher_record = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
if ($is_methodist) {
    $methodist_org_id = ($teacher_record && $teacher_record->organization_id)
        ? (int)$teacher_record->organization_id : 0;
    $teacher_record   = false; // отключаем teacher_student-фильтр для методиста
} else {
    $methodist_org_id = 0;
}

/**
 * Может ли текущий пользователь создавать материал В ЭТОМ курсе.
 *
 * Условие дословно то же, что у остальных страниц семьи, работающих внутри курса:
 * course_diagnostic, course_final_exam, course_milestones, course_students, codifier_tag
 * (в первой из них так и написано - «Гейт как у Сгенерировать УМК»). Раньше здесь не было
 * НИКАКОЙ проверки: course_id принимался из POST как есть.
 *
 * Делегирование (`delegation_manager`) сюда НЕ годится, хотя и выглядит похоже: во-первых оно
 * про запись на курс, а не про создание содержимого; во-вторых оно обходится - методист,
 * записанный на любой курс, получает Moodle-роль `editingteacher` (enrol_teachers.php),
 * а `access::user_has_role()` ищет роль БЕЗ фильтра контекста, поэтому резолвер делегирования
 * считает такого методиста педагогом-создателем и снимает ограничение вовсе.
 *
 * Вопрос «должен ли методист иметь власть над ЛЮБЫМ курсом» здесь сознательно не решается:
 * это политика, одинаково зашитая в шесть страниц, и менять ее в одной - значит разъехаться.
 */
$can_build_in_course = fn(int $course_id): bool => local_unics_can_build_in_course($course_id);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/generate_umk.php'));
$PAGE->set_title('Генерация УМК - УНИКС');
$PAGE->set_heading('Сгенерировать учебный материал (ИИ)');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Обработка POST: запуск генерации
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    // A3: превью перед запуском. 'preview' (default, с формы) - страница
    // подтверждения; 'confirm' (с превью) - постановка в очередь.
    $action = optional_param('action', 'preview', PARAM_ALPHA);

    $course_id      = required_param('course_id', PARAM_INT);
    $student_ids    = optional_param_array('student_ids', [], PARAM_INT);
    $generate_audio      = optional_param('generate_audio',      0, PARAM_INT);
    $generate_quiz       = optional_param('generate_quiz',       1, PARAM_INT);
    $generate_assignment = optional_param('generate_assignment', 0, PARAM_INT);
    $generate_video      = optional_param('generate_video',      0, PARAM_INT);
    $generate_images     = optional_param('generate_images',     0, PARAM_INT);
    $extra_prompt        = optional_param('extra_prompt',       '', PARAM_TEXT);
    $individual          = optional_param('individual',          0, PARAM_INT);
    $student_ids    = array_filter($student_ids);

    $title          = required_param('title', PARAM_TEXT);
    $topic          = required_param('topic', PARAM_TEXT);
    $target_section = optional_param('target_section', -1, PARAM_INT);
    // Элемент кодификатора: 0 = методист не выбрал, привязки и пула не будет.
    // Существование проверяем: по этому id пойдут привязки заданий, и мусорное значение
    // развело бы пул вокруг несуществующего навыка, где его никто никогда не увидит.
    //
    // Мало существования: элемент обязан принадлежать предмету ЭТОГО курса. После разведения
    // предметов в списке видны элементы всех кодификаторов, и задание по географии, привязанное
    // к «Нахождению процента», ушло бы в чужой пул, а калибровка посчитала бы трудность чужой
    // темы по этим ответам ([[element-course-match]]).
    $element_id     = optional_param('element_id', 0, PARAM_INT);
    $element_problem = '';
    if ($element_id !== 0
            && !\local_unics\codifier_manager::element_belongs_to_course($element_id, (int)$course_id)) {
        // Три разных беды выглядели одинаково, и методист получал ложный диагноз (найдено
        // ревью): у предмета может вовсе не быть кодификатора - тогда совет «выберите элемент
        // своего предмета» бесполезен.
        $element_problem = \local_unics\codifier_manager::get_codifier_for_course((int)$course_id)
            ? 'Элемент кодификатора относится к другому предмету. Выберите элемент того предмета, '
                . 'к которому относится курс, или оставьте «Не привязывать».'
            : 'У предмета этого курса еще нет кодификатора. Заведите его в разделе «Кодификатор» '
                . 'или оставьте «Не привязывать».';
    }

    if (empty($course_id) || empty($student_ids) || empty($title) || empty($topic)) {
        redirect(
            new moodle_url('/local/unics/pages/generate_umk.php'),
            'Заполните все поля и выберите хотя бы одного учащегося.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    if (empty(get_config('local_unics', 'ai_api_key'))) {
        redirect(
            new moodle_url('/local/unics/pages/generate_umk.php'),
            'Не настроен API-ключ ИИ. Администрирование → Локальные плагины → УНИКС: Настройки ИИ',
            null, \core\output\notification::NOTIFY_ERROR
        );
    }

    // Ось КУРСА: без этой проверки любой, кто дошел до страницы, мог создать активности в ЛЮБОМ
    // курсе сайта - course_id принимался из POST как есть. Заодно отсекается курс 1 (главная
    // страница сайта), которого нет и в выпадающем списке.
    if (!$can_build_in_course((int)$course_id)) {
        redirect(
            new moodle_url('/local/unics/pages/generate_umk.php'),
            'У вас нет прав создавать материалы в этом курсе.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    // Молча сбросить чужой элемент в «не привязывать» нельзя: методист был бы уверен, что
    // задания копятся в пуле темы, а они копились бы нигде.
    if ($element_problem !== '') {
        redirect(
            new moodle_url('/local/unics/pages/generate_umk.php', ['course_id' => (int)$course_id]),
            $element_problem, null, \core\output\notification::NOTIFY_WARNING
        );
    }

    // Scope-check: ученик должен входить в скоуп текущего пользователя. Без этого методист
    // прямым POST получал ФИО, класс и средний балл ребенка ЧУЖОЙ организации: фильтр по
    // организации стоял только на GET-списке (воспроизведено живьем 2026-08-09).
    //
    // Здесь НЕЛЬЗЯ звать local_unics_require_manage_or_scope_user(), как делают
    // enrol_students.php и assign.php: этот гейт начинается с require_capability('manageorg'),
    // а те страницы требуют manageorg на ВХОДЕ. Наша пускает по viewstudents, то есть
    // педагогов, у которых manageorg нет, - и гейт закрыл бы им генерацию по СОБСТВЕННЫМ
    // ученикам (проверено живьем: педагог получал «[[unics:manageorg]]»). Педагога
    // ограничивают его привязки, проверяемые ниже, поэтому здесь только не-педагоги.
    if (!$is_admin && !$teacher_record) {
        foreach ($student_ids as $sid) {
            $s_uid = (int)$DB->get_field('unics_students', 'mdl_user_id', ['id' => (int)$sid]);
            // Отказ и на несуществующем id: гейт обязан быть fail-closed, иначе будущий код,
            // читающий сырой $student_ids, унаследует непроверенный набор.
            if (!$s_uid || !\local_unics\identity\scope_checker::user_can_access_user(
                    (int)$USER->id, $s_uid)) {
                throw new \moodle_exception('nopermissions', 'error', '',
                    'учащийся вне вашего скоупа');
            }
        }
    }

    // Отсев по правам: педагог ставит генерацию только своим привязанным учащимся.
    $allowed = [];
    foreach ($student_ids as $student_id) {
        if ($teacher_record && !$DB->record_exists('unics_teacher_student', [
            'teacher_id' => $teacher_record->id, 'student_id' => $student_id,
        ])) continue;
        // Заархивированный или выпустившийся в GET-списке не показывается - POST не должен
        // давать к нему доступ в обход. Та же развилка GET/POST, что и со скоупом.
        if (!$DB->record_exists_select('unics_students',
                'id = :id AND archived_at IS NULL AND graduated_at IS NULL',
                ['id' => (int)$student_id])) {
            continue;
        }
        $allowed[] = (int)$student_id;
    }

    // Группировка по отпечатку профиля ([[umk-per-student-design]]): внутри группы профили
    // тождественны по построению, поэтому «представителя» и расхождений здесь больше нет.
    $groups = \local_unics\ai\profile_fingerprint::group_students($allowed, (bool)$individual);
    $limit  = \local_unics\ai\umk_launcher::limit();

    if ($action === 'confirm') {
        // Потолок проверяется и здесь: ветку confirm можно позвать прямым POST мимо превью,
        // и проверка только в превью была бы декоративной.
        if ($limit > 0 && count($groups) > $limit) {
            redirect(new moodle_url('/local/unics/pages/generate_umk.php'),
                'Комплектов получилось: ' . count($groups) . ', потолок: ' . $limit
                . '. Сузьте выбор учащихся.',
                null, \core\output\notification::NOTIFY_WARNING);
        }

        // Постановка = строка УМК + группа доступа + строка очереди + adhoc-задача на
        // параллельное исполнение ([[ai-queue-parallel-design]], 3.4 аудита).
        $queued = \local_unics\ai\umk_launcher::launch((int)$course_id, $groups, [
            'title'          => $title,
            'topic'          => $topic,
            'target_section' => (int)$target_section,
            'extra_prompt'   => $extra_prompt,
            'individual'     => (bool)$individual,
            'element_id'     => $element_id,
            'flags'          => [
                'generate_audio'      => (int)$generate_audio,
                'generate_quiz'       => (int)$generate_quiz,
                'generate_assignment' => (int)$generate_assignment,
                'generate_video'      => (int)$generate_video,
                'generate_images'     => (int)$generate_images,
            ],
        ]);

        $students_total = 0;
        foreach ($groups as $g) {
            $students_total += count($g['students']);
        }

        $msg  = $queued > 0
            ? "Создано {$queued} комплектов для {$students_total} учащихся. Материалы появятся в курсе автоматически в ближайшее время."
            : 'Не удалось добавить задачи - проверьте права доступа к учащимся.';
        $type = $queued > 0
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING;

        // Педагог не имеет доступа к umk_status.php (требует local/unics:manage) - редиректим в «Мои учащиеся».
        $after_url = $is_admin
            ? new moodle_url('/local/unics/pages/umk_status.php')
            : new moodle_url('/local/unics/pages/my_students.php');
        redirect($after_url, $msg, null, $type);
    }

    // ------------------------------------------------------------
    // action=preview: превью материала перед запуском (A3).
    // Live-вызовов ИИ здесь НЕТ - только build_criteria/build_prompt.
    // ------------------------------------------------------------
    if (empty($groups)) {
        redirect(new moodle_url('/local/unics/pages/generate_umk.php'),
            'Не удалось сформировать группы - проверьте права доступа к учащимся.',
            null, \core\output\notification::NOTIFY_WARNING);
    }

    $generator   = new \local_unics\ai\ai_generator();
    $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
    $course      = get_course($course_id);

    // Имя раздела (нормализация как в get_sections.php).
    $section_name = 'новый раздел (будет создан)';
    if ((int)$target_section >= 0) {
        $sec_row = $DB->get_record('course_sections',
            ['course' => $course_id, 'section' => (int)$target_section], 'section, name');
        if ($sec_row) {
            $section_name = trim((string)$sec_row->name) !== ''
                ? trim($sec_row->name)
                : ((int)$sec_row->section === 0 ? 'Введение (раздел 0)' : 'Раздел ' . $sec_row->section);
        }
    }

    $materials = ['учебный текст'];
    if ($generate_quiz)       { $materials[] = 'тест'; }
    if ($generate_assignment) { $materials[] = 'письменное задание'; }
    if ($generate_audio)      { $materials[] = 'аудиоматериал'; }
    if ($generate_video)      { $materials[] = 'видеопрезентация'; }
    if ($generate_images)     { $materials[] = 'иллюстрации в тексте'; }

    echo $OUTPUT->header();
    echo local_unics_dashboard_button();
    echo $OUTPUT->heading('Превью материала перед запуском');

    echo '<div class="card p-3 mb-3">';
    echo '<p class="mb-1"><strong>Тема:</strong> ' . s($topic) . '</p>';
    echo '<p class="mb-1"><strong>Название материала:</strong> ' . s($title) . '</p>';
    echo '<p class="mb-1"><strong>Курс:</strong> ' . s($course->fullname) . '</p>';
    echo '<p class="mb-1"><strong>Раздел:</strong> ' . s($section_name) . '</p>';
    echo '<p class="mb-1"><strong>Материалы:</strong> ' . s(implode(', ', $materials)) . '</p>';
    if (trim($extra_prompt) !== '') {
        echo '<p class="mb-0"><strong>Дополнительные указания:</strong> ' . s($extra_prompt) . '</p>';
    }
    echo '</div>';

    // Цена запуска. Видео - НЕ одно обращение: сверх сценария воркер просит картинку на
    // каждый из 5 слайдов и, если задан ключ SaluteSpeech, еще и озвучку каждого слайда
    // (umk_processor.php, раздел 5). Первая версия счетчика считала видео за единицу и
    // занижала цену в разы - найдено ревью 2026-08-07.
    $video_calls = 0;
    if (!empty($generate_video)) {
        $video_calls = 1 + 5;                                    // сценарий + картинки слайдов
        if (!empty(get_config('local_unics', 'salute_speech_api_key'))) {
            $video_calls += 5;                                   // озвучка каждого слайда
        }
    }
    // Иллюстрации лекции - до MAX_IMAGES обращений (по одному на смысловой раздел).
    // Считаем по потолку, как видео считается по фиксированным пяти слайдам: занизить
    // цену хуже, чем завысить, а страница и так говорит «примерно».
    $image_calls = !empty($generate_images)
        ? \local_unics\ai\lecture_illustrator::MAX_IMAGES
        : 0;
    $per_set = 1 + (int)!empty($generate_quiz) + (int)!empty($generate_assignment)
                 + (int)!empty($generate_audio) + $video_calls + $image_calls;
    $sets    = count($groups);
    // Комплект для ЗПР может стоить лишнего обращения: учебный текст обязан нести артефакт
    // «Запомни», и при его отсутствии промт переспрашивается один раз
    // ([[ovz-adaptation-measured]]). Считаем по потолку - по той же причине, что и картинки.
    $memo_calls = 0;
    foreach ($groups as $g) {
        if ($generator->memo_required($generator->build_criteria($g['profile']))) {
            $memo_calls++;
        }
    }
    // Формулировки без числительных в родительном падеже: числа тут переменные, и
    // «1 комплектов» читалось бы неряшливо при любом значении потолка.
    echo '<p class="mb-3">Комплектов будет создано: <strong>' . $sets . '</strong>. '
       . 'Обращений к ИИ примерно: <strong>' . ($sets * $per_set + $memo_calls) . '</strong>.</p>';

    $over_limit = $limit > 0 && $sets > $limit;
    if ($over_limit) {
        echo $OUTPUT->notification(
            'Это больше потолка. Максимум комплектов за запуск: ' . $limit . '. Сузьте выбор: '
            . 'примените фильтр класса или организации либо снимите часть галочек. '
            . 'Потолок меняется в настройках: Администрирование - УНИКС - Настройки ИИ.',
            'error');
    }

    foreach ($groups as $key => $group) {
        $profile = $group['profile'];
        $crit    = $generator->build_criteria($profile);
        $prompt  = $generator->build_prompt($profile, $topic, $extra_prompt);

        // В шапке - БАЗОВЫЙ уровень группы: именно он лежит в unics_umk.difficulty_level и
        // показывается в истории генерации. Эффективный уровень (с поправкой на балл) виден
        // строкой ниже вместе с причиной снижения. Ставить в шапку эффективный нельзя: история
        // хранит базовый, и педагог видел бы расхождение - а хранить в базе эффективный тем
        // более нельзя, воркер прогнал бы adapt_level по нему второй раз.
        $base_level = (int)$group['level'];
        echo '<div class="card p-3 mb-3">';
        echo '<h5>' . s(($level_names[$base_level] ?? ('Уровень ' . $base_level))
            . ' уровень - ' . count($group['students']) . ' уч.') . '</h5>';

        echo '<ul class="mb-2">';
        echo '<li>Категория: ' . s($crit['category_label']) . '</li>';
        if (!empty($crit['ovz_labels'])) {
            echo '<li>Типы ОВЗ: ' . s(implode('; ', $crit['ovz_labels'])) . '</li>';
        }
        $lvl_line = 'Уровень материала: ' . s($crit['level_label']);
        if ($crit['level_change_reason'] !== null) {
            $lvl_line .= ' <span class="text-muted">(' . s($crit['level_change_reason']) . ')</span>';
        }
        echo '<li>' . $lvl_line . '</li>';
        echo '<li>Полоса среднего балла: ' . s($crit['avg_band']) . '</li>';
        echo '<li>Объем: ' . s($crit['word_count']) . ' слов</li>';
        // Оба набора: указания к учебному тексту и к формулировке заданий теста уходят в разные
        // промты ([[item-adaptation-design]]). Превью - источник правды о том, что реально
        // получит модель, поэтому показывать только первый набор нельзя: педагог не увидел бы
        // половину адаптации.
        if (!empty($crit['special_parts'])) {
            echo '<li>Особые указания к учебному тексту:<ul>';
            foreach ($crit['special_parts'] as $p) {
                echo '<li>' . s($p) . '</li>';
            }
            echo '</ul></li>';
        }
        // Гейт по галочке теста: без него превью показывало страницу указаний, которые никуда
        // не уйдут - тест не генерируется вовсе. Превью и есть источник правды о том, что
        // получит модель, значит лишнего в нем быть не должно (найдено ревью 2026-08-25).
        if (!empty($generate_quiz) && !empty($crit['special_parts_items'])) {
            echo '<li>Особые указания к заданиям теста:<ul>';
            foreach ($crit['special_parts_items'] as $p) {
                echo '<li>' . s($p) . '</li>';
            }
            echo '</ul></li>';
        }
        echo '</ul>';

        // Профили внутри группы тождественны по построению, поэтому расхождений и
        // «представителя» здесь больше нет - только собственный балл каждого ученика.
        echo '<p class="mb-1"><strong>Ученики комплекта:</strong></p><ul class="mb-2">';
        foreach ($group['students'] as $sid) {
            $st = $DB->get_record('unics_students', ['id' => $sid]);
            if (!$st) {
                continue;
            }
            $u   = $DB->get_record('user', ['id' => $st->mdl_user_id],
                'id, firstname, lastname, middlename');
            $fio = $u ? trim("{$u->lastname} {$u->firstname} " . ($u->middlename ?? ''))
                      : ('Ученик #' . $sid);
            $cls = $st->class_number
                ? $st->class_number . ($st->class_letter ? " «{$st->class_letter}»" : '')
                : '-';
            $avg = $generator->get_avg_score((int)$st->mdl_user_id);
            echo '<li>' . s($fio) . ' - ' . s($cls) . ', средний балл ' . s((string)$avg) . '%</li>';
        }
        echo '</ul>';

        echo '<details class="mt-2"><summary>Полный текст промта</summary>'
           . '<pre class="p-2 bg-light border rounded" style="white-space:pre-wrap">'
           . s($prompt) . '</pre></details>';
        echo '</div>';
    }

    // Форма подтверждения: те же параметры + action=confirm.
    echo html_writer::start_tag('form', ['method' => 'post',
        'action' => new moodle_url('/local/unics/pages/generate_umk.php')]);
    $hidden = [
        'action'              => 'confirm',
        'sesskey'             => sesskey(),
        'course_id'           => (int)$course_id,
        'title'               => $title,
        'topic'               => $topic,
        'target_section'      => (int)$target_section,
        'extra_prompt'        => $extra_prompt,
        'generate_audio'      => (int)$generate_audio,
        'generate_quiz'       => (int)$generate_quiz,
        'generate_assignment' => (int)$generate_assignment,
        'generate_video'      => (int)$generate_video,
        'generate_images'     => (int)$generate_images,
        'individual'          => (int)$individual,
        // Без этой строки выбор элемента терялся между предпросмотром и запуском, и пул не
        // работал НИ РАЗУ через интерфейс: форма всегда идет через предпросмотр (action по
        // умолчанию - preview). Тесты этого не видели - они зовут launcher напрямую.
        'element_id'          => (int)$element_id,
    ];
    foreach ($hidden as $hn => $hv) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $hn, 'value' => $hv]);
    }
    foreach ($groups as $group) {
        foreach ($group['students'] as $sid) {
            echo html_writer::empty_tag('input',
                ['type' => 'hidden', 'name' => 'student_ids[]', 'value' => (int)$sid]);
        }
    }
    if (!$over_limit) {
        echo html_writer::tag('button', 'Подтвердить и запустить',
            ['type' => 'submit', 'class' => 'btn btn-primary mr-2']);
    }
    echo html_writer::tag('button', 'Назад',
        ['type' => 'button', 'class' => 'btn btn-outline-secondary',
         'onclick' => 'history.back()']);
    echo html_writer::end_tag('form');

    echo $OUTPUT->footer();
    exit;
}

// ----------------------------------------------------------------
// Фильтры (GET)
// ----------------------------------------------------------------
$filter_org    = optional_param('filter_org',    0, PARAM_INT);
$filter_class  = optional_param('filter_class',  0, PARAM_INT);
$filter_letter = optional_param('filter_letter', '', PARAM_TEXT); // буква класса (кириллица)

// Методист - фильтр организации форсирован на его орг.
if ($is_methodist && $methodist_org_id) {
    $filter_org = $methodist_org_id;
}

// Меню организаций
$orgs_menu = [0 => '- все организации -'];
foreach ($DB->get_records('unics_organizations', ['is_active' => 1], 'name ASC', 'id, name') as $o) {
    $orgs_menu[$o->id] = s($o->name);
}

// Меню классов
$classes_menu = [0 => '- все классы -'];
for ($i = 1; $i <= 11; $i++) { $classes_menu[$i] = "{$i} класс"; }

// Меню букв класса (кириллица А–Ж)
$letters_menu = ['' => '- все буквы -', 'А' => 'А', 'Б' => 'Б', 'В' => 'В',
                 'Г' => 'Г', 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];

// Курсы. Список фильтруется ТЕМ ЖЕ предикатом, что проверяет POST: страница не должна предлагать
// то, что потом отвергнет, и оба пути обязаны спрашивать одно правило - раздвоение GET и POST
// уже дало одну утечку (см. журнал за 2026-08-09).
// category нужна форме: по ней прячутся элементы кодификаторов чужих предметов.
$courses = $DB->get_records_sql(
    "SELECT id, fullname, category FROM {course} WHERE id <> 1 ORDER BY fullname");
$courses = array_filter($courses, fn($c) => $can_build_in_course((int)$c->id));

// Предвыбор курса при переходе из шаблонов (course_templates.php)
$preselect_course = optional_param('course_id', 0, PARAM_INT);
$preselect_course_name = '';
if ($preselect_course && isset($courses[$preselect_course])
        && optional_param('from', '', PARAM_ALPHA) === 'templates') {
    $preselect_course_name = $courses[$preselect_course]->fullname;
}

// ----------------------------------------------------------------
// Учащиеся с учётом фильтров и роли текущего пользователя
// ----------------------------------------------------------------
$where  = 'u.deleted = 0 AND s.graduated_at IS NULL AND s.archived_at IS NULL';
$params = [];

if ($teacher_record) {
    $where .= ' AND ts.teacher_id = :teacher_id';
    $params['teacher_id'] = $teacher_record->id;
}
if ($filter_org > 0) {
    $where .= ' AND s.organization_id = :org_id';
    $params['org_id'] = $filter_org;
}
if ($filter_class > 0) {
    $where .= ' AND s.class_number = :class_num';
    $params['class_num'] = $filter_class;
}
if ($filter_letter !== '') {
    $where .= ' AND s.class_letter = :class_let';
    $params['class_let'] = $filter_letter;
}

// Скоуп: не-системный-админ без teacher-привязки (районный/региональн. методист или
// scoped-админ) видит только учащихся своего скоупа (регион/район/орг). Без этого
// фильтра районный методист увидел бы всех учащихся системы — утечка скоупа.
if (!$is_admin && !$teacher_record) {
    [$scope_where, $scope_params] = \local_unics\identity\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $where .= " AND ({$scope_where})";
    $params += $scope_params;
}

if ($teacher_record) {
    $students = $DB->get_records_sql(
        "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename,
                s.class_number, s.difficulty_level, o.name AS org_name
         FROM {unics_teacher_student} ts
         JOIN {unics_students} s  ON s.id  = ts.student_id
         JOIN {user} u            ON u.id  = s.mdl_user_id
         LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
         WHERE {$where}
         ORDER BY s.difficulty_level ASC, u.lastname, u.firstname",
        $params
    );
} else {
    $students = $DB->get_records_sql(
        "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename,
                s.class_number, s.difficulty_level, o.name AS org_name
         FROM {unics_students} s
         JOIN {user} u            ON u.id  = s.mdl_user_id
         LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
         WHERE {$where}
         ORDER BY s.difficulty_level ASC, u.lastname, u.firstname",
        $params
    );
}

$default_student = optional_param('student_id', 0, PARAM_INT);

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Сгенерировать учебный материал (ИИ)');

$ai_key      = get_config('local_unics', 'ai_api_key');
$salute_key  = get_config('local_unics', 'salute_speech_api_key');

if (empty($ai_key)) {
    echo $OUTPUT->notification(
        'API-ключ GigaChat не настроен. <a href="/admin/settings.php?section=local_unics_ai">Открыть настройки</a>',
        'warning'
    );
}
if (empty($salute_key)) {
    echo $OUTPUT->notification(
        'SaluteSpeech API key не настроен - аудио генерироваться не будет. <a href="/admin/settings.php?section=local_unics_ai">Открыть настройки</a>',
        'info'
    );
}

echo html_writer::link(
    new moodle_url('/local/unics/pages/umk_status.php'),
    'История генерации',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// --- Панель фильтров учащихся ---
$filter_url = new moodle_url('/local/unics/pages/generate_umk.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url,
    'class' => 'form-inline mb-4 p-3 bg-light border rounded']);
echo html_writer::tag('strong', 'Фильтр учащихся:', ['class' => 'mr-3']);

if ($is_methodist) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_org',
        'value' => (int)$filter_org]);
} else {
    echo html_writer::tag('label', 'Организация:', ['class' => 'mr-1', 'for' => 'menufilter_org']);
    echo html_writer::select($orgs_menu, 'filter_org', $filter_org, false,
        ['class' => 'form-control form-control-sm mr-3']);
}

echo html_writer::tag('label', 'Класс:', ['class' => 'mr-1', 'for' => 'menufilter_class']);
echo html_writer::select($classes_menu, 'filter_class', $filter_class, false,
    ['class' => 'form-control form-control-sm mr-3']);

echo html_writer::tag('label', 'Буква:', ['class' => 'mr-1', 'for' => 'menufilter_letter']);
echo html_writer::select($letters_menu, 'filter_letter', $filter_letter, false,
    ['class' => 'form-control form-control-sm mr-3']);

echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-sm btn-secondary']);
echo html_writer::end_tag('form');

// --- Основная форма генерации ---
$form_url = new moodle_url('/local/unics/pages/generate_umk.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);

echo html_writer::start_tag('div', ['class' => 'row']);

// Левая колонка: параметры материала
echo html_writer::start_tag('div', ['class' => 'col-md-5']);

echo html_writer::start_tag('div', ['id' => 'single-mode-fields']);

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Название материала в разделе <span class="text-danger">*</span>', ['for' => 'gen_title']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'title', 'id' => 'gen_title',
    'class' => 'form-control', 'required' => 'required',
]);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Тема урока <span class="text-danger">*</span>', ['for' => 'gen_topic']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'topic', 'id' => 'gen_topic',
    'class' => 'form-control', 'required' => 'required',
]);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div'); // single-mode-fields (только title+topic)

// Элемент кодификатора: точка, вокруг которой копится общий пул заданий и их калибровка.
// Поле необязательное - без него генерация работает ровно как раньше.
$element_opts = html_writer::tag('option', 'Не привязывать', ['value' => '0']);
// list_subject_categories() отдает menu-массив id => name, а не записи.
// Перебираем предметы ДОСТУПНЫХ курсов, а не все видимые категории сайта: сервер все равно
// примет только элемент своего предмета, а лишние группы - это запросы на каждую категорию и
// весь кодификатор сайта в одном списке. Заодно закрывается дыра при выключенном JS: методист
// видит ровно то, что форма примет (найдено ревью).
$element_categories = [];
foreach ($courses as $c) {
    $element_categories[(int)$c->category] = true;
}
foreach (array_keys($element_categories) as $catid) {
    $codifier = \local_unics\codifier_manager::get_codifier_for_category((int)$catid);
    if (!$codifier) {
        continue;
    }
    $items = '';
    foreach (\local_unics\codifier_manager::get_tree((int)$codifier->id) as $el) {
        // Экранируем: код и название элемента пишет методист в редакторе кодификатора,
        // html_writer::tag() содержимое не чистит (соседний цикл по курсам делает то же).
        $items .= html_writer::tag('option', s($el->code . ' ' . $el->title), ['value' => (int)$el->id]);
    }
    if ($items !== '') {
        // data-category: по нему форма прячет элементы чужих предметов при выборе курса.
        // Разметка, а не запрос к серверу: все элементы уже здесь.
        $element_opts .= html_writer::tag('optgroup', $items,
            ['label' => $codifier->name, 'data-category' => (int)$catid]);
    }
}
echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Элемент кодификатора', ['for' => 'gen_element']);
echo html_writer::tag('select', $element_opts,
    ['name' => 'element_id', 'id' => 'gen_element', 'class' => 'form-control']);
echo html_writer::tag('small',
    'Задания теста будут копиться в общем пуле этого элемента. Без выбора тест собирается '
    . 'из новых вопросов, как раньше.', ['class' => 'form-text text-muted']);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Курс <span class="text-danger">*</span>', ['for' => 'course_id_select']);
$course_opts = '';
foreach ($courses as $c) {
    $attrs = ['value' => $c->id, 'data-category' => (int)$c->category];
    if ($preselect_course && (int)$c->id === $preselect_course) {
        $attrs['selected'] = 'selected';
    }
    $course_opts .= html_writer::tag('option', htmlspecialchars($c->fullname), $attrs);
}
echo html_writer::tag('select', $course_opts, [
    'name' => 'course_id', 'id' => 'course_id_select', 'class' => 'form-control', 'required' => 'required',
]);
if ($preselect_course_name) {
    echo html_writer::tag('small',
        'Курс выбран автоматически - переход из «Шаблоны курсов».',
        ['class' => 'form-text text-success']
    );
}
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-group', 'id' => 'section-field-wrap']);
echo html_writer::tag('label', 'Раздел <span class="text-danger">*</span>', ['for' => 'target_section_select']);
echo html_writer::tag('select', html_writer::tag('option', '- создать новый раздел -', ['value' => '-1']), [
    'name' => 'target_section', 'id' => 'target_section_select', 'class' => 'form-control',
]);
echo html_writer::tag('small', 'Выберите существующий раздел или оставьте «новый» - раздел будет создан автоматически.',
    ['class' => 'form-text text-muted']);
echo html_writer::end_tag('div');

// JavaScript: загружает разделы при смене курса
$sections_url = (new moodle_url('/local/unics/pages/get_sections.php'))->out(false);
echo html_writer::script("
(function() {
    var courseSelect  = document.getElementById('course_id_select');
    var sectionSelect = document.getElementById('target_section_select');
    var sectionsUrl   = '{$sections_url}';
    var sesskey       = '" . sesskey() . "';

    function loadSections(courseId) {
        sectionSelect.innerHTML = '<option value=\"-1\">- создать новый раздел -</option>';
        if (!courseId) return;
        fetch(sectionsUrl + '?course_id=' + courseId + '&sesskey=' + sesskey)
            .then(function(r) { return r.json(); })
            .then(function(sections) {
                sections.forEach(function(s) {
                    var opt = document.createElement('option');
                    opt.value = s.section;
                    opt.textContent = s.name;
                    sectionSelect.appendChild(opt);
                });
            })
            .catch(function() {});
    }

    // Элементы кодификатора: показываем только предмет выбранного курса. Сервер это же
    // проверяет при отправке - разметка тут для удобства, а не вместо проверки.
    var elementSelect = document.getElementById('gen_element');

    function filterElements() {
        var opt = courseSelect.options[courseSelect.selectedIndex];
        var cat = opt ? opt.getAttribute('data-category') : null;
        var groups = elementSelect.querySelectorAll('optgroup');
        for (var i = 0; i < groups.length; i++) {
            var own = groups[i].getAttribute('data-category') === cat;
            groups[i].hidden = !own;
            groups[i].disabled = !own;
        }
        // Выбор мог остаться от прежнего курса: сбрасываем на «не привязывать», иначе методист
        // отправил бы форму с элементом чужого предмета и получил отказ на ровном месте.
        var chosen = elementSelect.selectedOptions[0];
        if (chosen && chosen.parentElement.tagName === 'OPTGROUP' && chosen.parentElement.hidden) {
            elementSelect.value = '0';
        }
    }

    courseSelect.addEventListener('change', function() {
        loadSections(this.value);
        filterElements();
    });

    if (courseSelect.value) {
        loadSections(courseSelect.value);
    }
    filterElements();
})();
");

echo '<div class="card p-3 mb-2 bg-light border">';
echo '<p class="font-weight-bold mb-2">Что генерировать:</p>';

echo html_writer::start_tag('div', ['class' => 'form-check mb-1']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'gen_text',
    'disabled' => 'disabled', 'checked' => 'checked', 'class' => 'form-check-input']);
echo html_writer::tag('label', 'Учебный текст (всегда)', ['for' => 'gen_text', 'class' => 'form-check-label text-muted']);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-check mb-1']);
// Спутник-hidden обязателен: неотмеченный чекбокс браузер не шлет вовсе, и
// optional_param('generate_quiz', 1) возвращал умолчание 1. Снять галочку теста через
// интерфейс было НЕВОЗМОЖНО - тест генерировался всегда, тратя лишнее обращение к ИИ.
// У остальных галочек умолчание параметра 0, поэтому спутник им не нужен.
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'generate_quiz', 'value' => '0']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'gen_quiz', 'name' => 'generate_quiz',
    'value' => '1', 'checked' => 'checked', 'class' => 'form-check-input']);
echo html_writer::tag('label', 'Тест (5 вопросов с выбором ответа)', ['for' => 'gen_quiz', 'class' => 'form-check-label']);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-check mb-1']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'gen_assign', 'name' => 'generate_assignment',
    'value' => '1', 'class' => 'form-check-input']);
echo html_writer::tag('label', 'Письменное задание (развёрнутый ответ)', ['for' => 'gen_assign', 'class' => 'form-check-label']);
echo html_writer::end_tag('div');

// Галочка озвучки гаснет, если синтез уже отвечал 402: педагог не должен тратить запуск
// на заведомо недоступный материал ([[tts-honest-availability-design]], раздел 3.4).
// Метка снимется сама при первом удачном синтезе, то есть сразу после оплаты пакета.
$tts_off = \local_unics\ai\tts_status::is_unavailable();

echo html_writer::start_tag('div', ['class' => 'form-check mb-1']);
$audio_attrs = ['type' => 'checkbox', 'id' => 'gen_audio', 'name' => 'generate_audio',
    'value' => '1', 'class' => 'form-check-input'];
if ($tts_off) {
    $audio_attrs['disabled'] = 'disabled';
}
echo html_writer::empty_tag('input', $audio_attrs);
echo html_writer::tag('label', 'Аудиоматериал (TTS, SaluteSpeech)',
    ['for' => 'gen_audio', 'class' => 'form-check-label' . ($tts_off ? ' text-muted' : '')]);
if ($tts_off) {
    // Дата делает метку проверяемой: без нее непонятно, свежий это отказ или прошлогодний.
    $tts_at   = \local_unics\ai\tts_status::marked_at();
    $tts_when = $tts_at > 0 ? ' Проверено ' . userdate($tts_at, '%d.%m.%Y') . '.' : '';
    echo html_writer::tag('div',
        'Недоступно: у аккаунта Сбера нет оплаченного пакета SmartSpeech. '
        . 'Заработает само после оплаты.' . $tts_when,
        ['class' => 'small text-muted ml-4']);
}
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-check mb-1']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'gen_video', 'name' => 'generate_video',
    'value' => '1', 'class' => 'form-check-input']);
echo html_writer::tag('label', 'Видеопрезентация (HTML5, 5 слайдов)', ['for' => 'gen_video', 'class' => 'form-check-label']);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-check mb-1']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'gen_images', 'name' => 'generate_images',
    'value' => '1', 'checked' => 'checked', 'class' => 'form-check-input']);
echo html_writer::tag('label', 'Иллюстрации в тексте (до 4 обращений к ИИ)',
    ['for' => 'gen_images', 'class' => 'form-check-label']);
echo html_writer::end_tag('div');

echo '</div>';

echo html_writer::end_tag('div'); // col-md-5

// Правая колонка: чекбоксы учащихся
echo html_writer::start_tag('div', ['class' => 'col-md-7']);
echo html_writer::tag('label',
    'Учащиеся <span class="text-danger">*</span> ' .
    html_writer::tag('small',
        html_writer::tag('a', 'Выбрать всех', [
            'href'    => '#',
            'onclick' => 'document.querySelectorAll(".umk-cb").forEach(c=>c.checked=true);return false;',
        ]) . ' / ' .
        html_writer::tag('a', 'Снять все', [
            'href'    => '#',
            'onclick' => 'document.querySelectorAll(".umk-cb").forEach(c=>c.checked=false);return false;',
        ]),
        ['class' => 'text-muted ml-2']
    )
);

if (empty($students)) {
    $hint = 'Нет учащихся по выбранному фильтру.';
    if ($teacher_record) {
        // Реальный педагог - список зависит от unics_teacher_student.
        $bound_count = $DB->count_records('unics_teacher_student',
            ['teacher_id' => $teacher_record->id]);
        if ($bound_count === 0) {
            $hint .= ' У вас пока нет привязанных учащихся - обратитесь к методисту/'
                . 'администратору, чтобы они выполнили привязку «педагог↔учащийся» '
                . 'на странице «Привязки».';
        } else {
            $hint .= ' Привязанных учащихся: ' . $bound_count
                . '. Если они не показаны - снимите фильтр класса/организации.';
        }
    } elseif (!$is_admin) {
        $scope = \local_unics\identity\scope_checker::get_user_scope((int)$USER->id);
        if (!$scope['organization_id'] && !$scope['district_id'] && !$scope['region_id']) {
            $hint .= ' Ваш профиль не привязан к организации, муниципалитету или региону - обратитесь к администратору.';
        } else {
            $hint .= ' Среди ваших учащихся нет подходящих по выбранному фильтру.';
        }
    }
    echo html_writer::tag('p', $hint, ['class' => 'text-muted']);
} else {
    $level_labels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
    $by_level     = [];
    foreach ($students as $s) {
        $by_level[(int)($s->difficulty_level ?? 2)][] = $s;
    }
    ksort($by_level);

    echo html_writer::tag('small',
        'Ученики с одинаковым профилем получат <strong>один</strong> комплект материалов; '
        . 'при различии профилей комплекты будут разными.',
        ['class' => 'text-muted d-block mb-1']
    );

    // Фон - классом (см. codifier.php): инлайновый цвет темная схема не перебивает.
    echo html_writer::start_tag('div', [
        'class' => 'border rounded p-2 bg-white',
        'style' => 'max-height:360px;overflow-y:auto',
    ]);

    foreach ($by_level as $lvl => $group_students) {
        $lvl_label = $level_labels[$lvl] ?? ('Уровень ' . $lvl);
        $lvl_count = count($group_students);
        echo html_writer::start_tag('div', ['class' => 'mb-2']);
        echo html_writer::tag('div',
            html_writer::tag('strong', $lvl_label . ' ур.' . $lvl)
            . html_writer::tag('span', " - {$lvl_count} уч. ", ['class' => 'text-muted'])
            . html_writer::tag('a', 'выбрать всех', [
                'href'    => '#',
                'class'   => 'small',
                'onclick' => "document.querySelectorAll('.umk-lvl{$lvl}').forEach(c=>c.checked=true);return false;",
            ]),
            ['class' => 'font-weight-bold small mb-1 border-bottom pb-1']
        );

        foreach ($group_students as $s) {
            $fio = htmlspecialchars(
                "{$s->lastname} {$s->firstname}"
                . ($s->class_number ? " - {$s->class_number} кл." : '')
                . ($s->org_name ? " ({$s->org_name})" : '')
            );
            $checked = ($default_student && $s->student_id == $default_student) ? ['checked' => 'checked'] : [];

            echo html_writer::start_tag('div', ['class' => 'form-check']);
            echo html_writer::empty_tag('input', array_merge([
                'type'  => 'checkbox',
                'name'  => 'student_ids[]',
                'value' => $s->student_id,
                'id'    => "u_{$s->student_id}",
                'class' => "form-check-input umk-cb umk-lvl{$lvl}",
            ], $checked));
            echo html_writer::tag('label', $fio,
                ['for' => "u_{$s->student_id}", 'class' => 'form-check-label']);
            echo html_writer::end_tag('div');
        }
        echo html_writer::end_tag('div');
    }

    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div'); // col-md-7
echo html_writer::end_tag('div'); // row

// Расширенное поле дополнительных указаний к промпту
echo html_writer::start_tag('div', ['class' => 'form-group mt-3']);
echo html_writer::tag('label',
    'Дополнительные указания к генерации ' .
    html_writer::tag('span', '(необязательно)', ['class' => 'text-muted font-weight-normal'])
);
echo html_writer::tag('textarea', '', [
    'name'        => 'extra_prompt',
    'class'       => 'form-control',
    'rows'        => '4',
    'placeholder' => 'Например: предмет - биология, тема связана с клеточным строением; акцент на схемах и классификациях; избегать сложных латинских терминов без пояснений; добавить пример из повседневной жизни.',
]);
echo html_writer::tag('small',
    'Эти указания будут переданы ИИ дополнительно к профилю учащегося. Можно уточнить предмет, особенности темы, что выделить или что опустить.',
    ['class' => 'form-text text-muted']
);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-check mt-3']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'gen_individual',
    'name' => 'individual', 'value' => '1', 'class' => 'form-check-input']);
echo html_writer::tag('label', 'Отдельный комплект каждому выбранному ученику',
    ['for' => 'gen_individual', 'class' => 'form-check-label']);
echo html_writer::tag('small',
    'Без объединения одинаковых профилей. Обращений к ИИ будет столько, сколько выбрано учащихся.',
    ['class' => 'form-text text-muted']);
echo html_writer::end_tag('div');

echo html_writer::tag('button', 'Продолжить',
    ['type' => 'submit', 'class' => 'btn btn-primary mt-3 mr-2']);
echo html_writer::tag('small', 'На следующем шаге - превью материала перед запуском.',
    ['class' => 'form-text text-muted d-block']);
echo html_writer::link(
    new moodle_url('/local/unics/pages/umk_status.php'),
    'Отмена', ['class' => 'btn btn-outline-secondary mt-3']
);

echo html_writer::end_tag('form');
echo $OUTPUT->footer();
