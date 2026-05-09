<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/user_manager.php');
require_login();
require_capability('local/unics:manage', context_system::instance());

$user_id = required_param('id', PARAM_INT);
$action  = optional_param('action', 'edit', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/unics/pages/edit_user.php', ['id' => $user_id]));
$PAGE->set_title('Редактировать пользователя — УНИКС');
$PAGE->set_heading('Редактирование пользователя');

$profile = unics_user_manager::get_user_profile($user_id);
if (!$profile) {
    throw new moodle_exception('Пользователь не найден в системе УНИКС');
}

$unics_role = (int)$profile->unics_role;
$is_student = ($unics_role === 7);
$is_teacher = in_array($unics_role, [4, 5, 6]);

$category_options = [1 => 'ОВЗ', 2 => 'Семейное обучение', 3 => 'Длительное лечение', 4 => 'Одарённый ребёнок'];
$ovz_options      = [1 => 'Слабовидящий', 2 => 'Слабослышащий', 3 => 'НОДА', 4 => 'ЗПР', 5 => 'РАС', 6 => 'Иное'];
$level_options    = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
$role_labels      = [3 => 'Администратор организации', 4 => 'Методист', 5 => 'Педагог', 6 => 'Тьютор', 7 => 'Учащийся', 8 => 'Родитель'];

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
        $data['student_category'] = required_param('student_category', PARAM_INT);
        $data['ovz_type']         = optional_param('ovz_type', null, PARAM_INT);
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

$return_url = new moodle_url('/local/unics/pages/users.php');
echo '<p><a href="' . $return_url . '" class="btn btn-sm btn-outline-secondary">Список пользователей</a></p>';
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
    $cat = (int)$profile->student_category;
    echo '<div class="card mb-3"><div class="card-header">Профиль учащегося</div><div class="card-body">';

    // Категория
    echo '<div class="mb-2"><label class="form-label">Категория <span class="text-danger">*</span></label>
        <select name="student_category" id="student_category" class="form-select" onchange="toggleOvz()">';
    foreach ($category_options as $v => $l) {
        $sel = ($cat === $v) ? 'selected' : '';
        echo "<option value=\"{$v}\" {$sel}>{$l}</option>";
    }
    echo '</select></div>';

    // Вид ОВЗ
    $ovz = (int)($profile->ovz_type ?? 0);
    $hide = ($cat !== 1) ? 'style="display:none"' : '';
    echo "<div class=\"mb-2\" id=\"ovz_block\" {$hide}><label class=\"form-label\">Вид ОВЗ</label>
        <select name=\"ovz_type\" class=\"form-select\">
        <option value=\"\">— не указан —</option>";
    foreach ($ovz_options as $v => $l) {
        $sel = ($ovz === $v) ? 'selected' : '';
        echo "<option value=\"{$v}\" {$sel}>{$l}</option>";
    }
    echo '</select></div>';

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
    echo '<script>function toggleOvz(){
        var v=document.getElementById("student_category").value;
        document.getElementById("ovz_block").style.display=(v=="1")?"":"none";
    }</script>';
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
