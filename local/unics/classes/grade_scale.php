<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Единая шкала оценивания УНИКС.
 *
 * Тесты (quiz) и развёрнутые задания (эссе, открытые ответы) приводятся
 * к одной шкале, чтобы их можно было сравнивать в отчётах и в проверке.
 * Шкала 5-балльная (школьная); при необходимости меняется в одном месте — MAX.
 */
class grade_scale {

    /** Максимум единой шкалы. Менять только здесь. */
    const MAX = 5;

    /** Человекочитаемое имя шкалы (для подписей в UI). */
    public static function label(): string {
        return 'из ' . self::MAX;
    }

    /** Процент (0..100) → единая шкала. */
    public static function from_percent(float $pct): float {
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 100) {
            $pct = 100;
        }
        return round($pct / 100 * self::MAX, 1);
    }

    /** Сырой балл/максимум Moodle → единая шкала. */
    public static function from_raw(float $final, float $max): float {
        if ($max <= 0) {
            return 0.0;
        }
        return self::from_percent($final / $max * 100);
    }

    /** Bootstrap-класс бейджа по значению единой шкалы. */
    public static function badge_class(float $val): string {
        $pct = self::MAX > 0 ? $val / self::MAX * 100 : 0;
        return $pct >= 85 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
    }
}
