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

    public function test_garbage_returns_null(): void {
        $this->assertNull(json_reply::decode('Извините, не могу помочь.', 'sections'));
    }

    public function test_missing_expected_key_returns_null(): void {
        $this->assertNull(json_reply::decode('{"questions":[{"text":"а"}]}', 'sections'));
    }
}
