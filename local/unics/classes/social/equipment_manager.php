<?php
namespace local_unics\social;

defined('MOODLE_INTERNAL') || die();

/**
 * Слот-модель экипировки учащегося (этап 3 мотивации, [[title-equipment-design]]).
 * Один надетый предмет на слот (UNIQUE student_id+slot в unics_equipped).
 * Три слота: title/frame/accent.
 *
 * @package local_unics
 */
class equipment_manager {

    /** Тип товара (unics_shop_items.item_type) -> слот. Расширяется одной строкой. */
    const ITEM_TYPE_SLOT = [
        1 => 'title',   // Титул/звание
        2 => 'frame',   // Рамка аватара
        3 => 'sticker', // Стикер-карточка (носимый любимый стикер)
        4 => 'accent',  // Акцент дашборда
    ];

    /** Слот для типа товара, или null (тип без слота, напр. несуществующий/резервный). */
    public static function slot_for_item_type(int $item_type): ?string {
        return self::ITEM_TYPE_SLOT[$item_type] ?? null;
    }

    /** Надетый предмет в слоте (объект товара + equipped_at) или null. */
    public static function get_equipped(int $student_id, string $slot): ?object {
        global $DB;
        return $DB->get_record_sql(
            "SELECT s.id, s.name, s.icon, s.icon_emoji, s.item_type, s.effect_key, e.equipped_at
               FROM {unics_equipped} e
               JOIN {unics_shop_items} s ON s.id = e.item_id
              WHERE e.student_id = :sid AND e.slot = :slot",
            ['sid' => $student_id, 'slot' => $slot]
        ) ?: null;
    }

    /** Надеть купленный предмет в его слот. true или строка-причина отказа. */
    public static function equip(int $student_id, int $item_id): bool|string {
        global $DB;
        $item = $DB->get_record('unics_shop_items', ['id' => $item_id, 'is_active' => 1]);
        if (!$item) {
            return 'Товар не найден';
        }
        if (!$DB->record_exists('unics_purchases',
                ['student_id' => $student_id, 'item_id' => $item_id])) {
            return 'Товар не приобретен';
        }
        $slot = self::slot_for_item_type((int)$item->item_type);
        if ($slot === null) {
            return 'Этот товар нельзя надеть';
        }
        self::set_slot($student_id, $slot, $item_id);
        return true;
    }

    /** Снять предмет со слота (идемпотентно). */
    public static function unequip(int $student_id, string $slot): void {
        global $DB;
        $DB->delete_records('unics_equipped', ['student_id' => $student_id, 'slot' => $slot]);
    }

    /** Автоэкипировка при первой покупке слот-предмета: если слот пуст - надеть. */
    public static function auto_equip_if_empty(int $student_id, int $item_id): void {
        global $DB;
        $item = $DB->get_record('unics_shop_items', ['id' => $item_id]);
        if (!$item) {
            return;
        }
        $slot = self::slot_for_item_type((int)$item->item_type);
        if ($slot === null) {
            return;
        }
        if (!$DB->record_exists('unics_equipped',
                ['student_id' => $student_id, 'slot' => $slot])) {
            self::set_slot($student_id, $slot, $item_id);
        }
    }

    /**
     * Backfill слота титула из последней покупки титула (миграция + идемпотентно).
     * Точно воспроизводит прежнее поведение get_active_title («последний купленный»).
     * @return int число вставленных строк.
     */
    public static function backfill_titles(): int {
        global $DB;
        $inserted = 0;
        $sids = $DB->get_fieldset_sql(
            "SELECT DISTINCT p.student_id
               FROM {unics_purchases} p
               JOIN {unics_shop_items} s ON s.id = p.item_id
              WHERE s.item_type = 1"
        );
        foreach ($sids as $sid) {
            if ($DB->record_exists('unics_equipped',
                    ['student_id' => $sid, 'slot' => 'title'])) {
                continue;
            }
            $last = $DB->get_record_sql(
                "SELECT p.item_id
                   FROM {unics_purchases} p
                   JOIN {unics_shop_items} s ON s.id = p.item_id
                  WHERE p.student_id = :sid AND s.item_type = 1
                  ORDER BY p.purchased_at DESC, p.id DESC
                  LIMIT 1",
                ['sid' => $sid]
            );
            if ($last) {
                $DB->insert_record('unics_equipped', (object)[
                    'student_id'  => (int)$sid,
                    'slot'        => 'title',
                    'item_id'     => (int)$last->item_id,
                    'equipped_at' => time(),
                ]);
                $inserted++;
            }
        }
        return $inserted;
    }

    /** Внутренний upsert: ровно одна строка на (student, slot). */
    private static function set_slot(int $student_id, string $slot, int $item_id): void {
        global $DB;
        $row = $DB->get_record('unics_equipped',
            ['student_id' => $student_id, 'slot' => $slot]);
        if ($row) {
            $row->item_id = $item_id;
            $row->equipped_at = time();
            $DB->update_record('unics_equipped', $row);
        } else {
            $DB->insert_record('unics_equipped', (object)[
                'student_id'  => $student_id,
                'slot'        => $slot,
                'item_id'     => $item_id,
                'equipped_at' => time(),
            ]);
        }
    }
}
