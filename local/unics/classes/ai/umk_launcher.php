<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Постановка комплектов УМК в очередь ([[umk-per-student-design]], разделы 6-9).
 *
 * Группа доступа заводится ЗДЕСЬ, на постановке, а не в воркере. Очередь дренится параллельно
 * ([[ai-queue-parallel-design]]), и нумерация «Вариант N» внутри воркера была бы гонкой: два
 * воркера посчитали бы одно и то же N. На постановке код однопоточный.
 *
 * @package local_unics
 */
class umk_launcher {

    /** Потолок комплектов за один запуск; 0 - без ограничения. */
    public static function limit(): int {
        return (int)get_config('local_unics', 'umk_max_per_run');
    }

    /**
     * Создать комплекты и поставить их в очередь.
     *
     * @param int $course_id
     * @param array<string,array{profile:array,level:int,students:int[]}> $groups результат
     *        profile_fingerprint::group_students()
     * @param array $params title, topic, target_section, extra_prompt, individual, flags
     * @param course_builder|null $builder DI для тестов
     * @return int сколько комплектов создано
     * @throws \moodle_exception при превышении потолка - НЕ создав ничего
     */
    public static function launch(int $course_id, array $groups, array $params,
                                  ?course_builder $builder = null): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $limit = self::limit();
        if ($limit > 0 && count($groups) > $limit) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'Потолок генерации: получилось ' . count($groups)
                . ' комплектов при потолке ' . $limit . '.');
        }

        $builder    = $builder ?? new course_builder();
        $individual = !empty($params['individual']);
        $created    = 0;

        foreach ($groups as $key => $group) {
            $umkid = (int)$DB->insert_record('unics_umk', (object)[
                'difficulty_level' => (int)$group['level'],
                'mdl_course_id'    => $course_id,
                'title'            => $params['title'],
                'topic'            => $params['topic'],
                'target_section'   => (int)$params['target_section'],
                'extra_prompt'     => $params['extra_prompt'] ?? '',
                // Статусы unics_umk нумеруются отдельно от статусов очереди: 1 = ожидает.
                'status'           => 1,
                'generated_at'     => time(),
                'profile_key'      => $key,
            ]);

            if ($individual) {
                $uid = (int)$DB->get_field('unics_students', 'mdl_user_id',
                    ['id' => (int)$group['students'][0]]);
                $groupid = $builder->get_or_create_student_group($course_id, $uid);
            } else {
                $groupid = $builder->get_or_create_profile_group($course_id, $key);
                foreach ($group['students'] as $sid) {
                    $uid = (int)$DB->get_field('unics_students', 'mdl_user_id', ['id' => (int)$sid]);
                    if ($uid && !groups_is_member($groupid, $uid)) {
                        groups_add_member($groupid, $uid);
                    }
                }
            }
            $DB->set_field('unics_umk', 'mdl_group_id', $groupid, ['id' => $umkid]);

            ai_queue::enqueue($umkid, array_values($group['students']), $params['flags']);
            $created++;
        }

        return $created;
    }
}
