<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Память о недоступности синтеза речи ([[tts-honest-availability-design]]).
 *
 * Зачем: у аккаунта Сбера нет оплаченного пакета SmartSpeech, синтез отвечает 402
 * Payment Required. Замерено на двух ключах и четырех сочетаниях голоса и формата -
 * кодом это не чинится. Но интерфейс не должен предлагать педагогу галочку, которая
 * заведомо ничего не даст.
 *
 * Знание берется из РЕАЛЬНОЙ попытки генерации: ни зондов по расписанию, ни лишних
 * обращений к Сберу. Оплатят пакет - первый удачный синтез снимет метку сам.
 *
 * @package local_unics
 */
class tts_status {

    /** Причина недоступности; пустая строка = озвучка доступна. */
    private const REASON = 'tts_unavailable_reason';

    /** Время установки метки - администратору, чтобы отличить свежую от прошлогодней. */
    private const AT = 'tts_unavailable_at';

    /**
     * Пометить озвучку недоступной. Вызывать ТОЛЬКО на устойчивый отказ (HTTP 402):
     * таймаут и пятисотка - временные неурядицы, выключать из-за них функцию нельзя.
     */
    public static function mark_unavailable(string $reason): void {
        $reason = trim($reason);
        if ($reason === '') {
            return;
        }
        set_config(self::REASON, $reason, 'local_unics');
        set_config(self::AT, time(), 'local_unics');
    }

    /** Снять метку: синтез удался, значит пакет оплачен. */
    public static function mark_available(): void {
        set_config(self::REASON, '', 'local_unics');
        set_config(self::AT, 0, 'local_unics');
    }

    public static function is_unavailable(): bool {
        return self::reason() !== '';
    }

    public static function reason(): string {
        return trim((string)get_config('local_unics', self::REASON));
    }
}
