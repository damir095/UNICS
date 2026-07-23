<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_unics\observer::course_module_deleted',
    ],
    [
        // B7: реагируем на attempt_graded (грейд уже посчитан), не на attempt_submitted.
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback'  => '\local_unics\observer::quiz_attempt_graded',
    ],
    [
        // Каскадная зачистка course-привязанных данных unics_* (см. cleanup).
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_unics\observer::course_deleted',
    ],
    [
        // Зачистка данных пользователя в unics_* при удалении ядром (этап 1.2 аудита).
        'eventname' => '\core\event\user_deleted',
        'callback'  => '\local_unics\observer::user_deleted',
    ],
    [
        // Ручное добавление активности (этап 6.1 роадмапа) - ставит adhoc-задачу
        // уведомления, если модуль видим сразу и это quiz/assign; УМК-модули
        // создаются скрытыми.
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\local_unics\observer::course_module_created',
    ],
];
