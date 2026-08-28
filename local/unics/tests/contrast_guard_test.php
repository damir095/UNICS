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
     * Дефекты, вскрытые расширением охвата 2026-08-28. Из одиннадцати починены десять.
     *
     * Списки не «на всякий случай», а рабочий механизм: тест
     * сверяет их РОВНО, поэтому непустой список без соответствующего дефекта валит тест, а
     * дефект без списка - тоже. Появится новая партия находок - её сюда и запишут.
     */
    private const OPEN_AFTER_WIDENING = [
        '.path-mod-quiz #mod_quiz_navblock .qnbutton.incorrect,'
            . '.path-mod-quiz #mod_quiz_navblock .qnbutton.incorrect .trafficlight' =>
            '4.19:1 в схеме light (#ca3120 на #ffdddd), правило 1. Наше правило задает пару '
            . '--unics-error на --unics-error-bg (около 8:1); замер берет ЯДРОВЫЕ #ca3120 и #fdd, '
            . 'то есть слияние выбрало не ту пару. Нужен отдельный разбор: либо остаток артефакта '
            . 'группировки, либо мы и правда не перекрываем светофорный кружок.',
    ];

    /** То же для брендовых заливок без закрепленного цвета текста. */
    private const OPEN_FILLS_AFTER_WIDENING = [];

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

        // Свойства сливаем по ЧАСТЯМ группового селектора, а не по строке целиком. Цвет на
        // брендовой заливке проект закрепляет соседним правилом на более широком наборе
        // селекторов (`.btn-primary, .btn-primary:hover, ... { color: ... }`), и проверка «есть ли
        // color в ТОМ ЖЕ блоке» объявляла такие заливки незакрепленными - четыре ложных
        // срабатывания из четырех (найдено 2026-08-28).
        $bysel = [];
        $fillsel = [];
        foreach (contrast_analyzer::declarations(contrast_analyzer::css()) as $d) {
            foreach (explode(',', $d['sel']) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $bysel[$part][$d['prop']] = $d['val'];
                if (in_array($d['prop'], ['background', 'background-color'], true)) {
                    // Для сообщения нужен исходный вид правила, а не разобранная часть.
                    $fillsel[$part] = $d['sel'];
                }
            }
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
