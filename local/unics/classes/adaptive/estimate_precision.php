<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * Достигла ли оценка способности заявленной точности.
 *
 * Проверка CAT останавливается по одному из трех условий: точность достигнута, кончился лимит
 * вопросов, кончились задания в банке. Наружу все три выглядели одинаково - готовой полосой
 * владения. Живой заход 2026-08-22 ([[probe-full-cycle-2026-08-22]]) закончился на четырех
 * заданиях с se = 0.769 при пороге 0.30, и ребенку показали «почти», как если бы это было
 * измерение.
 *
 * Для детей с ОВЗ цена ошибки здесь выше обычного: полоса уезжает в отчет педагогу и родителю,
 * а маршрут строится по ней же. Предварительную оценку надо называть предварительной.
 *
 * @package local_unics
 */
class estimate_precision {

    /** Порог точности по умолчанию - тот же, что у CAT (cat_se_threshold). */
    const DEFAULT_SE_THRESHOLD = 0.3;

    /** Настроенный порог точности. */
    public static function threshold(): float {
        $se = (float)get_config('local_unics', 'cat_se_threshold');
        return $se > 0 ? $se : self::DEFAULT_SE_THRESHOLD;
    }

    /**
     * Предварительна ли оценка.
     *
     * @param float|null $se стандартная ошибка; null - оценка получена не через IRT
     *                       (обычный путь rolling_avg), и говорить о точности нечего
     */
    public static function is_provisional(?float $se): bool {
        if ($se === null) {
            return false;
        }
        return $se > self::threshold();
    }

    /**
     * Пояснение для ребенка. Пустая строка, если оценка полноценная.
     *
     * Без цифр и без слова «погрешность»: ребенку важно, что проверка не закончена, а не то,
     * каков доверительный интервал.
     */
    public static function child_note(?float $se): string {
        return self::is_provisional($se)
            ? 'Вопросов пока мало, поэтому результат предварительный.' : '';
    }

    /** Пояснение для педагога и родителя. Пустая строка, если оценка полноценная. */
    public static function staff_note(?float $se): string {
        if (!self::is_provisional($se)) {
            return '';
        }
        return 'Предварительная оценка: точность ниже заявленной (стандартная ошибка '
            . number_format($se, 2, ',', ' ') . ' при пороге '
            . number_format(self::threshold(), 2, ',', ' ') . ').';
    }
}
