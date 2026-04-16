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
                $umk = $DB->get_record('unics_umk', ['id' => $task->umk_id], '*', MUST_EXIST);
                $student = $DB->get_record('unics_students', ['id' => $umk->student_id], '*', MUST_EXIST);

                $profile = [
                    'category'         => (int)$student->category,
                    'difficulty_level' => (int)$student->difficulty_level,
                    'class_number'     => (int)($student->class_number ?? 5),
                    'avg_score'        => $generator->get_avg_score((int)$student->mdl_user_id),
                ];

                // 1. Генерация текста через Gemini
                $prompt = $generator->build_prompt($profile, $umk->topic);
                $text   = $generator->generate_text($prompt);

                $section = max(1, (int)$student->difficulty_level);
                $text_cmid = $builder->add_text_page(
                    (int)$umk->mdl_course_id,
                    $section,
                    $umk->title,
                    $text
                );

                $DB->insert_record('unics_umk_materials', (object)[
                    'umk_id'               => $umk->id,
                    'mdl_course_module_id' => $text_cmid,
                    'material_type'        => 1,
                    'sort_order'           => 1,
                ]);

                // 2. Генерация аудио через VoiceRSS (если запрошено)
                if ($task->generate_audio) {
                    $audio = $generator->generate_audio($text);
                    $audio_cmid = $builder->add_audio_resource(
                        (int)$umk->mdl_course_id,
                        $section,
                        $umk->title,
                        $audio
                    );

                    $DB->insert_record('unics_umk_materials', (object)[
                        'umk_id'               => $umk->id,
                        'mdl_course_module_id' => $audio_cmid,
                        'material_type'        => 3,
                        'sort_order'           => 2,
                    ]);
                }

                $DB->set_field('unics_umk', 'status', 2, ['id' => $umk->id]);
                $DB->update_record('unics_ai_queue', (object)[
                    'id'           => $task->id,
                    'status'       => 3,
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);

                mtrace("UMK #{$umk->id} «{$umk->title}» — готов.");

            } catch (\Throwable $e) {
                $DB->update_record('unics_ai_queue', (object)[
                    'id'            => $task->id,
                    'status'        => 4,
                    'error_message' => $e->getMessage(),
                    'processed_at'  => date('Y-m-d H:i:s'),
                ]);
                $DB->set_field('unics_umk', 'status', 3, ['id' => $task->umk_id]);
                mtrace("UMK #{$task->umk_id} — ошибка: " . $e->getMessage());
            }
        }
    }
}
