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
final class estimator_factory_test extends \advanced_testcase {

    public function test_subplugin_type_is_registered(): void {
        $types = \core_component::get_plugin_types();
        $this->assertArrayHasKey('unicsest', $types,
            'тип подплагинов не объявлен в db/subplugins.json');
        $this->assertStringEndsWith('local/unics/estimator', str_replace('\\', '/', $types['unicsest']));
    }
}
