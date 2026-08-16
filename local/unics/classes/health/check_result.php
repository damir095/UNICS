<?php
namespace local_unics\health;

defined('MOODLE_INTERNAL') || die();

/**
 * Исход одной проверки здоровья.
 *
 * Несет не код ошибки, а две человеческие строки: что случилось и что сделать. Страницу читает
 * администратор школы без знания PHP - «ERROR 402» ему бесполезен. [[health-page-design]]
 */
class check_result {

    /** Все в порядке. */
    const OK = 0;
    /** Работает, но требует внимания. В полосу тревоги НЕ идет. */
    const ATTENTION = 1;
    /** Часть системы не работает. Поднимает полосу тревоги. */
    const ALARM = 2;

    /**
     * @param int $level один из OK / ATTENTION / ALARM
     * @param string $summary что произошло, одной фразой
     * @param string $action что сделать; пусто при OK
     * @param array $details пары «метка => значение» для страницы
     */
    public function __construct(
        public readonly int $level,
        public readonly string $summary,
        public readonly string $action = '',
        public readonly array $details = []
    ) {
    }

    public static function ok(string $summary, array $details = []): self {
        return new self(self::OK, $summary, '', $details);
    }

    public static function attention(string $summary, string $action, array $details = []): self {
        return new self(self::ATTENTION, $summary, $action, $details);
    }

    public static function alarm(string $summary, string $action, array $details = []): self {
        return new self(self::ALARM, $summary, $action, $details);
    }
}
