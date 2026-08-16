<?php
namespace local_unics;

use local_unics\learning\item_pool;

/**
 * Отбор заданий из общего пула элемента кодификатора.
 *
 * Пул нужен затем, чтобы ОДНО задание видели многие ученики: IRT оценивает параметры задания по
 * ответам многих на одно, а генерация «каждому свои пять вопросов» давала по одному ответу на
 * задание и делала калибровку невозможной ([[umk-item-pool-design]]).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_pool::class)]
final class item_pool_test extends \advanced_testcase {

    /** Элемент кодификатора, к которому привязываем задания. */
    private function make_element(): int {
        global $DB, $USER;
        $catid = $DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => 1, 'name' => 'тестовый', 'created_by_mdl_user_id' => (int)$USER->id,
            'timecreated' => time(),
        ]);
        return (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $catid, 'parent_id' => null, 'code' => '1',
            'title' => 'Тема', 'ordinal' => 0, 'path' => '/1/', 'timecreated' => time(),
        ]);
    }

    /**
     * Задание в банке, привязанное к элементу.
     *
     * Возвращает [questionbankentryid, questionid]: они разные, и это принципиально -
     * ответы лежат на questionid (версия), а привязка на questionbankentryid.
     */
    private function make_item(int $element_id, ?int $level = null): array {
        global $DB, $USER;
        $catid = (int)$DB->insert_record('question_categories', (object)[
            'name' => 'тест', 'contextid' => \context_system::instance()->id, 'info' => '',
            'infoformat' => FORMAT_HTML, 'stamp' => make_unique_id_code(), 'parent' => 0,
            'sortorder' => 0,
        ]);
        $qbeid = (int)$DB->insert_record('question_bank_entries', (object)[
            'questioncategoryid' => $catid, 'ownerid' => null,
        ]);
        $qid = (int)$DB->insert_record('question', (object)[
            'category' => $catid, 'parent' => 0, 'name' => 'в', 'questiontext' => 'в',
            'questiontextformat' => FORMAT_HTML, 'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML, 'defaultmark' => 1, 'penalty' => 0,
            'qtype' => 'multichoice', 'length' => 1, 'stamp' => make_unique_id_code(),
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => 0, 'modifiedby' => 0,
        ]);
        $DB->insert_record('question_versions', (object)[
            'questionbankentryid' => $qbeid, 'version' => 1, 'questionid' => $qid,
            'status' => 'ready',
        ]);
        \local_unics\codifier_link_manager::link_question($element_id, $qbeid, (int)$USER->id);
        if ($level !== null) {
            item_pool::remember_level($qbeid, $level, (int)$USER->id);
        }
        return [$qbeid, $qid];
    }

    /** Своя попытка использования на каждый ответ: у question_attempts уникальны (usage, slot). */
    private int $usage = 0;

    /** Ответ ученика на задание: именно он делает калибровку возможной. */
    private function answer(int $questionid, int $times = 1): void {
        global $DB;
        for ($i = 0; $i < $times; $i++) {
            $this->usage++;
            $DB->insert_record('question_attempts', (object)[
                'questionusageid' => $this->usage, 'slot' => 1, 'behaviour' => 'deferredfeedback',
                'questionid' => $questionid, 'variant' => 1, 'maxmark' => 1,
                'minfraction' => 0, 'maxfraction' => 1, 'flagged' => 0,
                'questionsummary' => '', 'rightanswer' => '', 'responsesummary' => '',
                'timemodified' => time(),
            ]);
        }
    }

    public function test_empty_pool_reports_everything_missing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();

        $r = item_pool::take($element, 2, 5);

        $this->assertSame([], $r['ids']);
        $this->assertSame(5, $r['missing']);
    }

    public function test_partial_pool_reports_the_shortfall(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        $this->make_item($element, 2);
        $this->make_item($element, 2);

        $r = item_pool::take($element, 2, 5);

        $this->assertCount(2, $r['ids']);
        $this->assertSame(3, $r['missing']);
    }

    /** Ради этого все и затевалось: два подряд запроса дают ОДНИ И ТЕ ЖЕ задания. */
    public function test_two_takes_return_the_same_items(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        for ($i = 0; $i < 5; $i++) {
            $this->make_item($element, 2);
        }

        $first  = item_pool::take($element, 2, 5)['ids'];
        $second = item_pool::take($element, 2, 5)['ids'];

        sort($first);
        sort($second);
        $this->assertSame($first, $second);
    }

    /** Реже отвеченные идут первыми: иначе задания сверх пятерки не откалибруются никогда. */
    public function test_least_answered_go_first(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$busy, $busyqid] = $this->make_item($element, 2);
        [$fresh, ] = $this->make_item($element, 2);
        $this->answer($busyqid, 4);

        $ids = item_pool::take($element, 2, 1)['ids'];

        $this->assertSame([$fresh], $ids, 'первым обязан идти тот, у кого ответов меньше');
        $this->assertSame(4, item_pool::answers_count($busy));
        $this->assertSame(0, item_pool::answers_count($fresh));
    }

    /** Удаленное из банка задание не должно попасть в слот: это прямой урок 249 сирот. */
    public function test_deleted_bank_entry_is_filtered_out(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$gone, ] = $this->make_item($element, 2);
        $this->make_item($element, 2);
        $DB->delete_records('question_bank_entries', ['id' => $gone]);

        $r = item_pool::take($element, 2, 5);

        $this->assertNotContains($gone, $r['ids']);
        $this->assertCount(1, $r['ids']);
        $this->assertSame(4, $r['missing']);
    }

    /**
     * Удаленный педагогом вопрос в пул не возвращается.
     *
     * Moodle не удаляет использованный вопрос физически: `question_delete_question()` помечает
     * версию скрытой, а запись банка остается. Проверки существования записи тут мало, и без
     * проверки статуса удаленный вопрос всплывал бы в тесте каждого следующего ученика
     * (найдено ревью).
     */
    public function test_hidden_question_version_is_not_taken(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$hidden, ] = $this->make_item($element, 2);
        [$alive, ]  = $this->make_item($element, 2);
        $DB->set_field('question_versions', 'status', 'hidden', ['questionbankentryid' => $hidden]);

        $r = item_pool::take($element, 2, 5);

        $this->assertSame([$alive], $r['ids']);
        $this->assertSame(4, $r['missing']);
    }

    /** Задание с чужим уровнем в выдачу по своему уровню не идет. */
    public function test_other_level_is_not_taken(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        $this->make_item($element, 3);

        $r = item_pool::take($element, 1, 5);

        $this->assertSame([], $r['ids']);
        $this->assertSame(5, $r['missing']);
    }

    /** Старые ручные привязки без уровня годятся, но берутся последними. */
    public function test_items_without_level_come_last_but_are_used(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$nolevel, ] = $this->make_item($element, null);
        [$exact, ]   = $this->make_item($element, 2);

        $ids = item_pool::take($element, 2, 2)['ids'];

        $this->assertSame([$exact, $nolevel], $ids);
    }

    /** Записать калибровку заданию. */
    private function calibrate(int $item_ref, int $element_id, float $b, int $n, float $a = 1.0): void {
        global $DB;
        $DB->insert_record('unics_item_irt', (object)[
            'item_ref' => $item_ref, 'element_id' => $element_id, 'model' => 'rasch',
            'a' => $a, 'b' => $b, 'c' => 0, 'calibrated_n' => $n, 'updated_at' => time(),
        ]);
    }

    /**
     * Заявленный уровень - ЖЕСТКИЙ фильтр, измеренная трудность на него не влияет.
     *
     * Раньше поведение было обратным: откалиброванное задание судилось только по b, и ребенку
     * уровня 1 могло достаться задание, заявленное как продвинутое. Для детей с ОВЗ это хуже
     * любой статистической выгоды, поэтому уровень решает, а b лишь упорядочивает внутри него.
     */
    public function test_declared_level_beats_measured_difficulty(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        // Заявлен уровень 3, измеренная трудность - как у базового.
        [$measured, ] = $this->make_item($element, 3);
        $this->calibrate($measured, $element, -1.0, 30);

        $r = item_pool::take($element, 1, 5);

        $this->assertSame([], $r['ids'],
            'задание чужого уровня не идет ребенку, как бы ни легла измеренная трудность');
    }

    /**
     * Калибровка по одному-двум ответам не считается измерением.
     *
     * Найдено живым зондом: задания с n=1 получали вырожденную b = -3.892, промахивались мимо
     * любого уровня и ПРОПАДАЛИ из пула совсем. Пока наблюдений мало, b игнорируется и задание
     * судится по заявленному уровню, как до калибровки.
     */
    public function test_low_confidence_calibration_is_ignored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();

        // У «шумного» измеренная трудность далеко от целевой, но наблюдение всего одно, значит
        // верить ей нельзя. У «честного» трудность известна и чуть в стороне.
        [$noisy, $noisyqid]     = $this->make_item($element, 2);
        [$trusted, $trustedqid] = $this->make_item($element, 2);
        $this->calibrate($noisy, $element, 2.5, 1);
        $this->calibrate($trusted, $element, 0.5, 30);
        // Ответов поровну, иначе порядок решал бы счетчик, а не доверие к трудности.
        $this->answer($noisyqid, 1);
        $this->answer($trustedqid, 1);

        $ids = item_pool::take($element, 2, 5)['ids'];

        $this->assertSame([$noisy, $trusted], $ids,
            'трудность по одному наблюдению не учитывается, поэтому задание не уезжает в хвост');
        $this->assertCount(2, $ids, 'и уж точно не пропадает из пула');
    }

    /**
     * Задание своего уровня остается в выдаче, даже если измеренная трудность далеко.
     *
     * Регресс находки зонда: жесткий допуск выбрасывал такие задания, и пул уровня 2 просел с
     * пяти до трех сразу после первой калибровки - против самой цели пула.
     */
    public function test_own_level_item_with_far_b_is_still_taken(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$far, ] = $this->make_item($element, 2);
        $this->calibrate($far, $element, 2.5, 30);

        $ids = item_pool::take($element, 2, 5)['ids'];

        $this->assertSame([$far], $ids);
    }

    /** При равном числе ответов первым идет задание с трудностью ближе к целевой. */
    public function test_closer_difficulty_goes_first_when_answers_equal(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$far, $farqid]     = $this->make_item($element, 2);
        [$close, $closeqid] = $this->make_item($element, 2);
        $this->calibrate($far, $element, 2.5, 30);
        $this->calibrate($close, $element, 0.1, 30);
        // Ответов поровну: иначе решал бы счетчик ответов, а не трудность.
        $this->answer($farqid, 1);
        $this->answer($closeqid, 1);

        $ids = item_pool::take($element, 2, 2)['ids'];

        $this->assertSame([$close, $far], $ids);
    }
}
