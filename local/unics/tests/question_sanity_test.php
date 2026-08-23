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
    // ------------------------------------------------------------------
    // Пригодность формулировки ([[question-wording-design]])
    // ------------------------------------------------------------------

    public function test_question_asking_for_several_answers_drops(): void {
        // Живой заход 2026-08-23: «Какие два материка полностью расположены в южном
        // полушарии?» с одним верным вариантом. Ребенок не может ответить верно НИКАК.
        $out = question_sanity::verdict('Какие два материка расположены в южном полушарии?',
            ['Австралия', 'Антарктида', 'Африка', 'Южная Америка'], 1);
        $this->assertSame('drop', $out['verdict']);
        $this->assertStringContainsString('несколько', $out['reason']);
    }

    public function test_select_all_drops(): void {
        $this->assertSame('drop', question_sanity::verdict(
            'Выберите все верные утверждения о клетке',
            ['Есть ядро', 'Есть стенка', 'Есть хлоропласты', 'Есть жабры'], 0)['verdict']);
    }

    public function test_list_them_drops(): void {
        $this->assertSame('drop', question_sanity::verdict(
            'Перечислите органоиды растительной клетки',
            ['Ядро', 'Стенка', 'Вакуоль', 'Все перечисленное'], 3)['verdict']);
    }

    public function test_singular_question_survives(): void {
        // Проверка не должна цепляться за любое множественное число: «какие органоиды
        // отвечают за фотосинтез» - законный вопрос с одним верным ответом.
        $out = question_sanity::verdict('Какие органоиды отвечают за фотосинтез?',
            ['Хлоропласты', 'Митохондрии', 'Рибосомы', 'Лизосомы'], 0);
        $this->assertSame('ok', $out['verdict']);
    }

    public function test_answer_contained_in_another_drops(): void {
        // Два варианта нельзя различить однозначно: понимающий тему ребенок промахнется.
        $out = question_sanity::verdict('Столица России?',
            ['Москва', 'Москва и область', 'Тверь', 'Казань'], 0);
        $this->assertSame('drop', $out['verdict']);
        $this->assertStringContainsString('входит в другой', $out['reason']);
    }

    public function test_short_answer_inside_a_long_word_is_not_containment(): void {
        // «Рим» и «Кримпель» - разные слова, а не вложенные варианты: сверяем по словам.
        $out = question_sanity::verdict('Столица Италии?',
            ['Рим', 'Кримпель', 'Милан', 'Турин'], 0);
        $this->assertSame('ok', $out['verdict']);
    }

    public function test_long_question_is_only_a_note(): void {
        $long = 'Опираясь на изученный материал и вспоминая все, о чем говорилось на прошлом '
            . 'занятии, определите, какой именно климатический пояс Земли характеризуется '
            . 'наличием полярного дня и полярной ночи в течение года?';
        $out = question_sanity::verdict($long, ['Полярный', 'Тропический', 'Умеренный'], 0);
        $this->assertSame('ok', $out['verdict']);
        $this->assertContains('вопрос длинный - тяжело для ЗПР', $out['notes']);
    }

    public function test_multi_sentence_question_is_only_a_note(): void {
        $out = question_sanity::verdict(
            'Петр I правил с 1682 года. В каком году он основал Санкт-Петербург?',
            ['1703', '1700', '1712', '1721'], 0);
        $this->assertSame('ok', $out['verdict']);
        $this->assertContains('вопрос из нескольких предложений', $out['notes']);
    }

    public function test_double_negation_is_only_a_note(): void {
        $out = question_sanity::verdict('Что не является не живым организмом?',
            ['Камень', 'Дерево', 'Вода', 'Песок'], 1);
        $this->assertSame('ok', $out['verdict']);
        $this->assertContains('несколько отрицаний в вопросе', $out['notes']);
    }

    public function test_single_negation_is_not_double(): void {
        $out = question_sanity::verdict('Что не относится к млекопитающим?',
            ['Крокодил', 'Кит', 'Еж', 'Лиса'], 0);
        $this->assertNotContains('несколько отрицаний в вопросе', $out['notes']);
    }
    // ------------------------------------------------------------------
    // Ложные срабатывания правил формулировки (найдено ревью 2026-08-23)
    // ------------------------------------------------------------------

    public function test_counting_question_with_numeral_survives(): void {
        // «Сколько будет два плюс три» - счетный вопрос с ОДНИМ ответом. Числительное само по
        // себе требованием нескольких ответов не является, иначе гибнут целые комплекты.
        $this->assertSame('ok', question_sanity::verdict('Сколько будет два плюс три?',
            ['5', '4', '6', '7'], 0)['verdict']);
        $this->assertSame('ok', question_sanity::verdict(
            'Какое животное имеет три камеры сердца?',
            ['Лягушка', 'Рыба', 'Собака', 'Голубь'], 0)['verdict']);
    }

    public function test_numeric_answers_are_not_containment(): void {
        // «10» - слово внутри «10 000», но это разные числа, а не уточнение одного ответа.
        // Ключ '10' - слово внутри дистрактора '10 000': без исключения чисел правило
        // объявляло бы это неоднозначностью.
        $this->assertSame('ok', question_sanity::verdict('Сколько десятков в сотне?',
            ['10', '10 000', '1 000', '100'], 0)['verdict']);
    }

    public function test_overlapping_distractors_do_not_drop(): void {
        // Перекрытие двух НЕВЕРНЫХ вариантов ребенку не мешает: он выбирает ключ.
        $this->assertSame('ok', question_sanity::verdict('Какая рыба самая опасная?',
            ['Акула', 'Кит', 'Синий кит', 'Дельфин'], 0)['verdict']);
    }

    public function test_digit_numeral_drops_like_the_spelled_one(): void {
        $this->assertSame('drop', question_sanity::verdict(
            'Какие 2 материка расположены в южном полушарии?',
            ['Австралия', 'Антарктида', 'Африка', 'Азия'], 1)['verdict']);
    }

    public function test_yo_spelling_of_all_drops(): void {
        // Букву «е» с точками в выходе ИИ не трогаем намеренно, и «отметьте всё» модель пишет
        // именно так.
        $this->assertSame('drop', question_sanity::verdict(
            'Отметьте всё, что относится к млекопитающим',
            ['Кит', 'Еж', 'Лиса', 'Крокодил'], 0)['verdict']);
    }

    public function test_numeral_before_punctuation_drops(): void {
        $this->assertSame('drop', question_sanity::verdict(
            'Материки южного полушария - назовите два.',
            ['Австралия', 'Антарктида', 'Африка', 'Азия'], 1)['verdict']);
    }

    public function test_containment_sees_through_punctuation(): void {
        $this->assertSame('drop', question_sanity::verdict('Столица России?',
            ['Москва, столица', 'Москва', 'Тверь', 'Казань'], 1)['verdict']);
    }

    public function test_initials_are_not_several_sentences(): void {
        $out = question_sanity::verdict('А. С. Пушкин родился в каком году?',
            ['1799', '1800', '1801', '1802'], 0);
        $this->assertNotContains('вопрос из нескольких предложений', $out['notes']);
    }

    public function test_two_separate_negations_are_noted(): void {
        // Не «несколько отрицаний в вопросе», а именно несколько отрицаний: разбирать тяжело так же.
        $out = question_sanity::verdict('Что не относится к рыбам и не живет в воде?',
            ['Кит', 'Карп', 'Щука', 'Окунь'], 0);
        $this->assertContains('несколько отрицаний в вопросе', $out['notes']);
    }

    public function test_adjacent_negations_are_counted(): void {
        // Формулировка нарочито корявая: она проверяет СЧЕТЧИК. Прежний шаблон «(^|\s)не\s»
        // на подряд идущих «не не» давал одно совпадение - пробел между ними съедался первым.
        $out = question_sanity::verdict('Камень не не живой организм?',
            ['Верно', 'Неверно', 'Не знаю', 'Иногда'], 1);
        $this->assertContains('несколько отрицаний в вопросе', $out['notes']);
    }
}
