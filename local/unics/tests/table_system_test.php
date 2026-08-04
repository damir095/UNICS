<?php
namespace local_unics;

/**
 * Единая система таблиц «Мягкие карточки» на штабных страницах ([[tables-staff-design]]).
 *
 * Два уровня проверки. Первый - юнит-тест хелпера local_unics_table_class():
 * строка классов должна быть ровно той же, что задача 1 поставила в mustache
 * группы C, иначе система расщепится на два вида.
 *
 * Второй - структурный сканер ИСХОДНИКОВ pages/*.php (добавляется задачей 5).
 * Он читает файлы, а не отрендеренный HTML: 23 страницы - это 23 разных гейта
 * доступа и набора данных, поднимать под каждую рендер-стенд несоразмерно, а
 * регрессия здесь всегда выглядит как вернувшийся литерал класса в исходнике.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class table_system_test extends \advanced_testcase {

    public function test_helper_returns_comfortable_classes_by_default(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/lib.php');

        $this->assertSame(
            'table table-striped table-hover unics-table',
            local_unics_table_class()
        );
    }

    public function test_helper_appends_compact_modifier(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/lib.php');

        $this->assertSame(
            'table table-striped table-hover unics-table unics-compact',
            local_unics_table_class(true)
        );
    }

    public function test_helper_matches_string_already_used_by_group_c(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/lib.php');

        // statistics.php:259 - строка, поставленная задачей 1. Хелпер обязан ее
        // воспроизводить дословно, иначе штабные страницы разъедутся с группой C.
        $source = file_get_contents($CFG->dirroot . '/local/unics/pages/statistics.php');
        $this->assertStringContainsString(
            "'" . local_unics_table_class() . "'",
            $source
        );
        $this->assertStringContainsString(
            "'" . local_unics_table_class(true) . "'",
            $source
        );
    }
}
