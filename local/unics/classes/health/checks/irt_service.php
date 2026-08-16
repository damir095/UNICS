<?php
namespace local_unics\health\checks;

use local_unics\adaptive\estimator_factory;
use local_unics\adaptive\item_response_consumer;
use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Доступен ли Python-сервис IRT - но только если он кому-то нужен.
 *
 * Потребителей два: оценщик-подплагин, потребляющий ответы по заданиям, и адаптивная проверка CAT.
 * Если ни один не включен, сервис не нужен, и красить страницу в аварию из-за выключенного
 * сервиса было бы ложной тревогой.
 */
class irt_service implements check {

    /** Подставляемый зонд для тестов: fn(): array{ok: bool, message: string}. */
    public ?\Closure $probe = null;

    public function name(): string {
        return 'irt_service';
    }

    public function title(): string {
        return 'Python-сервис IRT';
    }

    public function is_cheap(): bool {
        return false;
    }

    public function run(): check_result {
        $needed_by_estimator = estimator_factory::make() instanceof item_response_consumer;
        $needed_by_cat = (int)get_config('local_unics', 'adaptive_cat_enabled') === 1;
        if (!$needed_by_estimator && !$needed_by_cat) {
            return check_result::ok('Не используется (оценщик встроенный, CAT выключен)');
        }
        $res = $this->probe !== null ? ($this->probe)() : $this->live_probe();
        if (!empty($res['ok'])) {
            return check_result::ok('Отвечает');
        }
        return check_result::alarm(
            'Сервис недоступен: ' . ($res['message'] ?? 'нет ответа'),
            'Запустите сервис из каталога ai-service (см. его README) или переключите оценщик на '
            . '«Встроенный» в настройках адаптивного обучения. Пока сервис недоступен, владение '
            . 'считается встроенным расчетом.'
        );
    }

    /** `irt_client::health()` уже есть в проекте - свой HTTP не изобретаем. */
    private function live_probe(): array {
        try {
            $ok = \local_unics\adaptive\irt_client::health();
            return ['ok' => $ok, 'message' => $ok ? 'ok' : 'нет ответа'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
