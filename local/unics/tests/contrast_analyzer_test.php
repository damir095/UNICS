<?php
namespace local_unics;

/**
 * Юнит-тесты ядра анализатора контраста ([[contrast-audit-design]], раздел 6).
 * Работают на синтетическом CSS: компиляция реальной темы медленная, а разбор
 * деклараций и резолв токенов от нее не зависят.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class contrast_analyzer_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/tests/fixtures/contrast_analyzer.php');
    }

    public function test_declarations_parses_selector_property_value(): void {
        $css = ':root{--unics-text:#1f2430;--unics-bg:#f5f6f9}.a{color:red;background:#fff}';
        $decls = contrast_analyzer::declarations($css);

        $this->assertCount(4, $decls);
        $this->assertSame(':root', $decls[0]['sel']);
        $this->assertSame('--unics-text', $decls[0]['prop']);
        $this->assertSame('#1f2430', $decls[0]['val']);
        $this->assertSame('.a', $decls[2]['sel']);
        $this->assertSame('color', $decls[2]['prop']);
        // ord монотонно растет - на нем разрешаются ничьи по специфичности.
        $this->assertSame(0, $decls[0]['ord']);
        $this->assertSame(3, $decls[3]['ord']);
    }

    public function test_ratio_matches_known_wcag_values(): void {
        // Черный на белом - максимум 21:1.
        $this->assertEqualsWithDelta(21.0, contrast_analyzer::ratio('000000', 'ffffff'), 0.01);
        // Калибровка по замеру из спеки: .add-section = #f26545 на #f5f6f9.
        $this->assertEqualsWithDelta(2.89, contrast_analyzer::ratio('f26545', 'f5f6f9'), 0.01);
        // Порядок аргументов не влияет.
        $this->assertEqualsWithDelta(
            contrast_analyzer::ratio('f26545', 'f5f6f9'),
            contrast_analyzer::ratio('f5f6f9', 'f26545'),
            0.001
        );
    }

    public function test_combos_enumerates_sixteen_combinations(): void {
        $combos = contrast_analyzer::combos();
        $this->assertCount(16, $combos, 'theme(2) x contrast(2) x accent(4) = 16');
        // Светлая, без контраста, акцент по умолчанию - пустой список классов.
        $this->assertContains([], $combos);
        // Комбинация без собственного CSS-блока тоже обязана быть в списке.
        $this->assertContains(
            ['unics-a11y-dark', 'unics-a11y-contrast', 'unics-a11y-accent-blue'],
            $combos
        );
    }

    public function test_declarations_handles_semicolons_inside_urls(): void {
        // Парсер должен учитывать скобки: url(...) может содержать точку с запятой.
        // Это типичный случай для скомпилированного CSS: data-URI с base64 в Bootstrap.
        $css = '.icon{background:url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iAA==);color:red}';
        $decls = contrast_analyzer::declarations($css);

        $this->assertCount(2, $decls);
        // Фон должен прийти целым, с полным data-URI.
        $this->assertSame('.icon', $decls[0]['sel']);
        $this->assertSame('background', $decls[0]['prop']);
        $this->assertSame('url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iAA==)', $decls[0]['val']);
        $this->assertSame(0, $decls[0]['ord']);
        // Цвет - второй.
        $this->assertSame('.icon', $decls[1]['sel']);
        $this->assertSame('color', $decls[1]['prop']);
        $this->assertSame('red', $decls[1]['val']);
        $this->assertSame(1, $decls[1]['ord']);
    }

    public function test_tokens_applies_only_matching_scheme_blocks(): void {
        $css = ':root{--unics-text:#1f2430}'
             . 'html.unics-a11y-dark{--unics-text:#e8eaf0}';

        $light = contrast_analyzer::tokens(contrast_analyzer::declarations($css), []);
        $dark = contrast_analyzer::tokens(
            contrast_analyzer::declarations($css), ['unics-a11y-dark']
        );

        $this->assertSame('1f2430', $light['--unics-text']);
        $this->assertSame('e8eaf0', $dark['--unics-text']);
    }

    public function test_tokens_later_declaration_wins_equal_specificity(): void {
        // Реальная коллизия из _accessibility.scss: контраст-блок (строка 598) ставит
        // углубленный акцент, accent-миксин (строка 724) - свой, специфичность равна,
        // побеждает поздний. Это и есть дефект «акцент отменяет контраст».
        $css = 'html.unics-a11y-contrast{--unics-primary-text:#9a3216}'
             . 'html.unics-a11y-accent-blue{--unics-primary-text:#1565c0}';

        $tokens = contrast_analyzer::tokens(
            contrast_analyzer::declarations($css),
            ['unics-a11y-contrast', 'unics-a11y-accent-blue']
        );

        $this->assertSame('1565c0', $tokens['--unics-primary-text']);
    }

    public function test_tokens_higher_specificity_beats_earlier_order(): void {
        // Два класса в селекторе = (0,2,1) и должны бить одноклассовый (0,1,1),
        // даже если объявлены раньше.
        $css = 'html.unics-a11y-dark.unics-a11y-contrast{--unics-text:#ffffff}'
             . 'html.unics-a11y-contrast{--unics-text:#000000}';

        $tokens = contrast_analyzer::tokens(
            contrast_analyzer::declarations($css),
            ['unics-a11y-dark', 'unics-a11y-contrast']
        );

        $this->assertSame('ffffff', $tokens['--unics-text']);
    }

    public function test_resolve_follows_var_chains_and_fallbacks(): void {
        $tokens = ['--unics-surface' => 'ffffff', '--unics-section-bg' => 'ffffff'];

        $this->assertSame('ffffff', contrast_analyzer::resolve('#fff', $tokens));
        $this->assertSame('ffffff', contrast_analyzer::resolve('var(--unics-surface)', $tokens));
        // Несуществующий токен -> используется fallback. Это механизм M4:
        // var(--unics-muted, #6c757d) молча дает хардкод во всех схемах.
        $this->assertSame('6c757d', contrast_analyzer::resolve('var(--unics-muted, #6c757d)', $tokens));
        // Несуществующий токен без fallback -> null, пара непроверяема.
        $this->assertNull(contrast_analyzer::resolve('var(--unics-nope)', $tokens));
        // Не сводится к непрозрачному цвету.
        $this->assertNull(contrast_analyzer::resolve('transparent', $tokens));
        $this->assertNull(contrast_analyzer::resolve('linear-gradient(135deg,#fff,#000)', $tokens));
    }
}
