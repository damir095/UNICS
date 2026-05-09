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

        $currentpath = parse_url($this->page->url->out(false), PHP_URL_PATH);

        // Учащийся: своя панель быстрого доступа
        $student_rec = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
        if ($student_rec && !$ismanager && !$isteacher) {
            $links = [
                ['/local/unics/pages/dashboard.php', [], 'Панель'],
                ['/local/unics/pages/achievements.php', ['student_id' => $student_rec->id], 'Мои значки'],
            ];

            $html  = '<div class="unics-quicknav" role="navigation" aria-label="Навигация УНИКС">';
            $html .= '<div class="unics-quicknav-inner"><div class="unics-qnav-links">';

            foreach ($links as [$path, $params, $label]) {
                $url    = (new moodle_url($path, $params))->out(false);
                $active = ($currentpath === $path) ? ' active' : '';
                $html  .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                        . 'class="unics-qnav-item' . $active . '">'
                        . htmlspecialchars($label, ENT_QUOTES)
                        . '</a>';
            }

            $html .= '</div></div></div>';
            return $html;
        }

        if (!$ismanager && !$isteacher) {
            return '';
        }

        // Педагог должен быть реальным педагогом УНИКС, а не просто иметь роль Moodle.
        if ($isteacher && !$DB->record_exists('unics_teachers', ['mdl_user_id' => $USER->id])) {
            return '';
        }

        if ($ismanager) {
            $links = [
                ['/local/unics/pages/dashboard.php',    'Панель'],
                ['/local/unics/pages/users.php',        'Пользователи'],
                ['/local/unics/pages/organizations.php','Организации'],
                ['/local/unics/pages/org_report.php',   'Отчёты'],
                ['/local/unics/pages/generate_umk.php', 'Генерация УМК'],
                ['/local/unics/pages/umk_status.php',   'Очередь ИИ'],
            ];
        } else {
            $links = [
                ['/local/unics/pages/my_students.php',  'Мои учащиеся'],
                ['/local/unics/pages/generate_umk.php', 'Генерация УМК'],
                ['/local/unics/pages/org_report.php',   'Отчёты'],
            ];
        }

        $html  = '<div class="unics-quicknav" role="navigation" aria-label="Навигация УНИКС">';
        $html .= '<div class="unics-quicknav-inner"><div class="unics-qnav-links">';

        foreach ($links as [$path, $label]) {
            $url    = (new moodle_url($path))->out(false);
            $active = ($currentpath === $path) ? ' active' : '';
            $html  .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                    . 'class="unics-qnav-item' . $active . '">'
                    . htmlspecialchars($label, ENT_QUOTES)
                    . '</a>';
        }

        $html .= '</div></div></div>';

        return $html;
    }
}
