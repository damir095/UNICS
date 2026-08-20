<?php
namespace local_unics;

use local_unics\ai\question_tagger;

/**
 * Выборка неразмеченных вопросов и запись привязок ([[codifier-bank-tagging-design]]).
 *
 * @package local_unics
 */
final class question_tagger_bank_test extends \advanced_testcase {

    private int $codifier;
    private int $element;
    private \stdClass $course;

    protected function setUp(): void {
        parent::setUp();
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->codifier = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => (int)$this->course->category, 'name' => 'Математика',
            'created_by_mdl_user_id' => (int)$USER->id, 'timecreated' => time(),
        ]);
        $this->element = codifier_manager::add_element($this->codifier, null, '1', 'Дроби', 'делит дроби');
    }

    /** Вопрос в банке курса; возвращает bankentryid. */
    private function make_question(string $name, string $qtype = 'truefalse'): int {
        global $DB;
        $gen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $gen->create_question_category(
            ['contextid' => \context_course::instance($this->course->id)->id]);
        $q = $gen->create_question($qtype, null, ['name' => $name, 'category' => $cat->id]);
        return (int)$DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $q->id]);
    }

    public function test_untagged_returns_question_of_the_subject(): void {
        $beid = $this->make_question('Про дроби');
        $out = question_tagger::untagged($this->codifier, 30);
        $this->assertCount(1, $out);
        $this->assertSame($beid, $out[0]['bankentryid']);
        $this->assertSame('Про дроби', $out[0]['name']);
    }

    public function test_tagged_question_is_excluded(): void {
        global $USER;
        $beid = $this->make_question('Уже размечен');
        codifier_link_manager::link_question($this->element, $beid, (int)$USER->id);
        $this->assertSame([], question_tagger::untagged($this->codifier, 30),
            'размеченное решение методиста не переигрываем');
    }

    public function test_random_question_is_excluded(): void {
        // Имя случайному вопросу генератор ставит свое («Random (Test question category 1)»),
        // поэтому проверять надо не отсутствие имени, а состав выборки: иначе тест зеленый и
        // без фильтра по qtype - на этом его поймала мутация.
        $normal = $this->make_question('Обычный');
        $this->make_question('Случайный', 'random');
        $out = question_tagger::untagged($this->codifier, 30);
        $this->assertSame([$normal], array_column($out, 'bankentryid'),
            'случайный вопрос - не задание, а правило отбора');
    }

    public function test_question_of_another_category_is_excluded(): void {
        $other = $this->getDataGenerator()->create_course(
            ['category' => $this->getDataGenerator()->create_category()->id]);
        $gen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $gen->create_question_category(
            ['contextid' => \context_course::instance($other->id)->id]);
        $gen->create_question('truefalse', null, ['name' => 'Чужая дисциплина', 'category' => $cat->id]);
        $names = array_column(question_tagger::untagged($this->codifier, 30), 'name');
        $this->assertNotContains('Чужая дисциплина', $names);
    }

    public function test_limit_is_respected(): void {
        $this->make_question('Первый');
        $this->make_question('Второй');
        $this->make_question('Третий');
        $this->assertCount(2, question_tagger::untagged($this->codifier, 2));
        $this->assertSame(3, question_tagger::untagged_count($this->codifier));
    }

    public function test_apply_links_and_does_not_duplicate(): void {
        global $DB, $USER;
        $beid = $this->make_question('Про дроби');
        $pairs = [['bankentryid' => $beid, 'element_id' => $this->element]];

        $this->assertSame(1, question_tagger::apply($pairs, (int)$USER->id));
        $this->assertSame(0, question_tagger::apply($pairs, (int)$USER->id),
            'повторное подтверждение не должно плодить привязки');
        $this->assertSame(1, $DB->count_records('unics_codifier_link',
            ['target_type' => codifier_link_manager::TYPE_QUESTION, 'target_id' => $beid]));
    }

    public function test_apply_skips_pair_without_element(): void {
        global $DB, $USER;
        $beid = $this->make_question('Про дроби');
        $this->assertSame(0, question_tagger::apply(
            [['bankentryid' => $beid, 'element_id' => 0]], (int)$USER->id));
        $this->assertSame(0, $DB->count_records('unics_codifier_link',
            ['target_type' => codifier_link_manager::TYPE_QUESTION]));
    }

    public function test_propose_retries_once_after_broken_reply(): void {
        $good = '{"tags":[{"n":1,"code":"1","sure":true}]}';
        $gen = new class('мусор', $good) extends \local_unics\ai\ai_generator {
            private array $queue;
            public int $calls = 0;
            // Родительский конструктор не зову намеренно: он читает ключ API из настроек.
            public function __construct(string $first, string $second) {
                $this->queue = [$first, $second];
            }
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                $this->calls++;
                return array_shift($this->queue) ?? '';
            }
        };
        $questions = [['bankentryid' => 11, 'name' => 'Про дроби', 'text' => 'Разделите 1/2 на 1/4']];
        $out = (new question_tagger($gen))->propose($questions,
            question_tagger::elements_for_prompt($this->codifier));
        $this->assertSame(2, $gen->calls, 'битый ответ обязан вызывать вторую попытку');
        $this->assertSame('1', $out[0]['code']);
    }

    public function test_elements_for_prompt_carry_code_and_description(): void {
        $out = question_tagger::elements_for_prompt($this->codifier);
        $this->assertSame('1', $out[0]['code']);
        $this->assertSame('Дроби', $out[0]['title']);
        $this->assertSame('делит дроби', $out[0]['description']);
    }
}
