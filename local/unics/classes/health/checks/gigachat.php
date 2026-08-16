<?php
namespace local_unics\health\checks;

use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Отвечает ли сервис генерации текста.
 *
 * Инцидент 2026-08-06: живой ключ GigaChat был перезаписан мусором, и генерация встала. Ключ при
 * этом оставался НЕПУСТОЙ строкой - проверка «задан ли ключ» такое не ловит, ловит только живой
 * запрос. Поэтому проверка дорогая и запускается по кнопке.
 */
class gigachat implements check {

    /** Подставляемый зонд для тестов: fn(): array{ok: bool, message: string}. */
    public ?\Closure $probe = null;

    public function name(): string {
        return 'gigachat';
    }

    public function title(): string {
        return 'GigaChat (генерация текста)';
    }

    public function is_cheap(): bool {
        return false;
    }

    public function run(): check_result {
        $key = trim((string)get_config('local_unics', 'ai_api_key'));
        if ($key === '') {
            return check_result::alarm(
                'Ключ не задан',
                'Укажите ключ в Администрирование -> УНИКС -> Настройки ИИ. Без него не работает '
                . 'генерация УМК и проверка развернутых ответов.'
            );
        }
        $res = $this->probe !== null ? ($this->probe)() : $this->live_probe();
        if (!empty($res['ok'])) {
            return check_result::ok('Отвечает');
        }
        return check_result::alarm(
            'Ключ задан, но сервис не отвечает: ' . ($res['message'] ?? 'нет ответа'),
            'Проверьте правильность ключа и доступ в интернет с сервера.',
            ['Ответ сервиса' => (string)($res['message'] ?? '')]
        );
    }

    /** Живой запрос: авторизация в GigaChat через существующий генератор. */
    private function live_probe(): array {
        return (new \local_unics\ai\ai_generator())->probe_auth();
    }
}
