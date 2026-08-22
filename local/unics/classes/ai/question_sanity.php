<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Формальные признаки брака в задании ([[answer-judge-design]], раздел 2.1).
 *
 * Ярус не знает предмета и не обращается к сети: он ловит то, что видно по самой разметке
 * задания, и потому работает на любом предмете, где арифметический верификатор молчит.
 *
 * Разделение на «явный брак» и «подозрение» принципиально: по корреляционному признаку
 * выкашивать годные задания дороже, чем терпеть их. Подозрения уезжают в след и никогда
 * не меняют вердикт.
 *
 * @package local_unics
 */
class question_sanity {

    /** Во сколько раз ключ должен быть длиннее среднего дистрактора, чтобы это стало подозрением. */
    private const LONG_KEY_RATIO = 2.0;

    /**
     * Нормализация для сравнения вариантов: пробелы, регистр, конечная точка.
     *
     * Кириллица - только через mb_*: strtolower ее не берет и два одинаковых варианта,
     * различающихся регистром, разошлись бы.
     */
    public static function normalize(string $s): string {
        $s = preg_replace('~\s+~u', ' ', trim($s));
        $s = mb_strtolower($s);
        return rtrim($s, ". \u{00A0}");
    }

    /**
     * Вердикт по формальным признакам.
     *
     * @param string $text текст вопроса
     * @param array $answers варианты ответа
     * @param int $correct индекс ключа
     * @return array ['verdict' => 'ok'|'drop', 'reason' => string, 'notes' => string[]]
     */
    public static function verdict(string $text, array $answers, int $correct): array {
        $answers = array_values($answers);
        $n = count($answers);
        $notes = [];

        if ($n < 2) {
            return self::drop('вариантов меньше двух');
        }
        if ($correct < 0 || $correct >= $n) {
            // Зажим индекса прятал ошибку: correct = 7 при четырех вариантах объявлял верным
            // последний, и ребенок получал «неверно» за верный ответ. Битый индекс означает,
            // что модель потеряла соответствие ключа вариантам, - доверять тут нечему.
            return self::drop('индекс ключа вне диапазона');
        }

        $norm = [];
        foreach ($answers as $a) {
            $one = self::normalize((string)$a);
            if ($one === '') {
                return self::drop('пустой вариант ответа');
            }
            $norm[] = $one;
        }
        if (count(array_unique($norm)) !== $n) {
            return self::drop('два варианта одинаковы');
        }
        if (in_array(self::normalize($text), $norm, true)) {
            return self::drop('вариант повторяет текст вопроса');
        }

        // Ниже - только подозрения: вердикт они не меняют.
        $keylen = mb_strlen((string)$answers[$correct]);
        $others = 0;
        foreach ($answers as $i => $a) {
            if ($i !== $correct) {
                $others += mb_strlen((string)$a);
            }
        }
        $avg = $others / max(1, $n - 1);
        if ($avg > 0 && $keylen >= $avg * self::LONG_KEY_RATIO) {
            $notes[] = 'ключ заметно длиннее прочих вариантов';
        }
        foreach ($norm as $one) {
            if (preg_match('~все перечислен|все ответы верн|все варианты верн~u', $one)) {
                $notes[] = 'среди вариантов «все перечисленное»';
                break;
            }
        }
        // Отрицание детям с ЗПР дается тяжело, но само по себе задание не портит.
        if (preg_match('~(^|\s)не\s|неверн|не явля~u', mb_strtolower($text))) {
            $notes[] = 'отрицание в формулировке';
        }

        return ['verdict' => 'ok', 'reason' => '', 'notes' => $notes];
    }

    private static function drop(string $reason): array {
        return ['verdict' => 'drop', 'reason' => $reason, 'notes' => []];
    }
}
