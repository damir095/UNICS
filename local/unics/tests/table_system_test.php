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

    /** Все 23 штабные страницы с таблицами системы. */
    private const TABLE_PAGES = [
        'adaptive_suggestions.php', 'assign.php', 'codifier.php', 'codifier_tag.php',
        'course_delegation.php', 'course_final_exam.php', 'course_levels.php',
        'course_milestones.php', 'course_report.php', 'course_students.php',
        'course_templates.php', 'courses.php', 'enrol_students.php', 'enrol_teachers.php',
        'essay_check.php', 'import_users.php', 'my_students.php', 'organizations.php',
        'promote_students.php', 'setup_roles.php', 'shop.php', 'umk_status.php', 'users.php',
    ];

    /** Страницы, где хотя бы одна таблица плотная: матрицы и крупные операционные списки. */
    private const COMPACT_PAGES = [
        'course_milestones.php', 'enrol_students.php', 'enrol_teachers.php',
        'import_users.php', 'my_students.php', 'setup_roles.php', 'umk_status.php', 'users.php',
    ];

    /**
     * Страницы, где обертку таблицы пишем руками. Остальные либо строят таблицу
     * через html_writer::table() (обертку ставит ядро), либо держат таблицу внутри
     * card-body (см. NESTED_IN_CARD).
     */
    private const MANUAL_WRAPPER_PAGES = [
        'adaptive_suggestions.php', 'codifier.php', 'codifier_tag.php', 'course_final_exam.php',
        'course_levels.php', 'course_milestones.php', 'course_report.php', 'course_students.php',
        'courses.php', 'essay_check.php', 'organizations.php', 'shop.php',
    ];

    /**
     * Таблицы внутри бутстраповской карточки: штатная обертка сама рисует карточку и
     * дала бы карточку в карточке, поэтому скролл берется утилитой на card-body.
     */
    private const NESTED_IN_CARD = ['course_templates.php', 'setup_roles.php'];

    private function page_source(string $page): string {
        global $CFG;
        $path = $CFG->dirroot . '/local/unics/pages/' . $page;
        $this->assertFileExists($path, "Страница {$page} не найдена");
        return file_get_contents($path);
    }

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

    public function test_no_page_carries_legacy_bootstrap_table_classes(): void {
        foreach (self::TABLE_PAGES as $page) {
            $source = $this->page_source($page);
            $this->assertStringNotContainsString('table-bordered', $source,
                "{$page}: вертикальные линии table-bordered - от них редизайн уходил");
            $this->assertStringNotContainsString('table-sm', $source,
                "{$page}: плотность задается unics-compact, а не table-sm");
            $this->assertStringNotContainsString('thead class="table-light"', $source,
                "{$page}: table-light на thead перебивает шапку системы");
        }
    }

    public function test_every_page_builds_class_through_the_helper(): void {
        foreach (self::TABLE_PAGES as $page) {
            $this->assertStringContainsString('local_unics_table_class(', $this->page_source($page),
                "{$page}: строка классов должна приходить из хелпера, а не литералом");
        }
    }

    public function test_compact_pages_ask_for_the_dense_variant(): void {
        foreach (self::TABLE_PAGES as $page) {
            $source = $this->page_source($page);
            $expected = in_array($page, self::COMPACT_PAGES, true);
            $this->assertSame($expected, str_contains($source, 'local_unics_table_class(true)'),
                $expected
                    ? "{$page}: матрица или крупный список - ожидается компактная плотность"
                    : "{$page}: компактная плотность здесь не предусмотрена");
        }
    }

    public function test_manual_tables_are_wrapped_and_nested_ones_are_not(): void {
        foreach (self::MANUAL_WRAPPER_PAGES as $page) {
            $this->assertSame(1, substr_count($this->page_source($page), 'table-responsive'),
                "{$page}: ровно одна обертка .table-responsive - ни нуля, ни дубля");
        }
        foreach (self::NESTED_IN_CARD as $page) {
            $source = $this->page_source($page);
            $this->assertStringNotContainsString('table-responsive', $source,
                "{$page}: таблица внутри card-body, обертка дала бы карточку в карточке");
            $this->assertStringContainsString('overflow-x-auto', $source,
                "{$page}: горизонтальный скролл берется утилитой на card-body");
        }
    }
}
