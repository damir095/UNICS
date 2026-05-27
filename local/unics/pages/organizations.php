<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/organization_manager.php');

require_login();
local_unics_require_manage_or_manageorg();

global $USER;
$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
$my_scope      = $is_admin_user
    ? ['region_id' => null, 'district_id' => null, 'organization_id' => null]
    : \local_unics\scope_checker::get_user_scope((int)$USER->id);

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
    $action  = optional_param('action',  'save',  PARAM_ALPHANUMEXT);
    $type    = required_param('type',    PARAM_ALPHA);
    $edit_id = optional_param('edit_id', 0, PARAM_INT);

    if ($action === 'move_members') {
        $from_org_id = required_param('from_org_id', PARAM_INT);
        $to_org_id   = required_param('to_org_id',   PARAM_INT);
        local_unics_require_manage_or_scope_org($from_org_id);
        local_unics_require_manage_or_scope_org($to_org_id);
        $moved = unics_organization_manager::move_members($from_org_id, $to_org_id);
        redirect(
            new moodle_url('/local/unics/pages/organizations.php'),
            "Переведено участников: {$moved}.",
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'delete') {
        $del_id = required_param('del_id', PARAM_INT);
        if ($type === 'region') {
            // Удаление региона — только системный админ.
            require_capability('local/unics:manage', $sys_ctx);
            $result = unics_organization_manager::delete_region($del_id);
        } elseif ($type === 'district') {
            local_unics_require_manage_or_scope_district($del_id);
            $result = unics_organization_manager::delete_district($del_id);
        } elseif ($type === 'org') {
            local_unics_require_manage_or_scope_org($del_id);
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
            local_unics_require_manage_or_scope_region($edit_id);
            unics_organization_manager::update_region($edit_id, $name);
        } else {
            // Создание нового региона — только системный админ.
            require_capability('local/unics:manage', $sys_ctx);
            unics_organization_manager::create_region($name);
        }

    } elseif ($type === 'district') {
        $name      = required_param('name',      PARAM_TEXT);
        $region_id = required_param('region_id', PARAM_INT);
        if ($edit_id) {
            local_unics_require_manage_or_scope_district($edit_id);
            unics_organization_manager::update_district($edit_id, $name);
        } else {
            // Создание нового района — region должен входить в скоуп.
            local_unics_require_manage_or_scope_region($region_id);
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
            local_unics_require_manage_or_scope_org($edit_id);
            unics_organization_manager::update_organization($edit_id, [
                'name'       => $name,
                'short_name' => $short_name,
                'org_type'   => $org_type,
                'address'    => $address,
                'phone'      => $phone,
                'email'      => $email,
            ]);
        } else {
            // Создание новой орг — район должен входить в скоуп.
            local_unics_require_manage_or_scope_district($district_id);
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
    // Scope-check: пользователь не должен видеть форму редактирования для сущности вне скоупа.
    if ($edit_type === 'region') {
        local_unics_require_manage_or_scope_region($edit_id);
        $edit_item = $DB->get_record('unics_regions', ['id' => $edit_id]);
    } elseif ($edit_type === 'district') {
        local_unics_require_manage_or_scope_district($edit_id);
        $edit_item = $DB->get_record('unics_districts', ['id' => $edit_id]);
    } elseif ($edit_type === 'org') {
        local_unics_require_manage_or_scope_org($edit_id);
        $edit_item = $DB->get_record('unics_organizations', ['id' => $edit_id]);
    }
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo local_unics_dashboard_button();

echo '<div class="mb-3">';
echo '<a href="/local/unics/pages/users.php" class="btn btn-outline-secondary btn-sm">Пользователи</a>';
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
        echo '<div class="form-group"><label>Название муниципалитета</label>';
        echo '<input type="text" name="name" class="form-control" value="' . s($edit_item->name) . '" required></div>';

    } elseif ($edit_type === 'org') {
        echo '<input type="hidden" name="district_id" value="' . $edit_item->district_id . '">';
        echo '<div class="row g-2">';
        echo '<div class="col-md-6 form-group"><label>Полное название *</label>';
        echo '<input type="text" name="name" class="form-control" value="' . s($edit_item->name) . '" required></div>';
        echo '<div class="col-md-4 form-group"><label>Краткое название</label>';
        echo '<input type="text" name="short_name" class="form-control" value="' . s($edit_item->short_name) . '"></div>';
        echo '</div>';
        echo '<div class="row g-2">';
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

// Фильтрация дерева по скоупу: не-админ видит только свою ветвь.
if (!$is_admin_user) {
    $tree = array_filter($tree, function ($region) use ($my_scope) {
        if ($my_scope['region_id'] !== null) {
            return (int)$region->id === $my_scope['region_id'];
        }
        if ($my_scope['district_id'] !== null) {
            $region->districts = array_filter($region->districts,
                fn($d) => (int)$d->id === $my_scope['district_id']);
            return !empty($region->districts);
        }
        if ($my_scope['organization_id'] !== null) {
            foreach ($region->districts as &$d) {
                $d->organizations = array_filter($d->organizations,
                    fn($o) => (int)$o->id === $my_scope['organization_id']);
            }
            unset($d);
            $region->districts = array_filter($region->districts, fn($d) => !empty($d->organizations));
            return !empty($region->districts);
        }
        return false;
    });
}

$all_orgs_grouped = unics_organization_manager::get_organizations_grouped();

// ---- Дерево организаций ----
if (empty($tree)) {
    echo $OUTPUT->notification('Регионов пока нет. Регион создаётся администратором системы.', 'info');
} else {
    foreach ($tree as $region) {
        echo '<div class="card mb-4">';

        // Заголовок региона
        echo '<div class="card-header d-flex justify-content-between align-items-center">';
        echo '<strong>' . s($region->name) . '</strong>';

        // Кнопки действий для региона
        echo '<div>';
        echo '<a href="?edit_type=region&edit_id=' . $region->id . '" class="btn btn-sm btn-outline-secondary mr-1">Изменить</a>';
        echo '<form method="post" class="d-inline"
                onsubmit="return confirm(\'Удалить регион ' . s(addslashes($region->name)) . '? Все муниципалитеты должны быть удалены заранее.\')">';
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
            echo '<strong>' . s($dist->name) . '</strong>';

            // Кнопки действий для района
            echo '<div>';
            echo '<a href="?edit_type=district&edit_id=' . $dist->id . '" class="btn btn-sm btn-outline-secondary mr-1">Изменить</a>';
            echo '<form method="post" class="d-inline"
                    onsubmit="return confirm(\'Удалить муниципалитет ' . s(addslashes($dist->name)) . '? Организации должны быть удалены заранее.\')">';
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
                echo '<thead class="table-light"><tr>
                    <th>Организация</th><th>Краткое</th><th>Тип</th>
                    <th>Email</th><th>Действия</th>
                </tr></thead><tbody>';
                foreach ($dist->organizations as $org) {
                    $type_name = $org_types[$org->org_type] ?? '?';

                    $del_form = '<form method="post" class="d-inline"
                        onsubmit="return confirm(\'Удалить организацию ' . s(addslashes($org->name)) . '?\')">'
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
                    $move_btn = '<button type="button" class="btn btn-sm btn-outline-info mr-1"'
                        . ' data-bs-toggle="modal" data-bs-target="#moveOrgModal"'
                        . ' data-org-id="' . $org->id . '"'
                        . ' data-org-name="' . s($org->name) . '">'
                        . 'Перевести всех</button>';

                    echo '<td class="text-nowrap">';
                    echo '<a href="?edit_type=org&edit_id=' . $org->id . '" class="btn btn-sm btn-outline-secondary mr-1">Изменить</a>';
                    echo $move_btn;
                    echo $del_form;
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p class="text-muted small">В этом муниципалитете пока нет организаций.</p>';
            }

            // Форма добавления организации в район
            echo '<details><summary class="text-primary" style="cursor:pointer">Добавить организацию в этот муниципалитет</summary>';
            echo '<form method="post" class="mt-2">';
            echo '<input type="hidden" name="action"      value="save">';
            echo '<input type="hidden" name="type"        value="org">';
            echo '<input type="hidden" name="district_id" value="' . $dist->id . '">';
            echo '<input type="hidden" name="sesskey"     value="' . sesskey() . '">';
            echo '<div class="row g-2">';

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
            echo '<button type="submit" class="btn btn-primary btn-sm mt-1">Создать</button>';
            echo '</form></details>';
            echo '</div>'; // border rounded
        }

        // Server-side gate lives in the POST handler via _scope_region.
        if ($is_admin_user || $my_scope['region_id'] !== null) {
            echo '<details class="mt-2"><summary class="text-primary" style="cursor:pointer">Добавить муниципалитет в этот регион</summary>';
            echo '<form method="post" class="mt-2 form-inline">';
            echo '<input type="hidden" name="action"    value="save">';
            echo '<input type="hidden" name="type"      value="district">';
            echo '<input type="hidden" name="region_id" value="' . $region->id . '">';
            echo '<input type="hidden" name="sesskey"   value="' . sesskey() . '">';
            echo '<div class="form-group mr-2">';
            echo '<label class="mr-1">Название</label>';
            echo '<input type="text" name="name" class="form-control form-control-sm" required style="width:260px">';
            echo '</div>';
            echo '<button type="submit" class="btn btn-primary btn-sm">Создать муниципалитет</button>';
            echo '</form></details>';
        }

        echo '</div>'; // card-body
        echo '</div>'; // card
    }
}

// ---- Модальное окно «Перевести всех участников» ----
echo '
<div class="modal fade" id="moveOrgModal" tabindex="-1" role="dialog" aria-labelledby="moveOrgModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="moveOrgModalLabel">Перевести всех участников</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action"   value="move_members">
        <input type="hidden" name="type"     value="org">
        <input type="hidden" name="sesskey"  value="' . sesskey() . '">
        <input type="hidden" name="from_org_id" id="moveFromOrgId" value="">
        <div class="modal-body">
          <p>Организация-источник: <strong id="moveOrgName"></strong></p>
          <div class="form-group">
            <label for="moveToOrgId">Перевести в организацию</label>
            <select name="to_org_id" id="moveToOrgId" class="form-control" required>';

foreach ($all_orgs_grouped as $oid => $olabel) {
    echo '<option value="' . $oid . '">' . s($olabel) . '</option>';
}

echo '            </select>
          </div>
          <p class="text-warning small">Все участники исходной организации будут переведены в выбранную.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
          <button type="submit" class="btn btn-info">Перевести</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById("moveOrgModal").addEventListener("show.bs.modal", function(e) {
    var btn = e.relatedTarget;
    document.getElementById("moveFromOrgId").value = btn.getAttribute("data-org-id");
    document.getElementById("moveOrgName").textContent = btn.getAttribute("data-org-name");
    // Скрыть саму исходную организацию из списка
    var sel = document.getElementById("moveToOrgId");
    var srcId = btn.getAttribute("data-org-id");
    for (var i = 0; i < sel.options.length; i++) {
        sel.options[i].hidden = (sel.options[i].value == srcId);
    }
    // Выбрать первый не скрытый вариант
    for (var i = 0; i < sel.options.length; i++) {
        if (!sel.options[i].hidden) { sel.selectedIndex = i; break; }
    }
});
</script>';

echo $OUTPUT->footer();
