<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

class evaluate_adaptive_levels extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'УНИКС: Автоматическая коррекция уровней сложности';
    }

    public function execute(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/adaptive_engine.php');

        $students = $DB->get_records('unics_students', [], '', 'id, mdl_user_id, difficulty_level');
        $changed  = 0;
        $skipped  = 0;

        foreach ($students as $student) {
            $new_level = \local_unics\adaptive_engine::evaluate_student((int)$student->id);
            if ($new_level !== null) {
                $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
                $old_label   = $level_names[$student->difficulty_level] ?? $student->difficulty_level;
                $new_label   = $level_names[$new_level] ?? $new_level;
                mtrace("  Учащийся #{$student->id}: {$old_label} → {$new_label}");
                $changed++;
            } else {
                $skipped++;
            }
        }

        mtrace("Адаптация завершена: изменено {$changed}, без изменений / мало данных {$skipped}.");
    }
}
