<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'mysql';
$CFG->dbuser    = 'root';
$CFG->dbpass    = '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => '',
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_general_ci',
);

$CFG->wwwroot   = 'http://localhost';
$CFG->dataroot  = 'C:\\Moodle\\server\\moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

// PHPUnit (этап 5.2 аудита): полностью изолированная тестовая среда -
// отдельная БД и отдельный dataroot, живые данные не затрагиваются.
$CFG->phpunit_dbname   = 'phpunit';
$CFG->phpunit_prefix   = 'phpu_';
$CFG->phpunit_dataroot = 'C:\\Moodle\\server\\phpunit_moodledata';

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
