<?php
/**
 * Адаптивная проверка (CAT) для учащегося - A/ML-шаг 2 [[cat-design]]. Вопросы по одному;
 * сложность подстраивается под ответы. Результат - advisory (владение навыком), доступ не гейтит.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\learning\cat_session_manager;
use local_unics\learning\mastery_manager;

require_login();
global $USER, $DB;

if ((int)get_config('local_unics', 'adaptive_cat_enabled') !== 1) {
    throw new moodle_exception('cat_disabled', 'local_unics');
}
$student = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
if (!$student) {
    throw new moodle_exception('accessdenied', 'error');
}
$student_id = (int)$student->id;

$element_id = optional_param('element', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/cat.php', ['element' => $element_id]));
$PAGE->set_title('Адаптивная проверка - УНИКС');
$PAGE->set_heading('Адаптивная проверка');
$PAGE->set_pagelayout('standard');

// POST: обработать ответ на текущий вопрос.
if ($element_id && data_submitted() && confirm_sesskey() && $action === 'answer') {
    $session = cat_session_manager::active_session($student_id, $element_id);
    if ($session) {
        cat_session_manager::answer($session);
    }
    redirect(new moodle_url('/local/unics/pages/cat.php', ['element' => $element_id]));
}

// POST: начать заново.
if ($element_id && data_submitted() && confirm_sesskey() && $action === 'restart') {
    $old = cat_session_manager::active_session($student_id, $element_id);
    if ($old) {
        cat_session_manager::abandon((int)$old->id);
    }
    redirect(new moodle_url('/local/unics/pages/cat.php', ['element' => $element_id, 'action' => 'begin']));
}

echo $OUTPUT->header();

// Экран выбора элемента.
if (!$element_id) {
    $els = cat_session_manager::eligible_elements();
    echo html_writer::tag('p', 'Выберите тему для адаптивной проверки. '
        . 'Вопросы будут подбираться под ваши ответы.');
    if (!$els) {
        echo $OUTPUT->notification('Пока нет тем с подготовленными вопросами.', 'info');
    } else {
        echo html_writer::start_tag('ul', ['class' => 'unics-cat-elements']);
        foreach ($els as $e) {
            $url = new moodle_url('/local/unics/pages/cat.php', ['element' => $e['element_id']]);
            // .unics-cta - договоренность проекта для детских страниц: касание 60x60, а не 44x44
            // обычной кнопки. Тут был голый текстовый список ссылок.
            echo html_writer::tag('li',
                html_writer::link($url, s($e['code'] . ' ' . $e['title'])
                    . ' (' . (int)$e['n'] . ' вопр.)', ['class' => 'unics-cta']),
                ['class' => 'mb-2']);
        }
        echo html_writer::end_tag('ul');
    }
    echo $OUTPUT->footer();
    exit;
}

// Сессия: активная (возобновление) | begin (новая) | последняя завершенная (показ результата) | первый раз (старт).
$session = cat_session_manager::active_session($student_id, $element_id);
if ($session && $action === 'begin') {
    cat_session_manager::abandon((int)$session->id);
    $session = null;
}
if (!$session) {
    // Нет активной: при явном «начать заново» стартуем новую; иначе показываем результат прошлой
    // завершенной сессии (если есть), а в первый раз - стартуем новую.
    $finished = ($action === 'begin') ? null
        : cat_session_manager::latest_finished($student_id, $element_id);
    if ($finished) {
        $session = $finished;
    } else {
        try {
            $session = cat_session_manager::start($student_id, $element_id);
        } catch (moodle_exception $e) {
            echo $OUTPUT->notification($e->getMessage(), 'error');
            echo html_writer::link(new moodle_url('/local/unics/pages/cat.php'), '< К выбору темы');
            echo $OUTPUT->footer();
            exit;
        }
    }
}

$slot = cat_session_manager::current_slot($session);

if ($slot !== null) {
    // Рендер текущего вопроса.
    $quba = cat_session_manager::load_quba($session);
    $options = new question_display_options();
    $options->marks = question_display_options::MAX_ONLY;
    $options->feedback = question_display_options::VISIBLE;
    $options->generalfeedback = question_display_options::HIDDEN;

    echo html_writer::tag('p', 'Вопрос ' . ((int)$session->items_administered + 1) . '.',
        ['class' => 'unics-cat-progress']);

    $formurl = new moodle_url('/local/unics/pages/cat.php',
        ['element' => $element_id, 'action' => 'answer']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false),
        'id' => 'unics-cat-form']);
    echo $quba->render_question($slot, $options);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',
        'value' => sesskey()]);
    echo html_writer::tag('div',
        html_writer::tag('button', 'Ответить', ['type' => 'submit', 'class' => 'btn btn-primary']),
        ['class' => 'unics-cat-actions mt-3']);
    echo html_writer::end_tag('form');
} else if ((int)$session->status === cat_session_manager::STATUS_ACTIVE) {
    // Вопросов нет, а сессия жива: сервис оценки упал посреди проверки, следующее задание не
    // выдано, владение не записано. Раньше сюда попадал экран «Проверка завершена» со старой
    // способностью - незаконченная проверка выглядела законченной ([[cat-honest-precision]]).
    echo $OUTPUT->notification('Проверка прервалась: сервис оценки не ответил. '
        . 'Попробуйте продолжить позже - ответы сохранены.', 'warning');
    echo html_writer::link(new moodle_url('/local/unics/pages/cat.php'),
        'Пройти другую тему', ['class' => 'btn btn-secondary mt-2']);
} else {
    // Экран результата.
    $score = $session->theta !== null
        ? \local_unics\adaptive\theta_scale::project((float)$session->theta) : null;
    $band = $score !== null
        ? \local_unics\adaptive\mastery_bands::band_for($score,
            (int)$session->items_administered) : 0;
    [$label, $cls] = mastery_manager::band_label($band, true);
    echo html_writer::tag('h3', 'Проверка завершена');
    echo html_writer::tag('p', 'Вопросов задано: ' . (int)$session->items_administered);
    if ($score !== null) {
        echo html_writer::tag('p', 'Результат: ' . html_writer::tag('span', s($label),
            ['class' => 'badge badge-' . $cls]));
        // Проверка могла кончиться не потому, что стало ясно, а потому, что кончились задания
        // в банке или лимит вопросов. Ребенку про это надо сказать: иначе предварительная
        // оценка выглядит измеренной ([[cat-honest-precision]]).
        // По СОХРАНЕННОЙ причине остановки, а не сравнением с нынешней настройкой: иначе
        // смена порога переписывала бы вердикт по всем прошлым проверкам.
        if (\local_unics\adaptive\estimate_precision::session_is_provisional($session)) {
            echo $OUTPUT->notification(
                'Вопросов пока мало, поэтому результат предварительный.', 'info');
        }
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-block; margin-right:8px;',
        'action' => (new moodle_url('/local/unics/pages/cat.php',
            ['element' => $element_id, 'action' => 'restart']))->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    // Главное действие экрана - оно и есть CTA (правило темы: одна CTA на блок).
    echo html_writer::tag('button', 'Пройти заново',
        ['type' => 'submit', 'class' => 'btn btn-primary unics-cta mt-2']);
    echo html_writer::end_tag('form');
    // ms-2: без отступа кнопки слипались в «Пройти зановоПройти другую тему» - на детской
    // странице это читается как одна строка и промахнуться по нужной легко.
    echo html_writer::link(new moodle_url('/local/unics/pages/cat.php'),
        'Пройти другую тему', ['class' => 'btn btn-secondary mt-2 ms-2']);
}

echo $OUTPUT->footer();
