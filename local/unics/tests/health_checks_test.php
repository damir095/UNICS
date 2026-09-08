<?php
namespace local_unics;

use local_unics\health\check_result;
use local_unics\health\checks\cat_threshold;
use local_unics\health\checks\cron_freshness;

/**
 * Дешевые проверки здоровья: запросы к своей БД, считаются в том числе для полосы тревоги.
 * Пороги и уровни выведены из реальных инцидентов, см. [[health-page-design]].
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cron_freshness::class)]
final class health_checks_test extends \advanced_testcase {

    /** Проставить всем плановым задачам время последнего прогона. */
    private function set_last_cron(int $when): void {
        global $DB;
        $DB->execute('UPDATE {task_scheduled} SET lastruntime = ?', [$when]);
    }

    public function test_fresh_cron_is_ok(): void {
        $this->resetAfterTest();
        $this->set_last_cron(time() - 60);

        $r = (new cron_freshness())->run();

        $this->assertSame(check_result::OK, $r->level);
    }

    public function test_stale_cron_is_alarm_and_says_what_to_do(): void {
        $this->resetAfterTest();
        $this->set_last_cron(time() - 40 * DAYSECS);

        $r = (new cron_freshness())->run();

        $this->assertSame(check_result::ALARM, $r->level);
        // Человеку без PHP нужно ДЕЙСТВИЕ, а не код ошибки.
        $this->assertNotSame('', $r->action);
        $this->assertStringContainsString('Планировщик', $r->action);
    }

    public function test_never_run_cron_is_alarm(): void {
        $this->resetAfterTest();
        $this->set_last_cron(0);

        $this->assertSame(check_result::ALARM, (new cron_freshness())->run()->level);
    }

    /** Граница: ровно на пороге еще порядок, за порогом уже авария. */
    public function test_threshold_boundary(): void {
        $this->resetAfterTest();
        $this->set_last_cron(time() - cron_freshness::STALE_AFTER + 5);
        $this->assertSame(check_result::OK, (new cron_freshness())->run()->level);

        $this->set_last_cron(time() - cron_freshness::STALE_AFTER - 5);
        $this->assertSame(check_result::ALARM, (new cron_freshness())->run()->level);
    }

    public function test_check_is_cheap(): void {
        $this->resetAfterTest();
        // Дешевая = считается на каждой странице для полосы, значит только свои таблицы.
        $this->assertTrue((new cron_freshness())->is_cheap());
    }

    /** Строка очереди УМК в нужном статусе и возрасте. */
    private function make_queue_row(int $status, int $agesec): int {
        global $DB;
        return (int)$DB->insert_record('unics_ai_queue', (object)[
            'umk_id'              => 0,
            'student_ids'         => json_encode([]),
            'generate_text'       => 1,
            'generate_audio'      => 0,
            'generate_quiz'       => 0,
            'generate_assignment' => 0,
            'generate_video'      => 0,
            'generate_images'     => 0,
            'status'              => $status,
            'created_at'          => time() - $agesec,
        ]);
    }

    public function test_empty_queue_is_ok(): void {
        $this->resetAfterTest();
        $this->assertSame(check_result::OK, (new \local_unics\health\checks\ai_queue_backlog())->run()->level);
        $this->assertSame(check_result::OK, (new \local_unics\health\checks\ai_queue_stuck())->run()->level);
        $this->assertSame(check_result::OK, (new \local_unics\health\checks\ai_queue_failures())->run()->level);
    }

    public function test_fresh_pending_is_ok_but_old_pending_is_alarm(): void {
        $this->resetAfterTest();
        // Свежая заявка - норма: воркер стартует не мгновенно.
        $this->make_queue_row(\local_unics\ai\ai_queue::STATUS_PENDING, 60);
        $this->assertSame(check_result::OK, (new \local_unics\health\checks\ai_queue_backlog())->run()->level);

        // Старая ждущая - значит дренаж не идет.
        $this->make_queue_row(\local_unics\ai\ai_queue::STATUS_PENDING, 3600);
        $r = (new \local_unics\health\checks\ai_queue_backlog())->run();
        $this->assertSame(check_result::ALARM, $r->level);
        $this->assertNotSame('', $r->action);
    }

    public function test_long_processing_is_alarm(): void {
        $this->resetAfterTest();
        $this->make_queue_row(\local_unics\ai\ai_queue::STATUS_PROCESSING, 7 * 3600);
        $this->assertSame(check_result::ALARM, (new \local_unics\health\checks\ai_queue_stuck())->run()->level);
    }

    /** Ошибки в истории - это «внимание», а не «авария»: полоса не должна гореть всегда. */
    public function test_failures_are_attention_not_alarm(): void {
        $this->resetAfterTest();
        $this->make_queue_row(\local_unics\ai\ai_queue::STATUS_FAILED, 10 * DAYSECS);
        $r = (new \local_unics\health\checks\ai_queue_failures())->run();
        $this->assertSame(check_result::ATTENTION, $r->level);
        $this->assertStringContainsString('1', $r->summary);
    }

    /** Озвучка: метка ставится реальной попыткой синтеза, зонда нет. */
    public function test_tts_marked_unavailable_is_attention_with_payment_hint(): void {
        $this->resetAfterTest();
        set_config('salute_speech_api_key', 'ключ', 'local_unics');
        \local_unics\ai\tts_status::mark_unavailable('HTTP 402 Payment Required');

        $r = (new \local_unics\health\checks\salute_speech())->run();

        // Не оплачено - это не поломка системы, а состояние договора.
        $this->assertSame(check_result::ATTENTION, $r->level);
        $this->assertStringContainsString('оплат', mb_strtolower($r->action));
    }

    /**
     * Реальная метка со стенда: «Payment Required» БЕЗ кода.
     *
     * Метку пишет ai_generator из поля `message` ответа Сбера, а не из кода ответа, поэтому
     * проверка по подстроке «402» промахивалась и советовала «проверьте ключ и интернет».
     * Найдено живым заходом на страницу; тест выше кормил искусственную строку с числом.
     */
    public function test_tts_reason_without_code_still_gives_payment_hint(): void {
        $this->resetAfterTest();
        set_config('salute_speech_api_key', 'ключ', 'local_unics');
        \local_unics\ai\tts_status::mark_unavailable('Payment Required');

        $r = (new \local_unics\health\checks\salute_speech())->run();

        $this->assertStringContainsString('оплат', mb_strtolower($r->action));
    }

    public function test_tts_available_is_ok(): void {
        $this->resetAfterTest();
        set_config('salute_speech_api_key', 'ключ', 'local_unics');
        \local_unics\ai\tts_status::mark_available();

        $this->assertSame(check_result::OK,
            (new \local_unics\health\checks\salute_speech())->run()->level);
    }

    public function test_old_adhoc_task_is_alarm(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record('task_adhoc', (object)[
            'component'     => 'local_unics',
            'classname'     => '\local_unics\task\send_new_assign_notification',
            'nextruntime'   => time() - 2 * DAYSECS,
            'faildelay'     => 0,
            'customdata'    => json_encode(['cmid' => 1]),
            'timecreated'   => time() - 2 * DAYSECS,
        ]);
        $this->assertSame(check_result::ALARM, (new \local_unics\health\checks\adhoc_backlog())->run()->level);
    }

    /**
     * Недостижимый порог точности - повод сказать администратору, а не молчать.
     *
     * Замер 2026-09-08: порог 0.3 не сработал НИ РАЗУ - четыреста сессий из четырехсот
     * остановились по пределу заданий. Число вводит администратор в настройках, и там его никто
     * не предупреждал ([[cat-attainable-precision]]).
     *
     * Вопрос о НАСТРОЙКАХ, а не о базе: больше предела ребенку не дадут, сколько заданий ни
     * накопи, поэтому пол ошибки считается из одного предела.
     */
    public function test_unreachable_threshold_is_reported(): void {
        $this->resetAfterTest();
        set_config('adaptive_cat_enabled', 1, 'local_unics');
        set_config('cat_se_threshold', '0.3', 'local_unics');
        set_config('cat_max_items', 20, 'local_unics');

        $res = (new cat_threshold())->run();

        $this->assertSame(check_result::ATTENTION, $res->level);
        $this->assertStringContainsString('недостижим', $res->summary);
        // Совет обязан быть ВЫПОЛНИМЫМ. Первая редакция звала набрать 41 задание, а предел резал
        // пул на двадцати - 41 задание при пределе 20 дает 0.408, а не 0.30 (найдено ревью).
        $this->assertStringContainsString('увеличьте предел заданий', $res->action);
        $this->assertStringContainsString('до 41 задания', $res->action);
        // И проверяем, что совет действительно достигает порога.
        set_config('cat_max_items', 41, 'local_unics');
        $this->assertSame(check_result::OK, (new cat_threshold())->run()->level,
            'совет выполнен, а проверка все еще тревожит - значит совет неверен');
    }

    /**
     * СОВЕТ ОБЯЗАН БЫТЬ ВЫПОЛНИМЫМ - на всех порогах и пределах, а не на одном удобном.
     *
     * Прежний тест проверял выполнимость ровно при пороге 0.3 и пределе 20, а там обе формулы
     * срабатывали случайно: 0.40825 округлилось вверх само, и инверсия дала 41 вместо 40. Ревью
     * посчитало остальные комбинации: совет «поднимите порог» уводил НИЖЕ пола на пяти пределах из
     * шести, а совет «увеличьте предел» приводил ровно в отвергаемую точку на трех порогах из
     * четырех. Оба дефекта пережили зеленый сьют.
     *
     * Проверяется то, что администратор ЧИТАЕТ, а не то, что возвращают функции: числа
     * выковыриваются из текста совета и подставляются обратно в настройки.
     *
     * ЧЕГО ЭТОТ ТЕСТ НЕ ЛОВИТ, и это надо назвать: десятичную запятую. Третий дефект ревью в том,
     * что format_float() под русской локалью дает «0,41», а clean_param('0,41', PARAM_FLOAT) -
     * ноль, то есть совет не правил бы настройку, а обнулял. Окружение PHPUnit английское
     * (в phpunit_moodledata нет пакета ru), decsep там точка, и мутация «убрать $localized=false»
     * тест ПЕРЕЖИВАЕТ. Дефект и починка проверены на живом стенде: format_float(0.408, 3) дает
     * «0,408», с false - «0.408». Проверка clean_param ниже оставлена как страховка на случай
     * русского тестового окружения, но покрытием ее считать нельзя.
     */
    public function test_advice_is_executable_on_every_threshold_and_cap(): void {
        $this->resetAfterTest();
        set_config('adaptive_cat_enabled', 1, 'local_unics');
        $seen = 0;
        foreach ([0.2, 0.25, 0.3, 0.4, 0.5] as $t) {
            foreach ([10, 12, 20, 21, 30, 40] as $cap) {
                set_config('cat_se_threshold', (string)$t, 'local_unics');
                set_config('cat_max_items', $cap, 'local_unics');
                if ((new cat_threshold())->run()->level !== check_result::ATTENTION) {
                    continue;
                }
                $seen++;
                $action = (new cat_threshold())->run()->action;
                $where = "порог {$t}, предел {$cap}: ";

                $this->assertSame(1, preg_match('/поднимите порог до ([0-9.,]+)/u', $action, $m1),
                    $where . 'в совете нет порога');
                $this->assertSame(1, preg_match('/за сессию до ([0-9]+)/u', $action, $m2),
                    $where . 'в совете нет предела');

                // Число из совета администратор переносит в поле PARAM_FLOAT руками.
                $advised = clean_param($m1[1], PARAM_FLOAT);
                $this->assertGreaterThan(0.0, $advised,
                    $where . 'совет набран так, что настройка обнулится: ' . $m1[1]);

                // Совет первый: поднять порог, предел оставить.
                set_config('cat_se_threshold', (string)$advised, 'local_unics');
                $this->assertSame(check_result::OK, (new cat_threshold())->run()->level,
                    $where . 'порог поднят до ' . $advised . ' по совету, а тревога осталась');

                // Совет второй: увеличить предел, порог оставить.
                set_config('cat_se_threshold', (string)$t, 'local_unics');
                set_config('cat_max_items', (int)$m2[1], 'local_unics');
                $this->assertSame(check_result::OK, (new cat_threshold())->run()->level,
                    $where . 'предел поднят до ' . $m2[1] . ' по совету, а тревога осталась');
            }
        }
        // Сам перебор тоже может стать пустым - тогда тест зелен, ничего не проверив.
        $this->assertGreaterThanOrEqual(10, $seen, 'недостижимых сочетаний почти не набралось');
    }

    /**
     * Ровно на поле точности остановки НЕ будет: сервис требует строго меньше порога.
     *
     * estimate_precision::is_provisional держит ту же границу. Нестрогое сравнение делало бы
     * проверку немой ровно на пороге, который не срабатывает никогда.
     */
    public function test_threshold_exactly_at_the_floor_is_reported(): void {
        $this->resetAfterTest();
        set_config('adaptive_cat_enabled', 1, 'local_unics');
        // Предел 12 выбран НЕ случайно: 1 + 12/4 = 4, корень ровно 2, пол ровно 0.5. Первая
        // редакция брала предел 20 и порог (string)(1/sqrt(6)) - строка теряла разряды, точного
        // равенства не выходило, и мутация «нестрогое сравнение» тест переживала.
        set_config('cat_max_items', 12, 'local_unics');
        set_config('cat_se_threshold', '0.5', 'local_unics');

        $this->assertSame(check_result::ATTENTION, (new cat_threshold())->run()->level,
            'ровно на поле точность не достигается, а проверка промолчала');
    }

    /**
     * И то, что срабатывать НЕ должно: достижимый порог и выключенная проверка.
     */
    public function test_reachable_or_disabled_threshold_is_ok(): void {
        $this->resetAfterTest();
        set_config('cat_max_items', 20, 'local_unics');

        set_config('adaptive_cat_enabled', 1, 'local_unics');
        set_config('cat_se_threshold', '0.6', 'local_unics');
        $res = (new cat_threshold())->run();
        $this->assertSame(check_result::OK, $res->level);
        $this->assertStringContainsString('Достижим', $res->summary);

        set_config('adaptive_cat_enabled', 0, 'local_unics');
        set_config('cat_se_threshold', '0.3', 'local_unics');
        $this->assertSame(check_result::OK, (new cat_threshold())->run()->level,
            'при выключенной проверке порог ни на что не влияет');
    }

    /**
     * Незаданный предел заданий не означает «предела нет».
     *
     * cat_session_manager::config() подставляет свой запас, и считать по другому значило бы
     * обещать точность, которой сессия не даст.
     */
    public function test_unset_item_cap_uses_the_shared_default(): void {
        $this->resetAfterTest();
        set_config('adaptive_cat_enabled', 1, 'local_unics');
        set_config('cat_se_threshold', '0.3', 'local_unics');
        unset_config('cat_max_items', 'local_unics');

        $res = (new cat_threshold())->run();

        $this->assertSame(check_result::ATTENTION, $res->level);
        $this->assertStringContainsString(
            'пределе в ' . \local_unics\learning\cat_limits::DEFAULT_MAX_ITEMS,
            $res->summary);
    }

    /**
     * Подробности идут парами «метка - значение», как их рисует страница здоровья.
     */
    public function test_details_are_labelled(): void {
        $this->resetAfterTest();
        set_config('adaptive_cat_enabled', 1, 'local_unics');
        set_config('cat_se_threshold', '0.3', 'local_unics');
        set_config('cat_max_items', 20, 'local_unics');

        $res = (new cat_threshold())->run();

        $this->assertNotEmpty($res->details);
        foreach ($res->details as $k => $v) {
            $this->assertIsString($k, 'подробности со списковым ключом рисуются как «0: ...»');
        }
    }
}
