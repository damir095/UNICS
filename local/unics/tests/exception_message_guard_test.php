<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Сообщения исключений должны доходить до человека, а не как идентификатор языковой строки.
 *
 * Moodle трактует ПЕРВЫЙ аргумент moodle_exception как имя langstring. Русская фраза на этом
 * месте выходит наружу с префиксом: педагог и администратор видели «error/GigaChat image cURL
 * ошибка...». За два дня дефект вылез дважды - пришлось ставить костыль в проверке GigaChat на
 * странице здоровья, и он же виден в логе генерации.
 *
 * Верный прием уже был в коде (generate_text): идентификатор generalexceptionmessage, а текст
 * уходит четвертым аргументом.
 *
 * @package local_unics
 */
final class exception_message_guard_test extends \advanced_testcase {

    /** Все PHP-файлы плагина, кроме самих тестов (там фразы имитируют боевые сообщения). */
    private function plugin_sources(): array {
        // realpath обязателен: без него путь вида .../tests/.. содержит /tests/ и фильтр
        // ниже отсекал ВСЕ файлы - страж молча сканировал пустоту (поймано зондом).
        $root = realpath(__DIR__ . '/..');
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = $file->getRealPath();
            if (strpos(strtr($path, [DIRECTORY_SEPARATOR => '/']), '/tests/') !== false) {
                continue;
            }
            $out[] = $path;
        }
        return $out;
    }

    /**
     * Ни одного moodle_exception с русской фразой в первом аргументе.
     *
     * Страж по исходникам, а не точечная проверка: мест было 22, и точечные тесты защитили бы
     * только те, о которых вспомнили. Тот же прием, что у стража контраста в теме.
     */
    public function test_no_russian_phrase_as_langstring_id(): void {
        $call = '/moodle_exception\(\s*\x27([^\x27]*)\x27/u';
        $cyrillic = '/[\x{0400}-\x{04FF}]/u';
        $bad = [];

        foreach ($this->plugin_sources() as $path) {
            foreach (file($path) as $n => $line) {
                if (preg_match($call, $line, $m) && preg_match($cyrillic, $m[1])) {
                    $bad[] = basename($path) . ':' . ($n + 1) . ' -> ' . trim($m[1]);
                }
            }
        }

        $this->assertSame([], $bad,
            'первый аргумент moodle_exception - идентификатор langstring, а не текст для человека');
    }

    /** Сообщение доходит до человека без служебного префикса. */
    public function test_message_reaches_human_without_prefix(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', '', 'local_unics');

        try {
            (new ai_generator())->generate_image('промт');
            $this->fail('без ключа генерация обязана бросить');
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString('error/', $e->getMessage(),
                'префикс означает, что фразу приняли за имя языковой строки');
            $this->assertStringContainsString('ключ', mb_strtolower($e->getMessage()));
        }
    }
}
