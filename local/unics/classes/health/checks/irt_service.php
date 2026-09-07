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
                // Совет пишется для АДМИНИСТРАТОРА ШКОЛЫ без знания PHP - так требует контракт
                // check_result. Прежняя редакция велела ему править константу в классе, чего он
                // сделать не может, и расхождение осталось бы (найдено ревью). Техническая деталь
                // ушла в подробности - там ее прочтет разработчик.
                return check_result::attention(
                    'Отвечает, но пороги разошлись: у сервиса ' . (int)$theirs . ', у нас ' . $ours,
                    'Сообщите разработчику: расчетный сервис и плагин ждут разного числа ответов '
                    . 'для оценки задания. Пока они разные, колонка «2PL» и подсказка о нехватке '
                    . 'учащихся считаются по неверному порогу.',
                    ['Привести item_irt_manager::MIN_N_FOR_2PL к MIN_RESPONSES_FOR_2PL '
                     . 'из ai-service/app/irt.py']
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

    /**
     * Порог 2PL из ответа /health или null, если сервис его не отдает.
     *
     * Вынесено отдельно, чтобы путь до поля проверялся тестом. Оба теста сверки подставляют зонд,
     * то есть саму раскладку ответа не трогают: переименуй поле на стороне сервиса (он живет в
     * ОТДЕЛЬНОМ репозитории и меняется независимо) - и `?? null` съел бы это молча, а страница
     * вечно говорила бы «Отвечает» при разошедшихся порогах (найдено ревью).
     */
    public static function threshold_from_health(array $info): ?int {
        $v = $info['thresholds']['min_responses_for_2pl'] ?? null;
        return is_numeric($v) ? (int)$v : null;
    }

    /** Ответ сервиса целиком: свой HTTP не изобретаем, берем `irt_client::health_info()`. */
    private function live_probe(): array {
        try {
            $info = \local_unics\adaptive\irt_client::health_info();
            if ($info === null) {
                return ['ok' => false, 'message' => 'нет ответа'];
            }
            return [
                'ok'      => true,
                'message' => 'ok',
                'min_n_for_2pl' => self::threshold_from_health($info),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
