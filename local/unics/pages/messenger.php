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

// M1: групповые беседы класса (с педагогом). Для учащегося - его класс,
// для педагога - все классы, где у него есть ученики. Заодно лениво создаёт/
// синхронизирует беседы. Для родителя/методиста/админа - пусто.
$class_convs = \local_unics\social\class_chat_manager::get_user_class_conversations((int)$USER->id, $role);

// Допустимые для переписки id = только из контактов под роль (защита,
// чтобы во фрейм нельзя было подставить произвольного пользователя).
$allowed_ids = [];
foreach ($groups as $people) {
    foreach ($people as $p) {
        $allowed_ids[(int)$p->id] = true;
    }
}
$allowed_conv_ids = [];
foreach ($class_convs as $cc) {
    $allowed_conv_ids[(int)$cc->convid] = true;
}
$to = optional_param('to', 0, PARAM_INT);
if ($to && !isset($allowed_ids[$to])) {
    $to = 0;
}
// conv = id групповой беседы класса (взаимоисключающе с to).
$conv = optional_param('conv', 0, PARAM_INT);
if ($conv && !isset($allowed_conv_ids[$conv])) {
    $conv = 0;
}
if ($conv) {
    $to = 0;
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();

echo '<div class="mt-3 mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">';
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

$has_any = !empty($class_convs);
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

// Беседы класса - отдельной группой вверху списка.
if (!empty($class_convs)) {
    echo '<div class="unics-messenger__group-title">Беседы класса ('
       . count($class_convs) . ')</div>';
    foreach ($class_convs as $cc) {
        $cactive = ((int)$cc->convid === $conv);
        $chref   = new moodle_url('/local/unics/pages/messenger.php', ['conv' => $cc->convid]);
        echo '<a href="' . $chref . '" class="unics-contact unics-contact--class'
           . ($cactive ? ' unics-contact--active' : '') . '" data-convid="' . (int)$cc->convid . '"'
           . ($cactive ? ' aria-current="true"' : '') . '>';
        // Значок беседы: ядровая иконка вместо эмодзи (в UI эмодзи не держим). Наследует
        // color от .unics-contact__avatar, поэтому правки контраста на нем работают как есть.
        echo '<span class="unics-contact__avatar" aria-hidden="true">'
           . $OUTPUT->pix_icon('i/cohort', '') . '</span>';
        echo '<span class="unics-contact__name">' . s($cc->name)
           . ' <span class="text-muted">(' . (int)$cc->count . ')</span></span>';
        // Бейдж непрочитанного (M2.2) - наполняется JS из get_conversations.
        echo '<span class="unics-contact__badge" hidden></span>';
        echo '</a>';
    }
}

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
           . ($active ? ' unics-contact--active' : '') . '" data-userid="' . (int)$person->id . '"'
           . ($active ? ' aria-current="true"' : '') . '>';
        echo '<span class="unics-contact__avatar">' . s($initial) . '</span>';
        echo '<span class="unics-contact__name">' . s($name) . '</span>';
        // Бейдж непрочитанного (M2.2) - наполняется JS из get_conversations.
        echo '<span class="unics-contact__badge" hidden></span>';
        echo '</a>';
    }
}
echo '</div>';

// --- Правая панель: переписка (собственный UI, M2.1) ---
// Заменяет iframe штатного виджета: чтение и отправка через внешние функции
// core_message_* (ajax => true) из AMD-модуля messenger_app. См.
// [[messenger-ui-design]].
echo '<div class="unics-messenger__chat">';

// Метаданные активной беседы (одна на загрузку: либо группа $conv, либо личный $to).
$active_convid    = 0;
$active_userid    = 0;
$active_isgroup   = 0;
$active_name      = '';
$active_initials  = '';
$active_membercnt = 0;

if ($conv) {
    // Беседа класса - имя/счётчик уже посчитаны в $class_convs.
    $active_convid  = $conv;
    $active_isgroup = 1;
    foreach ($class_convs as $cc) {
        if ((int)$cc->convid === $conv) {
            $active_name      = (string)$cc->name;
            $active_membercnt = (int)$cc->count;
            break;
        }
    }
} else if ($to) {
    // Личная переписка - резолвим существующую беседу (может ещё не быть -> 0).
    $active_userid = $to;
    $existing = \core_message\api::get_conversation_between_users([(int)$USER->id, $to]);
    $active_convid = $existing ? (int)$existing : 0;
    // Имя/инициалы из уже загруженных контактов ($groups keyed by user id).
    foreach ($groups as $people) {
        if (isset($people[$to])) {
            $p = $people[$to];
            $active_name     = trim($p->lastname . ' ' . $p->firstname);
            $active_initials = core_text::strtoupper(
                core_text::substr((string)$p->lastname, 0, 1)
                . core_text::substr((string)$p->firstname, 0, 1));
            break;
        }
    }
}

if ($active_convid || $active_userid) {
    // Заголовок беседы.
    echo '<div class="unics-messenger__thread-header">';
    if ($active_isgroup) {
        echo '<span class="unics-contact__avatar unics-contact__avatar--group" '
           . 'aria-hidden="true">' . $OUTPUT->pix_icon('i/cohort', '') . '</span>';
    } else {
        echo '<span class="unics-contact__avatar">' . s($active_initials) . '</span>';
    }
    echo '<span class="unics-messenger__thread-title">' . s($active_name);
    if ($active_isgroup && $active_membercnt) {
        echo ' <span class="unics-messenger__thread-sub">('
           . $active_membercnt . ' участников)</span>';
    }
    echo '</span>';
    echo '</div>';

    // Кнопка подгрузки более ранних сообщений (M2.3). JS показывает её, когда на
    // загрузке пришла полная страница (есть что грузить выше); скролл сохраняется.
    echo '<button type="button" id="unics-thread-earlier" '
       . 'class="unics-messenger__earlier" hidden>Показать ранние сообщения</button>';

    // Лента сообщений - наполняется JS.
    echo '<div id="unics-thread" class="unics-messenger__thread" role="log" '
       . 'aria-live="polite" aria-relevant="additions" '
       . 'aria-label="Сообщения беседы" tabindex="0">';
    echo '<div class="unics-messenger__state">Загрузка сообщений...</div>';
    echo '</div>';

    // Плашка «новые сообщения» (M2.2): показывается JS, когда при поллинге пришли
    // новые сообщения, а пользователь прокручен вверх. Клик - вниз ленты.
    echo '<button type="button" id="unics-thread-jump" '
       . 'class="unics-messenger__jump" hidden>'
       . 'Новые сообщения <span aria-hidden="true">&#8595;</span></button>';

    // Панель быстрого ввода (M2.3, Q3): готовые фразы для детей с ОВЗ, кому трудно
    // набирать. Только ученику. Без эмодзи (предпочтение пользователя). Клик
    // вставляет фразу в поле ввода (JS, делегирование по .unics-quick-btn).
    if ($role === 'student') {
        $quick_phrases = [
            'Здравствуйте!',
            'Спасибо!',
            'Не понял(а), повторите, пожалуйста',
            'Мне нужна помощь',
            'У меня получилось!',
            'Можно вопрос?',
            'До свидания!',
        ];
        echo '<div class="unics-messenger__quick" role="group" aria-label="Быстрые фразы">';
        foreach ($quick_phrases as $phrase) {
            echo '<button type="button" class="unics-quick-btn" data-insert="'
               . s($phrase) . '">' . s($phrase) . '</button>';
        }
        echo '</div>';
    }

    // Композер. Решение Q2: отправка ТОЛЬКО кнопкой, Enter = новая строка
    // (без случайных отправок - безопаснее для детей с ОВЗ).
    echo '<form id="unics-composer" class="unics-messenger__composer" autocomplete="off">';
    echo '<textarea id="unics-composer-input" class="unics-messenger__input form-control" '
       . 'rows="2" aria-label="Новое сообщение" '
       . 'placeholder="Напишите сообщение и нажмите кнопку Отправить"></textarea>';
    echo '<button type="submit" id="unics-composer-send" '
       . 'class="btn btn-primary unics-cta unics-messenger__send">Отправить</button>';
    echo '</form>';
} else {
    echo '<div class="unics-messenger__placeholder">'
       . '<div><p class="mb-1"><strong>Выберите беседу слева</strong></p>'
       . '<p class="text-muted small mb-0">Переписка откроется здесь, '
       . 'не покидая страницу.</p></div></div>';
}
echo '</div>';

echo '</div>'; // .unics-messenger

// AMD-клиент запускаем ВСЕГДА, когда показан мессенджер: даже без открытой беседы
// он поллит бейджи непрочитанного по левому списку (get_conversations). Если беседа
// открыта - дополнительно ведёт ленту + живой поллинг активной беседы (M2.2).
$PAGE->requires->js_call_amd('local_unics/messenger_app', 'init', [[
    'currentuserid' => (int)$USER->id,
    'convid'        => $active_convid,
    'userid'        => $active_userid,
    'isgroup'       => $active_isgroup,
]]);

// Микрофон (M2.3): переиспользуем voice_input (A1) - он навешивает кнопку диктовки
// на textarea композера. Только ученику и только если админ включил голосовой ввод
// (тот же тумблер, что на заданиях/тестах). Web Speech на стороне браузера.
if ($role === 'student' && get_config('local_unics', 'voice_input_enabled')) {
    $PAGE->requires->js_call_amd('local_unics/voice_input', 'init', []);
}

echo $OUTPUT->footer();
