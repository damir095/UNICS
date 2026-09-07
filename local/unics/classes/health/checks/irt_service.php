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
            // Порог 2PL живет в ДВУХ местах: в сервисе и копией у нас - методисту надо показывать
            // расстояние до оценки дискриминации. Копия держалась на одной лишь фразе «при
            // изменении порога в сервисе править и здесь» и разъехалась при первом же изменении
            // (найдено ревью). Сверяем вслух: молчаливое расхождение делает колонку «2PL» и
            // подсказку «нужно еще N учащихся» неверными.
            $theirs = $res['min_n_for_2pl'] ?? null;
            $ours   = \local_unics\item_irt_manager::MIN_N_FOR_2PL;
            if ($theirs !== null && (int)$theirs !== $ours) {
                return check_result::attention(
                    'Отвечает, но пороги разошлись: у сервиса ' . (int)$theirs . ', у нас ' . $ours,
                    'Приведите item_irt_manager::MIN_N_FOR_2PL к значению сервиса '
                    . '(MIN_RESPONSES_FOR_2PL в ai-service/app/irt.py). Пока они разные, колонка '
                    . '«2PL» и подсказка «нужно еще N учащихся» считаются по неверному порогу.'
                );
            }
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
            $info = \local_unics\adaptive\irt_client::health_info();
            if ($info === null) {
                return ['ok' => false, 'message' => 'нет ответа'];
            }
            return [
                'ok'      => true,
                'message' => 'ok',
                // Старый сервис порогов не отдает - тогда сверять нечего, и это не повод тревожить.
                'min_n_for_2pl' => $info['thresholds']['min_responses_for_2pl'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
