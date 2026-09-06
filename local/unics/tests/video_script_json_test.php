<?php
/**
 * Разбор ответа модели в generate_video_script().
 *
 * Зачем этот файл есть. Видеосценарий держал СВОЮ копию разбора JSON - жадный шаблон «{.*}» плюс
 * одна правка экранирования, - хотя в проекте есть общий `json_reply`, написанный ровно под три
 * способа, которыми GigaChat портит JSON. Копия падала на третьем из них: при обрыве ответа на
 * лимите токенов жадный шаблон хватал от первой скобки до ПОСЛЕДНЕЙ, а последняя лежала внутри
 * оборванного слайда. Разбор проваливался целиком, и ребенок терял весь видеосценарий.
 *
 * Поймано живой пробой: один отказ на десять генераций.
 *
 * @package local_unics
 */

namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\tests\fake_raw_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_ai_generator.php');

#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class video_script_json_test extends \advanced_testcase {

    /** Профиль без ОВЗ: разбор от профиля не зависит. */
    private function profile(): array {
        return ['class_number' => 5, 'difficulty_level' => 2, 'avg_score' => 70.0,
                'categories' => [2], 'ovz_types' => []];
    }

    /** Ответ модели с пятью слайдами в markdown-обертке, как она и присылает. */
    private function reply(int $slides = 5): string {
        $out = [];
        for ($i = 1; $i <= $slides; $i++) {
            $out[] = '    {"title":"Слайд ' . $i . '","content":"Текст слайда ' . $i
                . '.","key_points":["раз","два"]}';
        }
        return "```json\n{\n  \"slides\": [\n" . implode(",\n", $out) . "\n  ]\n}\n```";
    }

    /**
     * Генератор, отдающий заданный сырой ответ.
     *
     * Подменяется generate_text_gigachat(), то есть НИЖЕ output_style::clean(): ответ доезжает до
     * разбора ровно тем путем, каким доезжает боевой - через чистку эмодзи, тире и пробелов. С
     * заглушкой выше чистки тест утверждал бы про байты, которых разбор в бою не видит; докблок
     * фикстуры об этом прямо предупреждает (найдено ревью).
     */
    private function gen(string $raw): fake_raw_generator {
        return new class($raw) extends fake_raw_generator {
            public function __construct(private string $raw) {
                parent::__construct();
            }
            protected function reply(string $prompt): string {
                return $this->raw;
            }
        };
    }

    /** Генерация с перехватом следа: под PHPUnit trace() идет в mtrace. */
    private function lesson(fake_raw_generator $gen): array {
        ob_start();
        try {
            $slides = $gen->generate_video_script($this->profile(), 'Вода');
        } finally {
            // Буфер закрываем ВСЕГДА: на исключении он оставался открытым, и PHPUnit ронял весь
            // файл с «did not close its own output buffers», пряча настоящую причину.
            $trace = (string)ob_get_clean();
        }
        return [$slides, $trace];
    }

    public function test_wrapped_reply_is_parsed(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->gen($this->reply());

        $slides = $gen->generate_video_script($this->profile(), 'Вода');

        $this->assertCount(5, $slides);
        $this->assertSame('Слайд 1', $slides[0]['title']);
    }

    /**
     * Обрыв ПО ГРАНИЦЕ объекта: приходят четыре целых слайда, пятый не начался.
     *
     * Прежний разбор падал здесь целиком - ребенок оставался без видеосценария, педагог видел
     * «ИИ вернул некорректный формат». Конвейер работает по фактическому числу слайдов, поэтому
     * четыре из пяти - рабочий материал, а не брак.
     */
    public function test_truncated_at_object_boundary_keeps_whole_slides(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $full = $this->reply();
        // Срез ровно по закрывающей скобке четвертого слайда. Проверено печатью хвоста: прежний
        // тест утверждал «посреди слайда», а резал именно здесь - и путь восстановления
        // недописанного объекта не проверялся вовсе (найдено ревью).
        $gen = $this->gen(\core_text::substr($full, 0, \core_text::strlen($full) - 90));

        [$slides, $trace] = $this->lesson($gen);

        $this->assertCount(4, $slides);
        $this->assertSame('Слайд 4', $slides[3]['title']);
        $this->assertStringContainsString('слайдов 4 из 5', $trace,
            'Неполный сценарий прошел молча.');
    }

    /**
     * Обрыв ПОСРЕДИ слайда: огрызок в материал не идет.
     *
     * Восстановление честно закрывает скобки, и слайд с содержимым «Текс» проходил проверку на
     * непустоту: ребенок видел обрывок, а озвучка читала его вслух (найдено ревью, воспроизведено
     * на срезах -40 и -50). Потеря всего сценария была бедой, но менять ее на мусор нельзя.
     */
    public function test_truncated_mid_slide_drops_the_fragment(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $full = $this->reply();

        foreach ([50, 40] as $cut) {
            $gen = $this->gen(\core_text::substr($full, 0, \core_text::strlen($full) - $cut));

            [$slides, $trace] = $this->lesson($gen);

            $this->assertCount(4, $slides, "Обрывок дошел до материала при срезе -{$cut}");
            $this->assertSame('Слайд 4', $slides[3]['title']);
            $this->assertStringContainsString('неполным', $trace,
                'Сценарий восстановлен молча - частоту обрывов не измерить второй раз.');
        }
    }

    /**
     * Целый короткий ответ следа НЕ оставляет: иначе журнал задачи зарастет шумом.
     */
    public function test_complete_reply_leaves_no_trace(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->gen($this->reply());

        [$slides, $trace] = $this->lesson($gen);

        $this->assertCount(5, $slides);
        $this->assertSame('', $trace);
    }

    /**
     * И то, что сработать НЕ должно: короткий, но ЦЕЛЫЙ слайд остается.
     *
     * Рядом со знаком завершения сперва стоял порог длины в 15 знаков. Мутация показала, что он
     * не работает - оба живых обрывка отсекает знак, - а зря отбросить короткую целую фразу он
     * мог бы.
     */
    public function test_short_but_complete_slide_is_kept(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->gen('{"slides":[{"title":"Итог","content":"Вода вернулась."}]}');

        [$slides] = $this->lesson($gen);

        $this->assertCount(1, $slides);
        $this->assertSame('Вода вернулась.', $slides[0]['content']);
    }

    /**
     * Бюджет токенов сценария не ниже пола, принятого для теста.
     *
     * Было 3000 на пять слайдов - 600 на слайд, ниже и пола теста, и его бюджета на вопрос. Живая
     * проба дала обрыв на одной генерации из десяти, а по строению промта теряется ВСЕГДА
     * последний слайд, то есть «Итог»: урок ребенка обрывался без завершения. Разбор оборванного
     * ответа лечит симптом, потолок - причину.
     */
    public function test_token_budget_is_not_below_the_floor(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->gen($this->reply());

        $this->lesson($gen);

        $this->assertGreaterThanOrEqual(4096, $gen->limits[0] ?? 0,
            'Потолок токенов опущен - вернется обрыв на последнем слайде.');
    }

    /**
     * И то, что сработать НЕ должно: ответ без слайдов остается отказом.
     *
     * Иначе «мягкий» разбор превратил бы пустой или посторонний ответ в пустую презентацию, и
     * педагог увидел бы созданный материал вместо честной ошибки.
     */
    public function test_reply_without_slides_still_fails(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->gen('Извините, я не могу составить сценарий по этой теме.');

        $this->expectException(\moodle_exception::class);
        $gen->generate_video_script($this->profile(), 'Вода');
    }
}
