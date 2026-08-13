<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * Выбор реализации оценщика владения. Ядро не знает имен реализаций: подплагины типа
 * unicsest находятся через core_component, встроенный расчет - запасной вариант.
 * [[estimator-subplugin-design]]
 *
 * Порядок разрешения: явный аргумент -> настройка mastery_estimator -> legacy-флаг
 * adaptive_irt_enabled (живет до фазы 2, пока IRT не переехал в подплагин) -> встроенный.
 *
 * Отказ подплагина НИКОГДА не роняет адаптивный цикл: нет класса, класс не реализует
 * контракт, конструктор бросил - берем встроенный оценщик.
 */
class estimator_factory {

    /** Тип подплагинов (см. db/subplugins.json). */
    const PLUGINTYPE = 'unicsest';

    /**
     * Установленные оценщики-подплагины.
     *
     * @return array component => человекочитаемое имя, например ['unicsest_irt' => 'IRT (Раш)']
     */
    public static function installed(): array {
        $out = [];
        foreach (\core_component::get_plugin_list(self::PLUGINTYPE) as $name => $path) {
            $component = self::PLUGINTYPE . '_' . $name;
            $out[$component] = get_string('pluginname', $component);
        }
        return $out;
    }

    /**
     * Оценщик для работы.
     *
     * @param string|null $component null = разрешать по настройкам
     */
    public static function make(?string $component = null): mastery_estimator {
        if ($component === null) {
            $component = (string)get_config('local_unics', 'mastery_estimator');
            if ($component === '' && (int)get_config('local_unics', 'adaptive_irt_enabled') === 1) {
                // Фаза 1: IRT еще внутри ядра, выбирается старым флагом.
                return new irt_estimator();
            }
        }
        if ($component === '') {
            return new rolling_avg_estimator();
        }

        $class = '\\' . $component . '\\estimator';
        if (!class_exists($class)) {
            debugging("local_unics: оценщик $component не найден, беру встроенный", DEBUG_DEVELOPER);
            return new rolling_avg_estimator();
        }
        if (!in_array(mastery_estimator::class, class_implements($class) ?: [], true)) {
            debugging("local_unics: оценщик $component не реализует контракт, беру встроенный", DEBUG_DEVELOPER);
            return new rolling_avg_estimator();
        }
        try {
            return new $class();
        } catch (\Throwable $e) {
            debugging("local_unics: оценщик $component упал при создании ({$e->getMessage()}), беру встроенный",
                DEBUG_DEVELOPER);
            return new rolling_avg_estimator();
        }
    }
}
