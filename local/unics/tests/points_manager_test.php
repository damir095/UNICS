<?php
namespace local_unics;

use local_unics\social\points_manager;

/**
 * Тест коллекции стикеров points_manager::get_sticker_collection
 * ([[sticker-collection-design]], мотивация этап 3, срез 3).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_unics\social\points_manager::class)]
final class points_manager_test extends \advanced_testcase {

    /** Вставить товар магазина, вернуть id. */
    private function make_item(string $name, int $type, int $cost, int $sort, string $icon = ''): int {
        global $DB;
        return (int)$DB->insert_record('unics_shop_items', (object)[
            'name' => $name, 'cost' => $cost, 'icon' => $icon, 'icon_emoji' => '',
            'item_type' => $type, 'is_active' => 1, 'sort_order' => $sort,
        ]);
    }

    /** Отметить товар купленным для student_id. */
    private function buy(int $sid, int $itemid): void {
        global $DB;
        $DB->insert_record('unics_purchases', (object)[
            'student_id' => $sid, 'item_id' => $itemid, 'purchased_at' => time(),
        ]);
    }

    public function test_collection_lists_all_stickers_with_owned_flags(): void {
        $this->resetAfterTest();
        $sid = 777; // строка unics_students не нужна - метод ее не читает.

        $lightning = $this->make_item('Молния', 3, 30, 1, 'lightning');
        $owl       = $this->make_item('Сова',   3, 40, 2, 'owl');
        $gem       = $this->make_item('Самоцвет', 3, 60, 3, 'gem');
        $title     = $this->make_item('Умник', 1, 50, 1); // НЕ стикер - должен быть исключен.

        $this->buy($sid, $lightning);
        $this->buy($sid, $owl);
        $this->buy($sid, $title); // покупка титула не влияет на коллекцию стикеров.

        $col = points_manager::get_sticker_collection($sid);

        $this->assertSame(3, $col['total']);          // 3 стикера, титул исключен
        $this->assertSame(2, $col['owned_count']);    // куплены молния + сова
        $this->assertFalse($col['complete']);
        $this->assertCount(3, $col['items']);

        // Порядок sort_order: молния, сова, самоцвет.
        $this->assertSame($lightning, $col['items'][0]['id']);
        $this->assertTrue($col['items'][0]['owned']);
        $this->assertTrue($col['items'][1]['owned']);
        $this->assertFalse($col['items'][2]['owned']); // самоцвет не куплен
        $this->assertSame(60, $col['items'][2]['cost']);
        $this->assertSame('gem', $col['items'][2]['icon']);
    }

    public function test_collection_complete_when_all_owned(): void {
        $this->resetAfterTest();
        $sid = 778;
        $a = $this->make_item('Молния', 3, 30, 1, 'lightning');
        $b = $this->make_item('Сова',   3, 40, 2, 'owl');
        $this->buy($sid, $a);
        $this->buy($sid, $b);

        $col = points_manager::get_sticker_collection($sid);
        $this->assertSame(2, $col['total']);
        $this->assertSame(2, $col['owned_count']);
        $this->assertTrue($col['complete']);
    }

    public function test_collection_empty_when_no_stickers(): void {
        $this->resetAfterTest();
        $col = points_manager::get_sticker_collection(779);
        $this->assertSame(0, $col['total']);
        $this->assertSame(0, $col['owned_count']);
        $this->assertFalse($col['complete']); // complete только при total > 0
        $this->assertSame([], $col['items']);
    }
}
