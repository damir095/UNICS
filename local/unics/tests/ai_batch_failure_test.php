<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Пачечный отказ сервиса картинок: пауза перед повтором и предохранитель.
 *
 * Замер 2026-08-10: отказы приходят ПАЧКАМИ, а повтор бил в ту же секунду - на пачке он
 * обречен. Хуже другое: комплект честно перебирал все девять картинок, тратя на мертвый
 * сервис до девяти минут (9 картинок x 2 попытки x 30 секунд таймаута) и все равно
 * оставаясь без единой иллюстрации.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class ai_batch_failure_test extends \advanced_testcase {

    /** Генератор, у которого запрос за картинкой всегда падает; считает обращения и паузы. */
    private function always_failing(): ai_generator {
        return new class extends ai_generator {
            public int $network_calls = 0;
            public int $pauses = 0;
            protected function fetch_image_uuid(string $prompt): string {
                $this->network_calls++;
                throw new \moodle_exception('GigaChat image cURL ошибка: Operation timed out');
            }
            protected function pause_before_retry(int $attempt): void {
                $this->pauses++;
            }
        };
    }

    private function set_key(): void {
        set_config('ai_api_key', 'ключ', 'local_unics');
    }

    /** Перед второй попыткой генератор обязан выждать, а не бить в ту же секунду. */
    public function test_retry_waits_before_second_attempt(): void {
        $this->resetAfterTest();
        $this->set_key();
        $gen = $this->always_failing();

        ob_start();
        try {
            $gen->generate_image('промт');
        } catch (\Throwable $e) {
            // Ожидаемо: сервис недоступен.
        }
        ob_end_clean();

        $this->assertSame(2, $gen->network_calls, 'попыток по-прежнему две');
        $this->assertSame(1, $gen->pauses, 'между ними обязана быть ровно одна пауза');
    }

    /**
     * После трех подряд неудавшихся картинок генератор перестает ходить в сеть.
     *
     * Ради этого все и делается: девять картинок комплекта на мертвом сервисе стоили до
     * девяти минут и давали ноль иллюстраций. Теперь потолок - три картинки.
     */
    public function test_batch_failure_stops_hitting_the_network(): void {
        $this->resetAfterTest();
        $this->set_key();
        $gen = $this->always_failing();

        ob_start();
        for ($i = 0; $i < 9; $i++) {
            try {
                $gen->generate_image('промт ' . $i);
            } catch (\Throwable $e) {
                // Каждая попытка ожидаемо падает.
            }
        }
        ob_end_clean();

        $this->assertSame(6, $gen->network_calls,
            'три картинки по две попытки - и предохранитель закрывается');
    }

    /** Предохранитель не должен срабатывать, пока отказы не идут подряд. */
    public function test_success_resets_the_counter(): void {
        $this->resetAfterTest();
        $this->set_key();
        $gen = new class extends ai_generator {
            public int $network_calls = 0;
            /** Падает всегда, кроме третьего обращения. */
            protected function fetch_image_uuid(string $prompt): string {
                $this->network_calls++;
                if ($this->network_calls === 3) {
                    return 'uuid-ok';
                }
                throw new \moodle_exception('GigaChat image cURL ошибка: Operation timed out');
            }
            protected function download_image(string $uuid): string {
                return 'картинка';
            }
            protected function pause_before_retry(int $attempt): void {
            }
        };

        $results = [];
        ob_start();
        for ($i = 0; $i < 5; $i++) {
            try {
                $results[] = $gen->generate_image('промт ' . $i) !== '' ? 'ok' : 'пусто';
            } catch (\Throwable $e) {
                $results[] = 'отказ';
            }
        }
        ob_end_clean();

        // Первая картинка: две неудачные попытки. Вторая: удача с первой (третье обращение) -
        // счетчик обнулен. Дальше снова отказы, и предохранителю нужны новые три подряд.
        $this->assertSame('ok', $results[1], 'удачная генерация обязана пройти');
        // Арифметика: 2 обращения на первую картинку, 1 на удачную вторую (счетчик
        // обнулен), дальше по 2 на каждую из трех оставшихся = 9. Без обнуления удача
        // не разрывала бы серию, предохранитель закрылся бы на пятой картинке, и
        // обращений вышло бы 7. Разница между 9 и 7 и есть проверяемое поведение.
        $this->assertSame(9, $gen->network_calls,
            'удача обязана разрывать серию неудач');
    }
}
