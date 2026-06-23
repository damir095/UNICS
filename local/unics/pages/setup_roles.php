<?php
/**
 * Настройка прав ролей УНИКС.
 * Применяет матрицу прав для каждой роли системы.
 *
 * Реализация матрицы — в \local_unics\role_manager (классы переиспользуются
 * из db/upgrade.php для авто-применения при апгрейде плагина).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/role_manager.php');

use local_unics\role_manager;

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/setup_roles.php'));
$PAGE->set_title('Настройка прав ролей - УНИКС');
$PAGE->set_heading('Настройка прав ролей УНИКС');
$PAGE->set_pagelayout('admin');

// ================================================================
// Обработка POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'apply') {
        $results = role_manager::apply_matrix();
        role_manager::apply_role_names();
        $_SESSION['unics_setup_results'] = $results;

        redirect(
            new moodle_url('/local/unics/pages/setup_roles.php'),
            'Матрица прав применена.',
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// ================================================================
// Вывод
// ================================================================
echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Настройка прав ролей УНИКС');

echo html_writer::link(
    new moodle_url('/local/unics/pages/users.php'),
    'Назад к пользователям',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// Результаты последнего применения
if (!empty($_SESSION['unics_setup_results'])) {
    $res = $_SESSION['unics_setup_results'];
    unset($_SESSION['unics_setup_results']);

    echo '<div class="alert alert-success"><strong>Результат применения:</strong><ul class="mb-0">';
    foreach ($res as $sn => $r) {
        $icon = $r['status'] === 'ok' ? '✓' : '-';
        echo "<li><code>{$sn}</code>: {$icon} {$r['msg']}</li>";
    }
    echo '</ul></div>';
}

// Информация про автоприменение
echo '<div class="alert alert-info">';
echo '<strong>Матрица применяется автоматически</strong> при апгрейде плагина (см. db/upgrade.php). ';
echo 'Ручной запуск нужен только если админ изменил права вручную и хочет вернуть канонический набор.';
echo '</div>';

// --- Описание матрицы ---
echo '<div class="card mb-4">';
echo '<div class="card-header"><strong>Матрица прав ролей</strong></div>';
echo '<div class="card-body p-0">';
echo '<table class="table table-sm table-bordered mb-0">';
echo '<thead class="table-light"><tr>
    <th>Группа прав</th>
    <th>Админ¹</th>
    <th>Методист²</th>
    <th>Педагог,<br>создаёт курсы</th>
    <th>Педагог<br>(non-editing)</th>
    <th>Учащийся</th>
    <th>Родитель</th>
</tr></thead><tbody>';

// Колонки: Админ (региональный администратор),
// Методист (организации/муниципальный — права идентичны),
// Педагог создающий курсы (editingteacher), Педагог non-editing (teacher),
// Учащийся, Родитель. Различие орг./муниципальный методист — только в скоупе.
// Муниципальный администратор удалён в v3 [[role-model-v3-2026-06-11]].
$matrix_display = [
    ['Редактировать содержимое курса',   '❌', '❌', '✅', '❌', '❌', '❌'],
    ['Выставлять оценки',               '❌', '❌', '✅', '✅', '❌', '❌'],
    ['Просматривать оценки всех',       '✅', '✅', '✅', '✅', '❌', '✅'],
    ['Управлять группами курса',        '❌', '❌', '✅', '❌', '❌', '❌'],
    ['Просматривать участников',        '✅', '✅', '✅', '✅', '❌', '❌'],
    ['Форум: участие',                  '❌', '✅', '✅', '✅', '✅', '❌'],
    ['Заметки о студентах',             '✅', '❌', '✅', '✅', '❌', '✅¹'],
    ['Отчёты активности',               '✅', '✅', '✅', '✅', '❌', '✅'],
    ['Сообщения',                       '✅', '✅', '✅', '✅', '✅', '✅'],
    ['Создавать курсы',                 '❌', '❌', '✅', '❌', '❌', '❌'],
    ['Записывать пользователей',        '✅', '✅²', '✅', '❌', '❌', '❌'],
    ['Резервное копирование',           '❌', '❌', '✅', '❌', '❌', '❌'],
    ['accessallgroups',                 '✅', '❌', '⚙️³', '❌', '❌', '❌'],
    ['local/unics:manage',              '❌', '❌', '❌', '❌', '❌', '❌'],
    ['local/unics:manageorg',           '✅', '✅', '❌', '🔒', '🔒', '🔒'],
    ['local/unics:viewstudents',        '✅', '✅', '✅', '✅', '🔒', '🔒'],
];

foreach ($matrix_display as $row) {
    echo '<tr>';
    foreach ($row as $i => $cell) {
        $class = $i === 0 ? '' : 'text-center';
        echo "<td class=\"{$class}\">{$cell}</td>";
    }
    echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '<div class="card-footer small text-muted">';
echo '<strong>Колонка «Методист»</strong> объединяет две роли с идентичными правами, '
   . 'различающиеся только скоупом: методист организации (организация) + муниципальный методист (муниципалитет). '
   . 'Колонка «Админ» = региональный администратор (регион). ';
echo '¹ Родитель видит заметки - только на просмотр; «Админ» = local/unics:manage у системного админа, '
   . 'у регионального админа - manageorg со scope-проверкой. ';
echo '² Методист записывает учащихся только на делегированные ему курсы '
   . '(enrol/manual, проверка скоупа и делегирования); курсы методист не создаёт и не редактирует. ';
echo '³ Педагог: inherit (не установлено глобально) - переопределяется PROHIBIT на уровне курса при включении режима «Раздельные группы». ';
echo '🔒 = жёсткий запрет (CAP_PROHIBIT). ';
echo 'local/unics:manageorg - даёт право писать в скоупе (регион/район/орг через unics_user_org); '
   . 'каждая страница, использующая её, обязана проверять, что target входит в скоуп. '
   . 'Системный администратор Moodle имеет local/unics:manage (всё).';
echo '</div>';
echo '</div>';

// --- Кнопка применения ---
echo '<form method="post">';
echo '<input type="hidden" name="action" value="apply">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

// Проверяем какие роли существуют
$role_labels = [
    'region_admin'       => 'Региональный администратор',
    'region_methodist'   => 'Региональный методист',
    'methodist'          => 'Методист организации',
    'district_methodist' => 'Муниципальный методист',
    'editingteacher'     => 'Педагог, создающий курсы',
    'teacher'            => 'Педагог (non-editing)',
    'student'            => 'Учащийся',
    'parent'             => 'Родитель',
    'org_admin'          => 'Адм. организации (legacy)',
];
$missing_roles = [];
foreach ($role_labels as $sn => $label) {
    if (!$DB->record_exists('role', ['shortname' => $sn])) {
        $missing_roles[] = "{$label} ({$sn})";
    }
}

if (!empty($missing_roles)) {
    echo '<div class="alert alert-warning">';
    echo '<strong>Роли не найдены в системе (права для них будут пропущены):</strong> ';
    echo implode(', ', $missing_roles);
    echo '<br><small>Создайте эти роли через Администрирование → Пользователи → Права → Определить роли.</small>';
    echo '</div>';
}

echo html_writer::tag('button', 'Применить матрицу прав ко всем ролям',
    ['type' => 'submit', 'class' => 'btn btn-primary',
     'onclick' => "return confirm('Применить матрицу прав? Существующие явные настройки прав для этих ролей будут перезаписаны.');"]
);
echo '</form>';

echo $OUTPUT->footer();
