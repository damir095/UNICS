<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Разбор JSON-ответа модели ([[codifier-ai-proposal-design]], раздел 4).
 *
 * Зачем отдельный класс: GigaChat отдает JSON тремя порчеными способами сразу - обкладывает его
 * пояснениями, ставит одиночную обратную косую внутри строки и обрывает ответ на лимите токенов.
 * Логика жила замыканием внутри generate_quiz и была привязана к ключу questions; кодификатору
 * нужно ровно то же самое для sections.
 *
 * Класс чистый: ни сети, ни БД.
 *
 * @package local_unics
 */
class json_reply {

    /**
     * Разобрать ответ модели.
     *
     * @param string $raw сырой ответ
     * @param string $expect_key ключ, без которого разбор считается неудачным ('' - любой массив)
     * @return array|null массив или null, если не разобралось даже после восстановления
     */
    public static function decode(string $raw, string $expect_key = ''): ?array {
        list($greedy, $tail) = self::candidates($raw);
        if ($greedy === '' && $tail === '') {
            return null;
        }
        // Целый ответ: жадный кусок «от первой скобки до последней» снимает пояснения вокруг.
        foreach ([$greedy, $tail] as $c) {
            $data = self::try_decode($c, $expect_key);
            if ($data !== null) {
                return $data;
            }
        }
        // Обрезанный ответ: восстанавливаем СНАЧАЛА хвост до конца строки. Жадный кусок тут
        // вредит - он отрезает все после последней закрывающей скобки, то есть выбрасывает
        // недописанный последний раздел целиком.
        foreach ([$tail, $greedy] as $c) {
            $data = self::try_decode(self::close_brackets($c), $expect_key);
            if ($data !== null) {
                return $data;
            }
        }
        return null;
    }

    private static function try_decode(string $json, string $expect_key): ?array {
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true) ?? json_decode(self::fix_escapes($json), true);
        return self::acceptable($data, $expect_key) ? $data : null;
    }

    private static function acceptable($data, string $expect_key): bool {
        return is_array($data) && ($expect_key === '' || isset($data[$expect_key]));
    }

    /**
     * Два кандидата на разбор: жадный кусок от первой скобки до последней и хвост от первой
     * скобки до конца строки. Первый нужен против пояснений вокруг JSON, второй - против обрыва.
     *
     * @return array{0: string, 1: string} [жадный, хвост]
     */
    private static function candidates(string $raw): array {
        $pos = strpos($raw, '{');
        if ($pos === false) {
            return ['', ''];
        }
        $tail = trim(substr($raw, $pos));
        $greedy = preg_match('/\{.*\}/su', $raw, $m) ? $m[0] : '';
        return [$greedy, $tail];
    }

    /** Экранировать одиночную обратную косую, оставив законные последовательности. */
    private static function fix_escapes(string $s): string {
        return preg_replace_callback('/\\\\(.)/u', static function (array $m): string {
            return in_array($m[1], ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true)
                ? $m[0] : '\\\\' . $m[1];
        }, $s) ?? $s;
    }

    /**
     * Закрыть незакрытые скобки обрезанного ответа.
     *
     * Скобки считаются СТЕКОМ и с учетом строковых литералов: разность счетчиков закрывает
     * «{"a":[{"b":1» как «}]» плюс догадка, то есть в неверном порядке. Стек дает «}]}» сразу
     * и не путается на скобке внутри текста темы.
     */
    private static function close_brackets(string $s): string {
        $stack = [];
        $instring = false;
        $escaped = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($instring) {
                if ($escaped) {
                    $escaped = false;
                } else if ($ch === '\\') {
                    $escaped = true;
                } else if ($ch === '"') {
                    $instring = false;
                }
                continue;
            }
            if ($ch === '"') {
                $instring = true;
            } else if ($ch === '{' || $ch === '[') {
                $stack[] = $ch;
            } else if ($ch === '}' || $ch === ']') {
                array_pop($stack);
            }
        }
        $out = $s;
        if ($instring) {
            $out .= '"';
        }
        // Хвост без значения («"description":») и висящая запятая - иначе JSON не соберется.
        $out = preg_replace('/,?\s*"[^"]*"\s*:\s*$/u', '', $out);
        $out = preg_replace('/,\s*$/u', '', $out);
        while ($stack) {
            $out .= array_pop($stack) === '{' ? '}' : ']';
        }
        return $out;
    }
}
