<?php
namespace local_unics;

/**
 * Смоук-тест mustache-шаблона хаба курса: рендер local_unics/course_hub с полным
 * фикстурным контекстом и с урезанным - шаблон валиден, опциональные блоки скрываются,
 * экранирование на месте. Контекст фикстурный (не из build_context) - шаблон проверяется
 * отдельно от гейтов, которые покрыты course_hub_test.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class course_hub_template_test extends \advanced_testcase {

    /** Полный контекст: сводка + две группы плиток. */
    private function full_context(): array {
        return [
            'back_url'   => 'http://localhost/course/view.php?id=21',
            'back_label' => '← В курс',
            'heading'    => 'Инструменты курса',
            'attention'  => ['title' => 'Требует внимания', 'cards' => [
                ['url' => '#', 'label' => '4 работы ждут проверки',
                 'tone_class' => 'unics-attention-card--info',
                 'icon_html' => '<i class="icon unics-attention-card__icon"></i>', 'badge' => 4],
                ['url' => '#', 'label' => '2 ученика застряли',
                 'tone_class' => 'unics-attention-card--warning',
                 'icon_html' => '<i class="icon unics-attention-card__icon"></i>', 'badge' => 2],
            ]],
            'groups' => [
                ['title' => 'Как идут дела', 'tiles' => [
                    ['url' => '#', 'label' => 'Журнал курса', 'desc' => 'оценки за тесты курса',
                     'icon_html' => '<i class="icon unics-action-card__icon"></i>'],
                    ['url' => '#', 'label' => 'Отчет по курсу & <xss>', 'desc' => 'группа риска и средние',
                     'icon_html' => '<i class="icon unics-action-card__icon"></i>'],
                ]],
                ['title' => 'Настройка курса', 'tiles' => [
                    ['url' => '#', 'label' => 'Кодификатор', 'desc' => 'привязка активностей',
                     'icon_html' => '<i class="icon unics-action-card__icon"></i>'],
                ]],
            ],
        ];
    }

    public function test_full_context_renders_summary_and_both_groups(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/course_hub', $this->full_context());

        $this->assertStringContainsString('Инструменты курса', $html);
        $this->assertStringContainsString('← В курс', $html);
        $this->assertStringContainsString('Требует внимания', $html);
        $this->assertStringContainsString('Как идут дела', $html);
        $this->assertStringContainsString('Настройка курса', $html);
        // Две карточки сводки, три плитки, обе сетки на месте.
        $this->assertSame(2, substr_count($html, 'unics-attention-card__label'));
        $this->assertSame(3, substr_count($html, 'unics-action-card__label'));
        $this->assertSame(3, substr_count($html, 'unics-action-card__desc'));
        $this->assertSame(2, substr_count($html, 'unics-action-cards unics-action-cards--hub'));
        $this->assertStringContainsString('unics-action-card--stacked', $html);
        $this->assertStringContainsString('unics-attention-card--warning', $html);
        // Экранирование: сырой текст с < > & не протек тегами.
        $this->assertStringNotContainsString('<xss>', $html);
        $this->assertStringContainsString('&lt;xss&gt;', $html);
    }

    public function test_context_without_attention_hides_the_block(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $context = $this->full_context();
        $context['attention'] = null;
        $html = $renderer->render_from_template('local_unics/course_hub', $context);

        $this->assertStringNotContainsString('Требует внимания', $html);
        $this->assertStringNotContainsString('unics-attention-card', $html);
        // Плитки при этом на месте.
        $this->assertStringContainsString('Как идут дела', $html);
    }

    public function test_single_group_renders_one_heading(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $context = $this->full_context();
        $context['groups'] = [$context['groups'][0]];
        $html = $renderer->render_from_template('local_unics/course_hub', $context);

        $this->assertStringContainsString('Как идут дела', $html);
        $this->assertStringNotContainsString('Настройка курса', $html);
        $this->assertSame(1, substr_count($html, 'unics-action-cards unics-action-cards--hub'));
    }
}
