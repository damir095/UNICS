<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Распознавание отказа модели ([[ai-refusal-detector-design]]).
 *
 * Зачем: 2026-08-09 GigaChat вернул на теме «Круговорот воды в природе» цензурную
 * болванку вместо учебного текста, она легла в курс страницей материала, а УМК
 * получил статус «готов». Ребенок с ОВЗ получил бы мусор вместо урока, и увидеть
 * это можно было только глазами.
 *
 * Класс чистый: ни сети, ни БД.
 *
 * @package local_unics
 */
class refusal_detector {

    /** finish_reason, которым GigaChat помечает сработавший фильтр. Замерено трижды. */
    public const BLACKLIST = 'blacklist';

    /**
     * Фразы-болванки отказа.
     *
     * Нужны сверх finish_reason, потому что отказов ДВА вида: blacklist-ный (ровно 336
     * символов, воспроизводится надежно) и мягкий - тот, что реально лег в курс. Мягкий
     * не воспроизвелся ни разу за семь живых прогонов, и его finish_reason неизвестен.
     *
     * Пополняется правкой этой константы. Порога длины тут намеренно нет: материалы для
     * категории «длительное лечение» заказываются на 250-350 слов, и порог давал бы
     * ложные срабатывания на законных коротких текстах.
     */
    private const MARKERS = [
        'не обладает собственным мнением',
        'связанные с чувствительными темами',
        'временно ограничены',
    ];

    /**
     * @param string $text сырой ответ модели, ДО output_style::clean()
     * @param string $finish_reason поле ответа; пустая строка = неизвестен
     */
    public static function is_refusal(string $text, string $finish_reason): bool {
        if ($finish_reason === self::BLACKLIST) {
            return true;
        }

        // Пустой ответ ловит проверка длины в generate_text_gigachat() - не дублируем.
        if (trim($text) === '') {
            return false;
        }

        foreach (self::MARKERS as $marker) {
            if (mb_stripos($text, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
