<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Нормализатор текста, пришедшего от модели ([[ai-output-style-design]]).
 *
 * Почему пост-обработка, а не указания в промте: промт УЖЕ содержит однозначное «используй
 * #### для заголовков», а живой прогон 2026-08-06 вернул пять «##» и ни одного «####».
 * Указание, которое уже есть, модель проигнорировала - значит нужен инвариант, а не просьба.
 *
 * Буква «ё» НЕ трогается сознательно: правило «без ё» действует на наши собственные строки, но
 * в учебном материале для детей с ЗПР и РАС «ё» снимает двусмысленность (все/всё, небо/нёбо).
 *
 * @package local_unics
 */
class output_style {

    /** Эмодзи, вариационный селектор и ZWJ. Стрелки (U+2190-U+21FF) сюда НЕ входят. */
    private const EMOJI = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u';

    /** Тире: figure dash, en dash, em dash, horizontal bar. Дефис-минус не трогаем. */
    private const DASHES = '/[\x{2012}-\x{2015}]/u';

    /**
     * Убрать из текста модели то, что запрещено правилами проекта.
     */
    public static function clean(string $text): string {
        $text = preg_replace(self::EMOJI, '', $text) ?? $text;
        $text = preg_replace(self::DASHES, '-', $text) ?? $text;

        // Дыры от вырезанных эмодзи. Отступ в НАЧАЛЕ строки не трогаем - это markdown-вложенность
        // и блоки кода, поэтому схлопываем только пробелы после непробельного символа.
        $text = preg_replace('/(?<=\S)\h{2,}/u', ' ', $text) ?? $text;

        // Хвостовые пробелы в конце строк - тоже след вырезания.
        $text = preg_replace('/\h+$/mu', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Сдвинуть уровни заголовков так, чтобы минимальный стал «####».
     *
     * Именно сдвиг, а не выравнивание всех заголовков в один уровень: относительная структура
     * разделов сохраняется, иначе программа экранного доступа потеряет вложенность вместо того,
     * чтобы ее обрести.
     */
    public static function shift_headings(string $text): string {
        if (!preg_match_all('/^(#{1,6})\h+/mu', $text, $matches)) {
            return $text;
        }

        $min = 6;
        foreach ($matches[1] as $hashes) {
            $min = min($min, strlen($hashes));
        }
        $delta = 4 - $min;
        if ($delta === 0) {
            return $text;
        }

        return preg_replace_callback('/^(#{1,6})(\h+)/mu',
            static function (array $m) use ($delta): string {
                $level = min(6, max(1, strlen($m[1]) + $delta));
                return str_repeat('#', $level) . $m[2];
            }, $text) ?? $text;
    }
}
