<?php
namespace local_unics;

use local_unics\health\banner;

/**
 * Полоса тревоги: кому и где показывается.
 *
 * Проверяется в том числе то, что сработать НЕ должно. Полоса красная и на весь экран: если она
 * вылезет ребенку или педагогу, он не сможет ни понять текст, ни что-либо сделать, а нам это
 * стоило бы доверия к предупреждению вообще.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(banner::class)]
final class health_banner_test extends \advanced_testcase {

    /** Мертвый cron - самая настоящая авария, на ней и проверяем. */
    private function break_cron(): void {
        global $DB;
        $DB->execute('UPDATE {task_scheduled} SET lastruntime = ?', [time() - 40 * DAYSECS]);
        \local_unics\health\health_report::forget();
    }

    private function healthy_cron(): void {
        global $DB;
        $DB->execute('UPDATE {task_scheduled} SET lastruntime = ?', [time() - 60]);
        \local_unics\health\health_report::forget();
    }

    /** Открыть страницу от имени пользователя. */
    private function visit(string $path, \stdClass $user): string {
        global $PAGE;
        $this->setUser($user);
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url($path));
        return banner::render();
    }

    public function test_admin_sees_banner_on_our_page_when_cron_is_dead(): void {
        $this->resetAfterTest();
        $this->break_cron();

        $html = $this->visit('/local/unics/pages/users.php', get_admin());

        $this->assertStringContainsString('Плановые задачи', $html);
        $this->assertStringContainsString('/local/unics/pages/health.php', $html);
    }

    public function test_no_banner_when_nothing_is_broken(): void {
        $this->resetAfterTest();
        $this->healthy_cron();

        $this->assertSame('', $this->visit('/local/unics/pages/users.php', get_admin()));
    }

    /** Чужая страница: полоса не лезет за пределы своей области даже при аварии. */
    public function test_no_banner_outside_our_pages(): void {
        $this->resetAfterTest();
        $this->break_cron();

        $this->assertSame('', $this->visit('/my/index.php', get_admin()));
    }

    /** На самой странице здоровья полоса избыточна. */
    public function test_no_banner_on_health_page_itself(): void {
        $this->resetAfterTest();
        $this->break_cron();

        $this->assertSame('', $this->visit('/local/unics/pages/health.php', get_admin()));
    }

    /** Тому, кто не может починить, полосу не показываем. */
    public function test_no_banner_for_user_without_capability(): void {
        $this->resetAfterTest();
        $this->break_cron();
        $teacher = $this->getDataGenerator()->create_user();

        $this->assertSame('', $this->visit('/local/unics/pages/users.php', $teacher));
    }
}
