<?php
require_once(__DIR__ . '/../../../config.php');
require_login();

if (!has_capability('local/unics:viewstudents', context_system::instance())) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$course_id = required_param('course_id', PARAM_INT);

$rows = $DB->get_records_sql(
    "SELECT section, name FROM {course_sections}
      WHERE course = :course
      ORDER BY section ASC",
    ['course' => $course_id]
);

$result = [];
foreach ($rows as $r) {
    $name = !empty(trim($r->name))
        ? trim($r->name)
        : ($r->section == 0 ? 'Введение (раздел 0)' : "Раздел {$r->section}");
    $result[] = ['section' => (int)$r->section, 'name' => $name];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
