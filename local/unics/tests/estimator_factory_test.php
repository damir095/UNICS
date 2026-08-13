<?php
namespace local_unics;

use local_unics\adaptive\estimator_factory;
use local_unics\adaptive\rolling_avg_estimator;

/**
 * Точка расширения оценщика: тип подплагинов unicsest + выбор реализации с откатом.
 * [[estimator-subplugin-design]]
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(estimator_factory::class)]
final class estimator_factory_test extends \advanced_testcase {

    public function test_subplugin_type_is_registered(): void {
        $types = \core_component::get_plugin_types();
        $this->assertArrayHasKey('unicsest', $types,
            'тип подплагинов не объявлен в db/subplugins.json');
        $this->assertStringEndsWith('local/unics/estimator', str_replace('\\', '/', $types['unicsest']));
    }

    public function test_default_is_builtin(): void {
        $this->resetAfterTest();
        set_config('mastery_estimator', '', 'local_unics');
        set_config('adaptive_irt_enabled', 0, 'local_unics');
        $this->assertInstanceOf(rolling_avg_estimator::class, estimator_factory::make());
    }

    public function test_legacy_flag_selects_irt_until_phase_two(): void {
        $this->resetAfterTest();
        set_config('mastery_estimator', '', 'local_unics');
        set_config('adaptive_irt_enabled', 1, 'local_unics');
        $this->assertInstanceOf(\local_unics\adaptive\irt_estimator::class, estimator_factory::make());
    }

    public function test_unknown_component_falls_back_to_builtin(): void {
        $this->resetAfterTest();
        $this->assertInstanceOf(rolling_avg_estimator::class,
            estimator_factory::make('unicsest_такогонет'));
        // Молчаливый откат недопустим: причина обязана попасть в лог разработчика.
        // Сообщение сверяется целиком - иначе тест не отличает свою причину отката
        // от чужой и проходит по случайности (поймано мутацией).
        $this->assertDebuggingCalled(
            'local_unics: оценщик unicsest_такогонет не найден, беру встроенный');
    }

    public function test_component_not_implementing_interface_falls_back(): void {
        $this->resetAfterTest();
        // Класс есть, но контракт не выполняет - брать его нельзя. Без ЯВНОЙ проверки
        // контракта откат все равно произошел бы, но по TypeError на возврате, и
        // диагностика для админа была бы нечитаемой.
        $this->assertInstanceOf(rolling_avg_estimator::class,
            estimator_factory::make('unicsest_notanestimator'));
        $this->assertDebuggingCalled(
            'local_unics: оценщик unicsest_notanestimator не реализует контракт, беру встроенный');
    }

    public function test_valid_component_is_used(): void {
        $this->resetAfterTest();
        $this->assertInstanceOf(\unicsest_stub\estimator::class,
            estimator_factory::make('unicsest_stub'));
    }

    public function test_throwing_component_falls_back_to_builtin(): void {
        $this->resetAfterTest();
        $this->assertInstanceOf(rolling_avg_estimator::class,
            estimator_factory::make('unicsest_boom'));
        $this->assertDebuggingCalled(
            'local_unics: оценщик unicsest_boom упал при создании (подплагин сломан), беру встроенный');
    }
}

/**
 * Заглушки для проверки контракта. Живут в тесте, а не на диске: настоящий подплагин
 * появится в фазе 2, а механизм выбора должен быть проверен уже сейчас.
 */
namespace unicsest_stub;

class estimator implements \local_unics\adaptive\mastery_estimator {
    public function estimate(?\local_unics\adaptive\mastery_state $prior, array $ctx): \local_unics\adaptive\mastery_state {
        return new \local_unics\adaptive\mastery_state(42.0, \local_unics\adaptive\mastery_bands::BAND_MID, 1);
    }
}

namespace unicsest_notanestimator;

/** Класс с нужным именем, но без реализации интерфейса. */
class estimator {
}

namespace unicsest_boom;

class estimator implements \local_unics\adaptive\mastery_estimator {
    public function __construct() {
        throw new \RuntimeException('подплагин сломан');
    }
    public function estimate(?\local_unics\adaptive\mastery_state $prior, array $ctx): \local_unics\adaptive\mastery_state {
        return new \local_unics\adaptive\mastery_state(0.0, 0, 0);
    }
}
