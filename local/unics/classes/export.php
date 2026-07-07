<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Выгрузка отчётов в файл (Excel/CSV/ODS) через нативный \core\dataformat
 * ([[export-helper-org-report-design]], [[gradebook-export-design]];
 * этап 2.1 разгрузки lib.php).
 *
 * Тела перенесены из lib.php; там остались тонкие обёртки
 * (`local_unics_export_student_stats()` и т.д.) - страницы вызывают их как раньше.
 */
class export {

    /**
     * Выгрузка построчной статистики учеников в файл (Excel/CSV/ODS). Вызывать РАНО,
     * до $OUTPUT->header(): при валидном параметре download ставит заголовки, стримит
     * файл и завершает скрипт; иначе просто возвращает.
     * Данные - stats_manager::get_student_rows($org_ids) (скоуп задаёт вызывающая страница).
     *
     * @param int[]|null $org_ids  скоуп организаций (null = вся система)
     * @param string     $basename базовое имя файла (дата добавится автоматически)
     */
    public static function student_stats(?array $org_ids, string $basename): void {
        global $DB;
        $download = optional_param('download', '', PARAM_ALPHA);
        if ($download === '' || !in_array($download, ['excel', 'csv', 'ods'], true) || !confirm_sesskey()) {
            return;
        }
        $exrows = stats_manager::get_student_rows($org_ids);
        $names = [];
        if ($exrows) {
            $uids = array_map(static fn($r) => (int)$r->mdl_user_id, $exrows);
            $users = $DB->get_records_list('user', 'id', $uids, '',
                'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
            foreach ($users as $u) {
                $names[(int)$u->id] = fullname($u);
            }
        }
        $columns = [
            'name'          => 'ФИО',
            'category'      => 'Категория',
            'ovz'           => 'Вид ОВЗ',
            'class'         => 'Класс',
            'org'           => 'Организация',
            'district'      => 'Муниципалитет',
            'region'        => 'Регион',
            'courses'       => 'Курсов',
            'avg_score'     => 'Средний балл, %',
            'completion'    => 'Завершаемость, %',
            'views'         => 'Просмотры',
            'time_min'      => 'Время, мин',
            'attempts'      => 'Попытки',
            'ai'            => 'Выдано УМК',
            'level_changes' => 'Смен уровня',
            'last_active'   => 'Последняя активность',
        ];
        $data = [];
        foreach ($exrows as $r) {
            $ovz = student_helper::ovz_type_names($r->ovz_type);
            $data[] = [
                'name'          => $names[(int)$r->mdl_user_id] ?? '-',
                'category'      => implode(', ', student_helper::category_names($r->category)),
                'ovz'           => $ovz ? implode(', ', $ovz) : '-',
                'class'         => $r->class_number ? (int)$r->class_number : '-',
                'org'           => $r->organization_name ?: '-',
                'district'      => $r->district_name ?: '-',
                'region'        => $r->region_name ?: '-',
                'courses'       => (int)$r->n_courses,
                'avg_score'     => $r->avg_score_pct === null ? '-' : $r->avg_score_pct,
                'completion'    => (int)$r->total > 0 ? round((int)$r->completed / (int)$r->total * 100) : '-',
                'views'         => (int)$r->views,
                'time_min'      => (int)$r->time_est_min,
                'attempts'      => (int)$r->attempts,
                'ai'            => (int)$r->ai_uses,
                'level_changes' => (int)$r->level_changes,
                'last_active'   => $r->last_active_at ? userdate((int)$r->last_active_at, '%Y-%m-%d') : '-',
            ];
        }
        \core\dataformat::download_data($basename . '-' . userdate(time(), '%Y-%m-%d'),
            $download, $columns, $data);
        die;
    }

    /**
     * Кнопки выгрузки «Выгрузить: Excel CSV ODS» для страницы-отчёта. Ссылки сохраняют
     * параметры $pageurl (например org_id) и несут sesskey.
     */
    public static function buttons(\moodle_url $pageurl): string {
        $out = \html_writer::start_div('mb-3');
        $out .= \html_writer::tag('span', 'Выгрузить: ', ['class' => 'mr-2']);
        foreach (['excel' => 'Excel', 'csv' => 'CSV', 'ods' => 'ODS'] as $fmt => $lbl) {
            $durl = new \moodle_url($pageurl, ['download' => $fmt, 'sesskey' => sesskey()]);
            $out .= \html_writer::tag('a', $lbl,
                ['href' => $durl->out(false), 'class' => 'btn btn-outline-secondary btn-sm mr-2']);
        }
        $out .= \html_writer::end_div();
        return $out;
    }

    /**
     * Выгрузка журнала (матрица ученики x задания) в файл (Excel/CSV/ODS). Вызывать ДО header.
     * Значения - % (округл.) по заданию + средний %.
     */
    public static function gradebook(int $course_id, int $filter_class, string $filter_letter): void {
        $download = optional_param('download', '', PARAM_ALPHA);
        if ($download === '' || !in_array($download, ['excel', 'csv', 'ods'], true) || !confirm_sesskey()) {
            return;
        }
        $gb = gradebook::matrix($course_id, $filter_class, $filter_letter);
        // Задания по sortorder.
        $item_meta = $gb['item_meta'];
        uasort($item_meta, static fn($a, $b) => $a['sortorder'] <=> $b['sortorder']);
        $ordered_iids = array_keys($item_meta);
        // Быстрый доступ [uid][iid].
        $grade_by_ui = [];
        foreach ($gb['by_user'] as $uid => $list) {
            foreach ($list as $g) {
                $grade_by_ui[$uid][$g['itemid']] = $g;
            }
        }
        $columns = ['name' => 'ФИО', 'class' => 'Класс'];
        foreach ($ordered_iids as $iid) {
            $columns['i' . $iid] = $item_meta[$iid]['name'];
        }
        $columns['avg'] = 'Средний';
        $data = [];
        foreach ($gb['students'] as $s) {
            $uid = (int)$s->mdl_user_id;
            $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
            $cls = $s->class_number
                ? $s->class_number . ($s->class_letter ? ' ' . $s->class_letter : '')
                : '-';
            $row  = ['name' => $fio !== '' ? $fio : '-', 'class' => $cls];
            $pcts = [];
            foreach ($ordered_iids as $iid) {
                if (isset($grade_by_ui[$uid][$iid])) {
                    $p = $grade_by_ui[$uid][$iid]['pct'];
                    $row['i' . $iid] = round($p);
                    $pcts[] = $p;
                } else {
                    $row['i' . $iid] = '-';
                }
            }
            $row['avg'] = $pcts ? round(array_sum($pcts) / count($pcts)) : '-';
            $data[] = $row;
        }
        \core\dataformat::download_data('unics-zhurnal-' . $course_id . '-' . userdate(time(), '%Y-%m-%d'),
            $download, $columns, $data);
        die;
    }
}
