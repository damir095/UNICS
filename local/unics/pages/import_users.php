<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/user_manager.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

global $DB, $CFG;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/import_users.php'));
$PAGE->set_title('Импорт пользователей из CSV — УНИКС');
$PAGE->set_heading('Импорт пользователей из CSV');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Скачать шаблон CSV
// ----------------------------------------------------------------
if (optional_param('download_template', 0, PARAM_INT)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unics_users_template.csv"');

    $out = fopen('php://output', 'w');
    // BOM для корректного открытия в Excel
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'lastname', 'firstname', 'middlename', 'email', 'username', 'password',
        'unics_role', 'organization_id', 'class_number', 'category', 'difficulty_level', 'subjects'
    ]);

    // Пример: учащийся
    fputcsv($out, ['Иванов', 'Иван', 'Иванович', 'ivanov@example.com', 'ivanov_i', 'Pass123!',
        '7', '1', '5', '2', '2', '']);
    // Пример: педагог
    fputcsv($out, ['Петрова', 'Мария', 'Сергеевна', 'petrova@example.com', 'petrova_m', 'Pass123!',
        '5', '1', '', '', '', 'Математика, Физика']);

    fclose($out);
    exit;
}

// ----------------------------------------------------------------
// Константы
// ----------------------------------------------------------------
$ROLES = [
    3 => 'Администратор орг.',
    4 => 'Методист',
    5 => 'Педагог',
    6 => 'Тьютор',
    7 => 'Учащийся',
    8 => 'Родитель',
];
$CATEGORIES   = [1 => 'ОВЗ', 2 => 'Семейное', 3 => 'Лечение', 4 => 'Одарённый'];
$LEVELS       = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
$REQUIRED_COLS = ['lastname', 'firstname', 'email', 'username', 'password', 'unics_role', 'organization_id'];

// ----------------------------------------------------------------
// Обработка загруженного файла
// ----------------------------------------------------------------
$results   = [];
$has_file  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $has_file = !empty($_FILES['csvfile']['tmp_name']);

    if (!$has_file) {
        redirect(
            new moodle_url('/local/unics/pages/import_users.php'),
            'Файл не загружен.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $file = $_FILES['csvfile']['tmp_name'];
    $handle = fopen($file, 'r');

    if (!$handle) {
        redirect(
            new moodle_url('/local/unics/pages/import_users.php'),
            'Не удалось открыть файл.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Читаем заголовок — поддерживаем запятую и точку с запятой
    $raw_header = fgets($handle);
    rewind($handle);

    // Убираем BOM если есть
    $raw_header = ltrim($raw_header, "\xEF\xBB\xBF");
    $delimiter  = (substr_count($raw_header, ';') > substr_count($raw_header, ',')) ? ';' : ',';

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        redirect(
            new moodle_url('/local/unics/pages/import_users.php'),
            'Не удалось прочитать заголовок CSV.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Нормализуем заголовки
    $header = array_map(fn($h) => mb_strtolower(trim($h)), $header);

    // Проверка наличия обязательных колонок
    $missing = array_diff($REQUIRED_COLS, $header);
    if (!empty($missing)) {
        fclose($handle);
        redirect(
            new moodle_url('/local/unics/pages/import_users.php'),
            'В CSV отсутствуют обязательные колонки: ' . implode(', ', $missing),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $do_import = (bool)optional_param('do_import', 0, PARAM_INT);
    $row_num   = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row_num++;
        if (count(array_filter($row)) === 0) continue; // пустая строка

        // Сопоставляем колонки
        $data = [];
        foreach ($header as $i => $col) {
            $data[$col] = isset($row[$i]) ? trim($row[$i]) : '';
        }

        $errors = [];

        // Валидация обязательных полей
        foreach ($REQUIRED_COLS as $col) {
            if (empty($data[$col])) {
                $errors[] = "Пустое поле «{$col}»";
            }
        }

        // Валидация роли
        $role = (int)($data['unics_role'] ?? 0);
        if (!isset($ROLES[$role])) {
            $errors[] = "Неверная роль «{$data['unics_role']}» (допустимые: " . implode(', ', array_keys($ROLES)) . ')';
        }

        // Валидация организации
        $org_id = (int)($data['organization_id'] ?? 0);
        if ($org_id > 0 && !$DB->record_exists('unics_organizations', ['id' => $org_id, 'is_active' => 1])) {
            $errors[] = "Организация #{$org_id} не найдена";
        }

        // Проверка уникальности username и email
        if (!empty($data['username']) && $DB->record_exists('user', ['username' => $data['username'], 'deleted' => 0])) {
            $errors[] = "Логин «{$data['username']}» уже занят";
        }
        if (!empty($data['email']) && $DB->record_exists('user', ['email' => $data['email'], 'deleted' => 0, 'mnethostid' => 1])) {
            $errors[] = "Email «{$data['email']}» уже используется";
        }

        // Для учащихся — category и difficulty_level
        if ($role === 7) {
            $cat = (int)($data['category'] ?? 0);
            if (!isset($CATEGORIES[$cat])) {
                $errors[] = "Для учащегося поле category должно быть 1–4";
            }
            $lvl = (int)($data['difficulty_level'] ?? 0);
            if (!isset($LEVELS[$lvl])) {
                $errors[] = "Для учащегося поле difficulty_level должно быть 1–3";
            }
        }

        $status = 'pending';
        $status_msg = empty($errors) ? 'Готов к импорту' : implode('; ', $errors);

        // Если режим импорта и нет ошибок — создаём пользователя
        if ($do_import && empty($errors)) {
            try {
                unics_user_manager::create_user([
                    'lastname'          => $data['lastname'],
                    'firstname'         => $data['firstname'],
                    'middlename'        => $data['middlename'] ?? '',
                    'email'             => $data['email'],
                    'username'          => $data['username'],
                    'password'          => $data['password'],
                    'unics_role'        => $role,
                    'organization_id'   => $org_id,
                    'class_number'      => (int)($data['class_number'] ?? 0) ?: null,
                    'student_category'  => (int)($data['category'] ?? 2),
                    'ovz_type'          => null,
                    'difficulty_level'  => (int)($data['difficulty_level'] ?? 2),
                    'special_needs'     => '',
                    'subjects'          => $data['subjects'] ?? '',
                    'qualification'     => '',
                ]);
                $status = 'ok';
                $status_msg = 'Создан';
            } catch (\Throwable $e) {
                $status = 'error';
                $status_msg = $e->getMessage();
            }
        } elseif (!empty($errors)) {
            $status = 'error';
        }

        $results[] = [
            'row'        => $row_num,
            'fio'        => trim("{$data['lastname']} {$data['firstname']} " . ($data['middlename'] ?? '')),
            'username'   => $data['username'] ?? '',
            'email'      => $data['email'] ?? '',
            'role'       => $ROLES[$role] ?? $data['unics_role'],
            'org_id'     => $org_id,
            'status'     => $status,
            'status_msg' => $status_msg,
        ];
    }

    fclose($handle);
}

// ----------------------------------------------------------------
// Вывод страницы
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Импорт пользователей из CSV');

echo html_writer::link(
    new moodle_url('/local/unics/pages/users.php'),
    'Назад к пользователям',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3 mr-2']
);
echo html_writer::link(
    new moodle_url('/local/unics/pages/import_users.php', ['download_template' => 1]),
    'Скачать шаблон CSV',
    ['class' => 'btn btn-outline-info btn-sm mb-3']
);

// Описание формата
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-header font-weight-bold']);
echo 'Формат CSV-файла';
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', ['class' => 'card-body p-2']);

$col_table = new html_table();
$col_table->head = ['Колонка', 'Обязательная', 'Описание'];
$col_table->attributes['class'] = 'table table-sm table-bordered mb-0';
$col_table->data = [
    ['lastname',         'Да', 'Фамилия'],
    ['firstname',        'Да', 'Имя'],
    ['middlename',       'Нет', 'Отчество'],
    ['email',            'Да', 'Email (уникальный)'],
    ['username',         'Да', 'Логин (уникальный, латиница)'],
    ['password',         'Да', 'Пароль (мин. 8 символов)'],
    ['unics_role',       'Да', '5 — Педагог, 6 — Тьютор, 7 — Учащийся, 8 — Родитель, 3 — Адм. орг., 4 — Методист'],
    ['organization_id',  'Да', 'ID организации из системы УНИКС'],
    ['class_number',     'Нет', 'Класс обучения 1–11 (только для учащихся)'],
    ['category',         'Для учащихся', '1 — ОВЗ, 2 — Семейное, 3 — Лечение, 4 — Одарённый'],
    ['difficulty_level', 'Для учащихся', '1 — Базовый, 2 — Стандартный, 3 — Продвинутый'],
    ['subjects',         'Нет', 'Предметы через запятую (для педагогов)'],
];
echo html_writer::table($col_table);
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ----------------------------------------------------------------
// Форма загрузки
// ----------------------------------------------------------------
if (empty($results)) {
    $upload_url = new moodle_url('/local/unics/pages/import_users.php', ['sesskey' => sesskey()]);
    echo html_writer::start_tag('form', [
        'method'  => 'post',
        'action'  => $upload_url,
        'enctype' => 'multipart/form-data',
        'class'   => 'mb-4',
    ]);

    echo html_writer::start_tag('div', ['class' => 'form-group']);
    echo html_writer::tag('label', 'CSV-файл:', ['for' => 'csvfile', 'class' => 'font-weight-bold']);
    echo html_writer::empty_tag('input', [
        'type'   => 'file',
        'name'   => 'csvfile',
        'id'     => 'csvfile',
        'accept' => '.csv',
        'class'  => 'form-control-file mt-1',
    ]);
    echo html_writer::tag('small', 'Кодировка UTF-8. Разделитель: запятая или точка с запятой.', ['class' => 'text-muted']);
    echo html_writer::end_tag('div');

    echo html_writer::tag('button', 'Проверить файл',
        ['type' => 'submit', 'name' => 'do_import', 'value' => '0', 'class' => 'btn btn-secondary mr-2']);

    echo html_writer::end_tag('form');

} else {
    // ----------------------------------------------------------------
    // Результаты проверки / импорта
    // ----------------------------------------------------------------
    $count_ok    = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
    $count_ready = count(array_filter($results, fn($r) => $r['status'] === 'pending'));
    $count_err   = count(array_filter($results, fn($r) => $r['status'] === 'error'));
    $do_import   = (bool)optional_param('do_import', 0, PARAM_INT);

    if ($do_import) {
        echo $OUTPUT->notification(
            "Импорт завершён. Создано: {$count_ok}. Ошибок: {$count_err}.",
            $count_err === 0 ? 'success' : 'warning'
        );
    } else {
        echo $OUTPUT->notification(
            "Проверка завершена. Готовы к импорту: {$count_ready}. Ошибок: {$count_err}.",
            $count_err === 0 ? 'success' : 'warning'
        );
    }

    // Таблица строк
    $table = new html_table();
    $table->head = ['Стр.', 'ФИО', 'Логин', 'Email', 'Роль', 'Статус'];
    $table->attributes['class'] = 'table table-sm table-bordered';

    foreach ($results as $r) {
        $badge_class = match($r['status']) {
            'ok'      => 'badge-success',
            'error'   => 'badge-danger',
            default   => 'badge-secondary',
        };
        $badge = html_writer::tag('span', htmlspecialchars($r['status_msg']),
            ['class' => "badge {$badge_class}", 'style' => 'white-space:normal;font-size:0.85em']);

        $row_class = $r['status'] === 'error' ? 'table-danger' : ($r['status'] === 'ok' ? 'table-success' : '');
        $row = new html_table_row([
            $r['row'],
            htmlspecialchars($r['fio']),
            htmlspecialchars($r['username']),
            htmlspecialchars($r['email']),
            htmlspecialchars($r['role']),
            $badge,
        ]);
        $row->attributes['class'] = $row_class;
        $table->data[] = $row;
    }
    echo html_writer::table($table);

    // Кнопки действий
    if (!$do_import && $count_ready > 0) {
        // Кнопка "Импортировать" — повторно отправляем тот же файл нельзя,
        // поэтому предлагаем загрузить снова с флагом do_import=1
        echo html_writer::start_tag('div', ['class' => 'alert alert-info mt-3']);
        echo html_writer::tag('strong', "Готовы к импорту: {$count_ready} пользователей.");
        if ($count_err > 0) {
            echo ' Строки с ошибками будут пропущены.';
        }
        echo html_writer::end_tag('div');

        $upload_url = new moodle_url('/local/unics/pages/import_users.php', ['sesskey' => sesskey()]);
        echo html_writer::start_tag('form', [
            'method'  => 'post',
            'action'  => $upload_url,
            'enctype' => 'multipart/form-data',
            'class'   => 'mb-3',
        ]);
        echo html_writer::start_tag('div', ['class' => 'form-group']);
        echo html_writer::tag('label', 'Загрузите файл снова для импорта:',
            ['for' => 'csvfile2', 'class' => 'font-weight-bold']);
        echo html_writer::empty_tag('input', [
            'type'   => 'file',
            'name'   => 'csvfile',
            'id'     => 'csvfile2',
            'accept' => '.csv',
            'class'  => 'form-control-file mt-1',
        ]);
        echo html_writer::end_tag('div');
        echo html_writer::tag('button', "Импортировать ({$count_ready})",
            ['type' => 'submit', 'name' => 'do_import', 'value' => '1', 'class' => 'btn btn-primary mr-2']);
        echo html_writer::end_tag('form');
    }

    echo html_writer::link(
        new moodle_url('/local/unics/pages/import_users.php'),
        'Загрузить другой файл',
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
}

echo $OUTPUT->footer();
