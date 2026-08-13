<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * Проекция способности theta на шкалу score 0..100 - соглашение ЯДРА, а не дело оценщика.
 *
 * Раньше метод жил статикой на `irt_estimator`, и его звали из ядра в двух местах:
 * `mastery_manager::record_cat_mastery()` (итог CAT-сессии) и `pages/cat.php` (экран
 * результата). Обе точки работают независимо от того, какой оценщик выбран, поэтому
 * при переезде IRT в подплагин проекция обязана была остаться здесь: иначе ядро
 * зависело бы от установленного подплагина. [[estimator-subplugin-design]]
 */
class theta_scale {

    /** Проекция theta -> score 0..100 (логистическая шкала; theta=0 -> 50). */
    public static function project(float $theta): float {
        return round(100.0 / (1.0 + exp(-$theta)), 2);
    }
}
