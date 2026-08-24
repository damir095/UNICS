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

    /** Настроенный порог точности. Единственный источник правды - зовет и сам CAT. */
    public static function threshold(): float {
        $se = (float)get_config('local_unics', 'cat_se_threshold');
        return $se > 0 ? $se : self::DEFAULT_SE_THRESHOLD;
    }

    /**
     * Предварительна ли оценка.
     *
     * Сравнение нестрогое: сервис останавливается по «se СТРОГО меньше порога», значит ровно на
     * пороге точность не достигнута, и называть такую оценку законченной нельзя.
     *
     * @param float|null $se стандартная ошибка; null - оценка получена не через IRT
     *                       (обычный путь rolling_avg), и говорить о точности нечего
     */
    public static function is_provisional(?float $se): bool {
        if ($se === null) {
            return false;
        }
        return $se >= self::threshold();
    }

    /**
     * Причины остановки, которые называет сервис (ai-service/app/cat.py).
     *
     * Строки дублируют контракт сервиса; незнакомое значение обрабатывается запасным путем,
     * поэтому переименование на той стороне не ломает нас молча - оно лишь возвращает к
     * сравнению с порогом.
     */
    const REASON_PRECISION = 'se_reached';
    const REASON_MAX_ITEMS = 'max_items';
    const REASON_BANK_EXHAUSTED = 'bank_exhausted';

    /**
     * Предварительна ли оценка сессии CAT - по СОХРАНЕННОЙ причине остановки.
     *
     * Проверка останавливается по одному из трех условий, и сервис прямо называет, по какому.
     * Раньше причина терялась, а признак выводился сравнением с ТЕКУЩЕЙ настройкой: смена
     * порога задним числом переписывала вердикт по всем прошлым проверкам.
     *
     * Запасной путь - для сессий, завершенных до появления поля: у них причины нет, и судить
     * можно только сравнением. Неизвестная причина (сервис добавил новую) тоже идет запасным
     * путем: молча объявлять оценку законченной нельзя.
     */
    public static function session_is_provisional(object $session): bool {
        $reason = (string)($session->stop_reason ?? '');
        if ($reason === self::REASON_PRECISION) {
            return false;
        }
        if ($reason === self::REASON_MAX_ITEMS || $reason === self::REASON_BANK_EXHAUSTED) {
            return true;
        }
        $se = isset($session->theta_se) ? (float)$session->theta_se : null;
        if ($se === null) {
            // Ни причины, ни ошибки: про такую проверку не известно ничего, и объявлять ее
            // законченной нельзя. У обычной записи владения null означает «оценка не из IRT»,
            // но у СЕССИИ CAT он означает «мерить нечем» (найдено ревью).
            return true;
        }
        return self::is_provisional($se);
    }

    /**
     * Предварительна ли нынешняя оценка ученика по этому элементу.
     *
     * Решает ПОСЛЕДНЯЯ завершенная проверка: ребенок мог пройти тему заново и довести ее до
     * точности. Проверки не было вовсе - значит оценка получена обычным путем, о точности
     * говорить нечего, и ограничивать по ней нечего ([[provisional-suggestions]]).
     */
    public static function is_element_provisional(int $student_id, int $element_id): bool {
        $session = \local_unics\learning\cat_session_manager::latest_finished(
            $student_id, $element_id);
        return $session ? self::session_is_provisional($session) : false;
    }

    /**
     * Пояснение ребенку по сохраненной причине остановки. Пустая строка - оценка полная.
     *
     * Текст живет здесь, а не на странице: раньше страница держала свою копию строки, и правка
     * в этом классе не меняла бы ничего из того, что ребенок видит.
     */
    public static function child_note_for_session(object $session): string {
        if (!self::session_is_provisional($session)) {
            return '';
        }
        // «Вопросов пока мало» неправда, когда проверка кончилась НА ЛИМИТЕ вопросов.
        if ((string)($session->stop_reason ?? '') === self::REASON_MAX_ITEMS) {
            return 'Вопросов было много, но точного результата пока не получилось. '
                . 'Попробуй эту тему позже.';
        }
        return 'Вопросов пока мало, поэтому результат предварительный.';
    }

    /**
     * Пояснение персоналу по сессии - с порогом, который действовал ТОГДА.
     *
     * Прежний staff_note() сравнивал с нынешней настройкой, и подсказка спорила с вердиктом:
     * при мягком пороге она вовсе пустела, оставляя пометку «предварительно» без объяснения.
     */
    public static function staff_note_for_session(object $session): string {
        if (!self::session_is_provisional($session)) {
            return '';
        }
        $se = isset($session->theta_se) ? (float)$session->theta_se : null;
        $threshold = isset($session->se_threshold)
            ? (float)$session->se_threshold : self::threshold();
        $reasons = [
            self::REASON_MAX_ITEMS => 'кончился лимит вопросов',
            self::REASON_BANK_EXHAUSTED => 'кончились задания темы',
        ];
        $why = $reasons[(string)($session->stop_reason ?? '')] ?? 'точность не достигнута';
        return 'Предварительная оценка: ' . $why
            . ($se !== null
                ? ' (стандартная ошибка ' . number_format($se, 2, ',', ' ')
                    . ' при пороге ' . number_format($threshold, 2, ',', ' ') . ')'
                : '');
    }

    /**
     * Та ли это оценка, которую сняла IRT, или запись уже пересчитана обычным путем.
     *
     * `theta` и `theta_se` в записи владения ПЕРЕЖИВАЮТ обновления не-IRT путем: mastery_manager
     * сохраняет их через `?? $row->theta`. Поэтому после нескольких обычных тестов в записи
     * лежит балл, снятый процентами, и рядом - стандартная ошибка давнего прохождения CAT.
     * Признак предварительности к такому баллу отношения не имеет.
     *
     * Отличаем без нового поля схемы: балл, снятый IRT, есть проекция theta и с ней совпадает.
     */
    public static function is_irt_estimate(?float $theta, ?float $score): bool {
        if ($theta === null || $score === null) {
            return false;
        }
        return abs(theta_scale::project($theta) - $score) < 0.01;
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
