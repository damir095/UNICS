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

    /**
     * Эмодзи, вариационный селектор, ZWJ и keycap.
     *
     * Текстовые стрелки U+2190-U+21FF (->, <-) сюда НЕ входят: в учебном тексте стрелка
     * осмысленна. А вот дингбатные и «толстые» стрелки блоков U+2600-U+27BF и U+2B00-U+2BFF
     * (вместе со звездами вроде U+2B50) - декор, и они вырезаются.
     *
     * Блок U+1F000-U+1F2FF (закрытые буквы, флаги) и keycap U+20E3 добавлены после ревью
     * 2026-08-07: без них инвариант «эмодзи в выходе ИИ нет» не выполнялся.
     */
    private const EMOJI = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}'
                        . '\x{FE0F}\x{200D}\x{20E3}]/u';

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
        $lines = preg_split('/\R/u', $text);
        if ($lines === false) {
            return $text;
        }

        // Решетка внутри блока кода - комментарий, а не заголовок. Без этой проверки одна
        // строка «# считаем сумму» в примере на Python задирала минимум до первого уровня,
        // перекашивала весь сдвиг и портила сам код (найдено ревью 2026-08-07).
        $min      = 7;
        $in_fence = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/u', $line)) {
                $in_fence = !$in_fence;
                continue;
            }
            if (!$in_fence && preg_match('/^(#{1,6})\h+/u', $line, $m)) {
                $min = min($min, strlen($m[1]));
            }
        }

        $delta = 4 - $min;
        if ($min === 7 || $delta === 0) {
            return $text;
        }

        $in_fence = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*```/u', $line)) {
                $in_fence = !$in_fence;
                continue;
            }
            if ($in_fence) {
                continue;
            }
            $lines[$i] = preg_replace_callback('/^(#{1,6})(\h+)/u',
                static function (array $m) use ($delta): string {
                    $level = min(6, max(1, strlen($m[1]) + $delta));
                    return str_repeat('#', $level) . $m[2];
                }, $line) ?? $line;
        }

        return implode("\n", $lines);
    }
}
