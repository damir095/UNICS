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
        // Утверждать надо ИМЕННО свою пометку: assertNotEmpty проходил бы за счет соседней,
        // и признак остался бы непроверенным (найдено ревью задачи 1).
        $this->assertContains('ключ заметно длиннее прочих вариантов', $out['notes']);
    }

    public function test_long_distractor_does_not_hide_a_leaky_key(): void {
        // Один многословный неверный вариант поднимал среднее и прятал утечку подсказки.
        $out = question_sanity::verdict('Что придает растениям зеленый цвет?', [
            'Хлорофилл в клетках листа', 'Вода', 'Почва', 'Свет'], 0);
        $this->assertContains('ключ заметно длиннее прочих вариантов', $out['notes']);
    }

    public function test_all_of_the_above_is_only_a_note(): void {
        $out = question_sanity::verdict('Что относится к млекопитающим?',
            ['Кит', 'Летучая мышь обыкновенная', 'Еж', 'Все перечисленное'], 3);
        $this->assertSame('ok', $out['verdict']);
        $this->assertContains('среди вариантов «все перечисленное»', $out['notes']);
    }

    public function test_negation_is_noted(): void {
        $out = question_sanity::verdict('Что НЕ относится к млекопитающим?',
            ['Крокодил', 'Кит', 'Еж', 'Лиса'], 0);
        $this->assertSame('ok', $out['verdict']);
        $this->assertContains('отрицание в формулировке', $out['notes']);
    }

    public function test_except_counts_as_negation(): void {
        // «Кроме» - то же отрицание другими словами, и ребенку оно так же тяжело.
        $out = question_sanity::verdict('Все перечисленное относится к рыбам, кроме одного',
            ['Дельфин', 'Карп', 'Щука', 'Окунь'], 0);
        $this->assertContains('отрицание в формулировке', $out['notes']);
    }

    public function test_word_containing_ne_is_not_negation(): void {
        // «Небо», «независимость» - не отрицание: признак не должен срабатывать на подстроке.
        $out = question_sanity::verdict('Какого цвета небо в ясный день?',
            ['Голубое', 'Красное', 'Черное', 'Желтое'], 0);
        $this->assertSame([], $out['notes'], 'подстрока «не» внутри слова не отрицание');
    }

    public function test_empty_question_text_drops(): void {
        $this->assertSame('drop', question_sanity::verdict('   ',
            ['Москва', 'Тверь', 'Казань', 'Самара'], 0)['verdict']);
    }

    public function test_broken_utf8_answer_survives_normalization(): void {
        // Битые байты не должны превращаться в «пустой вариант»: причина была бы ложной.
        $out = question_sanity::normalize("\xC3\x28abc");
        $this->assertNotSame('', $out, 'испорченный байтами вариант не пустой');
    }

    public function test_normalize_keeps_valid_utf8(): void {
        // rtrim режет побайтно: список из точки, пробела и NBSP обрубал символы,
        // кончающиеся байтом 0xA0, и на выходе получался невалидный UTF-8.
        $this->assertTrue(mb_check_encoding(question_sanity::normalize('Рыба и крестик †'), 'UTF-8'));
        $this->assertSame('итог †', question_sanity::normalize('Итог †'));
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
