<?php
// Сообщения УНИКС (NEW-5 / задача #4).
// Обёртка над штатным Moodle messaging: список контактов под роль
// пользователя. Сами переписки ведутся в стандартном интерфейсе
// /message/index.php?id=<userid>. Кнопка сообщений в навбаре скрыта
// темой (см. _navbar.scss), общение — отсюда. Колокольчик уведомлений
// остаётся штатным.

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
global $USER, $DB, $OUTPUT, $PAGE;

$role = local_unics_get_role_for_user((int)$USER->id);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/messenger.php'));
$PAGE->set_title('Сообщения');
$PAGE->set_heading('Сообщения');
$PAGE->set_pagelayout('standard');

/**
 * Группа контактов: ['Заголовок' => [ объекты {id, firstname, lastname, sub} ]].
 */
$groups = [];

$role_label = [
    'student'   => 'Учащийся',
    'parent'    => 'Родитель',
    'teacher'   => 'Педагог',
    'methodist' => 'Методист',
    'admin'     => 'Администратор',
];

if ($role === 'student') {
    $srec = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
    if ($srec) {
        $groups['Мои педагоги'] = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
               JOIN {user} u ON u.id = t.mdl_user_id
              WHERE ts.student_id = :sid AND u.deleted = 0
              ORDER BY u.lastname, u.firstname",
            ['sid' => $srec->id]
        );
        if (!empty($srec->class_number)) {
            $groups['Одноклассники'] = $DB->get_records_sql(
                "SELECT u.id, u.firstname, u.lastname
                   FROM {unics_students} s2
                   JOIN {user} u ON u.id = s2.mdl_user_id
                  WHERE s2.organization_id = :org
                    AND s2.class_number = :cn
                    AND s2.class_letter = :cl
                    AND s2.id <> :sid AND u.deleted = 0
                  ORDER BY u.lastname, u.firstname",
                [
                    'org' => $srec->organization_id,
                    'cn'  => $srec->class_number,
                    'cl'  => (string)($srec->class_letter ?? ''),
                    'sid' => $srec->id,
                ]
            );
        }
    }
} elseif ($role === 'parent') {
    $child_ids = $DB->get_fieldset_select('unics_parent_student', 'student_id',
        'parent_mdl_user_id = ?', [$USER->id]);
    if (!empty($child_ids)) {
        [$in, $p] = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED);
        $groups['Мои дети'] = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
               FROM {unics_students} s
               JOIN {user} u ON u.id = s.mdl_user_id
              WHERE s.id $in AND u.deleted = 0
              ORDER BY u.lastname, u.firstname", $p
        );
        $groups['Педагоги моих детей'] = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
               JOIN {user} u ON u.id = t.mdl_user_id
              WHERE ts.student_id $in AND u.deleted = 0
              ORDER BY u.lastname, u.firstname", $p
        );
    }
} elseif ($role === 'teacher') {
    $trec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    if ($trec) {
        $stud_ids = $DB->get_fieldset_select('unics_teacher_student', 'student_id',
            'teacher_id = ?', [$trec->id]);
        if (!empty($stud_ids)) {
            [$in, $p] = $DB->get_in_or_equal($stud_ids, SQL_PARAMS_NAMED);
            $groups['Мои учащиеся'] = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname
                   FROM {unics_students} s
                   JOIN {user} u ON u.id = s.mdl_user_id
                  WHERE s.id $in AND u.deleted = 0
                  ORDER BY u.lastname, u.firstname", $p
            );
            $groups['Родители моих учащихся'] = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname
                   FROM {unics_parent_student} ps
                   JOIN {user} u ON u.id = ps.parent_mdl_user_id
                  WHERE ps.student_id $in AND u.deleted = 0
                  ORDER BY u.lastname, u.firstname", $p
            );
        }
    }
} elseif ($role === 'methodist') {
    $mrec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $org  = ($mrec && $mrec->organization_id) ? (int)$mrec->organization_id : 0;
    if ($org > 0) {
        $groups['Педагоги организации'] = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
               FROM {unics_teachers} t
               JOIN {user} u ON u.id = t.mdl_user_id
              WHERE t.organization_id = :org AND u.deleted = 0
                AND u.id <> :me
              ORDER BY u.lastname, u.firstname",
            ['org' => $org, 'me' => $USER->id]
        );
        $groups['Учащиеся организации'] = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
               FROM {unics_students} s
               JOIN {user} u ON u.id = s.mdl_user_id
              WHERE s.organization_id = :org AND u.deleted = 0
              ORDER BY u.lastname, u.firstname",
            ['org' => $org]
        );
    }
}

// Допустимые для переписки id = только из контактов под роль (защита,
// чтобы во фрейм нельзя было подставить произвольного пользователя).
$allowed_ids = [];
foreach ($groups as $people) {
    foreach ($people as $p) {
        $allowed_ids[(int)$p->id] = true;
    }
}
$to = optional_param('to', 0, PARAM_INT);
if ($to && !isset($allowed_ids[$to])) {
    $to = 0;
}

echo $OUTPUT->header();

echo '<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">';
echo '<span class="badge badge-secondary">Ваша роль: '
   . s($role_label[$role] ?? $role) . '</span>';
echo html_writer::link(new moodle_url('/message/index.php'),
    'Открыть полный мессенджер Moodle',
    ['class' => 'btn btn-outline-secondary btn-sm']);
echo '</div>';

if ($role === 'admin') {
    echo $OUTPUT->notification('Для администратора список контактов не '
        . 'ограничивается ролью. Используйте полный мессенджер Moodle.', 'info');
    echo html_writer::link(new moodle_url('/message/index.php'),
        'Перейти в мессенджер', ['class' => 'btn btn-primary']);
    echo $OUTPUT->footer();
    exit;
}

$has_any = false;
foreach ($groups as $people) {
    if (!empty($people)) { $has_any = true; break; }
}

if (!$has_any) {
    echo $OUTPUT->notification('Пока нет контактов для общения. Контакты '
        . 'появляются после привязки педагогов, учащихся и родителей.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// Двухпанельный мессенджер: слева контакты под роль, справа переписка.
echo '<div class="unics-messenger">';

// --- Левая панель: контакты ---
echo '<div class="unics-messenger__contacts">';
foreach ($groups as $title => $people) {
    if (empty($people)) {
        continue;
    }
    echo '<div class="unics-messenger__group-title">' . s($title)
       . ' (' . count($people) . ')</div>';
    foreach ($people as $person) {
        $name    = trim($person->lastname . ' ' . $person->firstname);
        $initial = core_text::strtoupper(
            core_text::substr((string)$person->lastname, 0, 1)
            . core_text::substr((string)$person->firstname, 0, 1)
        );
        $active  = ((int)$person->id === $to);
        $href    = new moodle_url('/local/unics/pages/messenger.php', ['to' => $person->id]);

        echo '<a href="' . $href . '" class="unics-contact'
           . ($active ? ' unics-contact--active' : '') . '">';
        echo '<span class="unics-contact__avatar">' . s($initial) . '</span>';
        echo '<span class="unics-contact__name">' . s($name) . '</span>';
        echo '</a>';
    }
}
echo '</div>';

// --- Правая панель: переписка (штатный Moodle messaging во фрейме) ---
echo '<div class="unics-messenger__chat">';
if ($to) {
    $frame_src = (new moodle_url('/message/index.php', ['id' => $to]))->out(false);
    echo '<iframe class="unics-messenger__chat-frame" src="' . $frame_src . '" '
       . 'title="Переписка"></iframe>';
} else {
    echo '<div class="unics-messenger__placeholder">'
       . '<div><p class="mb-1"><strong>Выберите контакт слева</strong></p>'
       . '<p class="text-muted small mb-0">Переписка откроется здесь, '
       . 'не покидая страницу.</p></div></div>';
}
echo '</div>';

echo '</div>'; // .unics-messenger

echo $OUTPUT->footer();
