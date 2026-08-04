<?php
namespace local_unics;

/**
 * Смоук-тест mustache-шаблона истории УМК (2.5 аудита, слайс 5): рендер
 * local_unics/umk_status с фикстурными контекстами - список пуст / есть
 * ожидающие задачи (кнопка «Отменить все») / без них - шаблон валиден,
 * маркеры секций и экранирование на месте.
 *
 * Таблица - доверенный пре-рендер html_writer::table() (см. дизайн-заметку
 * в шаблоне): Moodle сама добавляет служебные классы header/cN/lastcol/
 * lastrow, воспроизводить их вручную в mustache рискованно и не нужно -
 * страница строит html_table как раньше, шаблон получает готовую HTML-строку.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class umk_status_template_test extends \advanced_testcase {

    public function test_full_context_with_pending_and_table(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/umk_status', [
            'toolbar' => [
                'create_url'  => 'generate_umk.php',
                'cancel_all'  => ['url' => '#', 'count' => 3],
                'run_now_url' => '#',
            ],
            'table_html'  => '<table class="table table-striped table-hover unics-table unics-compact">TABLEMARK</table>',
            'paging_html' => '<nav class="unics-paging">PAGEMARK</nav>',
        ]);

        $this->assertStringContainsString('Создать новый УМК', $html);
        $this->assertStringContainsString('href="generate_umk.php"', $html);
        $this->assertStringContainsString('Отменить все ожидающие (3)', $html);
        $this->assertStringContainsString("confirm('Отменить все 3 ожидающих задачи?')", $html);
        $this->assertStringContainsString('Запустить обработку сейчас', $html);
        $this->assertStringContainsString('TABLEMARK', $html);
        $this->assertStringContainsString('PAGEMARK', $html);
        $this->assertStringNotContainsString('Материалов пока нет', $html);
    }

    public function test_no_pending_hides_cancel_all_button(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/umk_status', [
            'toolbar' => [
                'create_url'  => 'generate_umk.php',
                'run_now_url' => '#',
            ],
            'table_html'  => '<table>TABLEMARK</table>',
            'paging_html' => '',
        ]);

        $this->assertStringNotContainsString('Отменить все ожидающие', $html);
        $this->assertStringContainsString('Запустить обработку сейчас', $html);
        $this->assertStringContainsString('TABLEMARK', $html);
    }

    public function test_empty_context_shows_notification_no_table(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/umk_status', [
            'toolbar' => [
                'create_url'  => 'generate_umk.php',
                'run_now_url' => '#',
            ],
            'empty' => ['html' => '<div class="alert alert-info">Материалов пока нет. Создайте первый УМК.</div>'],
        ]);

        $this->assertStringContainsString('Материалов пока нет. Создайте первый УМК.', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('Создать новый УМК', $html);
    }
}
