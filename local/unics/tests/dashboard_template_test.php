<?php
namespace local_unics;

/**
 * Смоук-тест mustache-шаблона дашборда ([[mustache-dashboard-design]], 2.5 аудита):
 * рендер local_unics/dashboard с полным фикстурным контекстом (все опциональные
 * секции разом) - шаблон валиден, ключевые маркеры и экранирование на месте.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class dashboard_template_test extends \advanced_testcase {

    /** Полный контекст: все секции супер-порядка включены. */
    private function full_context(): array {
        return [
            'welcome' => [
                'greeting'    => 'Привет, Полина!',
                'subline'     => '8 «Б» класс & <тест>',
                'title_badge' => ['name' => 'Умник', 'iconurl' => 'http://example.com/i.png'],
            ],
            'child_switcher' => [
                'action_url' => 'http://localhost/local/unics/pages/dashboard.php',
                'options'    => [
                    ['id' => 5, 'fio' => 'Гусева Алиса', 'selected' => true],
                    ['id' => 6, 'fio' => 'Гусев Максим', 'selected' => false],
                ],
            ],
            'attention' => ['cards' => [
                ['url' => '#', 'label' => 'Новые сообщения', 'icon' => 't/message',
                 'tone_class' => 'unics-attention-card--info', 'badge' => 3],
                ['url' => '#', 'label' => 'Дети без курса & <xss>', 'icon' => 'i/users',
                 'tone_class' => 'unics-attention-card--warning', 'badge' => null],
            ]],
            'actions' => ['cards' => [
                ['url' => '#', 'label' => 'Журнал', 'icon' => 'i/grades', 'badge' => null],
                ['url' => '#', 'label' => 'Мои учащиеся', 'icon' => 'i/users', 'badge' => 2],
            ]],
            'weak_widget' => [
                'items' => [['title' => 'Круговорот воды', 'band_text' => 'слабо', 'band_class' => 'danger']],
                'details_url' => '#',
            ],
            'metrics' => ['items' => [
                ['col' => 'col-6 col-md-3', 'extraclass' => '', 'label' => 'Курсов', 'value' => '2'],
                ['col' => 'col-6 col-md-3', 'extraclass' => 'unics-points-card', 'label' => 'Баллов', 'value' => '150'],
                ['col' => 'col-6 col-md-3', 'label' => 'Средний балл',
                 'pct_badge' => ['pct' => 71.9, 'tone' => 'warning']],
                ['col' => 'col-6 col-md-2', 'lvl_label_pill' => ['lv' => 1, 'label' => 'Базовый'], 'value' => '3'],
                ['col' => 'col-6 col-md-3', 'label' => 'Текущий уровень',
                 'lvl_pill' => ['lv' => 2, 'label' => 'Стандартный']],
            ]],
            'umk_table' => ['empty' => false, 'rows' => [
                ['title' => 'Вода & <жизнь>', 'lvl' => 2, 'lvl_label' => 'Стандартный',
                 'students' => 4, 'status_tone' => 'success', 'status_label' => 'Готов', 'date' => '15.07.2026'],
            ]],
            'grades_table' => ['rows' => [
                ['quiz' => 'Нефть - тест', 'course' => 'География. 7 класс',
                 'score' => '8/10', 'pct' => 80.0, 'tone' => 'warning'],
            ]],
            'children_cards' => ['rows' => [
                ['fio' => 'Гусева Алиса', 'cls' => '5 «А»', 'has_avg' => true, 'avg' => 91.0,
                 'tone' => 'success', 'unread' => 2, 'report_url' => '#'],
                ['fio' => 'Гусев Максим', 'cls' => '-', 'has_avg' => false, 'avg' => null,
                 'tone' => 'secondary', 'unread' => 0, 'report_url' => '#'],
            ]],
            'children_link' => ['url' => '#'],
        ];
    }

    public function test_full_context_renders_all_sections(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/dashboard', $this->full_context());

        // Каркас и секции.
        $this->assertStringContainsString('unics-welcome', $html);
        $this->assertStringContainsString('Привет, Полина!', $html);
        $this->assertStringContainsString('Умник', $html);                    // бейдж титула
        $this->assertStringContainsString('name="child"', $html);             // переключатель ребенка
        $this->assertStringContainsString('Требует внимания', $html);
        $this->assertStringContainsString('Быстрые действия', $html);
        $this->assertStringContainsString('Стоит повторить', $html);
        $this->assertStringContainsString('stat-value', $html);
        $this->assertStringContainsString('Последние генерации УМК', $html);
        $this->assertStringContainsString('Последние тесты', $html);
        $this->assertStringContainsString('Мои дети', $html);
        $this->assertStringContainsString('Все дети', $html);

        // Число карточек как в контексте (по метке - якорь и метка дают уникальные классы).
        $this->assertSame(2, substr_count($html, 'unics-attention-card__label'));
        $this->assertSame(2, substr_count($html, 'unics-action-card__label'));

        // Экранирование: сырой текст с < > & не протек тегами.
        $this->assertStringNotContainsString('<xss>', $html);
        $this->assertStringContainsString('&lt;xss&gt;', $html);
        $this->assertStringContainsString('Вода &amp; &lt;жизнь&gt;', $html);

        // Пилюли уровня и бейджи процентов - данными, классы на месте.
        $this->assertStringContainsString('unics-lvl unics-lvl-2', $html);
        $this->assertStringContainsString('badge badge-warning', $html);
        $this->assertStringContainsString('71.9%', $html);
    }

    public function test_minimal_context_renders_welcome_only(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/dashboard', [
            'welcome' => ['greeting' => 'Добро пожаловать, Иван', 'subline' => 'Панель'],
        ]);

        $this->assertStringContainsString('Добро пожаловать, Иван', $html);
        $this->assertStringNotContainsString('Требует внимания', $html);
        $this->assertStringNotContainsString('Быстрые действия', $html);
        $this->assertStringNotContainsString('stat-value', $html);
        $this->assertStringNotContainsString('<tbody>', $html);
    }
}
