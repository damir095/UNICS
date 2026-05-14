<?php
/**
 * Возвращает JSON со списком уведомлений из unics_notifications для текущего пользователя,
 * созданных после переданной метки времени `since` (UNIX seconds).
 *
 * Используется AMD-модулем local_unics/toast_poller для всплывающих toast'ов.
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

require_login();
global $USER, $DB;

$since = optional_param('since', 0, PARAM_INT);
$now   = time();

// Защита от бесконечно большого lookback - максимум 5 минут назад.
$min_since = $now - 300;
if ($since < $min_since) {
    $since = $min_since;
}

$rows = $DB->get_records_sql(
    "SELECT id, notif_type, subject, created_at
       FROM {unics_notifications}
      WHERE mdl_user_id = :uid
        AND created_at  > :since
      ORDER BY created_at ASC",
    ['uid' => (int)$USER->id, 'since' => $since]
);

// notif_type → toast variant.
$type_map = [
    1 => 'success',  // TYPE_UMK_READY
    2 => 'warning',  // TYPE_LOW_SCORE
    3 => 'info',     // TYPE_NEW_ASSIGN
    4 => 'success',  // TYPE_LEVEL_UP
    5 => 'warning',  // TYPE_LEVEL_DOWN
    6 => 'success',  // TYPE_BADGE_EARNED
    7 => 'info',     // TYPE_NEW_COMMENT
];

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'         => (int)$r->id,
        'type'       => $type_map[(int)$r->notif_type] ?? 'info',
        'subject'    => $r->subject,
        'created_at' => (int)$r->created_at,
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'notifications' => $out,
    'now'           => $now,
]);
