<?php
namespace local_unics\health\checks;

use local_unics\adaptive\estimate_precision;
use local_unics\codifier_analytics;
use local_unics\health\check;
use local_unics\health\check_result;
use local_unics\item_irt_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Достижим ли настроенный порог точности CAT хоть на одном элементе.
 *
 * Замер 2026-09-08 ([[cat-attainable-precision]]): порог 0.3, стоявший на стенде, не сработал НИ
 * РАЗУ. Четыреста смоделированных сессий из четырехсот остановились по лимиту заданий, живые
 * сессии стенда - по исчерпанию пула, набрав 3-5 заданий и закончившись с ошибкой 0.75-0.97.
 *
 * Индикатор готовности к CAT это уже показывает, но там методист видит следствие, а число вводит
 * администратор в настройках - и там ему никто не говорил, что требование невыполнимо. Проверка
 * закрывает причину: она смотрит на САМЫЙ богатый пул установки и сравнивает достижимую им
 * точность с настроенным порогом (найдено ревью).
 *
 * Порог, который не срабатывает никогда, - тот же класс дефекта, что непроверяемое указание
 * модели: настройка есть, следствия у нее нет.
 */
class cat_threshold implements check {

    /**
     * Подменный размер лучшего пула для теста.
     *
     * Тот же прием, что у сетевых проверок: считать по базе в юнит-тесте значит заводить
     * кодификатор, вопросы и калибровку ради одного числа.
     *
     * @var callable|null
     */
    public $probe = null;

    public function name(): string {
        return 'cat_threshold';
    }

    public function title(): string {
        return 'Порог точности адаптивной проверки';
    }

    /**
     * Один агрегат по таблице параметров - дешево. Дешевые проверки считаются на КАЖДОЙ штабной
     * странице ради полосы состояния, поэтому тяжелого тут быть не должно.
     */
    public function is_cheap(): bool {
        return true;
    }

    public function run(): check_result {
        if ((int)get_config('local_unics', 'adaptive_cat_enabled') !== 1) {
            return check_result::ok('Адаптивная проверка выключена');
        }

        $best = $this->probe !== null ? (int)($this->probe)() : $this->best_pool();
        if ($best === 0) {
            // Пулов еще нет вовсе - это забота индикатора готовности, а не порога. Тревожить
            // администратора нечем: он не сможет сделать ничего, кроме как ждать калибровки.
            return check_result::ok('Калиброванных заданий пока нет');
        }

        $threshold = estimate_precision::threshold();
        $maxitems = (int)get_config('local_unics', 'cat_max_items');
        if ($maxitems <= 0) {
            $maxitems = 20;
        }
        // Считаем по дискриминации 1: гард Раша держит ее у единицы ниже порога 2PL, а он в свою
        // очередь высок ([[irt-calibration-precision]]). Это ОПТИМИСТИЧНАЯ оценка - реальные
        // задания попадают в способность хуже идеала, значит вывод «недостижим» надежен.
        $attainable = codifier_analytics::attainable_se(array_fill(0, $best, 1.0), $maxitems);
        if ($attainable <= $threshold) {
            return check_result::ok('Достижим: лучший пул дает '
                . format_float($attainable, 2) . ' при пороге ' . format_float($threshold, 2));
        }

        // Сколько заданий нужно на самом деле: SE = 1/sqrt(1 + n/4) -> n = 4 * (1/SE^2 - 1).
        $needed = (int)ceil(4.0 * (1.0 / ($threshold * $threshold) - 1.0));
        // lib.php грузится НЕ везде: проверка дешевая и считается на каждой штабной странице, а
        // зовется и из CLI. Без этой строки склонение уронило бы страницу «undefined function» -
        // тот же класс дефекта, что уже ловили у user_manager без автозагрузки.
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/lib.php');
        return check_result::attention(
            'Порог ' . format_float($threshold, 2) . ' недостижим: лучший пул дает '
                . format_float($attainable, 2),
            'Ни одна проверка не остановится по достигнутой точности - все дойдут до предела в '
            . $maxitems . ' заданий и будут помечены как предварительные. Либо снизьте порог в '
            . 'настройках адаптивной проверки, либо наберите больше калиброванных заданий: для '
            . 'этого порога нужно около ' . $needed . ' '
            . local_unics_plural($needed, 'задания', 'заданий', 'заданий') . ' на одну тему.',
            ['Лучший пул сейчас: ' . $best . ', предел заданий за сессию: ' . $maxitems]
        );
    }

    /**
     * Самый богатый пул установки: сколько калиброванных заданий у элемента-рекордсмена.
     *
     * Считаем по РАЗНЫМ заданиям (item_ref уникален в таблице параметров) и только по тем, что
     * прошли порог наблюдений: именно их и берет пул CAT.
     */
    private function best_pool(): int {
        global $DB;
        $sql = "SELECT MAX(cnt) FROM (
                    SELECT COUNT(1) AS cnt
                      FROM {unics_item_irt}
                     WHERE element_id IS NOT NULL AND calibrated_n >= :mincal
                  GROUP BY element_id
                ) t";
        return (int)$DB->get_field_sql($sql, ['mincal' => item_irt_manager::MIN_CALIBRATED_N]);
    }
}
