<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
global $USER, $OUTPUT, $PAGE;

$ctx = context_system::instance();
$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/accessibility.php'));
$PAGE->set_title('Доступность - УНИКС');
$PAGE->set_heading('Настройки доступности');
$PAGE->set_pagelayout('standard');

$allowed = local_unics_a11y_allowed_values();
$msg = '';

// --- POST: сохранить или сбросить предпочтения. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if (optional_param('reset', 0, PARAM_INT)) {
        foreach (array_keys($allowed) as $key) {
            unset_user_preference('local_unics_a11y_' . $key);
        }
        $msg = 'Настройки сброшены к значениям по умолчанию.';
    } else {
        $theme    = optional_param('theme', 'light', PARAM_ALPHA);
        $contrast = optional_param('contrast', 0, PARAM_INT) ? '1' : '0';
        $font     = optional_param('font', 'normal', PARAM_ALPHA);
        $accent   = optional_param('accent', 'default', PARAM_ALPHA);

        // Валидация по белому списку — мусор отбрасываем в дефолт.
        $theme  = in_array($theme, $allowed['theme'], true) ? $theme : 'light';
        $font   = in_array($font, $allowed['font'], true) ? $font : 'normal';
        $accent = in_array($accent, $allowed['accent'], true) ? $accent : 'default';

        set_user_preference('local_unics_a11y_theme', $theme);
        set_user_preference('local_unics_a11y_contrast', $contrast);
        set_user_preference('local_unics_a11y_font', $font);
        set_user_preference('local_unics_a11y_accent', $accent);
        $msg = 'Настройки доступности сохранены.';
    }
}

$prefs = local_unics_a11y_get_prefs();

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Настройки доступности');

if ($msg) {
    echo $OUTPUT->notification($msg, 'success');
}

echo '<p class="text-muted">Настройки применяются только к вашему аккаунту и работают на всех '
   . 'страницах портала. Изменения вступают в силу сразу после сохранения.</p>';

$form_url = new moodle_url('/local/unics/pages/accessibility.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);
echo '<div class="card mb-3" style="max-width:640px;"><div class="card-body">';

// --- Цветовая схема ---
echo html_writer::tag('h2', 'Цветовая схема', ['class' => 'h5 mt-0']);
$themes = ['light' => 'Светлая (по умолчанию)', 'dark' => 'Тёмная'];
foreach ($themes as $val => $label) {
    echo '<div class="form-check mb-2">';
    echo html_writer::empty_tag('input', [
        'type' => 'radio', 'name' => 'theme', 'value' => $val,
        'id' => 'theme_' . $val, 'class' => 'form-check-input',
    ] + ($prefs['theme'] === $val ? ['checked' => 'checked'] : []));
    echo html_writer::tag('label', $label, ['for' => 'theme_' . $val, 'class' => 'form-check-label']);
    echo '</div>';
}

// --- Высокий контраст ---
echo '<hr>';
echo html_writer::tag('h2', 'Высокий контраст', ['class' => 'h5']);
echo '<div class="form-check mb-2">';
echo html_writer::empty_tag('input', [
    'type' => 'checkbox', 'name' => 'contrast', 'value' => '1',
    'id' => 'contrast', 'class' => 'form-check-input',
] + ($prefs['contrast'] === '1' ? ['checked' => 'checked'] : []));
echo html_writer::tag('label', 'Усилить контраст текста и границ, подчеркнуть ссылки',
    ['for' => 'contrast', 'class' => 'form-check-label']);
echo '</div>';

// --- Размер шрифта ---
echo '<hr>';
echo html_writer::tag('h2', 'Размер шрифта', ['class' => 'h5']);
$fonts = ['normal' => 'Обычный', 'large' => 'Крупный (+12%)', 'xlarge' => 'Очень крупный (+25%)'];
foreach ($fonts as $val => $label) {
    echo '<div class="form-check mb-2">';
    echo html_writer::empty_tag('input', [
        'type' => 'radio', 'name' => 'font', 'value' => $val,
        'id' => 'font_' . $val, 'class' => 'form-check-input',
    ] + ($prefs['font'] === $val ? ['checked' => 'checked'] : []));
    echo html_writer::tag('label', $label, ['for' => 'font_' . $val, 'class' => 'form-check-label']);
    echo '</div>';
}

// --- Акцент-цвет ---
echo '<hr>';
echo html_writer::tag('h2', 'Акцент-цвет', ['class' => 'h5']);
echo '<p class="text-muted small">Палитра проверена на контраст (WCAG AA).</p>';
$accents = [
    'default' => 'Оранжевый (по умолчанию)',
    'blue'    => 'Синий',
    'green'   => 'Зелёный',
    'purple'  => 'Фиолетовый',
];
foreach ($accents as $val => $label) {
    echo '<div class="form-check mb-2">';
    echo html_writer::empty_tag('input', [
        'type' => 'radio', 'name' => 'accent', 'value' => $val,
        'id' => 'accent_' . $val, 'class' => 'form-check-input',
    ] + ($prefs['accent'] === $val ? ['checked' => 'checked'] : []));
    echo html_writer::tag('label', $label, ['for' => 'accent_' . $val, 'class' => 'form-check-label']);
    echo '</div>';
}

echo '<hr>';
echo html_writer::tag('button', 'Сохранить', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo '</div></div>';
echo html_writer::end_tag('form');

// --- Сброс (отдельная форма, чтобы не тащить значения полей). ---
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'reset', 'value' => '1']);
echo html_writer::tag('button', 'Сбросить к умолчанию',
    ['type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
