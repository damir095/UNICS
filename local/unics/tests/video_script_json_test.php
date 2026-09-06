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
use local_unics\tests\fake_ai_generator;

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

    /** Генератор, отдающий заданный сырой ответ на промт видеосценария. */
    private function gen(string $raw): fake_ai_generator {
        return new class($raw) extends fake_ai_generator {
            public function __construct(private string $raw) {
                parent::__construct();
            }
            protected function quiz_reply(string $prompt): string {
                return $this->raw;
            }
        };
    }

    public function test_wrapped_reply_is_parsed(): void {
        $this->resetAfterTest();
        $gen = $this->gen($this->reply());

        $slides = $gen->generate_video_script($this->profile(), 'Вода');

        $this->assertCount(5, $slides);
        $this->assertSame('Слайд 1', $slides[0]['title']);
    }

    /**
     * Оборванный на лимите токенов ответ отдает то, что успело прийти, а не пустоту.
     *
     * Прежний разбор падал здесь целиком: ребенок оставался без видеосценария, педагог видел
     * «ИИ вернул некорректный формат». Конвейер работает по фактическому числу слайдов, поэтому
     * четыре из пяти - рабочий материал, а не брак.
     */
    public function test_truncated_reply_keeps_the_slides_that_arrived(): void {
        $this->resetAfterTest();
        $full = $this->reply();
        // Срез посреди последнего слайда - ровно так выглядит упор в лимит токенов.
        $gen = $this->gen(\core_text::substr($full, 0, \core_text::strlen($full) - 90));

        $slides = $gen->generate_video_script($this->profile(), 'Вода');

        $this->assertCount(4, $slides);
        $this->assertSame('Слайд 4', $slides[3]['title']);
    }

    /**
     * И то, что сработать НЕ должно: ответ без слайдов остается отказом.
     *
     * Иначе «мягкий» разбор превратил бы пустой или посторонний ответ в пустую презентацию, и
     * педагог увидел бы созданный материал вместо честной ошибки.
     */
    public function test_reply_without_slides_still_fails(): void {
        $this->resetAfterTest();
        $gen = $this->gen('Извините, я не могу составить сценарий по этой теме.');

        $this->expectException(\moodle_exception::class);
        $gen->generate_video_script($this->profile(), 'Вода');
    }
}
