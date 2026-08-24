<?php
/**
 * Установка плагина с нуля.
 *
 * Роли УНИКС и матрица прав заводились ТОЛЬКО шагами db/upgrade.php, а установка идет из
 * install.xml - ни один шаг апгрейда при этом не выполняется. На развернутой с нуля копии не
 * было ни ролей, ни прав: ни методиста, ни родителя, ни региональных ролей. Держалось все на
 * том, что боевой стенд рос апгрейдами ([[roles-on-fresh-install]]).
 *
 * @package local_unics
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_unics_install(): bool {
    global $CFG;
    require_once($CFG->dirroot . '/local/unics/classes/identity/role_manager.php');

    $created = \local_unics\identity\role_manager::ensure_roles();
    $new = array_keys(array_filter($created, static fn(string $v): bool => $v === 'created'));
    if ($new) {
        mtrace('local_unics: заведены роли: ' . implode(', ', $new));
    }

    // Матрицу прав применяем ОТЛОЖЕННО, разовой adhoc-задачей. Прямо здесь нельзя: ядро зовет
    // install.php ДО upgrade_component_updated(), который и регистрирует capability плагина, -
    // на этот момент прав вида local/unics:manageorg еще не существует, и матрица применилась
    // бы вхолостую (роли есть, прав у них нет).
    \core\task\manager::queue_adhoc_task(new \local_unics\task\apply_role_matrix(), true);

    return true;
}
