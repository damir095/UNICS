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
        $message = self::readable((string)($res['message'] ?? ''));
        return check_result::alarm(
            'Ключ задан, но сервис не отвечает: ' . $message,
            'Проверьте правильность ключа и доступ в интернет с сервера.',
            ['Ответ сервиса' => $message]
        );
    }

    /**
     * Причина отказа в виде, годном для страницы.
     *
     * Сообщение приходит из moodle_exception. Корень беды починен 2026-08-18: наши 22 вызова
     * больше не передают русскую фразу как идентификатор langstring. Срез префикса оставлен
     * намеренно - сообщение может прийти и из ядра Moodle, где такой префикс законен. Длину
     * ограничиваем: полный ответ сервиса администратору без знания PHP все равно ничего не
     * скажет.
     */
    private static function readable(string $message): string {
        $message = trim(preg_replace('#^error/#', '', trim($message)));
        if ($message === '') {
            return 'нет ответа';
        }
        return \core_text::strlen($message) > 200
            ? \core_text::substr($message, 0, 200) . '...'
            : $message;
    }

    /** Живой запрос: авторизация в GigaChat через существующий генератор. */
    private function live_probe(): array {
        return (new \local_unics\ai\ai_generator())->probe_auth();
    }
}
