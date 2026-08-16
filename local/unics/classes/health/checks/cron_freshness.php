<?php
namespace local_unics\health\checks;

use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Работают ли плановые задачи вообще.
 *
 * Инцидент 2026-08-16: задача Windows «Moodle UNCS cron» оказалась выключена, и НИ ОДНА плановая
 * задача не запускалась 40 дней - на машине разработчика, который смотрит в систему ежедневно.
 * Не работали: ночная переоценка уровней, сборка статистики, дренаж очереди ИИ, доставка
 * уведомлений. Интерфейс при этом открывался как ни в чем не бывало.
 */
class cron_freshness implements check {

    /** Порог тревоги. Штатный интервал запуска - 10 минут, три пропуска уже аномалия. */
    const STALE_AFTER = 1800;

    public function name(): string {
        return 'cron_freshness';
    }

    public function title(): string {
        return 'Плановые задачи (cron)';
    }

    public function is_cheap(): bool {
        return true;
    }

    public function run(): check_result {
        global $DB;
        $last = (int)$DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
        $action = 'Включите задачу «Moodle UNCS cron» в Планировщике заданий Windows '
            . '(нужны права администратора). Без нее не работают ночная переоценка уровней, '
            . 'сборка статистики, генерация УМК и доставка уведомлений.';

        if ($last <= 0) {
            return check_result::alarm('Плановые задачи не запускались ни разу', $action);
        }
        $ago = time() - $last;
        if ($ago > self::STALE_AFTER) {
            return check_result::alarm(
                'Плановые задачи не запускались ' . format_time($ago),
                $action,
                ['Последний запуск' => userdate($last)]
            );
        }
        return check_result::ok('Работают, последний запуск ' . format_time($ago) . ' назад',
            ['Последний запуск' => userdate($last)]);
    }
}
