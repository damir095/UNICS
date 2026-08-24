<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Разовая задача: завести роли УНИКС и применить к ним матрицу прав.
 *
 * Ставится при установке плагина. Почему не прямо в db/install.php: ядро выполняет install.php
 * ДО `upgrade_component_updated()`, а именно он регистрирует capability плагина
 * (lib/upgradelib.php: install.php -> upgrade_component_updated). В момент установки прав вида
 * `local/unics:manageorg` еще не существует, `get_capability_info()` их не находит, и матрица
 * применилась бы вхолостую - роли были бы, а прав у них нет ([[roles-on-fresh-install]]).
 *
 * Задача идемпотентна: ensure_roles() не трогает существующие роли, apply_matrix() перезаписывает
 * назначения теми же значениями.
 *
 * @package local_unics
 */
class apply_role_matrix extends \core\task\adhoc_task {

    public function execute(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/identity/role_manager.php');

        $made = \local_unics\identity\role_manager::ensure_roles();
        $new = array_keys(array_filter($made, static fn(string $v): bool => $v === 'created'));
        if ($new) {
            mtrace('local_unics: заведены роли: ' . implode(', ', $new));
        }

        $res = \local_unics\identity\role_manager::apply_matrix();
        $ok = count(array_filter($res, static fn(array $r): bool => ($r['status'] ?? '') === 'ok'));
        mtrace('local_unics: матрица прав применена к ролям: ' . $ok . ' из ' . count($res));
    }
}
