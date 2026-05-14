<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class points_manager {

    // Типы причин начисления/списания
    const REASON_UMK_READY = 1;  // +20 - УМК готов
    const REASON_BADGE     = 2;  // +50 - получен значок
    const REASON_LEVEL_UP  = 3;  // +100 - уровень повышен
    const REASON_QUIZ_PASS = 4;  // +10 - тест сдан
    const REASON_PURCHASE  = 5;  // -N  - покупка в магазине

    // Размер начислений
    const POINTS_UMK_READY = 20;
    const POINTS_BADGE     = 50;
    const POINTS_LEVEL_UP  = 100;
    const POINTS_QUIZ_PASS = 10;

    /**
     * Начислить баллы учащемуся. Возвращает новый баланс.
     */
    public static function award(int $student_id, int $points, int $reason_type, string $reason_text): int {
        global $DB;

        $DB->insert_record('unics_points_log', (object)[
            'student_id'  => $student_id,
            'points'      => $points,
            'reason_type' => $reason_type,
            'reason_text' => mb_substr($reason_text, 0, 200),
            'created_at'  => time(),
        ]);

        $DB->execute(
            "UPDATE {unics_students} SET points = points + :pts WHERE id = :sid",
            ['pts' => $points, 'sid' => $student_id]
        );

        return self::get_balance($student_id);
    }

    /**
     * Списать баллы за покупку. Возвращает false если недостаточно баллов.
     */
    public static function spend(int $student_id, int $cost, string $reason_text): bool {
        global $DB;

        if (self::get_balance($student_id) < $cost) {
            return false;
        }

        $DB->insert_record('unics_points_log', (object)[
            'student_id'  => $student_id,
            'points'      => -$cost,
            'reason_type' => self::REASON_PURCHASE,
            'reason_text' => mb_substr($reason_text, 0, 200),
            'created_at'  => time(),
        ]);

        $DB->execute(
            "UPDATE {unics_students} SET points = points - :pts WHERE id = :sid",
            ['pts' => $cost, 'sid' => $student_id]
        );

        return true;
    }

    /**
     * Получить текущий баланс учащегося.
     */
    public static function get_balance(int $student_id): int {
        global $DB;
        $val = $DB->get_field('unics_students', 'points', ['id' => $student_id]);
        return $val !== false ? (int)$val : 0;
    }

    /**
     * История баллов учащегося (последние $limit записей).
     */
    public static function get_history(int $student_id, int $limit = 15): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT id, points, reason_type, reason_text, created_at
               FROM {unics_points_log}
              WHERE student_id = :sid
              ORDER BY created_at DESC, id DESC
              LIMIT " . (int)$limit,
            ['sid' => $student_id]
        ));
    }

    /**
     * Проверить, есть ли у учащегося достаточно баллов для покупки.
     */
    public static function can_afford(int $student_id, int $cost): bool {
        return self::get_balance($student_id) >= $cost;
    }

    /**
     * Купить товар из магазина.
     * Возвращает true при успехе, строку с ошибкой при неудаче.
     */
    public static function purchase(int $student_id, int $item_id): bool|string {
        global $DB;

        $item = $DB->get_record('unics_shop_items', ['id' => $item_id, 'is_active' => 1]);
        if (!$item) {
            return 'Товар не найден';
        }

        // Каждый товар можно купить только один раз
        if ($DB->record_exists('unics_purchases', ['student_id' => $student_id, 'item_id' => $item_id])) {
            return 'Товар уже приобретён';
        }

        if (!self::spend($student_id, (int)$item->cost, 'Покупка: ' . $item->name)) {
            return 'Недостаточно баллов';
        }

        $DB->insert_record('unics_purchases', (object)[
            'student_id'   => $student_id,
            'item_id'      => $item_id,
            'purchased_at' => time(),
        ]);

        return true;
    }

    /**
     * Получить список покупок учащегося с информацией о товарах.
     */
    public static function get_purchases(int $student_id): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT p.id, p.purchased_at, s.name, s.icon_emoji, s.description
               FROM {unics_purchases} p
               JOIN {unics_shop_items} s ON s.id = p.item_id
              WHERE p.student_id = :sid
              ORDER BY p.purchased_at DESC",
            ['sid' => $student_id]
        ));
    }

    /**
     * Получить активный титул учащегося (последняя покупка типа «титул»).
     */
    public static function get_active_title(int $student_id): ?object {
        global $DB;
        return $DB->get_record_sql(
            "SELECT s.name, s.icon_emoji
               FROM {unics_purchases} p
               JOIN {unics_shop_items} s ON s.id = p.item_id
              WHERE p.student_id = :sid AND s.item_type = 1
              ORDER BY p.purchased_at DESC
              LIMIT 1",
            ['sid' => $student_id]
        ) ?: null;
    }

    public static function reason_label(int $type): string {
        return match ($type) {
            self::REASON_UMK_READY => 'Готов УМК',
            self::REASON_BADGE     => 'Значок',
            self::REASON_LEVEL_UP  => 'Повышение уровня',
            self::REASON_QUIZ_PASS => 'Тест',
            self::REASON_PURCHASE  => 'Покупка',
            default                => 'Другое',
        };
    }
}
