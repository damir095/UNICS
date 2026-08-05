<?php
/**
 * Отчет анализатора контраста ([[contrast-audit-design]]).
 *
 * Тонкая обертка: вся логика в tests/fixtures/contrast_analyzer.php, чтобы отчет
 * и PHPUnit-страж не разъехались в две реализации.
 *
 * Находки печатаются двумя группами. НАШИ - селекторы theme_unics / local_unics плюс
 * ядровые из CORE_OWNED, чей цвет подставляем мы; за них отвечаем и их чинит страж.
 * ЯДРОВЫЕ - справка: правила Moodle, написанные под светлую схему. Наши темные
 * переопределения живут на других селекторах, поэтому слияние по имени селектора
 * их не видит; полноценный каскад между разными селекторами - это CSS-движок,
 * что вне задачи (решение 3 спеки: чисто ядровое в отчет, без правки).
 *
 * Запуск: ../php/php.exe local/unics/cli/contrast_report.php
 * Только наши: ../php/php.exe local/unics/cli/contrast_report.php --ours
 *
 * @package local_unics
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/unics/tests/fixtures/contrast_analyzer.php');

use local_unics\contrast_analyzer;

[$options] = cli_get_params(['ours' => false, 'help' => false], ['h' => 'help']);

if ($options['help']) {
    cli_writeln("Отчет контраста темы unics.\n  --ours  печатать только наши находки");
    exit(0);
}

$decls = contrast_analyzer::declarations(contrast_analyzer::css());
$found = contrast_analyzer::audit($decls, contrast_analyzer::combos());

/**
 * Свернуть находки по паре «селектор + правило»: одна и та же пара повторяется в
 * нескольких комбинациях. Оставляем ХУДШИЙ замер и перечень схем, где она проявилась.
 */
$collapse = function (array $rows): array {
    $out = [];
    foreach ($rows as $f) {
        $key = $f['sel'] . '|' . $f['rule'];
        if (!isset($out[$key])) {
            $out[$key] = $f;
            $out[$key]['combos'] = [];
        } else if ($f['ratio'] < $out[$key]['ratio']) {
            $combos = $out[$key]['combos'];
            $out[$key] = $f;
            $out[$key]['combos'] = $combos;
        }
        $out[$key]['combos'][$f['combo']] = true;
    }
    uasort($out, fn($a, $b) => $a['ratio'] <=> $b['ratio']);
    return $out;
};

$ours = $collapse(array_filter($found, fn($f) => contrast_analyzer::is_ours($f['sel'])));
$core = $collapse(array_filter($found, fn($f) => !contrast_analyzer::is_ours($f['sel'])));

$print = function (array $rows) {
    foreach ($rows as $f) {
        cli_writeln(sprintf('%5.2f:1  правило %d  #%s на #%s  %s',
            $f['ratio'], $f['rule'], $f['fg'], $f['bg'], $f['sel']));
        cli_writeln(sprintf('         худшая схема: %s (всего схем: %d)',
            $f['combo'], count($f['combos'])));
    }
};

cli_writeln(sprintf('Деклараций разобрано: %d', count($decls)));
cli_writeln(sprintf('Находок по всем 16 комбинациям: %d', count($found)));
cli_writeln(sprintf('Уникальных пар: НАШИХ %d, ядровых %d', count($ours), count($core)));
cli_writeln('');
cli_writeln('=== НАШИ (за них отвечаем, их судит страж) ===');
$print($ours);

if (!$options['ours']) {
    cli_writeln('');
    cli_writeln('=== ЯДРОВЫЕ (справка, по решению 3 спеки не чиним) ===');
    $print($core);
}
