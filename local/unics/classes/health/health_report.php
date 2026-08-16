<?php
namespace local_unics\health;

defined('MOODLE_INTERNAL') || die();

/**
 * Сбор проверок здоровья, кеш и сводный уровень. [[health-page-design]]
 *
 * Деление на дешевые и дорогие - несущее. Дешевые ходят только в свою БД и считаются в том числе
 * для полосы тревоги на каждой странице; дорогие ходят по сети и запускаются лишь по кнопке,
 * иначе чужой таймаут повесил бы админку.
 */
class health_report {

    /** Сколько живет кеш дешевых проверок для полосы тревоги. */
    const CHEAP_TTL = 300;

    /** Все проверки в порядке показа. */
    public static function checks(): array {
        return [
            new checks\cron_freshness(),
            new checks\ai_queue_backlog(),
            new checks\ai_queue_stuck(),
            new checks\adhoc_backlog(),
            new checks\estimator_sanity(),
            new checks\ai_queue_failures(),
            new checks\gigachat(),
            new checks\salute_speech(),
            new checks\irt_service(),
        ];
    }

    /**
     * Дешевые проверки, посчитанные сейчас.
     *
     * @return array name => check_result
     */
    public static function cheap(): array {
        $out = [];
        foreach (self::checks() as $check) {
            if (!$check->is_cheap()) {
                continue;
            }
            try {
                $out[$check->name()] = $check->run();
            } catch (\Throwable $e) {
                // Сломанная проверка не должна ронять страницу, на которой висит полоса.
                debugging('local_unics health: проверка ' . $check->name() . ' упала: '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $out;
    }

    /**
     * Только аварии, для полосы тревоги. Кешируется на CHEAP_TTL секунд: полоса считается на
     * каждой штабной странице, и без кеша это были бы лишние запросы на всю навигацию.
     *
     * @return array name => check_result уровня ALARM
     */
    public static function alarms(): array {
        $cache = \cache::make('local_unics', 'health');
        $entry = $cache->get('alarms');
        if (is_array($entry) && isset($entry['at']) && (time() - (int)$entry['at']) < self::CHEAP_TTL) {
            return $entry['data'];
        }
        $alarms = array_filter(self::cheap(), fn($r) => $r->level === check_result::ALARM);
        $cache->set('alarms', ['at' => time(), 'data' => $alarms]);
        return $alarms;
    }

    /** Сбросить кеш полосы (после ручного прогона проверок на странице). */
    public static function forget(): void {
        \cache::make('local_unics', 'health')->delete('alarms');
    }

    /** Худший уровень из набора исходов. */
    public static function worst(array $results): int {
        $worst = check_result::OK;
        foreach ($results as $r) {
            if ($r->level > $worst) {
                $worst = $r->level;
            }
        }
        return $worst;
    }
}
