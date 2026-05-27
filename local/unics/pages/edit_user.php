<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/user_manager.php');
require_login();
local_unics_require_manage_or_manageorg();

$user_id = optional_param('id', 0, PARAM_INT);
$action  = optional_param('action', 'edit', PARAM_ALPHA);

if (!$user_id) {
    redirect(
        new moodle_url('/local/unics/pages/users.php'),
        'Выберите пользователя для редактирования.',
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$PAGE->set_url(new moodle_url('/local/unics/pages/edit_user.php', ['id' => $user_id]));
$PAGE->set_title('Редактировать пользователя - УНИКС');
$PAGE->set_heading('Редактирование пользователя');

$profile = unics_user_manager::get_user_profile($user_id);
if (!$profile) {
    throw new moodle_exception('Пользователь не найден в системе УНИКС');
}

// Scope-check: редактируемый пользователь должен входить в скоуп текущего.
local_unics_require_manage_or_scope_user((int)$user_id);

$unics_role = (int)$profile->unics_role;
$is_student = ($unics_role === 7);
$is_teacher = in_array($unics_role, [4, 5]);

// Категория ОВЗ — метки берём из lang (абстрактные «ОВЗ N категории»); расшифровка
// только в вики (student-categories.md). Хардкод-названий диагнозов здесь быть не должно.
$level_options    = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
$role_labels      = [3 => 'Администратор организации', 4 => 'Методист', 5 => 'Педагог', 7 => 'Учащийся', 8 => 'Родитель'];

// Обработка деактивации
if ($action === 'suspend' && confirm_sesskey()) {
    unics_user_manager::suspend_user($user_id);
    redirect(
        new moodle_url('/local/unics/pages/users.php'),
        'Пользователь деактивирован.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Обработка сохранения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $data = [
        'firstname'        => required_param('firstname', PARAM_TEXT),
        'lastname'         => required_param('lastname', PARAM_TEXT),
        'middlename'       => optional_param('middlename', '', PARAM_TEXT),
        'email'            => required_param('email', PARAM_EMAIL),
    ];
    if ($is_student) {
        // Плоский чеклист: ОВЗ 1-6 (ovz_types[]) + «Одарённый» (cat_gifted).
        // Категория ОВЗ (1) выводится из наличия любого вида ОВЗ; пусто = обычный.
        $ovz_raw = optional_param_array('ovz_types', [], PARAM_INT);
        $gifted  = optional_param('cat_gifted', 0, PARAM_INT);
        $cats = [];
        if (!empty($ovz_raw)) { $cats[] = 1; }
        if (!empty($gifted))  { $cats[] = 4; }
        // Legacy-категории (2=семейное, 3=лечение) из выбора убраны, но молча терять
        // их при сохранении нельзя — переносим, если были.
        $existing = \local_unics\student_helper::get_categories($profile);
        foreach ([2, 3] as $legacy) {
            if (in_array($legacy, $existing, true)) { $cats[] = $legacy; }
        }
        $data['student_category'] = \local_unics\student_helper::to_csv($cats);
        $data['ovz_type']         = \local_unics\student_helper::to_csv($ovz_raw);
        $data['difficulty_level'] = required_param('difficulty_level', PARAM_INT);
        $data['class_number']     = optional_param('class_number', null, PARAM_INT);
        $data['class_letter']     = optional_param('class_letter', '', PARAM_TEXT);
        $data['special_needs']    = optional_param('special_needs', '', PARAM_TEXT);
    }
    if ($is_teacher) {
        $data['subjects']      = optional_param('subjects', '', PARAM_TEXT);
        $data['qualification'] = optional_param('qualification', '', PARAM_TEXT);
    }

    unics_user_manager::update_user($user_id, $data);
    redirect(
        new moodle_url('/local/unics/pages/users.php'),
        'Данные сохранены.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();

$return_url = new moodle_url('/local/unics/pages/users.php');
$moodle_edit_url = new moodle_url('/user/editadvanced.php', [
    'id'     => $user_id,
    'course' => SITEID,
]);
echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
echo '<a href="' . $return_url . '" class="btn btn-sm btn-outline-secondary">Список пользователей</a>';
echo '<a href="' . $moodle_edit_url . '" class="btn btn-sm btn-outline-primary">'
    . 'Редактировать в Moodle (пароль, аватар, email)</a>';
echo '</div>';
echo '<p class="text-muted small">Эта форма редактирует данные УНИКС (организация, класс, уровень, '
    . 'категория). Базовые данные аккаунта (пароль, аватар, email, логин) — через кнопку «Редактировать в Moodle».</p>';
echo '<h4>' . s($profile->lastname . ' ' . $profile->firstname) . '
    <span class="badge bg-secondary ms-2">' . ($role_labels[$unics_role] ?? 'Роль ' . $unics_role) . '</span>
</h4>';

echo '<form method="post" class="mt-3" style="max-width:600px">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

// Основные поля
echo '<div class="card mb-3"><div class="card-header">Основные данные</div><div class="card-body">';
$fields = [
    'lastname'   => ['Фамилия', true],
    'firstname'  => ['Имя', true],
    'middlename' => ['Отчество', false],
    'email'      => ['Email', true],
];
foreach ($fields as $name => [$label, $required]) {
    $val = s($profile->$name ?? '');
    $req = $required ? 'required' : '';
    echo "<div class=\"mb-2\">
        <label class=\"form-label\">{$label}" . ($required ? ' <span class=\"text-danger\">*</span>' : '') . "</label>
        <input type=\"text\" name=\"{$name}\" value=\"{$val}\" class=\"form-control\" {$req}>
    </div>";
}
echo '</div></div>';

// Поля учащегося
if ($is_student) {
    $cats_selected = \local_unics\student_helper::parse_csv($profile->student_category ?? '');
    $ovz_selected  = \local_unics\student_helper::parse_csv($profile->ovz_type ?? '');

    echo '<div class="card mb-3"><div class="card-header">Профиль учащегося</div><div class="card-body">';

    // Категория учащегося — плоский чеклист: ОВЗ 1-6 + «Одарённый». Можно несколько.
    echo '<div class="mb-3"><label class="form-label d-block">Категория учащегося</label>';
    foreach (\local_unics\student_helper::OVZ_TYPES as $v => $key) {
        $chk = in_array($v, $ovz_selected, true) ? 'checked' : '';
        $l   = s(get_string($key, 'local_unics'));
        echo "<div class=\"form-check\">
            <input class=\"form-check-input\" type=\"checkbox\" name=\"ovz_types[]\" value=\"{$v}\" id=\"ovz_{$v}\" {$chk}>
            <label class=\"form-check-label\" for=\"ovz_{$v}\">{$l}</label>
        </div>";
    }
    $gchk   = in_array(4, $cats_selected, true) ? 'checked' : '';
    $glabel = s(get_string('category_gifted', 'local_unics'));
    echo "<div class=\"form-check\">
        <input class=\"form-check-input\" type=\"checkbox\" name=\"cat_gifted\" value=\"1\" id=\"cat_gifted\" {$gchk}>
        <label class=\"form-check-label\" for=\"cat_gifted\">{$glabel}</label>
    </div>";

    // Legacy-категории (семейное/лечение) — из выбора убраны, но показываем, что сохранятся.
    $legacy_names = [];
    if (in_array(2, $cats_selected, true)) { $legacy_names[] = get_string('category_family', 'local_unics'); }
    if (in_array(3, $cats_selected, true)) { $legacy_names[] = get_string('category_treatment', 'local_unics'); }
    if ($legacy_names) {
        echo '<div class="text-muted small mt-1">Сохранится прежняя категория: '
            . s(implode(', ', $legacy_names)) . '.</div>';
    }
    echo '<div class="text-muted small mt-1">Ничего не отмечено = обычный учащийся.</div>';
    echo '</div>';

    // Уровень сложности
    $lvl = (int)($profile->difficulty_level ?? 2);
    echo '<div class="mb-2"><label class="form-label">Уровень сложности <span class="text-danger">*</span></label>
        <select name="difficulty_level" class="form-select">';
    foreach ($level_options as $v => $l) {
        $sel = ($lvl === $v) ? 'selected' : '';
        echo "<option value=\"{$v}\" {$sel}>{$l}</option>";
    }
    echo '</select></div>';

    // Класс
    $cn = (int)($profile->class_number ?? 0);
    $cl = s($profile->class_letter ?? '');
    echo "<div class=\"mb-2 d-flex gap-2\">
        <div class=\"flex-grow-1\"><label class=\"form-label\">Класс</label>
        <input type=\"number\" name=\"class_number\" value=\"{$cn}\" min=\"1\" max=\"11\" class=\"form-control\"></div>
        <div style=\"width:80px\"><label class=\"form-label\">Буква</label>
        <input type=\"text\" name=\"class_letter\" value=\"{$cl}\" maxlength=\"2\" class=\"form-control\"></div>
    </div>";

    // Особые потребности
    $sn = s($profile->special_needs ?? '');
    echo "<div class=\"mb-2\"><label class=\"form-label\">Особые потребности</label>
        <textarea name=\"special_needs\" rows=\"2\" class=\"form-control\">{$sn}</textarea></div>";

    echo '</div></div>';
}

// Поля педагога/тьютора/методиста
if ($is_teacher) {
    $subj = s($profile->subjects ?? '');
    $qual = s($profile->qualification ?? '');
    echo '<div class="card mb-3"><div class="card-header">Профиль педагога</div><div class="card-body">';
    echo "<div class=\"mb-2\"><label class=\"form-label\">Предметы</label>
        <input type=\"text\" name=\"subjects\" value=\"{$subj}\" class=\"form-control\"></div>";
    echo "<div class=\"mb-2\"><label class=\"form-label\">Квалификация</label>
        <input type=\"text\" name=\"qualification\" value=\"{$qual}\" class=\"form-control\"></div>";
    echo '</div></div>';
}

echo '<div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">Сохранить</button>
    <a href="' . $return_url . '" class="btn btn-outline-secondary">Отмена</a>
</div>';
echo '</form>';

// Кнопка деактивации
echo '<hr class="mt-4">
<form method="post" onsubmit="return confirm(\'Деактивировать пользователя?\')">
    <input type="hidden" name="sesskey" value="' . sesskey() . '">
    <input type="hidden" name="action" value="suspend">
    <button type="submit" class="btn btn-sm btn-outline-danger">Деактивировать пользователя</button>
</form>';

echo $OUTPUT->footer();
