<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Декларативные кеш-определения плагина (этап 3.3 аудита).
 *
 * identity - запросный (MODE_REQUEST): дедуплицирует identity-чтения
 * (запись ученика / роли по shortname / признак родителя), которые рельс,
 * навигация и гарды спрашивают по несколько раз за одну страницу.
 * Живёт в пределах HTTP-запроса - межзапросной инвалидации не требует.
 */
$definitions = [
    'identity' => [
        'mode'       => cache_store::MODE_REQUEST,
        'simplekeys' => true,
        'simpledata' => false,
    ],
];
