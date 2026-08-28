<?php
namespace local_unics;

/**
 * Страж контраста: гоняет анализатор по реальному бандлу темы и падает на любой
 * НАШЕЙ находке вне allowlist ([[contrast-audit-design]], решение 7).
 *
 * Смысл стража - не найти сегодняшние дефекты (их находит CLI-отчет), а не дать
 * вернуться завтрашним: новый хардкод в партиале роняет сьют.
 *
 * Судятся только наши селекторы (contrast_analyzer::is_ours). Ядровые правила Moodle
 * написаны под светлую схему, а наши темные переопределения живут на других
 * селекторах - слияние по имени селектора их не видит и дает сотни ложных
 * срабатываний. По решению 3 спеки чисто ядровое идет в отчет, а не в правку.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class contrast_guard_test extends \advanced_testcase {

    /**
     * Пары, признанные приемлемыми, с обоснованием. Единственное место, где дефект
     * объявляется допустимым - добавлять сюда можно только с замером и причиной.
     */
    private const ALLOWLIST = [
        '.unics-child-course .unics-type-video .unics-tile' =>
            'Тайл типа активности 44x44, крупный декоративный значок: порог AA = 3.0, замер 3.45.',
        '.unics-child-course .unics-type-quiz .unics-tile' =>
            'Тайл типа активности 44x44, крупный декоративный значок: порог AA = 3.0, замер 3.58.',
        '.unics-child-course .unics-type-material .unics-tile' =>
            'Тайл типа активности 44x44, крупный декоративный значок: порог AA = 3.0, замер 3.94.',
        '.unics-child-course .unics-type-cert .unics-tile' =>
            'Тайл типа активности 44x44, крупный декоративный значок: порог AA = 3.0, замер 4.03. '
            . 'Значение подобрано намеренно, см. комментарий у --unics-ctype-cert в _variables.scss.',
    ];

    /**
     * Дефекты, ставшие видимыми 2026-08-28 после расширения охвата стража, и ЕЩЕ НЕ разобранные.
     *
     * Это НЕ allowlist. В allowlist попадает пара, признанная приемлемой ПОСЛЕ замера; сюда -
     * пара, которую страж раньше не судил вовсе, потому что определял принадлежность по слову
     * `unics` в селекторе, а партиалы `_core-*.scss` намеренно красят ядровые селекторы. Охват
     * вырос на 202 группы правил, то есть примерно на треть.
     *
     * Список обязан ТАЯТЬ ДО НУЛЯ, и тест сверяет его ровно: починив дефект, надо убрать строку,
     * иначе тест упадет на устаревшем перечне. Добавлять сюда новое нельзя.
     *
     * Разбор каждой находки - отдельная задача: часть может оказаться артефактом самого стража
     * (правило 3 берет фон ПРЕДКА и не знает ни специфичности, ни настоящего DOM), а часть -
     * настоящим дефектом, который годами не видел никто.
     */
    private const OPEN_AFTER_WIDENING = [
        '.activity-completion .completion-icon' =>
            '2.28:1 в схеме dark+accent-purple (#6b3fa0 на #1a1d24), правило 2',
        '.alert.alert-warning' =>
            '4.28:1 в схеме light (#b25e09 на #fff4e0), правило 1',
        '.breadcrumb-item+.breadcrumb-item::before' =>
            '1.26:1 в схеме dark (#292f3b на #1a1d24), правило 2',
        '.btn-outline-primary' =>
            '1.29:1 в схеме dark+contrast+accent-green (#8fe3a3 на #ffe6dc), правило 1',
        '.btn-outline-primary:hover,.btn-outline-primary:focus' =>
            '3.12:1 в схеме light (#ffffff на #f26545), правило 1',
        '.card .card-header.bg-primary,.card .card-header.bg-primary *' =>
            '1.00:1 в схеме light (#ffffff на #ffffff), правило 3',
        '.course-content li.section.current::before' =>
            '1.53:1 в схеме dark+contrast+accent-green (#ffffff на #8fe3a3), правило 1',
        '.navbar .navbar-brand:hover,.navbar .navbar-brand:focus,'
            . '.navbar .nav-link:hover,.navbar .nav-link:focus' =>
            '1.82:1 в схеме accent-purple (#6b3fa0 на #292f3b), правило 3',
        '.path-mod-quiz #mod_quiz_navblock .qnbutton.notanswered' =>
            '4.28:1 в схеме light (#b25e09 на #fff4e0), правило 1',
        '.que .formulation' =>
            '1.22:1 в схеме dark (#001a1e на #242832), правило 1',
    ];

    /** То же для брендовых заливок без закрепленного цвета текста. */
    private const OPEN_FILLS_AFTER_WIDENING = [
        '.btn-primary',
        '.btn-primary:hover,.btn-primary:focus,.btn-primary:active',
        '.card .card-header.bg-primary',
        '.que .badge.bg-primary',
    ];

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/tests/fixtures/contrast_analyzer.php');
    }

    public function test_no_contrast_failures_in_our_css(): void {
        $decls = contrast_analyzer::declarations(contrast_analyzer::css());
        $found = contrast_analyzer::audit($decls, contrast_analyzer::combos());

        $unexpected = [];
        $stillopen = [];
        foreach ($found as $f) {
            if (!contrast_analyzer::is_ours($f['sel'])) {
                continue;
            }
            if (isset(self::ALLOWLIST[$f['sel']])) {
                continue;
            }
            if (isset(self::OPEN_AFTER_WIDENING[$f['sel']])) {
                $stillopen[$f['sel']] = true;
                continue;
            }
            $key = $f['sel'];
            // Оставляем худший замер по паре, иначе одна пара повторится 16 раз.
            if (!isset($unexpected[$key]) || $f['ratio'] < $unexpected[$key]['ratio']) {
                $unexpected[$key] = $f;
            }
        }

        $lines = [];
        foreach ($unexpected as $f) {
            $lines[] = sprintf('  %.2f:1  правило %d  #%s на #%s  [%s]  %s',
                $f['ratio'], $f['rule'], $f['fg'], $f['bg'], $f['combo'], $f['sel']);
        }

        // Перечень открытого долга обязан быть ТОЧНЫМ: починив дефект, надо убрать строку.
        // Иначе список превратится в кладбище и переживет то, что описывает.
        $expectedopen = array_keys(self::OPEN_AFTER_WIDENING);
        $actualopen = array_keys($stillopen);
        sort($expectedopen);
        sort($actualopen);
        $this->assertSame($expectedopen, $actualopen,
            'Список OPEN_AFTER_WIDENING устарел: дефект починен, а строка осталась.');

        $this->assertSame([], $lines, sprintf(
            "Пар с недостаточным контрастом в НАШЕМ CSS: %d\n%s",
            count($lines),
            implode("\n", $lines)
        ));
    }

    /**
     * Брендовая заливка обязана закреплять цвет текста В ТОМ ЖЕ правиле.
     *
     * Правило контраста выше судит ПАРУ цветов и потому слепо к правилу, которое
     * задает только фон: цвет там приходит по наследованию или из другого правила,
     * и в темной схеме его перебивает общий текст-руль `_accessibility.scss`
     * (h1..., p, span, div, ...) значением --unics-text. На брендовом #C44A2F это
     * дает 3.99:1 при пороге 4.5.
     *
     * Дефект такого вида ловился рантайм-аудитом ТРИЖДЫ за два дня (аватарка
     * контакта и групповая аватарка 2026-08-12, текст своего пузыря сообщения
     * 2026-08-12), каждый раз уже на живом стенде. Проверка статическая: заливка
     * одним из брендовых токенов без `color` в том же блоке роняет сьют.
     *
     * Обратный случай (правило перекрывает ФОН у элемента, чей цвет закреплен
     * ДРУГИМ правилом, - так сломалась аватарка выбранной беседы класса) этой
     * проверкой НЕ накрыт: в статике не видно, какое правило выиграет каскад.
     */
    public function test_brand_fill_always_pins_text_colour(): void {
        // Токены-заливки, у которых парный текст - --unics-on-primary, а не
        // унаследованный цвет. --unics-primary-deep включен намеренно: в темной
        // схеме он ТЕКСТОВЫЙ (светлый), и заливка им - отдельная ошибка.
        $fills = ['--unics-primary-btn', '--unics-primary-hover', '--unics-primary-deep', '--unics-primary'];

        // Заливки без текста внутри: у полос прогресса контент - сама заливка.
        $allowed = [
            '.unics-child-course .unics-course-progress-bar' => 'Полоса прогресса курса: градиент без текста внутри.',
            '.unics-child-course .unics-sec-progress-bar'    => 'Полоса прогресса раздела: градиент без текста внутри.',
            '.unics-staff-course .unics-staff-sec-bar'       => 'Полоса прогресса раздела (педагог): градиент без текста внутри.',
        ];

        $bysel = [];
        foreach (contrast_analyzer::declarations(contrast_analyzer::css()) as $d) {
            $bysel[$d['sel']][$d['prop']] = $d['val'];
        }

        $lines = [];
        $stillopen = [];
        foreach ($bysel as $sel => $props) {
            if (!contrast_analyzer::is_ours($sel) || isset($allowed[$sel]) || isset($props['color'])) {
                continue;
            }
            if (in_array($sel, self::OPEN_FILLS_AFTER_WIDENING, true)) {
                $stillopen[] = $sel;
                continue;
            }
            foreach (['background', 'background-color'] as $prop) {
                if (!isset($props[$prop])) {
                    continue;
                }
                foreach ($fills as $fill) {
                    if (strpos($props[$prop], $fill) !== false) {
                        $lines[] = sprintf('  %s { %s: %s }  - нет color в том же правиле', $sel, $prop, $props[$prop]);
                        continue 3;
                    }
                }
            }
        }

        $expectedopen = self::OPEN_FILLS_AFTER_WIDENING;
        sort($expectedopen);
        sort($stillopen);
        $this->assertSame($expectedopen, $stillopen,
            'Список OPEN_FILLS_AFTER_WIDENING устарел: заливка починена, а строка осталась.');

        $this->assertSame([], $lines, sprintf(
            "Брендовая заливка без закрепленного цвета текста: %d\n%s",
            count($lines),
            implode("\n", $lines)
        ));
    }
}
