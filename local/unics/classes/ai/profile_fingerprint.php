<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Отпечаток профиля ученика: ключ, по которому УМК схлопываются в один комплект
 * ([[umk-per-student-design]], A2).
 *
 * Ключ снимается с ai_generator::build_criteria() - чистой функции профиля, которая НЕ
 * принимает тему. build_prompt = f(критерии, тема, доп. указания), поэтому одинаковые
 * критерии дают одинаковый промт для ЛЮБОЙ темы. Это позволяет одному ключу работать и
 * для группировки внутри запуска, и для Moodle-группы, живущей через все темы курса.
 *
 * generate_quiz / generate_assignment_description / generate_video_script читают из профиля
 * строгое ПОДМНОЖЕСТВО этих входов (class_number + difficulty_level), поэтому ключ корректен
 * для всего комплекта, а не только для текста.
 *
 * @package local_unics
 */
class profile_fingerprint {

    /**
     * Профиль ученика в том виде, в каком его ждет ai_generator.
     *
     * @param int $student_id unics_students.id
     * @param ai_generator|null $gen DI для тестов (фиксированный балл); null = боевой
     * @return array|null null, если ученика нет
     */
    public static function profile_of(int $student_id, ?ai_generator $gen = null): ?array {
        global $DB;

        $st = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$st) {
            return null;
        }
        $gen = $gen ?? new ai_generator();

        // Сортировка обязательна: порядок из get_fieldset_select не гарантирован, а
        // несортированный вход дал бы два разных ключа одному профилю.
        $cats = \local_unics\identity\student_helper::categories_of((int)$st->id);
        $ovz  = \local_unics\identity\student_helper::ovz_types_of((int)$st->id);
        sort($cats);
        sort($ovz);

        return [
            // Бэк-компат - первая категория скаляром.
            'category'         => $cats[0] ?? 2,
            'categories'       => $cats,
            'ovz_types'        => $ovz,
            'difficulty_level' => (int)$st->difficulty_level,
            'class_number'     => (int)($st->class_number ?? 5),
            'class_letter'     => $st->class_letter ?? '',
            'ovz_type'         => $ovz[0] ?? 0,
            'special_needs'    => $st->special_needs ?? '',
            'avg_score'        => $gen->get_avg_score((int)$st->mdl_user_id),
        ];
    }

    /**
     * Ключ профиля: sha1 канонических критериев без сырого балла.
     *
     * Сырой avg_score выбрасывается намеренно - в критериях остается avg_band, и именно
     * полоса, а не число, попадает и в промт, и в ключ. Иначе схлопывание не сработало бы:
     * совпадение среднего балла до процента - редкость.
     */
    public static function key(array $profile, ?ai_generator $gen = null): string {
        $gen = $gen ?? new ai_generator();
        $criteria = $gen->build_criteria($profile);
        unset($criteria['avg_score']);
        ksort($criteria);

        // json_encode отдает false на битом UTF-8 (например строка из импорта в cp1251), а
        // sha1(false) - это sha1(''), то есть ВСЕ такие профили схлопнулись бы в один ключ и
        // ребенок с ЗПР получил бы материал по профилю одаренного. Найдено ревью 2026-08-07.
        $json = json_encode($criteria, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = serialize($criteria);
        }
        return sha1($json);
    }

    /**
     * Разложить учеников по отпечатку профиля.
     *
     * Фильтрация доступа (unics_teacher_student, скоуп) остается на вызывающем коде -
     * сюда приходят уже разрешенные id.
     *
     * @param int[] $student_ids unics_students.id
     * @param bool $individual индивидуальный режим: схлопывания нет, ключ несет id ученика
     * @param ai_generator|null $gen DI для тестов
     * @return array<string,array{profile:array,level:int,students:int[]}>
     */
    public static function group_students(array $student_ids, bool $individual = false,
                                          ?ai_generator $gen = null): array {
        $gen = $gen ?? new ai_generator();
        $out = [];
        foreach ($student_ids as $sid) {
            $sid = (int)$sid;
            $profile = self::profile_of($sid, $gen);
            if ($profile === null) {
                continue;
            }
            $key = self::key($profile, $gen);
            if ($individual) {
                // Ключ остается 40-символьным sha1, но становится персональным.
                $key = sha1($key . ':' . $sid);
            }
            if (!isset($out[$key])) {
                $out[$key] = [
                    'profile'  => $profile,
                    'level'    => (int)$profile['difficulty_level'],
                    'students' => [],
                ];
            }
            $out[$key]['students'][] = $sid;
        }
        return $out;
    }
}
