<?php
namespace local_unics;

use local_unics\item_irt_manager;

/**
 * Надпись модели калибровки обязана отражать то, чем калибровка была на деле.
 *
 * Зонд 2026-08-22 ([[probe-full-cycle-2026-08-22]]): все девять заданий стенда получили
 * model = '2pl' при дискриминации ровно 1.000. Сервис отдает поле discrimination ВСЕГДА, а при
 * числе ответов меньше двадцати включает гард Раша и возвращает единицу. Смотреть надо на само
 * значение, а не на присутствие поля.
 *
 * @package local_unics
 */
final class irt_model_label_test extends \advanced_testcase {

    private function model_of(int $ref): string {
        global $DB;
        return (string)$DB->get_field('unics_item_irt', 'model', ['item_ref' => $ref]);
    }

    public function test_discrimination_one_is_recorded_as_rasch(): void {
        $this->resetAfterTest();
        // Гард Раша на стороне сервиса: дискриминация не оценивалась, ее подставили единицей.
        item_irt_manager::upsert(101, null, -1.2, 12, 1.0);
        $this->assertSame('rasch', $this->model_of(101));
    }

    public function test_real_discrimination_is_recorded_as_2pl(): void {
        $this->resetAfterTest();
        item_irt_manager::upsert(102, null, 0.4, 25, 1.63);
        $this->assertSame('2pl', $this->model_of(102));
    }

    public function test_missing_discrimination_is_rasch(): void {
        $this->resetAfterTest();
        item_irt_manager::upsert(103, null, 0.0, 30);
        $this->assertSame('rasch', $this->model_of(103));
    }

    public function test_label_follows_a_new_calibration(): void {
        $this->resetAfterTest();
        // Задание набрало ответов, и дискриминация стала настоящей: надпись обязана догнать.
        item_irt_manager::upsert(104, null, -0.3, 12, 1.0);
        $this->assertSame('rasch', $this->model_of(104));
        item_irt_manager::upsert(104, null, -0.3, 22, 0.78);
        $this->assertSame('2pl', $this->model_of(104));
    }

    public function test_almost_one_is_still_rasch(): void {
        // Точное сравнение с единицей опасно: число проходит через сеть, JSON и round().
        $this->resetAfterTest();
        item_irt_manager::upsert(105, null, 0.1, 15, 1.0001);
        $this->assertSame('rasch', $this->model_of(105));
    }

    public function test_clearly_different_discrimination_is_not_swallowed(): void {
        // Допуск не должен съедать настоящую оценку около единицы.
        $this->resetAfterTest();
        item_irt_manager::upsert(106, null, 0.1, 25, 1.05);
        $this->assertSame('2pl', $this->model_of(106));
    }
}
