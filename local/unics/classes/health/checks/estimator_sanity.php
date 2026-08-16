<?php
namespace local_unics\health\checks;

use local_unics\adaptive\estimator_factory;
use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Установлен ли оценщик владения, выбранный в настройках.
 *
 * Риск появился вместе с точкой расширения 2026-08-14: подплагин можно удалить папкой, а настройка
 * останется указывать на него. Ядро в этом случае молча откатывается на встроенный расчет - это
 * правильно для устойчивости, но администратор должен знать, что работает не то, что он выбрал.
 */
class estimator_sanity implements check {

    public function name(): string {
        return 'estimator_sanity';
    }

    public function title(): string {
        return 'Оценщик владения навыком';
    }

    public function is_cheap(): bool {
        return true;
    }

    public function run(): check_result {
        $selected = (string)get_config('local_unics', 'mastery_estimator');
        if ($selected === '') {
            return check_result::ok('Встроенный расчет');
        }
        $installed = estimator_factory::installed();
        if (!array_key_exists($selected, $installed)) {
            return check_result::alarm(
                'Выбран оценщик «' . $selected . '», но он не установлен',
                'Система считает встроенным расчетом. Установите подплагин или выберите '
                . '«Встроенный» в настройках адаптивного обучения.'
            );
        }
        return check_result::ok($installed[$selected]);
    }
}
