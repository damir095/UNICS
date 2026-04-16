<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/organization_manager.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/unics/pages/organizations.php'));
$PAGE->set_title(get_string('org_management', 'local_unics'));
$PAGE->set_heading(get_string('org_management', 'local_unics'));
$PAGE->set_pagelayout('admin');

$org_types = [
    1 => get_string('org_type_school',   'local_unics'),
    2 => get_string('org_type_cdo',      'local_unics'),
    3 => get_string('org_type_hospital', 'local_unics'),
    4 => get_string('org_type_boarding', 'local_unics'),
];

// ----------------------------------------------------------------
// Обработка POST
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action  = optional_param('action',  'save',  PARAM_ALPHA);
    $type    = required_param('type',    PARAM_ALPHA);
    $edit_id = optional_param('edit_id', 0, PARAM_INT);

    if ($action === 'delete') {
        $del_id = required_param('del_id', PARAM_INT);
        if ($type === 'region') {
            $result = unics_organization_manager::delete_region($del_id);
        } elseif ($type === 'district') {
            $result = unics_organization_manager::delete_district($del_id);
        } elseif ($type === 'org') {
            $result = unics_organization_manager::delete_organization($del_id);
        } else {
            $result = true;
        }

        if ($result === true) {
            redirect(
                new moodle_url('/local/unics/pages/organizations.php'),
                'Удалено успешно.',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } else {
            redirect(
                new moodle_url('/local/unics/pages/organizations.php'),
                $result,
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
    }

    // action === 'save' (create or update)
    if ($type === 'region') {
        $name = required_param('name', PARAM_TEXT);
        if ($edit_id) {
            unics_organization_manager::update_region($edit_id, $name);
        } else {
            unics_organization_manager::create_region($name);
        }

    } elseif ($type === 'district') {
        $name      = required_param('name',      PARAM_TEXT);
        $region_id = required_param('region_id', PARAM_INT);
        if ($edit_id) {
            unics_organization_manager::update_district($edit_id, $name);
        } else {
            unics_organization_manager::create_district($region_id, $name);
        }

    } elseif ($type === 'org') {
        $district_id = required_param('district_id', PARAM_INT);
        $name        = required_param('name',        PARAM_TEXT);
        $short_name  = optional_param('short_name',  '', PARAM_TEXT);
        $org_type    = required_param('org_type',    PARAM_INT);
        $address     = optional_param('address',     '', PARAM_TEXT);
        $phone       = optional_param('phone',       '', PARAM_TEXT);
        $email       = optional_param('email',       '', PARAM_EMAIL);

        if ($edit_id) {
            unics_organization_manager::update_organization($edit_id, [
                'name'       => $name,
                'short_name' => $short_name,
                'org_type'   => $org_type,
                'address'    => $address,
                'phone'      => $phone,
                'email'      => $email,
            ]);
        } else {
            unics_organization_manager::create_organization(
                $district_id, $name, $short_name, $org_type, $address, $phone, $email
            );
        }
    }

    redirect(
        new moodle_url('/local/unics/pages/organizations.php'),
        get_string('saved', 'local_unics'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ----------------------------------------------------------------
// GET-параметры для режима редактирования
// ----------------------------------------------------------------
$edit_type = optional_param('edit_type', '', PARAM_ALPHA);
$edit_id   = optional_param('edit_id',   0, PARAM_INT);

$edit_item = null;
if ($edit_type && $edit_id) {
    global $DB;
    if ($edit_type === 'region') {
        $edit_item = $DB->get_record('unics_regions', ['id' => $edit_id]);
    } elseif ($edit_type === 'district') {
        $edit_item = $DB->get_record('unics_districts', ['id' => $edit_id]);
    } elseif ($edit_type === 'org') {
        $edit_item = $DB->get_record('unics_organizations', ['id' => $edit_id]);
    }
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();

echo '<div class="mb-3">';
echo '<a href="/local/unics/pages/users.php" class="btn btn-outline-secondary btn-sm">&larr; Пользователи</a>';
echo '</div>';

// ---- Форма редактирования (показывается вверху, если кликнули Изменить) ----
if ($edit_item && $edit_type) {
    echo '<div class="card mb-4 border-warning">';
    echo '<div class="card-header bg-warning text-dark"><strong>Редактировать</strong></div>';
    echo '<div class="card-body">';
    echo '<form method="post">';
    echo '<input type="hidden" name="action"  value="save">';
    echo '<input type="hidden" name="type"    value="' . s($edit_type) . '">';
    echo '<input type="hidden" name="edit_id" value="' . $edit_id . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

    if ($edit_type === 'region') {
        echo '<div class="form-group"><label>Название региона</label>';
        echo '<input type="text" name="name" class="form-control" value="' . s($edit_item->name) . '" required></div>';

    } elseif ($edit_type === 'district') {
        echo '<input type="hidden" name="region_id" value="' . $edit_item->region_id . '">';
        echo '<div class="form-group"><label>Название района</label>';
        echo '<input type="text" name="name" class="form-control" value="' . s($edit_item->name) . '" required></div>';

    } elseif ($edit_type === 'org') {
        echo '<input type="hidden" name="district_id" value="' . $edit_item->district_id . '">';
        echo '<div class="form-row">';
        echo '<div class="col-md-6 form-group"><label>Полное название *</label>';
        echo '<input type="text" name="name" class="form-control" value="' . s($edit_item->name) . '" required></div>';
        echo '<div class="col-md-4 form-group"><label>Краткое название</label>';
        echo '<input type="text" name="short_name" class="form-control" value="' . s($edit_item->short_name) . '"></div>';
        echo '</div>';
        echo '<div class="form-row">';
        echo '<div class="col-md-4 form-group"><label>Адрес</label>';
        echo '<input type="text" name="address" class="form-control" value="' . s($edit_item->address) . '"></div>';
        echo '<div class="col-md-3 form-group"><label>Телефон</label>';
        echo '<input type="text" name="phone" class="form-control" value="' . s($edit_item->phone) . '"></div>';
        echo '<div class="col-md-3 form-group"><label>Email</label>';
        echo '<input type="email" name="email" class="form-control" value="' . s($edit_item->email) . '"></div>';
        echo '</div>';
        echo '<div class="form-group"><label>Тип организации *</label>';
        echo '<select name="org_type" class="form-control" required>';
        foreach ($org_types as $v => $l) {
            $sel = ($edit_item->org_type == $v) ? ' selected' : '';
            echo '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
        }
        echo '</select></div>';
    }

    echo '<button type="submit" class="btn btn-warning">Сохранить изменения</button> ';
    echo '<a href="/local/unics/pages/organizations.php" class="btn btn-outline-secondary">Отмена</a>';
    echo '</form>';
    echo '</div></div>';
}

$tree = unics_organization_manager::get_tree();

// ---- Форма добавления региона ----
echo '<div class="card mb-4">';
echo '<div class="card-header"><strong>' . get_string('add_region', 'local_unics') . '</strong></div>';
echo '<div class="card-body">';
echo '<form method="post" class="form-inline">';
echo '<input type="hidden" name="action" value="save">';
echo '<input type="hidden" name="type" value="region">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<div class="form-group mr-2">';
echo '<label class="mr-1">Название</label>';
echo '<input type="text" name="name" class="form-control form-control-sm" required style="width:280px">';
echo '</div>';
echo '<button type="submit" class="btn btn-primary btn-sm">Создать регион</button>';
echo '</form>';
echo '</div></div>';

// ---- Дерево организаций ----
if (empty($tree)) {
    echo $OUTPUT->notification('Регионов пока нет. Добавьте первый регион выше.', 'info');
} else {
    foreach ($tree as $region) {
        echo '<div class="card mb-4">';

        // Заголовок региона
        echo '<div class="card-header d-flex justify-content-between align-items-center">';
        echo '<span><strong>' . s($region->name) . '</strong>';
        $cat_label = $region->mdl_category_id
            ? '<span class="badge badge-success ml-2">cat #' . $region->mdl_category_id . '</span>'
            : '<span class="badge badge-warning ml-2">без категории</span>';
        echo $cat_label . '</span>';

        // Кнопки действий для региона
        echo '<div>';
        echo '<a href="?edit_type=region&edit_id=' . $region->id . '" class="btn btn-sm btn-outline-secondary mr-1">Изменить</a>';
        echo '<form method="post" class="d-inline"
                onsubmit="return confirm(\'Удалить регион \'' . s(addslashes($region->name)) . '\'? Все районы должны быть удалены заранее.\')">';
        echo '<input type="hidden" name="action"  value="delete">';
        echo '<input type="hidden" name="type"    value="region">';
        echo '<input type="hidden" name="del_id"  value="' . $region->id . '">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        echo '<button type="submit" class="btn btn-sm btn-outline-danger">Удалить</button>';
        echo '</form>';
        echo '</div>';

        echo '</div>'; // card-header

        echo '<div class="card-body">';

        foreach ($region->districts as $dist) {
            echo '<div class="border rounded p-3 mb-3">';

            // Заголовок района
            echo '<div class="d-flex justify-content-between align-items-center mb-2">';
            echo '<span><strong>' . s($dist->name) . '</strong>';
            $cat_label = $dist->mdl_category_id
                ? '<small class="text-success ml-2">cat #' . $dist->mdl_category_id . '</small>'
                : '<small class="text-danger ml-2">без категории</small>';
            echo $cat_label . '</span>';

            // Кнопки действий для района
            echo '<div>';
            echo '<a href="?edit_type=district&edit_id=' . $dist->id . '" class="btn btn-sm btn-outline-secondary mr-1">Изменить</a>';
            echo '<form method="post" class="d-inline"
                    onsubmit="return confirm(\'Удалить район \'' . s(addslashes($dist->name)) . '\'? Организации должны быть удалены заранее.\')">';
            echo '<input type="hidden" name="action"  value="delete">';
            echo '<input type="hidden" name="type"    value="district">';
            echo '<input type="hidden" name="del_id"  value="' . $dist->id . '">';
            echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
            echo '<button type="submit" class="btn btn-sm btn-outline-danger">Удалить</button>';
            echo '</form>';
            echo '</div>';

            echo '</div>'; // d-flex district header

            // Организации района
            if (!empty($dist->organizations)) {
                echo '<table class="table table-sm table-bordered mb-2">';
                echo '<thead class="thead-light"><tr>
                    <th>Организация</th><th>Краткое</th><th>Тип</th>
                    <th>Email</th><th>Категория Moodle</th><th>Действия</th>
                </tr></thead><tbody>';
                foreach ($dist->organizations as $org) {
                    $type_name = $org_types[$org->org_type] ?? '?';
                    $cat_cell  = $org->mdl_category_id
                        ? '<span class="text-success">cat #' . $org->mdl_category_id . '</span>'
                        : '<span class="text-danger">' . get_string('not_linked', 'local_unics') . '</span>';

                    $del_form = '<form method="post" class="d-inline"
                        onsubmit="return confirm(\'Удалить организацию \'' . s(addslashes($org->name)) . '\'?\')">'
                        . '<input type="hidden" name="action"  value="delete">'
                        . '<input type="hidden" name="type"    value="org">'
                        . '<input type="hidden" name="del_id"  value="' . $org->id . '">'
                        . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                        . '<button type="submit" class="btn btn-sm btn-outline-danger">Удалить</button>'
                        . '</form>';

                    echo '<tr>';
                    echo '<td>' . s($org->name) . '</td>';
                    echo '<td>' . s($org->short_name) . '</td>';
                    echo '<td>' . $type_name . '</td>';
                    echo '<td>' . s($org->email) . '</td>';
                    echo '<td>' . $cat_cell . '</td>';
                    echo '<td class="text-nowrap">';
                    echo '<a href="?edit_type=org&edit_id=' . $org->id . '" class="btn btn-sm btn-outline-secondary mr-1">Изменить</a>';
                    echo $del_form;
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p class="text-muted small">В этом районе пока нет организаций.</p>';
            }

            // Форма добавления организации в район
            echo '<details><summary class="text-primary" style="cursor:pointer">+ Добавить организацию в этот район</summary>';
            echo '<form method="post" class="mt-2">';
            echo '<input type="hidden" name="action"      value="save">';
            echo '<input type="hidden" name="type"        value="org">';
            echo '<input type="hidden" name="district_id" value="' . $dist->id . '">';
            echo '<input type="hidden" name="sesskey"     value="' . sesskey() . '">';
            echo '<div class="form-row">';

            $fields = [
                ['name',       'Полное название *', 'text',  true],
                ['short_name', 'Краткое название',  'text',  false],
                ['address',    'Адрес',             'text',  false],
                ['phone',      'Телефон',           'text',  false],
                ['email',      'Email',             'email', false],
            ];
            foreach ($fields as [$fname, $label, $ftype, $req]) {
                echo '<div class="col-md-4 mb-1">';
                echo '<label class="small">' . $label . '</label>';
                echo '<input type="' . $ftype . '" name="' . $fname . '" class="form-control form-control-sm"'
                   . ($req ? ' required' : '') . '>';
                echo '</div>';
            }

            echo '<div class="col-md-3 mb-1">';
            echo '<label class="small">Тип организации *</label>';
            echo '<select name="org_type" class="form-control form-control-sm" required>';
            foreach ($org_types as $v => $l) {
                echo '<option value="' . $v . '">' . $l . '</option>';
            }
            echo '</select></div>';

            echo '</div>';
            echo '<button type="submit" class="btn btn-success btn-sm mt-1">Создать</button>';
            echo '</form></details>';
            echo '</div>'; // border rounded
        }

        // Форма добавления района в регион
        echo '<details class="mt-2"><summary class="text-primary" style="cursor:pointer">+ Добавить район в этот регион</summary>';
        echo '<form method="post" class="mt-2 form-inline">';
        echo '<input type="hidden" name="action"    value="save">';
        echo '<input type="hidden" name="type"      value="district">';
        echo '<input type="hidden" name="region_id" value="' . $region->id . '">';
        echo '<input type="hidden" name="sesskey"   value="' . sesskey() . '">';
        echo '<div class="form-group mr-2">';
        echo '<label class="mr-1">Название</label>';
        echo '<input type="text" name="name" class="form-control form-control-sm" required style="width:260px">';
        echo '</div>';
        echo '<button type="submit" class="btn btn-success btn-sm">Создать район</button>';
        echo '</form></details>';

        echo '</div>'; // card-body
        echo '</div>'; // card
    }
}

echo $OUTPUT->footer();
