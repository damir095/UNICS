<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

class process_ai_queue extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'УНИКС: Обработка очереди генерации УМК';
    }

    public function execute(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        require_once($CFG->dirroot . '/local/unics/classes/social/notification_manager.php');
        require_once($CFG->dirroot . '/local/unics/classes/social/achievement_manager.php');
        require_once($CFG->dirroot . '/local/unics/classes/social/points_manager.php');
        require_once($CFG->dirroot . '/group/lib.php');

        $generator = new \local_unics\ai\ai_generator();
        $builder   = new \local_unics\ai\course_builder();

        // Review-гейт УМК: материалы создаются скрытыми (visible=0). Ученик не видит
        // их, пока педагог не нажмёт «Опубликовать» на umk_status.php. Секции остаются
        // видимыми, чтобы педагог видел структуру.
        $builder->set_default_visibility(0);

        $tasks = $DB->get_records(
            'unics_ai_queue', ['status' => 1], 'created_at ASC', '*', 0, 15
        );

        foreach ($tasks as $task) {
            $DB->set_field('unics_ai_queue', 'status', 2, ['id' => $task->id]);

            try {
                $umk = $DB->get_record('unics_umk', ['id' => $task->umk_id], '*', MUST_EXIST);

                // --- Список учащихся для этого УМК ---
                $student_ids = [];
                if (!empty($task->student_ids)) {
                    $student_ids = json_decode($task->student_ids, true) ?? [];
                }
                $student_ids = array_filter(array_map('intval', $student_ids));

                if (empty($student_ids)) {
                    throw new \moodle_exception('Список учащихся пуст для UMK #' . $umk->id);
                }

                // --- Репрезентативный профиль: первый учащийся + уровень из UMK ---
                $first_student = null;
                foreach ($student_ids as $sid) {
                    $first_student = $DB->get_record('unics_students', ['id' => $sid]);
                    if ($first_student) break;
                }
                if (!$first_student) {
                    throw new \moodle_exception('Не найден ни один учащийся для UMK #' . $umk->id);
                }

                $umk_level  = (int)$umk->difficulty_level;
                $avg_score  = $generator->get_avg_score((int)$first_student->mdl_user_id);

                $cats_arr = \local_unics\identity\student_helper::get_categories($first_student);
                $ovz_arr  = \local_unics\identity\student_helper::get_ovz_types($first_student);

                $profile = [
                    // Бэк-компат - первая категория как скаляр.
                    'category'         => $cats_arr[0] ?? 2,
                    // Полные массивы - ai_generator решает, как использовать.
                    'categories'       => $cats_arr,
                    'ovz_types'        => $ovz_arr,
                    'difficulty_level' => $umk_level,
                    'class_number'     => (int)($first_student->class_number ?? 5),
                    'class_letter'     => $first_student->class_letter ?? '',
                    'ovz_type'         => $ovz_arr[0] ?? 0,
                    'special_needs'    => $first_student->special_needs ?? '',
                    'avg_score'        => $avg_score,
                ];

                // --- 1. Генерация текста ---
                $extra_context = isset($umk->extra_prompt) ? (string)$umk->extra_prompt : '';
                $prompt = $generator->build_prompt($profile, $umk->topic, $extra_context);
                $text   = $generator->generate_text($prompt);

                // --- Целевая секция ---
                if ((int)$umk->target_section >= 0) {
                    $section = (int)$umk->target_section;
                } else {
                    $section = $builder->get_or_create_topic_section((int)$umk->mdl_course_id, $umk->topic);
                }

                // --- Создаём группу уровня ---
                $group_id = $builder->get_or_create_level_group(
                    (int)$umk->mdl_course_id,
                    $umk_level,
                    $umk->topic
                );
                $DB->set_field('unics_umk', 'mdl_group_id', $group_id, ['id' => $umk->id]);

                // --- Текстовая страница ---
                $text_cmid = $builder->add_text_page(
                    (int)$umk->mdl_course_id,
                    $section,
                    $umk->title,
                    $text
                );
                $builder->restrict_activity_to_group($text_cmid, $group_id);
                $builder->set_view_completion($text_cmid); // B1/B8

                // B1: материалы темы для гейта теста (текст всегда + аудио/видео ниже).
                $material_cmids = [$text_cmid];
                $quiz_cmid_gate = null;

                $DB->insert_record('unics_umk_materials', (object)[
                    'umk_id'               => $umk->id,
                    'mdl_course_module_id' => $text_cmid,
                    'material_type'        => 1,
                    'sort_order'           => 1,
                ]);

                // --- 2. Аудио ---
                if ($task->generate_audio) {
                    $audio = $generator->generate_audio($text);
                    $audio_cmid = $builder->add_audio_resource(
                        (int)$umk->mdl_course_id,
                        $section,
                        $umk->title,
                        $audio,
                        $generator->get_audio_ext()
                    );
                    $builder->restrict_activity_to_group($audio_cmid, $group_id);
                    $builder->set_view_completion($audio_cmid); // B1/B8
                    $material_cmids[] = $audio_cmid;

                    $DB->insert_record('unics_umk_materials', (object)[
                        'umk_id'               => $umk->id,
                        'mdl_course_module_id' => $audio_cmid,
                        'material_type'        => 3,
                        'sort_order'           => 2,
                    ]);
                }

                // --- 3. Тест (нефатальный) ---
                $generate_quiz = isset($task->generate_quiz) ? (int)$task->generate_quiz : 1;
                if ($generate_quiz) {
                    try {
                        $questions = $generator->generate_quiz($profile, $umk->topic, $text);
                        $quiz_cmid = $builder->add_quiz_with_questions(
                            (int)$umk->mdl_course_id,
                            $section,
                            $umk->title,
                            $questions
                        );
                        $builder->restrict_activity_to_group($quiz_cmid, $group_id);
                        $builder->set_view_completion($quiz_cmid); // B8 (тест входит в завершение курса)
                        $quiz_cmid_gate = $quiz_cmid;               // B1: гейт навесим после сборки материалов
                        $DB->insert_record('unics_umk_materials', (object)[
                            'umk_id'               => $umk->id,
                            'mdl_course_module_id' => $quiz_cmid,
                            'material_type'        => 4,
                            'sort_order'           => 3,
                        ]);
                        mtrace("  Тест создан (" . count($questions) . " вопросов)");
                    } catch (\Throwable $eq) {
                        $dbg = property_exists($eq, 'debuginfo') ? ' | ' . $eq->debuginfo : '';
                        mtrace("  [warn] Тест не создан: " . $eq->getMessage() . $dbg);
                    }
                }

                // --- 4. Задание (нефатальный) ---
                $generate_assignment = isset($task->generate_assignment) ? (int)$task->generate_assignment : 0;
                if ($generate_assignment) {
                    try {
                        $assign_desc = $generator->generate_assignment_description($profile, $umk->topic, $text);
                        $assign_cmid = $builder->add_assignment(
                            (int)$umk->mdl_course_id,
                            $section,
                            $umk->title . ' - задание',
                            $assign_desc
                        );
                        $builder->restrict_activity_to_group($assign_cmid, $group_id);
                        $builder->set_view_completion($assign_cmid); // B8
                        $DB->insert_record('unics_umk_materials', (object)[
                            'umk_id'               => $umk->id,
                            'mdl_course_module_id' => $assign_cmid,
                            'material_type'        => 5,
                            'sort_order'           => 4,
                        ]);
                        mtrace("  Задание создано");
                    } catch (\Throwable $ea) {
                        mtrace("  [warn] Задание не создано: " . $ea->getMessage());
                    }
                }

                // --- 5. Видеопрезентация (нефатальный) ---
                $generate_video = isset($task->generate_video) ? (int)$task->generate_video : 0;
                if ($generate_video) {
                    try {
                        $slides = $generator->generate_video_script($profile, $umk->topic, $text);

                        $slide_audios = [];
                        $salute_key = get_config('local_unics', 'salute_speech_api_key');
                        if (!empty($salute_key)) {
                            foreach ($slides as $i => $slide) {
                                $slide_text = $generator->strip_for_tts($slide['content']);
                                try {
                                    $slide_audios[$i] = $generator->generate_audio($slide_text);
                                } catch (\Throwable $ea) {
                                    mtrace("  [warn] Аудио слайда " . ($i + 1) . " не создано: " . $ea->getMessage());
                                    $slide_audios[$i] = '';
                                }
                            }
                        }

                        $slide_images = [];
                        $ai_key = get_config('local_unics', 'ai_api_key');
                        if (!empty($ai_key)) {
                            foreach ($slides as $i => $slide) {
                                try {
                                    $img_prompt = 'Нарисуй образовательную иллюстрацию для школьного урока на тему «'
                                        . $slide['title'] . '». Стиль: чистый, минималистичный, яркий. Без подписей и текста на изображении.';
                                    $slide_images[$i] = $generator->generate_image($img_prompt);
                                } catch (\Throwable $ei) {
                                    mtrace("  [warn] Изображение слайда " . ($i + 1) . " не создано: " . $ei->getMessage());
                                    $slide_images[$i] = '';
                                }
                            }
                        }

                        $img_count = count(array_filter($slide_images, fn($img) => $img !== ''));

                        $video_cmid = $builder->add_video_slideshow(
                            (int)$umk->mdl_course_id,
                            $section,
                            $umk->title,
                            $slides,
                            $slide_audios,
                            $slide_images
                        );
                        $builder->restrict_activity_to_group($video_cmid, $group_id);
                        $builder->set_view_completion($video_cmid); // B1/B8
                        $material_cmids[] = $video_cmid;
                        $DB->insert_record('unics_umk_materials', (object)[
                            'umk_id'               => $umk->id,
                            'mdl_course_module_id' => $video_cmid,
                            'material_type'        => 2,
                            'sort_order'           => 5,
                        ]);
                        $audio_count = count(array_filter($slide_audios, fn($a) => $a !== ''));
                        mtrace("  Видеопрезентация создана (" . count($slides) . " слайдов, аудио: {$audio_count}, изображения: {$img_count})");
                    } catch (\Throwable $ev) {
                        mtrace("  [warn] Видео не создано: " . $ev->getMessage());
                    }
                }

                // --- B1: гейт «материал освоен» — тест доступен после просмотра
                // материалов темы (текст/аудио/видео). Навешиваем после сборки всех
                // материалов, т.к. видео создаётся позже теста. ---
                if ($quiz_cmid_gate !== null) {
                    $builder->gate_quiz_on_materials($quiz_cmid_gate, $group_id, $material_cmids);
                    mtrace("  Гейт теста: доступен после " . count($material_cmids) . " материал(ов)");
                }

                // --- 6. Обработка каждого учащегося ---
                $enrolled_count = 0;
                foreach ($student_ids as $sid) {
                    $student = $DB->get_record('unics_students', ['id' => $sid]);
                    if (!$student) continue;

                    // Адаптация уровня для каждого учащегося
                    $s_avg   = $generator->get_avg_score((int)$student->mdl_user_id);
                    $s_base  = (int)$student->difficulty_level;
                    $s_eff   = $generator->adapt_level($s_base, $s_avg);
                    if ($s_eff !== $s_base) {
                        $DB->set_field('unics_students', 'difficulty_level', $s_eff, ['id' => $student->id]);
                        \unics_user_manager::set_student_level((int)$student->mdl_user_id, $s_eff);
                        mtrace("  Уровень учащегося #{$student->id}: {$s_base}→{$s_eff}");
                    }

                    // Добавляем в группу уровня
                    if (!groups_is_member($group_id, (int)$student->mdl_user_id)) {
                        groups_add_member($group_id, (int)$student->mdl_user_id);
                    }

                    // Запись на курс
                    self::enrol_student((int)$student->mdl_user_id, (int)$umk->mdl_course_id);
                    $enrolled_count++;

                    // Привязка учащегося к УМК (игнорируем дубликаты)
                    if (!$DB->record_exists('unics_umk_students', ['umk_id' => $umk->id, 'student_id' => $sid])) {
                        $DB->insert_record('unics_umk_students', (object)[
                            'umk_id'     => $umk->id,
                            'student_id' => $sid,
                        ]);
                    }

                    // Проверка достижений
                    try {
                        $new_badges = \local_unics\social\achievement_manager::evaluate_student(
                            (int)$student->id,
                            (int)$student->mdl_user_id
                        );
                        if ($new_badges) {
                            mtrace("  Значки учащегося #{$student->id}: +" . implode(', ', $new_badges));
                        }
                    } catch (\Throwable $eb) {
                        mtrace("  [warn] Достижения не обновлены: " . $eb->getMessage());
                    }

                    // Баллы за УМК + уведомление учащемуся «материал готов» перенесены
                    // на момент публикации (review-гейт): пока материал скрыт, ученик
                    // о нём не знает и баллов не получает. См. umk_status.php (publish).

                    // Уведомление педагогу: низкий балл (< 50%)
                    if ($s_avg < 50) {
                        try {
                            $s_user = $DB->get_record('user', ['id' => $student->mdl_user_id]);
                            $sname  = $s_user ? fullname($s_user) : 'Учащийся #' . $student->id;
                            $teachers = $DB->get_records_sql(
                                "SELECT t.mdl_user_id FROM {unics_teacher_student} ts
                                  JOIN {unics_teachers} t ON t.id = ts.teacher_id
                                 WHERE ts.student_id = :sid",
                                ['sid' => $student->id]
                            );
                            foreach ($teachers as $tl) {
                                \local_unics\social\notification_manager::notify_low_score(
                                    (int)$tl->mdl_user_id,
                                    $sname,
                                    $s_avg,
                                    (int)$student->id
                                );
                            }
                        } catch (\Throwable $en) {
                            mtrace("  [warn] Уведомление педагогу о низком балле: " . $en->getMessage());
                        }
                    }
                }

                // Уведомление педагогам учащихся: УМК на проверке (review-гейт).
                // Материал скрыт; педагог проверяет и публикует на umk_status.php.
                try {
                    $course_rec   = $DB->get_record('course', ['id' => $umk->mdl_course_id]);
                    $course_name  = $course_rec ? $course_rec->fullname : '';
                    [$tin, $tparams] = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED, 'sid');
                    $review_teachers = $DB->get_records_sql(
                        "SELECT DISTINCT t.mdl_user_id
                           FROM {unics_teacher_student} ts
                           JOIN {unics_teachers} t ON t.id = ts.teacher_id
                          WHERE ts.student_id {$tin}",
                        $tparams
                    );
                    foreach ($review_teachers as $rt) {
                        \local_unics\social\notification_manager::notify_umk_review(
                            (int)$rt->mdl_user_id,
                            $umk->title,
                            $course_name,
                            $umk_level
                        );
                    }
                } catch (\Throwable $en) {
                    mtrace("  [warn] Уведомление педагогу о проверке УМК не отправлено: " . $en->getMessage());
                }

                $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
                $level_label = $level_names[$umk_level] ?? $umk_level;

                $DB->set_field('unics_umk', 'status', 3, ['id' => $umk->id]);
                $DB->update_record('unics_ai_queue', (object)[
                    'id'           => $task->id,
                    'status'       => 3,
                    'processed_at' => time(),
                ]);

                // B8: пересобрать критерии завершения курса (все активности с completion).
                try {
                    $builder->rebuild_course_completion_criteria((int)$umk->mdl_course_id);
                } catch (\Throwable $ec) {
                    mtrace("  [warn] Критерии завершения курса не пересобраны: " . $ec->getMessage());
                }

                mtrace("UMK #{$umk->id} «{$umk->title}» - готов. Уровень: {$level_label}, учащихся: {$enrolled_count}, секция: {$section}");

            } catch (\Throwable $e) {
                $DB->update_record('unics_ai_queue', (object)[
                    'id'            => $task->id,
                    'status'        => 4,
                    'error_message' => $e->getMessage(),
                    'processed_at'  => time(),
                ]);
                $DB->set_field('unics_umk', 'status', 4, ['id' => $task->umk_id]);
                mtrace("UMK #{$task->umk_id} - ошибка: " . $e->getMessage());
            }
        }
    }

    private static function enrol_student(int $mdl_user_id, int $course_id): void {
        global $DB;

        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            mtrace("  [warn] плагин записи 'manual' недоступен");
            return;
        }

        $instance = $DB->get_record('enrol', [
            'courseid' => $course_id,
            'enrol'    => 'manual',
            'status'   => 0,
        ]);

        if (!$instance) {
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

        if (!is_enrolled(\context_course::instance($course_id), $mdl_user_id)) {
            $student_role = $DB->get_record('role', ['shortname' => 'student'], 'id');
            $role_id = $student_role ? (int)$student_role->id : 5;
            $enrol->enrol_user($instance, $mdl_user_id, $role_id);
            mtrace("  Учащийся #{$mdl_user_id} записан на курс #{$course_id}");
        }
    }
}
