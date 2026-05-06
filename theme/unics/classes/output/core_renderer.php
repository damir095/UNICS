<?php
namespace theme_unics\output;

defined('MOODLE_INTERNAL') || die();

use moodle_url;
use context_system;

class core_renderer extends \theme_boost\output\core_renderer {

    /**
     * Добавляем быструю панель УНИКС сразу после page-header.
     * Видна только менеджерам (local/unics:manage) и педагогам (local/unics:viewstudents).
     * Учащиеся и гости не видят ничего.
     */
    public function full_header(): string {
        $html = parent::full_header();

        if ($this->page->pagelayout === 'login' || !isloggedin() || isguestuser()) {
            return $html;
        }

        $quicknav = $this->render_unics_quicknav();
        if ($quicknav) {
            $html .= $quicknav;
        }

        return $html;
    }

    protected function render_unics_quicknav(): string {
        global $DB, $USER;

        $ctx = context_system::instance();

        $ismanager = has_capability('local/unics:manage', $ctx);
        $isteacher = !$ismanager && has_capability('local/unics:viewstudents', $ctx);

        if (!$ismanager && !$isteacher) {
            return '';
        }

        // Учащийся никогда не видит панель управления, даже если Moodle-роль назначена неверно.
        if ($DB->record_exists('unics_students', ['mdl_user_id' => $USER->id])) {
            return '';
        }

        // Педагог должен быть реальным педагогом УНИКС, а не просто иметь роль Moodle.
        if ($isteacher && !$DB->record_exists('unics_teachers', ['mdl_user_id' => $USER->id])) {
            return '';
        }

        if ($ismanager) {
            $grouplabel = 'УНИКС · Управление';
            $links = [
                ['/local/unics/pages/dashboard.php',    'Панель'],
                ['/local/unics/pages/users.php',        'Пользователи'],
                ['/local/unics/pages/organizations.php','Организации'],
                ['/local/unics/pages/org_report.php',   'Отчёты'],
                ['/local/unics/pages/generate_umk.php', 'Генерация УМК'],
                ['/local/unics/pages/umk_status.php',   'Очередь ИИ'],
            ];
        } else {
            $grouplabel = 'УНИКС';
            $links = [
                ['/local/unics/pages/my_students.php',  'Мои учащиеся'],
                ['/local/unics/pages/generate_umk.php', 'Генерация УМК'],
                ['/local/unics/pages/org_report.php',   'Отчёты'],
            ];
        }

        $currentpath = parse_url($this->page->url->out(false), PHP_URL_PATH);

        $html  = '<div class="unics-quicknav" role="navigation" aria-label="Навигация УНИКС">';
        $html .= '<div class="unics-quicknav-inner">';
        $html .= '<div class="unics-qnav-links">';

        foreach ($links as [$path, $label]) {
            $url    = (new moodle_url($path))->out(false);
            $active = ($currentpath === $path) ? ' active' : '';
            $html  .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                    . 'class="unics-qnav-item' . $active . '">'
                    . htmlspecialchars($label, ENT_QUOTES)
                    . '</a>';
        }

        $html .= '</div>'; // .unics-qnav-links
        $html .= '</div>'; // .unics-quicknav-inner
        $html .= '</div>'; // .unics-quicknav

        return $html;
    }
}
