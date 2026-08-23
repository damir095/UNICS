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

    /** Потолок комплектов за один запуск по умолчанию; дублируется в settings.php. */
    public const DEFAULT_LIMIT = 10;

    /**
     * Потолок комплектов за один запуск; 0 - без ограничения.
     *
     * Ненайденная настройка - НЕ ноль. get_config() отдает false, пока дефолты новой
     * настройки не применены (на живом стенде это случается, если апгрейд плагина прошел
     * раньше, чем настройка появилась в settings.php), и приведение false к int дало бы 0,
     * то есть молча снятый потолок. Отсутствие настройки означает дефолт.
     */
    public static function limit(): int {
        $raw = get_config('local_unics', 'umk_max_per_run');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        return (int)$raw;
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
                'Потолок генерации. Комплектов получилось: ' . count($groups)
                . ', потолок: ' . $limit . '.');
        }

        // Элемент чужого предмета уводит задания в чужой пул, а калибровка считает по ним
        // трудность чужой темы ([[element-course-match]]). Проверка стоит ЗДЕСЬ, потому что
        // это единственное место, где element_id попадает в базу: страница - лишь один из
        // возможных входов, и второй унаследовал бы дыру (найдено ревью).
        $element_id = (int)($params['element_id'] ?? 0);
        if (!\local_unics\codifier_manager::element_belongs_to_course($element_id, $course_id)) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'Элемент кодификатора относится к другому предмету, чем курс.');
        }

        $builder    = $builder ?? new course_builder();
        $individual = !empty($params['individual']);
        $created    = 0;

        // Все комплекты запуска - одной транзакцией. Без нее сбой на середине оставлял строку
        // unics_umk со статусом «Ожидает», но БЕЗ строки очереди: воркер такую не подберет
        // никогда, а педагог не знает, что запустилось, а что нет (найдено ревью 2026-08-07).
        $transaction = $DB->start_delegated_transaction();
        try {
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
                // 0 и пустая строка означают «методист не выбрал»: в БД кладем NULL, чтобы
                // отличать отсутствие выбора от элемента с несуществующим id.
                'element_id'       => !empty($params['element_id']) ? (int)$params['element_id'] : null,
            ]);

            if ($individual) {
                $uid = (int)$DB->get_field('unics_students', 'mdl_user_id',
                    ['id' => (int)$group['students'][0]]);
                if (!$uid) {
                    // Ученик исчез между превью и подтверждением. Без этой проверки uid=0
                    // заводил группу с именем «УМК: » и падал внутри groups_add_member.
                    throw new \moodle_exception('generalexceptionmessage', 'error', '',
                        'Учащийся #' . (int)$group['students'][0] . ' не найден - запуск отменен.');
                }
                $groupid = $builder->get_or_create_student_group($course_id, $uid);
            } else {
                $groupid = $builder->get_or_create_profile_group($course_id, $key, $params['topic']);
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
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // rollback() пробрасывает исключение дальше сам.
            $transaction->rollback($e);
        }

        return $created;
    }
}
