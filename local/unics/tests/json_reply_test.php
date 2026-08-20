<?php
namespace local_unics;

use local_unics\ai\json_reply;

/**
 * Разбор JSON-ответа модели ([[codifier-ai-proposal-design]], раздел 4).
 *
 * GigaChat портит JSON тремя способами сразу: обкладывает пояснениями, ставит одиночную
 * обратную косую внутри строки и обрывает ответ на лимите токенов. Логика жила замыканием
 * внутри generate_quiz и была привязана к ключу questions - кодификатору нужно то же самое
 * для sections.
 *
 * @package local_unics
 */
final class json_reply_test extends \advanced_testcase {

    public function test_plain_json(): void {
        $out = json_reply::decode('{"sections":[{"title":"Раздел"}]}', 'sections');
        $this->assertSame('Раздел', $out['sections'][0]['title']);
    }

    public function test_json_wrapped_in_chatter(): void {
        $raw = "Конечно! Вот структура:\n{\"sections\":[{\"title\":\"Раздел\"}]}\nГотово.";
        $out = json_reply::decode($raw, 'sections');
        $this->assertSame('Раздел', $out['sections'][0]['title']);
    }

    public function test_truncated_answer_is_recovered(): void {
        // Ответ оборвался на середине второго раздела: закрывающих скобок нет вовсе.
        $raw = '{"sections":[{"title":"Первый","topics":[{"title":"Тема"}]},{"title":"Второй","topics":[{"title":"Тема';
        $out = json_reply::decode($raw, 'sections');
        $this->assertNotNull($out, 'обрезанный ответ обязан восстанавливаться');
        $this->assertSame('Первый', $out['sections'][0]['title']);
        $this->assertSame('Второй', $out['sections'][1]['title']);
    }

    public function test_truncated_on_key_is_recovered(): void {
        // Обрыв на имени ключа без значения - самый частый вид обрезки по лимиту токенов.
        $raw = '{"sections":[{"title":"Первый","description":';
        $out = json_reply::decode($raw, 'sections');
        $this->assertNotNull($out);
        $this->assertSame('Первый', $out['sections'][0]['title']);
    }

    public function test_invalid_escape_is_repaired(): void {
        // GigaChat регулярно шлет одиночную обратную косую внутри строки.
        $raw = '{"sections":[{"title":"Дробь k\\x"}]}';
        $out = json_reply::decode($raw, 'sections');
        $this->assertNotNull($out);
    }

    public function test_trailing_comma_is_repaired(): void {
        // Замерено на живом ответе GigaChat 2026-08-20: модель ставит запятую перед скобкой.
        $raw = '{"sections":[{"title":"Дроби","topics":[{"title":"Сложение","description":"складывает",},],},]}';
        $out = json_reply::decode($raw, 'sections');
        $this->assertNotNull($out, 'висячая запятая обязана чиниться');
        $this->assertSame('Сложение', $out['sections'][0]['topics'][0]['title']);
    }

    public function test_trailing_comma_inside_text_is_left_alone(): void {
        // Запятая внутри описания не висячая: чинить ее значило бы портить текст темы.
        $raw = '{"sections":[{"title":"Итак, вот } скобка","topics":[]}]}';
        $out = json_reply::decode($raw, 'sections');
        $this->assertSame('Итак, вот } скобка', $out['sections'][0]['title']);
    }

    public function test_trailing_comma_and_truncation_together(): void {
        // Живой случай целиком: модель и запятые ставит, и обрывается на лимите токенов.
        $raw = '{"sections": [{"title": "Натуральные числа", "description": "считает", '
            . '"topics": [{"title": "Сложение дробей", "description": "складывает", }, '
            . '{"title": "Умножен';
        $out = json_reply::decode($raw, 'sections');
        $this->assertNotNull($out, 'запятая плюс обрыв обязаны чиниться вместе');
        $this->assertSame('Натуральные числа', $out['sections'][0]['title']);
        $this->assertSame('Сложение дробей', $out['sections'][0]['topics'][0]['title']);
    }

    public function test_echoed_format_does_not_win_over_real_answer(): void {
        // Модель повторяет пример формата из промта, а потом отвечает. Скобки есть и до, и
        // после: прежний разбор брал кусок «от первой скобки до последней» и получал мусор.
        $raw = 'Формат: {"items":[{"section":"пример","topic":"пример"}]}' . "\n\n"
            . '{"items":[{"section":"Дроби","topic":"Сравнение"},{"section":"Дроби","topic":"Сложение"}]}';
        $out = json_reply::decode($raw, 'items');
        $this->assertNotNull($out);
        $this->assertCount(2, $out['items'], 'выбран обязан быть содержательный ответ, а не эхо примера');
        $this->assertSame('Дроби', $out['items'][0]['section']);
    }

    public function test_braces_after_json_do_not_break_it(): void {
        $raw = '{"items":[{"section":"Дроби","topic":"Сравнение"}]}' . "\n"
            . 'Если нужен другой вид: {"sections": [...]}';
        $out = json_reply::decode($raw, 'items');
        $this->assertNotNull($out);
        $this->assertSame('Дроби', $out['items'][0]['section']);
    }

    public function test_truncation_right_after_backslash_is_recovered(): void {
        // Обрыв сразу за одиночной косой: приписанная кавычка становится экранированной,
        // и строка не закрывается вовсе.
        $raw = '{"items":[{"section":"А","topic":"дробь k' . "\\";
        $out = json_reply::decode($raw, 'items');
        $this->assertNotNull($out, 'висячая косая обязана отбрасываться перед закрытием строки');
        $this->assertSame('А', $out['items'][0]['section']);
    }

    public function test_broken_utf8_returns_null_without_php_warnings(): void {
        // Невалидный байт роняет /u-регулярку: без защиты preg_replace возвращает null,
        // и восстановление молча собирало строку из одних скобок.
        $raw = "{\"items\":[{\"section\":\"\xC3\x28\",\"topic\":\"т";
        $this->assertNull(json_reply::decode($raw, 'items'));
    }

    public function test_head_and_tail_shows_both_ends(): void {
        $raw = str_repeat('а', 300) . 'ХВОСТ';
        $out = json_reply::head_and_tail($raw, 50);
        $this->assertStringContainsString('ХВОСТ', $out, 'причина всегда в конце ответа');
        $this->assertStringContainsString(str_repeat('а', 50), $out);
        $this->assertLessThan(mb_strlen($raw), mb_strlen($out));
    }

    public function test_head_and_tail_leaves_short_answer_whole(): void {
        $this->assertStringContainsString('короткий ответ', json_reply::head_and_tail('короткий ответ', 50));
    }

    public function test_bare_list_without_wrapper(): void {
        // Живой ответ GigaChat 2026-08-20: обертку «tags» модель выбросила и прислала список.
        $raw = '[{"n":1,"code":"1.1","sure":true},{"n":2,"code":"2.1","sure":false}]';
        $out = json_reply::decode($raw, 'tags');
        $this->assertNotNull($out, 'список без обертки обязан разбираться');
        $this->assertCount(2, $out['tags']);
        $this->assertSame('1.1', $out['tags'][0]['code']);
    }

    public function test_key_written_with_equals_sign(): void {
        // Там же: «"sure=true"» вместо «"sure":true» - значение без ключа, JSON невалиден.
        $raw = '{"tags":[{"n":1,"code":"1.1","sure=true"},{"n":2,"code":"2.1","sure=false"}]}';
        $out = json_reply::decode($raw, 'tags');
        $this->assertNotNull($out);
        $this->assertTrue($out['tags'][0]['sure']);
        $this->assertFalse($out['tags'][1]['sure'], 'false обязан остаться ложью, а не строкой');
    }

    public function test_echoed_list_does_not_win_over_wrapped_answer(): void {
        // Модель повторяет эхом пример формата СПИСКОМ, а потом отвечает как просили. Голый
        // список - это запасной разбор, и он не должен вытеснять найденный ключ.
        $raw = '[{"n":1,"code":"пример","sure":true}]' . "\nВот разметка:\n"
            . '{"tags":[{"n":1,"code":"1.1","sure":true},{"n":2,"code":"2.1","sure":false}]}';
        $out = json_reply::decode($raw, 'tags');
        $this->assertNotNull($out);
        $this->assertSame(['1.1', '2.1'], array_column($out['tags'], 'code'));
    }

    public function test_bare_list_behind_prefix_and_fence(): void {
        // Список не обязан стоять первым символом: перед ним бывает и текст, и markdown-фенс,
        // хотя промт просит без них.
        $list = '[{"n":1,"code":"1.1","sure":true}]';
        foreach (["Вот разметка:\n" . $list, "```json\n" . $list . "\n```"] as $raw) {
            $out = json_reply::decode($raw, 'tags');
            $this->assertNotNull($out, 'список за префиксом обязан разбираться');
            $this->assertSame('1.1', $out['tags'][0]['code']);
        }
    }

    public function test_many_bracket_starts_do_not_exhaust_the_budget(): void {
        // Пример формата, повторенный много раз, не должен съедать бюджет кандидатов до того,
        // как дело дойдет до настоящего ответа.
        $echo = '';
        for ($i = 0; $i < 8; $i++) {
            $echo .= '[{"n":' . $i . ',"code":"пример"}] ';
        }
        $out = json_reply::decode('Формат: ' . $echo . "\nОтвет: "
            . '{"tags":[{"n":1,"code":"1.1","sure":true}]}', 'tags');
        $this->assertNotNull($out);
        $this->assertSame('1.1', $out['tags'][0]['code']);
    }

    public function test_value_with_equals_sign_survives(): void {
        // «solve x=5» - законное значение, а не пара через равно: чинить его нельзя.
        $raw = '[{"n":1,"code":"1.1","text":"solve x=5 for x"},{"oops":,}]';
        $out = json_reply::decode($raw, 'tags');
        $this->assertNotNull($out, 'битая строка не повод терять здоровую');
        $this->assertSame('solve x=5 for x', $out['tags'][0]['text']);
    }

    public function test_broken_element_does_not_kill_the_whole_list(): void {
        // Целиком список не декодируется, но девять строк из десяти пригодны.
        $raw = '[{"n":1,"code":"1.1","sure":true},{"n":2,"code":,,,},{"n":3,"code":"2.1","sure":false}]';
        $out = json_reply::decode($raw, 'tags');
        $this->assertNotNull($out, 'одна битая строка не повод терять остальные');
        // Считаем ИМЕННО состав: без этого тест зеленый и когда в список попали лишние
        // объекты - на этом ревью поймало двойную обертку.
        $this->assertSame(['1.1', '2.1'], array_column($out['tags'], 'code'));
    }

    public function test_latex_command_survives_decoding(): void {
        // «\f» - законный form feed в JSON, поэтому «\frac» декодер съедал до «rac», и в базе
        // оказывалось «$ rac{4}{7} $». В учебном тексте form feed не встречается, а «\frac»
        // встречается постоянно.
        $out = json_reply::decode('{"questions":[{"text":"Сложите \frac{4}{7}"}]}', 'questions');
        $this->assertNotNull($out);
        $this->assertStringContainsString('frac', $out['questions'][0]['text']);
    }

    public function test_garbage_returns_null(): void {
        $this->assertNull(json_reply::decode('Извините, не могу помочь.', 'sections'));
    }

    public function test_missing_expected_key_returns_null(): void {
        $this->assertNull(json_reply::decode('{"questions":[{"text":"а"}]}', 'sections'));
    }
}
