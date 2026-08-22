<?php
namespace local_unics;

use local_unics\ai\question_sanity;

/**
 * Формальные признаки брака в задании ([[answer-judge-design]], раздел 2.1).
 *
 * Ярус не знает предмета: он ловит то, что видно по самой разметке. Разделение на «явный брак»
 * и «подозрение» проверяется отдельно - по корреляционному признаку выкашивать годные задания
 * дороже, чем терпеть их.
 *
 * @package local_unics
 */
final class question_sanity_test extends \advanced_testcase {

    public function test_duplicate_answers_drop_question(): void {
        $out = question_sanity::verdict('Столица России?',
            ['Москва', 'москва.', 'Тверь', 'Казань'], 0);
        $this->assertSame('drop', $out['verdict']);
        $this->assertStringContainsString('одинаков', $out['reason']);
    }

    public function test_different_answers_survive(): void {
        $out = question_sanity::verdict('Столица России?',
            ['Москва', 'Тверь', 'Казань', 'Самара'], 0);
        $this->assertSame('ok', $out['verdict']);
    }

    public function test_correct_index_out_of_range_drops(): void {
        // Нынешний зажим объявлял верным последний вариант - маскировка вместо проверки.
        $out = question_sanity::verdict('Вопрос?', ['А', 'Б', 'В', 'Г'], 7);
        $this->assertSame('drop', $out['verdict']);
        $out = question_sanity::verdict('Вопрос?', ['А', 'Б', 'В', 'Г'], -1);
        $this->assertSame('drop', $out['verdict']);
    }

    public function test_empty_answer_drops(): void {
        $this->assertSame('drop',
            question_sanity::verdict('Вопрос?', ['Москва', '  ', 'Тверь', 'Казань'], 0)['verdict']);
    }

    public function test_answer_repeating_question_drops(): void {
        $this->assertSame('drop', question_sanity::verdict('Столица России?',
            ['Столица России?', 'Тверь', 'Казань', 'Самара'], 1)['verdict']);
    }

    public function test_single_answer_drops(): void {
        $this->assertSame('drop', question_sanity::verdict('Вопрос?', ['Один'], 0)['verdict']);
    }

    public function test_long_key_is_only_a_note(): void {
        // Развернутый верный ответ - законная примета хорошего задания, а не брак.
        $out = question_sanity::verdict('Почему растения зеленые?', [
            'Из-за хлорофилла, который поглощает красный и синий свет, отражая зеленый',
            'Из-за воды', 'Из-за почвы', 'Из-за воздуха'], 0);
        $this->assertSame('ok', $out['verdict'], 'подозрение не отбрасывает вопрос');
        $this->assertNotEmpty($out['notes']);
    }

    public function test_all_of_the_above_is_only_a_note(): void {
        $out = question_sanity::verdict('Что относится к млекопитающим?',
            ['Кит', 'Летучая мышь', 'Еж', 'Все перечисленное'], 3);
        $this->assertSame('ok', $out['verdict']);
        $this->assertNotEmpty($out['notes']);
    }

    public function test_clean_question_has_no_notes(): void {
        $out = question_sanity::verdict('В каком году отменили крепостное право?',
            ['1861', '1855', '1874', '1881'], 0);
        $this->assertSame('ok', $out['verdict']);
        $this->assertSame([], $out['notes'], 'чистый вопрос не должен собирать подозрений');
    }

    public function test_normalize_folds_spaces_case_and_final_dot(): void {
        $this->assertSame('москва', question_sanity::normalize("  Москва.\n"));
        $this->assertSame('санкт петербург', question_sanity::normalize('Санкт   Петербург'));
    }
}
