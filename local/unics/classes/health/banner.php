<?php
namespace local_unics\health;

defined('MOODLE_INTERNAL') || die();

/**
 * Полоса тревоги вверху страницы.
 *
 * Смысл всей затеи: страница здоровья, на которую никто не заходит, отказывает так же тихо, как
 * то, что она мониторит - cron на стенде был мертв 40 дней. Полоса приносит новость сама.
 *
 * Показывается только тем, кто может починить (capability local/unics:manage), только при уровне
 * «авария» и только на страницах УНИКС и админки - чтобы не мешать работе педагога и ребенка.
 */
class banner {

    public static function render(): string {
        global $PAGE, $CFG;

        if (!isloggedin() || isguestuser() || during_initial_install()) {
            return '';
        }
        $context = \context_system::instance();
        if (!has_capability('local/unics:manage', $context)) {
            return '';
        }
        $rel = self::relative_path($PAGE->url ? $PAGE->url->get_path() : '');
        $ours = strpos($rel, '/local/unics/') === 0
            || strpos($rel, '/' . $CFG->admin . '/') === 0;
        if (!$ours) {
            return '';
        }
        // На самой странице здоровья полоса избыточна.
        if ($rel === '/local/unics/pages/health.php') {
            return '';
        }

        try {
            $alarms = health_report::alarms();
        } catch (\Throwable $e) {
            debugging('local_unics health banner: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
        if (!$alarms) {
            return '';
        }

        $first = reset($alarms);
        $more = count($alarms) - 1;
        $text = $first->summary . ($more > 0 ? ' (и еще проблем: ' . $more . ')' : '');
        $url = new \moodle_url('/local/unics/pages/health.php');

        return \html_writer::div(
            \html_writer::tag('strong', 'УНИКС: ') . s($text) . ' '
            . \html_writer::link($url, 'Подробнее', ['class' => 'alert-link']),
            'alert alert-danger mb-0 rounded-0',
            ['role' => 'alert']
        );
    }

    /**
     * Путь страницы без каталога установки.
     *
     * На стенде сайт лежит в корне, и сравнение с «/local/unics/» работало бы и без этого.
     * Но при установке в подкаталог (`http://host/moodle/`) путь начинается с «/moodle/», и
     * полоса не появилась бы НИГДЕ и НИКОГДА - то есть отказала бы ровно так же тихо, как то,
     * что она сторожит. Каталог админки берется из настройки: его разрешено переименовывать.
     */
    private static function relative_path(string $path): string {
        $root = rtrim((new \moodle_url('/'))->get_path(), '/');
        if ($root !== '' && strpos($path, $root) === 0) {
            return substr($path, strlen($root));
        }
        return $path;
    }
}
