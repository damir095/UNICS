<?php
namespace local_unics;

/**
 * Смоук-тест mustache-шаблона сводного отчета по организации (2.5 аудита,
 * слайс 3): рендер local_unics/org_report с фикстурными контекстами трех
 * взаимоисключающих состояний страницы (нет учащихся / не найдена / полный
 * отчет) - шаблон валиден, маркеры секций и экранирование на месте.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class org_report_template_test extends \advanced_testcase {

    /** Полный отчет: селектор + группа риска + средний балл + рекомендации. */
    private function full_context(): array {
        return [
            'users_url' => '#',
            'selector'  => ['options' => [
                ['id' => 3, 'label' => 'г. Тюмень / МАОУ СОШ №9 & <орг>', 'selected' => true],
                ['id' => 4, 'label' => 'г. Тюмень / Центр дистанционного обучения', 'selected' => false],
            ]],
            'header' => [
                'org_name'            => 'МАОУ СОШ №9 & <орг>',
                'export_buttons_html' => '<div class="mb-3">EXPORTMARK</div>',
            ],
            'report' => [
                'stats' => [
                    ['value' => 4, 'label' => 'Учащихся', 'value_class' => null, 'card_class' => null],
                    ['value' => 3, 'label' => 'В группе риска', 'value_class' => 'text-danger', 'card_class' => 'border-danger'],
                    ['value' => 1, 'label' => 'Без риска', 'value_class' => 'text-success', 'card_class' => null],
                    ['value' => 0, 'label' => 'Нет данных', 'value_class' => 'text-muted', 'card_class' => null],
                ],
                'charts' => [
                    ['title' => 'Распределение по уровням', 'html' => '<div class="chart-area">CHARTMARK1</div>'],
                    ['title' => 'Группа риска', 'html' => '<div class="chart-area">CHARTMARK2</div>'],
                ],
                'risk_toggle' => [
                    'url' => '#', 'label' => 'Показать только группу риска (3)',
                    'cls' => 'btn btn-sm btn-outline-danger',
                ],
                'rows' => [
                    [
                        'row_class' => 'table-danger',
                        'fio' => 'Алексеева Полина & <тест>', 'class_str' => '8 «Б»',
                        'cat_label' => 'Одарённый ребёнок', 'level_label' => 'Стандарт',
                        'avg_badge' => ['class' => 'danger', 'text' => '2.1/5'],
                        'risk' => ['reasons' => 'низкий средний балл; нет активности 40 дн.'],
                        'nodata' => false,
                        'courses' => 2, 'umk' => 2,
                        'report_url' => '#',
                    ],
                    [
                        'row_class' => '',
                        'fio' => 'Новиков Артем', 'class_str' => '5 «В»',
                        'cat_label' => 'ОВЗ', 'level_label' => 'Продвинут.',
                        'avg_badge' => ['class' => 'success', 'text' => '4.5/5'],
                        'risk' => null,
                        'nodata' => false,
                        'courses' => 2, 'umk' => 1,
                        'report_url' => '#',
                    ],
                    [
                        'row_class' => '',
                        'fio' => 'Иванов Без Данных', 'class_str' => '-',
                        'cat_label' => '-', 'level_label' => 'Базовый',
                        'avg_badge' => null,
                        'risk' => null,
                        'nodata' => true,
                        'courses' => 0, 'umk' => 0,
                        'report_url' => '#',
                    ],
                ],
                'org_avg'    => ['badge_class' => 'warning', 'text' => '3.6/5'],
                'risk_alert' => ['low_avg_text' => '2.5/5', 'trend_drop' => 10, 'idle_days' => 21],
            ],
        ];
    }

    public function test_full_report_renders_all_sections(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/org_report', $this->full_context());

        $this->assertStringContainsString('name="org_id"', $html);
        $this->assertStringContainsString('г. Тюмень / МАОУ СОШ №9 &amp; &lt;орг&gt;', $html);
        $this->assertStringContainsString('МАОУ СОШ №9 &amp; &lt;орг&gt;</h5>', $html);
        $this->assertStringContainsString('<div class="mb-3">EXPORTMARK</div>', $html);

        // Мини-карточки сводки: значение + условный класс карточки/цифры.
        $this->assertSame(4, substr_count($html, 'class="card text-center p-2'));
        $this->assertStringContainsString('card text-center p-2 border-danger', $html);
        $this->assertStringContainsString('<div class="h4 text-danger">3</div>', $html);

        // Оба графика - доверенный пре-рендер.
        $this->assertStringContainsString('CHARTMARK1', $html);
        $this->assertStringContainsString('CHARTMARK2', $html);

        // Переключатель риска.
        $this->assertStringContainsString('Показать только группу риска (3)', $html);

        // Таблица: 3 строки, три разных состояния «риска».
        $this->assertSame(1, substr_count($html, 'class="table-danger"'));
        $this->assertStringContainsString('badge badge-danger" title="низкий средний балл', $html);
        $this->assertStringContainsString('<span class="badge badge-success">-</span>', $html);
        $this->assertStringContainsString('нет данных</span>', $html);
        $this->assertStringContainsString('Алексеева Полина &amp; &lt;тест&gt;', $html);

        // Средний балл по организации + рекомендации (буквальные &lt;/&gt; в тексте).
        $this->assertStringContainsString('<span class="badge badge-warning badge-lg">3.6/5</span>', $html);
        $this->assertStringContainsString('средний балл &lt; 2.5/5', $html);
        $this->assertStringContainsString('падение динамики &gt; 10 п.п.', $html);
        $this->assertStringContainsString('нет сданных тестов &gt; 21 дн.', $html);

        // Единая система таблиц (задача 3 tables-redesign): таблица отчета
        // обернута в table-responsive и несет table-striped table-hover
        // unics-table; старая разметка table-sm/table-bordered не осталась.
        $this->assertSame(1, substr_count($html, 'table-responsive'));
        $this->assertStringContainsString('table table-striped table-hover unics-table', $html);
        $this->assertStringNotContainsString('table-sm', $html);
        $this->assertStringNotContainsString('table-bordered', $html);

        // Экранирование пользовательских данных не протекло тегами.
        $this->assertStringNotContainsString('<орг>', $html);
        $this->assertStringNotContainsString('<тест>', $html);
    }

    /** Организация не найдена: только доверенный пре-рендер уведомления. */
    public function test_not_found_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/org_report', [
            'users_url' => '#',
            'selector'  => ['options' => [['id' => 3, 'label' => 'Орг', 'selected' => true]]],
            'not_found' => ['html' => '<div class="alert alert-danger">Организация не найдена.</div>'],
        ]);

        $this->assertStringContainsString('Организация не найдена.', $html);
        $this->assertStringNotContainsString('<h5', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /**
     * Минимальный отчет без риска: нет секции переключателя, нет alert -
     * все учащиеся в норме (фиксированный скоуп методиста - без селектора).
     */
    public function test_minimal_report_without_optional_parts(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/org_report', [
            'users_url' => '#',
            'header'    => [
                'org_name'            => 'Тестовая организация',
                'export_buttons_html' => '<div class="mb-3">EXPORTMARK</div>',
            ],
            'report'    => [
                'stats' => [
                    ['value' => 2, 'label' => 'Учащихся', 'value_class' => null, 'card_class' => null],
                    ['value' => 0, 'label' => 'В группе риска', 'value_class' => 'text-danger', 'card_class' => null],
                    ['value' => 2, 'label' => 'Без риска', 'value_class' => 'text-success', 'card_class' => null],
                    ['value' => 0, 'label' => 'Нет данных', 'value_class' => 'text-muted', 'card_class' => null],
                ],
                'charts' => [
                    ['title' => 'Распределение по уровням', 'html' => '<div class="chart-area">C1</div>'],
                    ['title' => 'Группа риска', 'html' => '<div class="chart-area">C2</div>'],
                ],
                'rows' => [
                    ['row_class' => '', 'fio' => 'Ученик Один', 'class_str' => '5 «А»',
                     'cat_label' => '-', 'level_label' => 'Базовый',
                     'avg_badge' => ['class' => 'success', 'text' => '4.8/5'],
                     'risk' => null, 'nodata' => false, 'courses' => 1, 'umk' => 0, 'report_url' => '#'],
                ],
                'org_avg' => ['badge_class' => 'success', 'text' => '4.8/5'],
            ],
        ]);

        $this->assertStringNotContainsString('name="org_id"', $html);
        $this->assertStringContainsString('Тестовая организация', $html);
        $this->assertStringNotContainsString('btn-outline-danger', $html);
        $this->assertStringNotContainsString('btn-outline-secondary btn-sm">←', $html);
        $this->assertStringNotContainsString('Рекомендации по группе риска', $html);
        $this->assertStringContainsString('card text-center p-2">', $html);
        $this->assertStringNotContainsString('border-danger', $html);
    }
}
