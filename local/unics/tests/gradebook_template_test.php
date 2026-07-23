<?php
namespace local_unics;

/**
 * Смоук-тест mustache-шаблона журнала (2.5 аудита, слайс 4): рендер
 * local_unics/gradebook с фикстурными контекстами - нет курсов / курс без
 * таблицы (уведомление builder'а) / вид «по порядку» / вид «по заданиям»
 * (с footer-строкой средних) - шаблон валиден, маркеры секций и
 * экранирование на месте.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class gradebook_template_test extends \advanced_testcase {

    private function options(array $pairs, $selectedValue): array {
        $out = [];
        foreach ($pairs as $value => $label) {
            $out[] = ['value' => (string)$value, 'label' => $label,
                      'selected' => (string)$value === (string)$selectedValue];
        }
        return $out;
    }

    private function filters(): array {
        return [
            'cat_options'    => $this->options([0 => 'Все категории', 5 => 'Математика & <кат>'], 0),
            'course_options' => $this->options([0 => '- Выберите курс -', 21 => 'География & <курс>'], 21),
            'class_options'  => $this->options([0 => 'Все классы', 7 => '7 класс'], 0),
            'letter_options' => $this->options(['' => 'Все буквы', 'А' => 'А'], ''),
            'view_options'   => $this->options(['order' => 'Оценки по порядку', 'item' => 'По заданиям'], 'order'),
        ];
    }

    /** Вид «по порядку»: числовые колонки, без footer-строки. */
    public function test_order_view_renders_table(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/gradebook', [
            'filters' => $this->filters(),
            'course_selected' => [
                'course_name'         => 'География & <курс>',
                'export_buttons_html' => '<div class="mb-3">EXPORTMARK</div>',
                'table' => [
                    'headers' => [
                        ['label' => '1', 'cls' => 'text-center'],
                        ['label' => '2', 'cls' => 'text-center'],
                    ],
                    'rows' => [
                        [
                            'fio' => 'Алексеева Полина & <тест>', 'report_url' => '#',
                            'edit_html' => '<a class="unics-grade-edit">EDITMARK1</a>',
                            'class_str' => '8 «Б»',
                            'cells' => [
                                ['has_value' => true, 'td_cls' => 'text-center',
                                 'badge_class' => 'warning', 'value' => '3.3', 'tip' => "Задание: Тест\nСредний: 4"],
                                ['has_value' => false, 'td_cls' => 'text-muted text-center'],
                            ],
                            'avg' => ['has_value' => true, 'badge_class' => 'warning', 'value' => '3.3'],
                        ],
                        [
                            'fio' => 'Без Оценок', 'report_url' => '#',
                            'edit_html' => null,
                            'class_str' => '-',
                            'cells' => [
                                ['has_value' => false, 'td_cls' => 'text-muted text-center'],
                                ['has_value' => false, 'td_cls' => 'text-muted text-center'],
                            ],
                            'avg' => ['has_value' => false],
                        ],
                    ],
                    'paging_html' => '<nav class="unics-paging">PAGEMARK</nav>',
                ],
            ],
        ]);

        $this->assertStringContainsString('name="course_id"', $html);
        $this->assertStringContainsString('География &amp; &lt;курс&gt;</h5>', $html);
        $this->assertStringContainsString('EXPORTMARK', $html);
        $this->assertStringContainsString('<th class="text-center">1</th>', $html);
        $this->assertStringNotContainsString('Средний по заданию', $html);
        $this->assertStringContainsString('Алексеева Полина &amp; &lt;тест&gt;', $html);
        $this->assertStringContainsString('EDITMARK1', $html);
        $this->assertStringContainsString('<td class="text-center"><span class="badge badge-warning" title="Задание: Тест', $html);
        $this->assertStringContainsString('<td class="text-muted text-center">–</td>', $html);
        // Средний присутствует: <td> БЕЗ класса (не text-center).
        $this->assertStringContainsString('<td><span class="badge badge-warning">3.3</span></td>', $html);
        $this->assertStringContainsString('PAGEMARK', $html);
        $this->assertStringNotContainsString('<xss>', $html);
    }

    /** Вид «по заданиям»: именованные колонки + footer-строка средних. */
    public function test_item_view_renders_footer_row(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/gradebook', [
            'filters' => $this->filters(),
            'course_selected' => [
                'course_name'         => 'География',
                'export_buttons_html' => '<div class="mb-3">EXPORTMARK</div>',
                'table' => [
                    'headers' => [
                        ['label' => 'Нефть - тест & <имя>', 'title' => 'Нефть - тест & <имя>',
                         'edit_html' => '<a class="unics-grade-edit">EDITCOLMARK</a>'],
                    ],
                    'rows' => [
                        [
                            'fio' => 'Ученик', 'report_url' => '#', 'edit_html' => null,
                            'class_str' => '7 «А»',
                            'cells' => [
                                ['has_value' => true, 'td_cls' => 'text-center',
                                 'badge_class' => 'success', 'value' => '4.5', 'tip' => 'Задание: Нефть'],
                            ],
                            'avg' => ['has_value' => true, 'badge_class' => 'success', 'value' => '4.5'],
                        ],
                    ],
                    'footer_row' => [
                        'cells' => [
                            ['badge_class' => 'warning', 'value' => '3.9'],
                        ],
                    ],
                    'paging_html' => '',
                ],
            ],
        ]);

        $this->assertStringContainsString('title="Нефть - тест &amp; &lt;имя&gt;"', $html);
        $this->assertStringContainsString('EDITCOLMARK', $html);
        $this->assertStringContainsString('<tr class="table-light"><th>Средний по заданию</th><th></th>', $html);
        $this->assertStringContainsString('<th class="text-center"><span class="badge badge-warning">3.9</span></th>', $html);
    }

    /** Нет доступных курсов: только доверенный пре-рендер уведомления. */
    public function test_no_courses_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/gradebook', [
            'no_courses' => ['html' => '<div class="alert alert-info">Нет курсов.</div>'],
        ]);

        $this->assertStringContainsString('Нет курсов.', $html);
        $this->assertStringNotContainsString('name="course_id"', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /** Курс выбран, но builder вернул уведомление (например, нет группы) - без таблицы. */
    public function test_course_notice_without_table(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/gradebook', [
            'filters' => $this->filters(),
            'course_selected' => [
                'course_name'         => 'География',
                'export_buttons_html' => '<div class="mb-3">EXPORTMARK</div>',
                'notice' => ['html' => '<div class="alert alert-warning">Вы не в группе.</div>'],
            ],
        ]);

        $this->assertStringContainsString('Вы не в группе.', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('EXPORTMARK', $html);
    }
}
