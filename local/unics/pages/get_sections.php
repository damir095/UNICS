<?php
require_once(__DIR__ . '/../../../config.php');
require_login();

if (!has_capability('local/unics:viewstudents', context_system::instance())) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Защита от подделки запроса: токен сессии. Вызывающий JS (generate_umk.php)
// передаёт sesskey в URL.
if (!confirm_sesskey()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid sesskey']);
    exit;
}

$course_id = required_param('course_id', PARAM_INT);

// То же правило, что и на странице генерации: без него эндпоинт отдавал структуру ЛЮБОГО курса
// сайта в обход гейта страницы. Предикат общий (lib.php), чтобы страница и эндпоинт не
// разъехались снова.
require_once(__DIR__ . '/../lib.php');
if (!local_unics_can_build_in_course($course_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

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
