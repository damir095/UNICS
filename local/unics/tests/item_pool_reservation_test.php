<?php
namespace local_unics;

use local_unics\learning\item_pool;

/**
 * Бронь мест в пуле: параллельные воркеры не плодят дубли.
 *
 * Настоящая параллельность в PHPUnit не нужна - двух воркеров изображают два последовательных
 * вызова с разными queue_id ([[item-pool-reservation-design]], раздел 7).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_pool::class)]
final class item_pool_reservation_test extends \advanced_testcase {

    /** Элемент кодификатора. */
    private function make_element(): int {
        global $DB, $USER;
        $cid = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => 1, 'name' => 'к', 'created_by_mdl_user_id' => (int)$USER->id,
            'timecreated' => time(),
        ]);
        return (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $cid, 'parent_id' => null, 'code' => '1', 'title' => 'Тема',
            'ordinal' => 0, 'path' => '/' . $cid . '/', 'timecreated' => time(),
        ]);
    }

    /** Задание в банке БЕЗ привязки к элементу: его привяжет fulfil. */
    private function make_bare_item(): int {
        global $DB;
        $qcat = (int)$DB->insert_record('question_categories', (object)[
            'name' => 'т', 'contextid' => \context_system::instance()->id, 'info' => '',
            'infoformat' => FORMAT_HTML, 'stamp' => make_unique_id_code(), 'parent' => 0,
            'sortorder' => 0,
        ]);
        $qbe = (int)$DB->insert_record('question_bank_entries', (object)[
            'questioncategoryid' => $qcat, 'ownerid' => null,
        ]);
        $qid = (int)$DB->insert_record('question', (object)[
            'category' => $qcat, 'parent' => 0, 'name' => 'в', 'questiontext' => 'в',
            'questiontextformat' => FORMAT_HTML, 'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML, 'defaultmark' => 1, 'penalty' => 0,
            'qtype' => 'multichoice', 'length' => 1, 'stamp' => make_unique_id_code(),
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => 0, 'modifiedby' => 0,
        ]);
        $DB->insert_record('question_versions', (object)[
            'questionbankentryid' => $qbe, 'version' => 1, 'questionid' => $qid, 'status' => 'ready',
        ]);
        return $qbe;
    }

    /** Готовое задание пула: привязано к элементу и имеет заявленный уровень. */
    private function make_item(int $element_id, int $level): int {
        global $USER;
        $qbe = $this->make_bare_item();
        codifier_link_manager::link_question($element_id, $qbe, (int)$USER->id);
        item_pool::remember_level($qbe, $level, (int)$USER->id);
        return $qbe;
    }

    /** Пустой пул: первый воркер берет все пять мест себе. */
    public function test_first_worker_reserves_everything(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();

        $r = item_pool::take_or_reserve($element, 2, 5, 101);

        $this->assertSame([], $r['ids']);
        $this->assertSame(5, $r['mine']);
        $this->assertSame(0, $r['waiting']);
    }

    /**
     * Второй воркер НЕ генерирует свое - он ждет чужую бронь.
     *
     * Ради этого вся задача: раньше здесь было mine = 5 у обоих, то есть 10 заданий на пятерых.
     */
    public function test_second_worker_waits_instead_of_duplicating(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        item_pool::take_or_reserve($element, 2, 5, 101);

        $r = item_pool::take_or_reserve($element, 2, 5, 202);

        $this->assertSame(0, $r['mine'], 'второй воркер не должен создавать дубли');
        $this->assertSame(5, $r['waiting']);
    }

    /** Часть заданий уже есть: бронируется только недостающее. */
    public function test_only_missing_slots_are_reserved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        $this->make_item($element, 2);
        $this->make_item($element, 2);

        $r = item_pool::take_or_reserve($element, 2, 5, 101);

        $this->assertCount(2, $r['ids'], 'готовые задания берут все, они общие');
        $this->assertSame(3, $r['mine']);
        $this->assertSame(0, $r['waiting']);
    }

    /** Протухшая бронь не держит места: мертвый воркер не блокирует пул навсегда. */
    public function test_expired_reservation_frees_slots(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        $DB->insert_record('unics_item_reservation', (object)[
            'element_id' => $element, 'level' => 2, 'slots' => 5,
            'owner_queue_id' => 101, 'expires_at' => time() - 1,
        ]);

        $r = item_pool::take_or_reserve($element, 2, 5, 202);

        $this->assertSame(5, $r['mine']);
        $this->assertSame(0, $r['waiting']);
    }

    /**
     * Протухшая строка не просто игнорируется, а УДАЛЯЕТСЯ.
     *
     * Мутация показала, что предыдущий тест проверял лишь фильтр в запросе: с выключенной
     * уборкой он оставался зеленым. Без этой проверки строки копились бы вечно, а спека
     * обещает, что уборка здесь и есть вся уборка - отдельного уборщика нет.
     */
    public function test_expired_reservation_row_is_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        $DB->insert_record('unics_item_reservation', (object)[
            'element_id' => $element, 'level' => 2, 'slots' => 5,
            'owner_queue_id' => 101, 'expires_at' => time() - 1,
        ]);

        item_pool::take_or_reserve($element, 2, 5, 202);

        $this->assertSame(0, $DB->count_records('unics_item_reservation',
            ['owner_queue_id' => 101]), 'протухшая бронь обязана удаляться, а не копиться');
    }

    /** release освобождает места немедленно, не дожидаясь протухания. */
    public function test_release_frees_slots_at_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        item_pool::take_or_reserve($element, 2, 5, 101);

        item_pool::release(101, $element, 2);
        $r = item_pool::take_or_reserve($element, 2, 5, 202);

        $this->assertSame(5, $r['mine']);
    }

    /** Перезапуск той же заявки заменяет бронь, а не добавляет вторую. */
    public function test_same_queue_replaces_its_reservation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();

        item_pool::take_or_reserve($element, 2, 5, 101);
        item_pool::take_or_reserve($element, 2, 5, 101);

        $this->assertSame(1, $DB->count_records('unics_item_reservation',
            ['owner_queue_id' => 101, 'element_id' => $element, 'level' => 2]));
    }

    /** fulfil привязывает созданное и снимает бронь - место освобождается для соседа. */
    public function test_fulfil_links_items_and_drops_reservation(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        item_pool::take_or_reserve($element, 2, 5, 101);
        $refs = [];
        for ($i = 0; $i < 5; $i++) {
            $refs[] = $this->make_bare_item();
        }

        item_pool::fulfil(101, $element, 2, $refs, (int)$USER->id);

        $this->assertSame(0, $DB->count_records('unics_item_reservation',
            ['owner_queue_id' => 101]), 'бронь обязана сняться');
        $r = item_pool::take_or_reserve($element, 2, 5, 202);
        $this->assertCount(5, $r['ids'], 'соседу достаются готовые задания');
        $this->assertSame(0, $r['mine']);
    }

    /**
     * Сгенерировалось меньше, чем забронировано: бронь снимается ЦЕЛИКОМ.
     *
     * Иначе она держала бы места до протухания зря, а сосед ждал бы того, чего уже не будет.
     */
    public function test_partial_generation_drops_whole_reservation(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        item_pool::take_or_reserve($element, 2, 5, 101);

        item_pool::fulfil(101, $element, 2, [$this->make_bare_item()], (int)$USER->id);

        $this->assertSame(0, $DB->count_records('unics_item_reservation',
            ['owner_queue_id' => 101]));
    }

    /** Хватает заданий - ожидание возвращает их сразу, не тратя ни секунды. */
    public function test_wait_returns_at_once_when_pool_is_full(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        for ($i = 0; $i < 5; $i++) {
            $this->make_item($element, 2);
        }

        $started = time();
        $ids = item_pool::wait_for_slots($element, 2, 5, 60);

        $this->assertCount(5, $ids);
        $this->assertLessThan(3, time() - $started, 'ждать было нечего');
    }

    /** Срок вышел - отдаем что есть, а не пустоту: короткий тест лучше, чем никакого. */
    public function test_wait_returns_what_exists_after_deadline(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        $this->make_item($element, 2);
        $this->make_item($element, 2);

        $ids = item_pool::wait_for_slots($element, 2, 5, 0);

        $this->assertCount(2, $ids);
    }
}
