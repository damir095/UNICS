<?php
namespace local_unics;

/**
 * Смоук-тест mustache-шаблона статистики (генерическая история mustache-
 * слайсов "тяжелых страниц" 2.5 аудита, тот же метод для statistics.php):
 * рендер local_unics/statistics с фикстурными контекстами - список пуст /
 * полный (карточки + срезы со и без графика + кодификатор с таблицей) /
 * кодификаторы отсутствуют / дисциплина не выбрана / нет элементов -
 * шаблон валиден, маркеры секций и экранирование на месте.
 *
 * Таблицы срезов и кодификатора - доверенный пре-рендер html_writer::table()
 * (тот же принцип, что в umk_status: Moodle сама добавляет служебные классы
 * header/cN/lastcol/lastrow - копировать их вручную в mustache не нужно).
 *
 * Задача 3 (tables-redesign): фикстуры table_html несут обертку
 * .table-responsive прямо в строке - так же, как ее реально добавляет
 * html_writer::table() (html_table->responsive по умолчанию true,
 * lib/classes/output/html_writer.php). Шаблон НЕ добавляет вторую обертку -
 * иначе получились бы вложенные .table-responsive (проверено живьем и
 * отклонено).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class statistics_template_test extends \advanced_testcase {

    public function test_full_context_renders_all_sections(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/statistics', [
            'rebuild_url'         => '#',
            'export_buttons_html' => '<div class="mb-3">EXPORTMARK</div>',
            'cards' => [
                ['value' => '16', 'label' => 'Учащихся & <тест>'],
                ['value' => '71.9%', 'label' => 'Средний балл'],
            ],
            'slices' => [
                [
                    'title'      => 'По категории учащихся',
                    'has_data'   => true,
                    'chart_html' => '<div class="chart-area">CHARTMARK</div>',
                    'table_html' => '<div class="table-responsive">'
                        . '<table class="table table-striped table-hover unics-table unics-compact">SLICETABLEMARK</table></div>',
                ],
                [
                    'title'    => 'По региону & <срез>',
                    'has_data' => false,
                ],
                [
                    'title'       => 'По организации',
                    'has_data'    => true,
                    'table_html'  => '<div class="table-responsive">'
                        . '<table class="table table-striped table-hover unics-table unics-compact">ORGTABLEMARK</table></div>',
                    'paging_html' => '<nav class="unics-paging">PAGEMARK</nav>',
                ],
            ],
            'codifier' => [
                'tabs' => [
                    ['url' => '#', 'label' => 'География & <дисц>', 'cls' => 'btn-primary'],
                    ['url' => '#', 'label' => 'Математика', 'cls' => 'btn-outline-primary'],
                ],
                'table_html' => '<div class="table-responsive">'
                    . '<table class="table table-striped table-hover unics-table">CODIFTABLEMARK</table></div>',
            ],
        ]);

        $this->assertStringContainsString('EXPORTMARK', $html);
        $this->assertStringContainsString('Итого по скоупу', $html);
        $this->assertStringContainsString('Учащихся &amp; &lt;тест&gt;', $html);
        $this->assertStringContainsString('По категории учащихся', $html);
        $this->assertStringContainsString('CHARTMARK', $html);
        $this->assertStringContainsString('SLICETABLEMARK', $html);
        $this->assertStringContainsString('По региону &amp; &lt;срез&gt;', $html);
        $this->assertStringContainsString('Нет данных для этого среза.', $html);
        $this->assertStringContainsString('ORGTABLEMARK', $html);
        $this->assertStringContainsString('PAGEMARK', $html);
        $this->assertStringContainsString('География &amp; &lt;дисц&gt;', $html);
        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString('CODIFTABLEMARK', $html);
        $this->assertStringContainsString('Пересчитать сейчас', $html);
        $this->assertStringNotContainsString('Материалов', $html);
        $this->assertStringNotContainsString('<срез>', $html);

        // Единая система таблиц (задача 3 tables-redesign): обе таблицы срезов
        // с данными (категория, организация) и таблица кодификатора несут
        // обертку table-responsive (из html_writer::table(), не из шаблона -
        // шаблон ее не дублирует); срез без данных (регион) - без таблицы и
        // без обертки. Матрицы срезов - unics-compact (плотные), таблица
        // кодификатора - комфортная (без unics-compact).
        $this->assertSame(3, substr_count($html, 'table-responsive'));
        $this->assertSame(2, substr_count($html, 'unics-compact'));
        $this->assertStringContainsString('unics-table">CODIFTABLEMARK', $html);

        // Регрессия (грабля задачи 3, поймана живьем): доверенный
        // doc-комментарий {{! ... }} в верхушке шаблона не должен утечь в
        // вывод. Буквальные {{{...}}} внутри такого комментария закрывают
        // его преждевременно на первом же }} - остаток текста комментария
        // рендерится как есть. Держим комментарий БЕЗ фигурных скобок.
        $this->assertStringNotContainsString('ДИЗАЙН-РЕШЕНИЕ', $html);
        $this->assertStringNotContainsString('tables-redesign', $html);
        $this->assertStringNotContainsString('html_writer.php', $html);
    }

    public function test_empty_context_shows_notification_only(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/statistics', [
            'rebuild_url' => '#',
            'empty'       => ['html' => '<div class="alert alert-info">Нет данных в вашем скоупе.</div>'],
        ]);

        $this->assertStringContainsString('Пересчитать сейчас', $html);
        $this->assertStringContainsString('Нет данных в вашем скоупе.', $html);
        $this->assertStringNotContainsString('Итого по скоупу', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /** Кодификаторы отсутствуют вовсе - без вкладок. */
    public function test_codifier_no_codifiers(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/statistics', [
            'rebuild_url'         => '#',
            'export_buttons_html' => '',
            'cards'               => [],
            'slices'              => [],
            'codifier'            => ['no_codifiers' => true],
        ]);

        $this->assertStringContainsString('Кодификаторы ещё не созданы.', $html);
        $this->assertStringNotContainsString('btn-primary', $html);
        // Задача 3 tables-redesign: table_html не задан - карточка не рисуется
        // (обертку .table-responsive несет само table_html, шаблон не
        // добавляет свою).
        $this->assertStringNotContainsString('table-responsive', $html);
    }

    /** Дисциплина не выбрана - подсказка вместо таблицы. */
    public function test_codifier_pick_message(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/statistics', [
            'rebuild_url'         => '#',
            'export_buttons_html' => '',
            'cards'               => [],
            'slices'              => [],
            'codifier'            => [
                'tabs'         => [['url' => '#', 'label' => 'География', 'cls' => 'btn-outline-primary']],
                'pick_message' => true,
            ],
        ]);

        $this->assertStringContainsString('Выберите дисциплину', $html);
        $this->assertStringNotContainsString('<table', $html);
        // Задача 3 tables-redesign: table_html не задан - пустой карточки нет.
        $this->assertStringNotContainsString('table-responsive', $html);
    }

    /** Дисциплина выбрана, но элементов нет - подсказка вместо таблицы. */
    public function test_codifier_no_elements(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/statistics', [
            'rebuild_url'         => '#',
            'export_buttons_html' => '',
            'cards'               => [],
            'slices'              => [],
            'codifier'            => [
                'tabs'        => [['url' => '#', 'label' => 'География', 'cls' => 'btn-primary']],
                'no_elements' => true,
            ],
        ]);

        $this->assertStringContainsString('В этом кодификаторе нет элементов.', $html);
        $this->assertStringNotContainsString('<table', $html);
        // Задача 3 tables-redesign: table_html не задан (элементов нет) -
        // обертка table-responsive не должна рендериться пустой карточкой.
        $this->assertStringNotContainsString('table-responsive', $html);
    }
}
