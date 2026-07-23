<?php
namespace local_unics;

use local_unics\social\equipment_manager;

/**
 * Тесты слот-модели экипировки (этап 3 мотивации, [[title-equipment-design]]):
 * автоэкипировка первой покупки, выбор активного, снятие, один-на-слот,
 * идемпотентный backfill миграции.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(equipment_manager::class)]
final class equipment_test extends \advanced_testcase {

    /** Ученик. */
    private function make_student(): int {
        global $DB;
        $u = $this->getDataGenerator()->create_user();
        return (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $u->id, 'difficulty_level' => 2,
        ]);
    }

    /** Товар-титул (item_type=1). */
    private function make_title(string $name, int $cost = 100): int {
        global $DB;
        return (int)$DB->insert_record('unics_shop_items', (object)[
            'name' => $name, 'cost' => $cost, 'icon_emoji' => 'X',
            'item_type' => 1, 'is_active' => 1, 'sort_order' => 0,
        ]);
    }

    /** Отметить товар купленным. */
    private function buy(int $sid, int $item_id): void {
        global $DB;
        $DB->insert_record('unics_purchases', (object)[
            'student_id' => $sid, 'item_id' => $item_id, 'purchased_at' => time(),
        ]);
    }

    public function test_slot_for_item_type(): void {
        $this->resetAfterTest();
        $this->assertSame('title', equipment_manager::slot_for_item_type(1));
        $this->assertNull(equipment_manager::slot_for_item_type(3)); // стикер - без слота
    }

    public function test_auto_equip_first_only(): void {
        $this->resetAfterTest();
        $sid = $this->make_student();
        $t1  = $this->make_title('Умник');
        $t2  = $this->make_title('Чемпион');

        $this->buy($sid, $t1);
        equipment_manager::auto_equip_if_empty($sid, $t1);
        $eq = equipment_manager::get_equipped($sid, 'title');
        $this->assertNotNull($eq);
        $this->assertSame($t1, (int)$eq->id);

        // Вторая покупка активный НЕ меняет.
        $this->buy($sid, $t2);
        equipment_manager::auto_equip_if_empty($sid, $t2);
        $eq = equipment_manager::get_equipped($sid, 'title');
        $this->assertSame($t1, (int)$eq->id);
    }

    public function test_equip_switches_within_slot(): void {
        $this->resetAfterTest();
        $sid = $this->make_student();
        $t1  = $this->make_title('Умник');
        $t2  = $this->make_title('Чемпион');
        $this->buy($sid, $t1);
        $this->buy($sid, $t2);
        equipment_manager::auto_equip_if_empty($sid, $t1);

        $this->assertTrue(equipment_manager::equip($sid, $t2));
        $eq = equipment_manager::get_equipped($sid, 'title');
        $this->assertSame($t2, (int)$eq->id);
        // Один на слот: ровно одна строка.
        global $DB;
        $this->assertSame(1, $DB->count_records('unics_equipped',
            ['student_id' => $sid, 'slot' => 'title']));
    }

    public function test_equip_not_purchased_rejected(): void {
        $this->resetAfterTest();
        $sid = $this->make_student();
        $t1  = $this->make_title('Умник');
        // Не куплен.
        $res = equipment_manager::equip($sid, $t1);
        $this->assertIsString($res);
        $this->assertNull(equipment_manager::get_equipped($sid, 'title'));
    }

    public function test_unequip_idempotent(): void {
        $this->resetAfterTest();
        $sid = $this->make_student();
        $t1  = $this->make_title('Умник');
        $this->buy($sid, $t1);
        equipment_manager::auto_equip_if_empty($sid, $t1);

        equipment_manager::unequip($sid, 'title');
        $this->assertNull(equipment_manager::get_equipped($sid, 'title'));
        // Повтор снятия - без ошибки.
        equipment_manager::unequip($sid, 'title');
        $this->assertNull(equipment_manager::get_equipped($sid, 'title'));
    }

    public function test_backfill_picks_last_title_and_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $sid = $this->make_student();
        $t1  = $this->make_title('Умник');
        $t2  = $this->make_title('Чемпион');
        // Куплены оба; t2 позже -> он и должен стать надетым.
        $DB->insert_record('unics_purchases', (object)[
            'student_id' => $sid, 'item_id' => $t1, 'purchased_at' => 1000]);
        $DB->insert_record('unics_purchases', (object)[
            'student_id' => $sid, 'item_id' => $t2, 'purchased_at' => 2000]);

        $n = equipment_manager::backfill_titles();
        $this->assertSame(1, $n);
        $eq = equipment_manager::get_equipped($sid, 'title');
        $this->assertSame($t2, (int)$eq->id);

        // Идемпотентно: повтор ничего не вставляет.
        $this->assertSame(0, equipment_manager::backfill_titles());
        $this->assertSame(1, $DB->count_records('unics_equipped',
            ['student_id' => $sid, 'slot' => 'title']));
    }

    public function test_purchase_auto_equips_first_title(): void {
        $this->resetAfterTest();
        $sid = $this->make_student();
        $t1  = $this->make_title('Умник', 10);
        // Дать баллы на покупку.
        \local_unics\social\points_manager::award($sid, 100,
            \local_unics\social\points_manager::REASON_QUIZ_PASS, 'старт');

        $this->assertTrue(\local_unics\social\points_manager::purchase($sid, $t1));
        $eq = \local_unics\social\points_manager::get_active_title($sid);
        $this->assertNotNull($eq);
        $this->assertSame('Умник', $eq->name);
    }

    public function test_get_active_title_reads_equipped_not_last_purchase(): void {
        $this->resetAfterTest();
        $sid = $this->make_student();
        $t1  = $this->make_title('Умник');
        $t2  = $this->make_title('Чемпион');
        $this->buy($sid, $t1);
        $this->buy($sid, $t2);
        // Надет t1, хотя t2 куплен «последним» - активный титул = надетый, не последний.
        equipment_manager::equip($sid, $t1); // не auto - явно
        $eq = \local_unics\social\points_manager::get_active_title($sid);
        $this->assertSame('Умник', $eq->name);
        // Снят - активного нет.
        equipment_manager::unequip($sid, 'title');
        $this->assertNull(\local_unics\social\points_manager::get_active_title($sid));
    }

    public function test_equip_nonexistent_item_rejected(): void {
        $this->resetAfterTest();
        $sid = $this->make_student();
        // item_id, которого нет в unics_shop_items.
        $res = equipment_manager::equip($sid, 999999);
        $this->assertIsString($res);
        $this->assertNull(equipment_manager::get_equipped($sid, 'title'));
    }

    public function test_equip_non_slot_item_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $sid = $this->make_student();
        // Товар без слота (стикер, item_type=3): даже купленный - надеть нельзя.
        $sticker = (int)$DB->insert_record('unics_shop_items', (object)[
            'name' => 'Сова', 'cost' => 30, 'icon_emoji' => 'X',
            'item_type' => 3, 'is_active' => 1, 'sort_order' => 0,
        ]);
        $DB->insert_record('unics_purchases', (object)[
            'student_id' => $sid, 'item_id' => $sticker, 'purchased_at' => time(),
        ]);
        $res = equipment_manager::equip($sid, $sticker);
        $this->assertIsString($res);
        $this->assertNull(equipment_manager::get_equipped($sid, 'title'));
    }
}
