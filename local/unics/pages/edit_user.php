<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/user_manager.php');
require_login();
require_capability('local/unics:manage', context_system::instance());

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
        // Множественный выбор: checkbox[] из формы.
        $cats_raw = optional_param_array('student_categories', [], PARAM_INT);
        $ovz_raw  = optional_param_array('ovz_types', [], PARAM_INT);
        if (empty($cats_raw)) {
            throw new moodle_exception('Не выбрана ни одна категория учащегося');
        }
        $data['student_category'] = \local_unics\student_helper::to_csv($cats_raw);
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
    $cats_selected = \local_unics\student_helper::parse_csv($profile->student_category ?? '');
    $ovz_selected  = \local_unics\student_helper::parse_csv($profile->ovz_type ?? '');
    $has_ovz_cat = in_array(1, $cats_selected, true);

    echo '<div class="card mb-3"><div class="card-header">Профиль учащегося</div><div class="card-body">';

    // Категории - checkboxes (могут быть несколько).
    echo '<div class="mb-3"><label class="form-label d-block">Категории <span class="text-danger">*</span></label>';
    foreach ($category_options as $v => $l) {
        $chk = in_array($v, $cats_selected, true) ? 'checked' : '';
        echo "<div class=\"form-check\">
            <input class=\"form-check-input\" type=\"checkbox\" name=\"student_categories[]\" value=\"{$v}\" id=\"cat_{$v}\" {$chk} onchange=\"toggleOvz()\">
            <label class=\"form-check-label\" for=\"cat_{$v}\">{$l}</label>
        </div>";
    }
    echo '</div>';

    // Виды ОВЗ - checkboxes (показываются только если в категориях отмечен «ОВЗ» = 1).
    $hide = !$has_ovz_cat ? 'style="display:none"' : '';
    echo "<div class=\"mb-3\" id=\"ovz_block\" {$hide}><label class=\"form-label d-block\">Виды ОВЗ</label>";
    foreach ($ovz_options as $v => $l) {
        $chk = in_array($v, $ovz_selected, true) ? 'checked' : '';
        echo "<div class=\"form-check\">
            <input class=\"form-check-input\" type=\"checkbox\" name=\"ovz_types[]\" value=\"{$v}\" id=\"ovz_{$v}\" {$chk}>
            <label class=\"form-check-label\" for=\"ovz_{$v}\">{$l}</label>
        </div>";
    }
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
    echo '<script>function toggleOvz(){
        var cb=document.getElementById("cat_1");
        document.getElementById("ovz_block").style.display=(cb && cb.checked)?"":"none";
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
