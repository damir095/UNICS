<?php
namespace local_unics;

use local_unics\health\check_result;
use local_unics\health\checks\gigachat;
use local_unics\health\checks\irt_service;

/**
 * Дорогие проверки: ходят по сети, поэтому в тестах зонд подставляется.
 * Конфигурационной проверки мало - оба реальных инцидента (перезаписанный ключ GigaChat и
 * неоплаченный SaluteSpeech) происходили при НЕПУСТОМ и внешне правильном ключе.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(gigachat::class)]
final class health_expensive_test extends \advanced_testcase {

    public function test_missing_key_is_alarm_without_network(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', '', 'local_unics');
        $check = new gigachat();
        // Зонд не подставлен: при пустом ключе он и не должен вызываться.
        $check->probe = function () {
            $this->fail('при пустом ключе в сеть ходить нельзя');
        };
        $r = $check->run();
        $this->assertSame(check_result::ALARM, $r->level);
    }

    public function test_key_present_and_service_answers_is_ok(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'ключ', 'local_unics');
        $check = new gigachat();
        $check->probe = fn() => ['ok' => true, 'message' => 'ответ получен'];
        $this->assertSame(check_result::OK, $check->run()->level);
    }

    public function test_key_present_but_service_refuses_is_alarm(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'протухший', 'local_unics');
        $check = new gigachat();
        $check->probe = fn() => ['ok' => false, 'message' => 'HTTP 401'];
        $r = $check->run();
        $this->assertSame(check_result::ALARM, $r->level);
        $this->assertStringContainsString('401', $r->summary . ' ' . implode(' ', $r->details));
    }

    public function test_irt_service_not_needed_is_ok_without_network(): void {
        $this->resetAfterTest();
        set_config('mastery_estimator', '', 'local_unics');
        set_config('adaptive_cat_enabled', 0, 'local_unics');
        $check = new irt_service();
        $check->probe = function () {
            $this->fail('сервис никому не нужен - проверять нечего');
        };
        $this->assertSame(check_result::OK, $check->run()->level);
    }

    /**
     * Разошедшиеся пороги - повод сказать вслух, а не промолчать.
     *
     * Порог 2PL живет в двух местах: в сервисе и копией в item_irt_manager. Копия держалась на
     * одной лишь фразе «при изменении порога в сервисе править и здесь» и разъехалась при первом
     * же изменении (найдено ревью). Молчаливое расхождение делает колонку «2PL» и подсказку
     * «нужно еще N учащихся» неверными.
     */
    public function test_threshold_drift_is_reported(): void {
        $this->resetAfterTest();
        set_config('adaptive_cat_enabled', 1, 'local_unics');
        $check = new irt_service();
        $check->probe = fn() => ['ok' => true, 'message' => 'ok',
            'min_n_for_2pl' => \local_unics\item_irt_manager::MIN_N_FOR_2PL + 1];

        $res = $check->run();

        $this->assertSame(check_result::ATTENTION, $res->level);
        $this->assertStringContainsString('пороги разошлись', $res->summary);
    }

    /**
     * Путь до поля в ответе /health проверяется отдельно.
     *
     * Оба теста сверки подставляют зонд, то есть саму раскладку ответа не трогают. Сервис живет в
     * ОТДЕЛЬНОМ репозитории и меняется независимо: переименуй поле - и `?? null` съел бы это
     * молча, а страница вечно говорила бы «Отвечает» при разошедшихся порогах (найдено ревью).
     *
     * Образец взят с живого ответа сервиса.
     */
    public function test_threshold_is_read_from_the_real_health_shape(): void {
        $this->resetAfterTest();

        $this->assertSame(200, irt_service::threshold_from_health(
            ['status' => 'ok', 'thresholds' => ['min_responses_for_2pl' => 200]]));
        // Старый сервис: порогов нет вовсе.
        $this->assertNull(irt_service::threshold_from_health(['status' => 'ok']));
        // Поле переехало или переименовано - молча за ноль не считаем.
        $this->assertNull(irt_service::threshold_from_health(
            ['status' => 'ok', 'thresholds' => ['min_n' => 200]]));
    }

    /**
     * И то, что срабатывать НЕ должно: совпадающие пороги и старый сервис без порогов.
     *
     * Второй случай важен отдельно: сервис, который порогов не отдает, сверять не с чем, и
     * тревожить администратора там не за что.
     */
    public function test_matching_or_absent_threshold_is_ok(): void {
        $this->resetAfterTest();
        set_config('adaptive_cat_enabled', 1, 'local_unics');

        $same = new irt_service();
        $same->probe = fn() => ['ok' => true, 'message' => 'ok',
            'min_n_for_2pl' => \local_unics\item_irt_manager::MIN_N_FOR_2PL];
        $this->assertSame(check_result::OK, $same->run()->level);

        $old = new irt_service();
        $old->probe = fn() => ['ok' => true, 'message' => 'ok'];
        $this->assertSame(check_result::OK, $old->run()->level,
            'старый сервис порогов не отдает - сверять нечего');
    }

    /**
     * Дорогих в РЕЕСТРЕ ровно две. Озвучка сюда не входит: она читает метку tts_status, без сети.
     *
     * Список берется из health_report::checks(), а не перечисляется руками (найдено ревью:
     * ручной список пропустил бы новую сетевую проверку, помеченную дешевой - а дешевые
     * считаются на КАЖДОЙ штабной странице ради полосы, и чужой таймаут вешал бы админку).
     */
    public function test_only_two_checks_are_expensive(): void {
        $this->resetAfterTest();
        $expensive = [];
        foreach (\local_unics\health\health_report::checks() as $c) {
            if (!$c->is_cheap()) {
                $expensive[] = $c->name();
            }
        }
        sort($expensive);
        $this->assertSame(['gigachat', 'irt_service'], $expensive,
            'сетевая проверка обязана быть дорогой, а дешевая - не ходить по сети');
    }
}
