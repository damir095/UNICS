<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace theme_unics\navigation\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Primary navigation для темы УНИКС.
 *
 * Override get_user_menu() — фильтрует пункты по роли пользователя в УНИКС и
 * добавляет первым пунктом «Личный кабинет» со ссылкой на наш дашборд.
 * Задача #1 из [[global-tasks-2026-05-13]] в LLM-вики.
 */
class primary extends \core\navigation\output\primary {

    /**
     * Whitelist titleidentifier'ов, разрешённых в user-menu для каждой роли.
     * null = фильтрация отключена (показывать всё, как у админа).
     *
     * Языковое меню (submenu-link) и Switch role (switchroleto,moodle) — обрабатываются отдельно
     * (см. is_item_allowed()).
     */
    private const ROLE_WHITELIST = [
        'student' => [
            'logout,moodle',
        ],
        'parent' => [
            'profile,moodle',
            'preferences,moodle',
            'logout,moodle',
        ],
        'teacher' => [
            'profile,moodle',
            'calendar,core_calendar',
            'privatefiles,moodle',
            'preferences,moodle',
            'logout,moodle',
        ],
        'methodist' => [
            'profile,moodle',
            'calendar,core_calendar',
            'privatefiles,moodle',
            'preferences,moodle',
            'logout,moodle',
        ],
    ];

    public function get_user_menu(\renderer_base $output): array {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/local/unics/lib.php');

        $data = parent::get_user_menu($output);

        // Не залогинен / unauthenticateduser — items вообще не выставлен parent::get_user_menu().
        // Только в этом случае пропускаем добавление «Личного кабинета».
        if (!isset($data['items'])) {
            return $data;
        }

        $role = local_unics_get_role_for_user((int)$USER->id);

        // DEBUG LK-1: разовая диагностика, удалить после фикса.
        if (!empty($CFG->debugdeveloper)) {
            error_log(sprintf(
                '[theme_unics LK-1] uid=%d role=%s items_before=%d titleidentifiers=%s',
                (int)$USER->id,
                $role,
                count($data['items']),
                json_encode(array_map(fn($i) => $i->titleidentifier ?? '?', $data['items']))
            ));
        }

        if (isset(self::ROLE_WHITELIST[$role])) {
            $allowed = self::ROLE_WHITELIST[$role];
            $filtered = [];
            foreach ($data['items'] as $item) {
                if ($this->is_item_allowed($item, $allowed)) {
                    $filtered[] = $item;
                }
            }
            $data['items'] = $this->cleanup_dividers($filtered);
        }

        // Первым пунктом — «Личный кабинет» на наш дашборд,
        // отделён divider'ом от служебных пунктов (профиль/выход/…).
        // Для guest нет смысла — он не залогинен.
        if ($role !== 'guest') {
            $dashboard = (object)[
                'itemtype'        => 'link',
                'url'             => new \moodle_url('/local/unics/pages/dashboard.php'),
                'title'           => get_string('myhome'),
                'titleidentifier' => 'localunicsdashboard,local_unics',
                'pixicon'         => 'i/home',
                'divider'         => false,
                'link'            => true,
            ];
            $divider = (object)[
                'itemtype' => 'divider',
                'divider'  => true,
                'link'     => false,
            ];
            $data['items'] = array_merge([$dashboard, $divider], $data['items']);
        }

        // DEBUG LK-1.
        if (!empty($CFG->debugdeveloper)) {
            error_log(sprintf(
                '[theme_unics LK-1] uid=%d role=%s items_after=%d first_titleidentifier=%s',
                (int)$USER->id,
                $role,
                count($data['items']),
                isset($data['items'][0]->titleidentifier) ? $data['items'][0]->titleidentifier : '?'
            ));
        }

        return $data;
    }

    /**
     * Решает, оставлять ли пункт в user-menu согласно whitelist роли.
     */
    private function is_item_allowed(\stdClass $item, array $allowed): bool {
        // Divider'ы оставим, потом отдельно почистим лишние.
        if (!empty($item->divider)) {
            return true;
        }
        // Language submenu (Moodle добавляет когда есть несколько языков) — оставляем всем.
        if (!empty($item->submenulink)) {
            return true;
        }
        // Switch role / login-as (admin-only механики) — оставляем, до этих кейсов нефильтруемые роли не доходят.
        if (!empty($item->titleidentifier)
            && in_array($item->titleidentifier, ['switchroleto,moodle', 'switchrolereturn,moodle'], true)) {
            return true;
        }
        if (empty($item->titleidentifier)) {
            return true;
        }
        return in_array($item->titleidentifier, $allowed, true);
    }

    /**
     * Убирает дублирующиеся / leading / trailing divider'ы после фильтрации.
     */
    private function cleanup_dividers(array $items): array {
        $clean = [];
        $prev_divider = true; // не начинаем с divider'а
        foreach ($items as $item) {
            $is_divider = !empty($item->divider);
            if ($is_divider && $prev_divider) {
                continue;
            }
            $clean[] = $item;
            $prev_divider = $is_divider;
        }
        // Хвостовой divider.
        while (!empty($clean) && !empty(end($clean)->divider)) {
            array_pop($clean);
        }
        return $clean;
    }
}
