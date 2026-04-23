<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

class process_ai_queue extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'УНИКС: Обработка очереди генерации УМК';
    }

    public function execute(): void {
        global $DB;

        $generator = new \local_unics\ai_generator();
        $builder   = new \local_unics\course_builder();

        // Берём до 5 задач со статусом "ожидает"
        $tasks = $DB->get_records(
            'unics_ai_queue', ['status' => 1], 'created_at ASC', '*', 0, 5
        );

        foreach ($tasks as $task) {
            $DB->set_field('unics_ai_queue', 'status', 2, ['id' => $task->id]);

            try {
                $umk     = $DB->get_record('unics_umk', ['id' => $task->umk_id], '*', MUST_EXIST);
                $student = $DB->get_record('unics_students', ['id' => $umk->student_id], '*', MUST_EXIST);

                $avg_score = $generator->get_avg_score((int)$student->mdl_user_id);
                $base_level = (int)$student->difficulty_level;
                $eff_level  = $generator->adapt_level($base_level, $avg_score);

                $profile = [
                    'category'         => (int)$student->category,
                    'difficulty_level' => $eff_level,
                    'class_number'     => (int)($student->class_number ?? 5),
                    'class_letter'     => $student->class_letter ?? '',
                    'ovz_type'         => (int)($student->ovz_type ?? 0),
                    'special_needs'    => $student->special_needs ?? '',
                    'avg_score'        => $avg_score,
                ];

                // 1. Генерация текста
                $extra_context = isset($umk->extra_prompt) ? (string)$umk->extra_prompt : '';
                $prompt = $generator->build_prompt($profile, $umk->topic, $extra_context);
                $text   = $generator->generate_text($prompt);

                // Секция по теме урока: общая для всех учащихся с одинаковой темой
                $section   = $builder->get_or_create_topic_section((int)$umk->mdl_course_id, $umk->topic);
                $text_cmid = $builder->add_text_page(
                    (int)$umk->mdl_course_id,
                    $section,
                    $umk->title,
                    $text
                );
                $builder->restrict_activity_to_student_group(
                    $text_cmid, (int)$umk->mdl_course_id, (int)$student->mdl_user_id
                );

                $DB->insert_record('unics_umk_materials', (object)[
                    'umk_id'               => $umk->id,
                    'mdl_course_module_id' => $text_cmid,
                    'material_type'        => 1,
                    'sort_order'           => 1,
                ]);

                // 2. Генерация аудио (если запрошено)
                if ($task->generate_audio) {
                    $audio = $generator->generate_audio($text);
                    $audio_cmid = $builder->add_audio_resource(
                        (int)$umk->mdl_course_id,
                        $section,
                        $umk->title,
                        $audio,
                        $generator->get_audio_ext()
                    );
                    $builder->restrict_activity_to_student_group(
                        $audio_cmid, (int)$umk->mdl_course_id, (int)$student->mdl_user_id
                    );

                    $DB->insert_record('unics_umk_materials', (object)[
                        'umk_id'               => $umk->id,
                        'mdl_course_module_id' => $audio_cmid,
                        'material_type'        => 3,
                        'sort_order'           => 2,
                    ]);
                }

                // 3. Автозапись учащегося на курс
                self::enrol_student($student->mdl_user_id, (int)$umk->mdl_course_id);

                $DB->set_field('unics_umk', 'status', 3, ['id' => $umk->id]);
                $DB->update_record('unics_ai_queue', (object)[
                    'id'           => $task->id,
                    'status'       => 3,
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);

                mtrace("UMK #{$umk->id} «{$umk->title}» — готов. Уровень: {$base_level}→{$eff_level}, секция: {$section}");

            } catch (\Throwable $e) {
                $DB->update_record('unics_ai_queue', (object)[
                    'id'            => $task->id,
                    'status'        => 4,
                    'error_message' => $e->getMessage(),
                    'processed_at'  => date('Y-m-d H:i:s'),
                ]);
                $DB->set_field('unics_umk', 'status', 4, ['id' => $task->umk_id]);
                mtrace("UMK #{$task->umk_id} — ошибка: " . $e->getMessage());
            }
        }
    }

    /**
     * Записать пользователя на курс через метод 'manual', если ещё не записан.
     */
    private static function enrol_student(int $mdl_user_id, int $course_id): void {
        global $DB;

        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            mtrace("  [warn] плагин записи 'manual' недоступен");
            return;
        }

        $instance = $DB->get_record('enrol', [
            'courseid'  => $course_id,
            'enrol'     => 'manual',
            'status'    => 0,
        ]);

        if (!$instance) {
            // Создаём экземпляр manual enrol для курса
            $course = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);
            $enrol->add_default_instance($course);
            $instance = $DB->get_record('enrol', [
                'courseid' => $course_id,
                'enrol'    => 'manual',
                'status'   => 0,
            ]);
        }

        if (!$instance) {
            mtrace("  [warn] не удалось получить экземпляр manual enrol для курса #{$course_id}");
            return;
        }

        // Проверяем — вдруг уже записан
        if (!is_enrolled(\context_course::instance($course_id), $mdl_user_id)) {
            // Роль student в Moodle
            $student_role = $DB->get_record('role', ['shortname' => 'student'], 'id');
            $role_id = $student_role ? (int)$student_role->id : 5;
            $enrol->enrol_user($instance, $mdl_user_id, $role_id);
            mtrace("  Учащийся #{$mdl_user_id} записан на курс #{$course_id}");
        }
    }
}
