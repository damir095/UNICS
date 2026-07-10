<?php
namespace local_unics\identity;

defined('MOODLE_INTERNAL') || die();

/**
 * Валидатор ФИО: запрет символов HTML-разметки на входе (follow-up этапа 4.4).
 * Наши страницы экранируют вывод, но ядровые отчеты - нет; блокируем на входе.
 *
 * @package local_unics
 */
final class name_validator {

    /** Есть ли в имени запрещенные символы разметки (< или >). */
    public static function has_markup(?string $name): bool {
        return $name !== null && preg_match('/[<>]/', $name) === 1;
    }
}
