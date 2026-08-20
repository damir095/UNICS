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
     * Сколько открывающих скобок ответа пробовать как начало JSON.
     *
     * Потолок нужен, потому что кандидатов вдвое больше, а разбор каждого не бесплатен. Живые
     * ответы содержат от одной до трех открывающих скобок верхнего уровня.
     */
    private const MAX_STARTS = 12;

    /**
     * Разобрать ответ модели.
     *
     * @param string $raw сырой ответ
     * @param string $expect_key ключ, без которого разбор считается неудачным ('' - любой массив)
     * @return array|null массив или null, если не разобралось даже после восстановления
     */
    public static function decode(string $raw, string $expect_key = ''): ?array {
        $best = null;
        $bestsize = -1;
        foreach (self::candidates($raw) as $candidate) {
            // Три вида порчи по возрастанию тяжести. Первый удавшийся вариант для КАЖДОГО
            // кандидата и есть наименее покалеченное его прочтение.
            $variants = [
                $candidate,
                self::strip_trailing_commas($candidate),
                self::close_brackets(self::strip_trailing_commas($candidate)),
            ];
            foreach ($variants as $variant) {
                $data = self::try_decode($variant, $expect_key);
                if ($data === null) {
                    continue;
                }
                $size = self::size_of($data, $expect_key);
                if ($size > $bestsize) {
                    $best = $data;
                    $bestsize = $size;
                }
                break;
            }
        }
        return $best;
    }

    /**
     * Насколько ответ содержателен: по этой мерке выбирается лучший кандидат.
     *
     * Нужна, потому что кандидатов бывает несколько ЗАКОННЫХ. Модель повторяет эхом пример
     * формата из промта, а следом отвечает по делу: оба куска разбираются, и брать надо тот,
     * где строк больше, а не тот, что встретился первым.
     */
    private static function size_of(array $data, string $expect_key): int {
        if ($expect_key === '') {
            return count($data);
        }
        return is_array($data[$expect_key] ?? null) ? count($data[$expect_key]) : 1;
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
     * Куски ответа, которые имеет смысл разбирать.
     *
     * От КАЖДОЙ открывающей скобки берутся два куска: сбалансированный блок (если он есть) и
     * хвост до конца строки. Раньше кандидатов было два на весь ответ - «от первой скобки до
     * последней» и «от первой до конца», - и любой текст со скобкой вокруг настоящего JSON
     * делал ответ неразбираемым целиком. Ровно этот случай класс и должен поглощать: модель
     * повторяет эхом пример формата из промта, а потом отвечает по делу.
     *
     * @return string[] кандидаты в порядке появления
     */
    private static function candidates(string $raw): array {
        $out = [];
        $len = strlen($raw);
        $starts = 0;
        for ($i = 0; $i < $len && $starts < self::MAX_STARTS; $i++) {
            if ($raw[$i] !== '{') {
                continue;
            }
            $starts++;
            $end = self::balanced_end($raw, $i);
            if ($end !== null) {
                $out[] = substr($raw, $i, $end - $i + 1);
            }
            $out[] = trim(substr($raw, $i));
        }
        return $out;
    }

    /**
     * Позиция скобки, закрывающей открывающую в позиции $from, или null если ответ обрезан.
     *
     * Скобки внутри строковых литералов не считаются: «"Итак, } вот"» не закрывает объект.
     */
    private static function balanced_end(string $s, int $from): ?int {
        $depth = 0;
        $instring = false;
        $escaped = false;
        $len = strlen($s);
        for ($i = $from; $i < $len; $i++) {
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
                $depth++;
            } else if ($ch === '}' || $ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Начало и хвост ответа для сообщения об ошибке.
     *
     * Только начало не годится: 2026-08-20 живой заход показал первые 300 символов, и по ним
     * нельзя было отличить обрыв ответа от порчи разметки - причина всегда в конце.
     */
    public static function head_and_tail(string $raw, int $len = 200): string {
        $raw = trim($raw);
        if (mb_strlen($raw) <= $len * 2) {
            return 'Ответ: ' . $raw;
        }
        return 'Начало ответа: ' . mb_substr($raw, 0, $len)
            . ' [...] Конец ответа: ' . mb_substr($raw, -$len);
    }

    /** Экранировать одиночную обратную косую, оставив законные последовательности. */
    private static function fix_escapes(string $s): string {
        return preg_replace_callback('/\\\\(.)/u', static function (array $m): string {
            return in_array($m[1], ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true)
                ? $m[0] : '\\\\' . $m[1];
        }, $s) ?? $s;
    }

    /**
     * Убрать запятые перед закрывающей скобкой: «[1, 2, ]» -> «[1, 2]».
     *
     * Строковые литералы обходятся стороной, иначе запятая внутри описания темы («Итак, }»)
     * была бы принята за висячую и текст испортился бы.
     */
    private static function strip_trailing_commas(string $s): string {
        $out = '';
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
                $out .= $ch;
                continue;
            }
            if ($ch === '"') {
                $instring = true;
            } else if ($ch === '}' || $ch === ']') {
                $trimmed = rtrim($out);
                if (substr($trimmed, -1) === ',') {
                    $out = substr($trimmed, 0, -1);
                }
            }
            $out .= $ch;
        }
        return $out;
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
            // Обрыв пришелся сразу за одиночной косой: приписанная кавычка стала бы
            // экранированной, и строка не закрылась бы вовсе. Косую отбрасываем.
            if ($escaped) {
                $out = substr($out, 0, -1);
            }
            $out .= '"';
        }
        // Хвост без значения («"description":») и висящая запятая - иначе JSON не соберется.
        // Защита от неудачи регулярки обязательна: на невалидном UTF-8 /u-шаблон возвращает
        // null, и восстановление молча собирало строку из одних скобок.
        $out = preg_replace('/,?\s*"[^"]*"\s*:\s*$/u', '', $out) ?? $out;
        $out = preg_replace('/,\s*$/u', '', $out) ?? $out;
        while ($stack) {
            $out .= array_pop($stack) === '{' ? '}' : ']';
        }
        return $out;
    }
}
