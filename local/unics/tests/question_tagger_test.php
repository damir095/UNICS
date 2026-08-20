<?php
namespace local_unics;

use local_unics\ai\question_tagger;

/**
 * Разбор ответа и промт разметки банка ([[codifier-bank-tagging-design]]).
 *
 * @package local_unics
 */
final class question_tagger_test extends \advanced_testcase {

    private function elements(): array {
        return [
            ['code' => '3.1', 'title' => 'Сравнение дробей', 'description' => 'сравнивает дроби'],
            ['code' => '4.2', 'title' => 'Деление дробей', 'description' => 'делит дроби'],
        ];
    }

    public function test_parses_normal_reply(): void {
        $raw = '{"tags":[{"n":1,"code":"3.1","sure":true},{"n":2,"code":"4.2","sure":false}]}';
        $out = question_tagger::parse($raw, ['3.1', '4.2'], 2);
        $this->assertCount(2, $out);
        $this->assertSame(1, $out[0]['n']);
        $this->assertSame('3.1', $out[0]['code']);
        $this->assertTrue($out[0]['sure']);
        $this->assertFalse($out[1]['sure'], 'неуверенность модели обязана доезжать до методиста');
    }

    public function test_unknown_code_is_dropped(): void {
        $raw = '{"tags":[{"n":1,"code":"9.9","sure":true},{"n":2,"code":"4.2","sure":true}]}';
        $out = question_tagger::parse($raw, ['3.1', '4.2'], 2);
        $this->assertCount(1, $out, 'кода 9.9 в кодификаторе нет');
        $this->assertSame('4.2', $out[0]['code']);
    }

    public function test_question_number_outside_batch_is_dropped(): void {
        $raw = '{"tags":[{"n":7,"code":"3.1","sure":true},{"n":1,"code":"3.1","sure":true}]}';
        $out = question_tagger::parse($raw, ['3.1'], 2);
        $this->assertCount(1, $out, 'в пачке было два вопроса, седьмого не существует');
        $this->assertSame(1, $out[0]['n']);
    }

    public function test_duplicate_number_keeps_first(): void {
        $raw = '{"tags":[{"n":1,"code":"3.1","sure":true},{"n":1,"code":"4.2","sure":true}]}';
        $out = question_tagger::parse($raw, ['3.1', '4.2'], 2);
        $this->assertCount(1, $out, 'один вопрос - один элемент');
        $this->assertSame('3.1', $out[0]['code']);
    }

    public function test_missing_sure_defaults_to_unsure(): void {
        // Молчание модели про уверенность трактуем в пользу методиста: галочка снята.
        $out = question_tagger::parse('{"tags":[{"n":1,"code":"3.1"}]}', ['3.1'], 1);
        $this->assertFalse($out[0]['sure']);
    }

    public function test_garbage_throws(): void {
        $this->expectException(\moodle_exception::class);
        question_tagger::parse('Извините, не могу помочь.', ['3.1'], 1);
    }

    public function test_prompt_carries_elements_and_questions(): void {
        $questions = [
            ['bankentryid' => 11, 'name' => 'Вопрос про дроби', 'text' => 'Сравните 1/2 и 2/3'],
            ['bankentryid' => 12, 'name' => 'Вопрос про деление', 'text' => 'Разделите 1/2 на 1/4'],
        ];
        $p = question_tagger::build_prompt($questions, $this->elements());
        $this->assertStringContainsString('3.1', $p);
        $this->assertStringContainsString('Сравнение дробей', $p);
        $this->assertStringContainsString('Сравните 1/2 и 2/3', $p);
        $this->assertStringContainsString('2. Вопрос про деление', $p, 'вопросы нумеруются с единицы');
        $this->assertStringContainsString('"tags"', $p, 'формат ответа обязан быть в промте');
    }
}
