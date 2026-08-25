<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

class evaluate_adaptive_levels extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'УНИКС: Автоматическая коррекция уровней сложности';
    }

    public function execute(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/learning/adaptive_engine.php');

        $students = $DB->get_records('unics_students', [], '', 'id, mdl_user_id, difficulty_level');
        $changed  = 0;
        $skipped  = 0;

        foreach ($students as $student) {
            $new_level = \local_unics\learning\adaptive_engine::gate_level_change((int)$student->id);
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

        // Страховка на случай, если очередь adhoc встала ([[refresh-suggestions-task-design]]).
        // С выносом пересчета в задачу предложения перестали создаваться в запросе ребенка и
        // зависят теперь от разбора очереди - а она в этом проекте уже вставала
        // ([[reference_windows_cron_task]]). Владение при этом пишется синхронно, поэтому со
        // стороны все выглядит здоровым, и педагог просто перестает получать карточки.
        //
        // Раз в сутки прогоняем рекомендатель по тем, у кого владение вообще есть. Дубли гасит
        // has_open() в suggestion_service, лишней работы это не создает.
        $withmastery = $DB->get_fieldset_sql(
            'SELECT DISTINCT student_id FROM {unics_skill_mastery}');
        $refreshed = 0;
        foreach ($withmastery as $sid) {
            try {
                \local_unics\learning\mastery_manager::regenerate_suggestions((int)$sid);
                $refreshed++;
            } catch (\Throwable $e) {
                mtrace('  [warn] Предложения для ученика ' . (int)$sid . ' не пересчитаны: '
                    . $e->getMessage());
            }
        }
        mtrace("Предложения пересчитаны для учащихся: {$refreshed}.");
    }
}
