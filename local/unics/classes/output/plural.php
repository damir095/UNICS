<?php
namespace local_unics\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Выбор русской формы числительного для lang-ключей вида <key>_{one,few,many}.
 * Общий для ученического ({@see course_view}) и педагогского ({@see course_staff_view})
 * видов страницы курса: обоим нужны фразы «1 тема / 2 темы / 5 тем», «1 работа / 2 работы /
 * 5 работ», и вторая копия правила была бы дублем логики.
 */
class plural {

    /**
     * Правило: последняя цифра 1 (кроме ...11) - one; 2-4 (кроме ...12-14) - few; иначе many.
     * @param int $n неотрицательное число
     * @return string 'one'|'few'|'many' - суффикс lang-ключа
     */
    public static function form(int $n): string {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'one';
        }
        if (in_array($mod10, [2, 3, 4], true) && !in_array($mod100, [12, 13, 14], true)) {
            return 'few';
        }
        return 'many';
    }
}
