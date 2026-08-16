<?php
namespace local_unics\health\checks;

use local_unics\ai\tts_status;
use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Работает ли синтез речи. Проверка ДЕШЕВАЯ и намеренно БЕЗ зонда.
 *
 * В проекте уже есть `ai\tts_status`, и в его спеке записано: «знание берется из РЕАЛЬНОЙ попытки
 * генерации: ни зондов по расписанию, ни лишних обращений к Сберу»
 * ([[tts-honest-availability-design]]). Синтетический зонд противоречил бы этому решению и добавил
 * бы обращений к платному контуру ради сведений, которые в системе уже есть.
 *
 * Инцидент 2026-08-10: пакет SaluteSpeech не оплачен, сервис отвечает 402 на ДВУХ ключах. Кодом
 * не чинится, потому уровень «внимание», а не «авария»: система работает, озвучки просто нет.
 * Первый удачный синтез снимет метку сам.
 */
class salute_speech implements check {

    public function name(): string {
        return 'salute_speech';
    }

    public function title(): string {
        return 'SaluteSpeech (озвучка)';
    }

    public function is_cheap(): bool {
        return true;
    }

    public function run(): check_result {
        $key = trim((string)get_config('local_unics', 'salute_speech_api_key'));
        if ($key === '') {
            return check_result::attention(
                'Ключ не задан',
                'Озвучка выключена. Укажите ключ в Администрирование -> УНИКС -> Настройки ИИ, '
                . 'если она нужна.'
            );
        }
        if (!tts_status::is_unavailable()) {
            return check_result::ok('Работала при последней попытке');
        }
        $reason = tts_status::reason();
        $at = tts_status::marked_at();
        $action = strpos($reason, '402') !== false
            ? 'Оплатите пакет SmartSpeech в личном кабинете Сбера. Кодом это не решается; после '
              . 'оплаты первый же удачный синтез снимет метку автоматически.'
            : 'Проверьте ключ и доступ в интернет с сервера. Метка снимется сама при первом '
              . 'удачном синтезе.';
        return check_result::attention(
            'Синтез отказал при последней реальной попытке: ' . $reason,
            $action,
            $at > 0 ? ['Метка поставлена' => userdate($at)] : []
        );
    }
}
