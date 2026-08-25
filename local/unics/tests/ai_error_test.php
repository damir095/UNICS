<?php
namespace local_unics;

use local_unics\ai\ai_error;

/**
 * Отказ ИИ объясняется педагогу, а не логу ([[ai-error-messages-design]]).
 *
 * Шесть throw в ai_generator передавали техническую фразу ПЕРВЫМ аргументом moodle_exception,
 * который трактуется как идентификатор языковой строки. Наружу выходило
 * «error/GigaChat HTTP 402: Payment Required»: и префикс от несостоявшегося поиска строки, и
 * сообщение, из которого педагогу неясно, что делать.
 *
 * На странице статуса УМК префикс срезали регуляркой, и в комментарии там прямо стояло, что
 * настоящее лечение - отдельная задача. Это она.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_error::class)]
final class ai_error_test extends \advanced_testcase {

    public function test_unauthorized_points_at_the_key(): void {
        $m = ai_error::message('GigaChat', 401, 'Unauthorized');

        $this->assertStringContainsString('ключ', mb_strtolower($m));
        $this->assertStringContainsString('401', $m, 'техническая деталь нужна для разбора');
    }

    public function test_forbidden_points_at_the_key_too(): void {
        // 403 у Сбера приходит и на протухший токен, и на чужой scope - для педагога это
        // один и тот же шаг: проверить ключ в настройках.
        $m = ai_error::message('GigaChat', 403, 'Forbidden');

        $this->assertStringContainsString('ключ', mb_strtolower($m));
    }

    public function test_payment_required_says_it_is_not_a_code_problem(): void {
        // Главный случай: пакет не оплачен. Кодом это не чинится, и педагог должен понять,
        // что ждать исправления бессмысленно ([[tts-honest-availability-design]]).
        $m = ai_error::message('SaluteSpeech', 402, 'Payment Required');

        $lower = mb_strtolower($m);
        $this->assertStringContainsString('оплач', $lower);
        $this->assertStringNotContainsString('повторите', $lower,
            'предлагать повтор при неоплате - вести педагога по кругу');
    }

    public function test_bad_request_on_auth_points_at_the_key(): void {
        // Найдено ЖИВЫМ заходом 2026-08-25, а не тестом: на заведомо негодный ключ Сбер
        // отвечает HTTP 400, а не 401. Утверждения выше писались по учебнику, и педагог
        // получал бы «запрос отклонен» вместо подсказки про ключ.
        $m = ai_error::message('GigaChat', 400, 'Bad Request', true);

        $this->assertStringContainsString('ключ', mb_strtolower($m));
        $this->assertStringContainsString('авторизация', $m, 'этап должен быть виден в детали');
    }

    public function test_bad_request_outside_auth_stays_generic(): void {
        // Обратная сторона: 400 на самом запросе - это не ключ, а негодные параметры.
        // Советовать проверять ключ здесь означало бы уводить педагога не туда.
        $m = ai_error::message('GigaChat', 400, 'field "model" is required');

        $this->assertStringNotContainsString('ключ', mb_strtolower($m));
        $this->assertStringNotContainsString('авторизация', $m);
    }

    public function test_rate_limit_suggests_waiting(): void {
        $m = ai_error::message('GigaChat', 429, 'Too Many Requests');

        $this->assertStringContainsString('позже', mb_strtolower($m));
    }

    public function test_server_error_is_not_blamed_on_the_teacher(): void {
        foreach ([500, 502, 503] as $code) {
            $m = ai_error::message('GigaChat', $code, 'Bad Gateway');
            $lower = mb_strtolower($m);
            $this->assertStringContainsString('сторон', $lower, "код $code");
            $this->assertStringContainsString((string)$code, $m);
        }
    }

    public function test_unknown_code_still_gets_a_sentence(): void {
        // Молчаливая пустота хуже технической строки: педагог не поймет даже, что сломалось.
        $m = ai_error::message('GigaChat', 418, 'I am a teapot');

        $this->assertNotSame('', trim($m));
        $this->assertStringContainsString('418', $m);
    }

    public function test_detail_is_kept_for_diagnosis(): void {
        $m = ai_error::message('GigaChat', 400, 'field "model" is required');

        $this->assertStringContainsString('field "model" is required', $m);
        $this->assertStringContainsString('GigaChat', $m, 'какой именно сервис отказал');
    }

    public function test_long_detail_is_trimmed(): void {
        // Тело ответа бывает страницей HTML: в error_message оно не поместится, а педагогу
        // и не нужно.
        $m = ai_error::message('GigaChat', 500, str_repeat('щ', 900));

        $this->assertLessThan(400, mb_strlen($m));
    }

    public function test_empty_detail_does_not_leave_dangling_punctuation(): void {
        $m = ai_error::message('GigaChat', 500, '');

        $this->assertStringNotContainsString(': )', $m);
        $this->assertStringContainsString('500', $m);
    }

    // ---------------------------------------------------------------
    // Как это выглядит наружу
    // ---------------------------------------------------------------

    public function test_exception_starts_with_the_message_itself(): void {
        // Ровно тот дефект, ради которого задача и делается - и ровно то место, где первая
        // редакция его НЕ дочинила: `generalexceptionmessage` подставляет текст в «Исключение -
        // {$a}», и педагог видел жаргонный префикс, только другой. Утверждение про отсутствие
        // «error/» это пропускало (найдено ревью 2026-08-25), поэтому проверяем начало строки.
        $e = ai_error::exception('GigaChat', 402, 'Payment Required');

        $this->assertStringStartsWith('Сервис GigaChat не ответил:', $e->getMessage());
        $this->assertStringNotContainsString('error/', $e->getMessage());
        $this->assertStringNotContainsString('Исключение', $e->getMessage());
        $this->assertStringContainsString('402', $e->getMessage());
    }

    public function test_transport_failure_gets_advice_too(): void {
        // Сеть легла - самый вероятный реальный отказ, и до правки он оставался голой строкой
        // «GigaChat cURL ошибка: Could not resolve host» без единого слова о том, что делать.
        $m = ai_error::transport('GigaChat', 'Could not resolve host: gigachat.devices.sberbank.ru');

        $this->assertStringContainsString('недоступен', mb_strtolower($m));
        $this->assertStringContainsString('Could not resolve host', $m, 'деталь для разбора');
        $this->assertStringNotContainsString('HTTP', $m, 'кода ответа тут нет вовсе');
    }

    public function test_transport_exception_has_no_prefix_either(): void {
        $e = ai_error::transport_exception('SaluteSpeech', 'timeout', true);

        $this->assertStringStartsWith('Сервис SaluteSpeech не ответил:', $e->getMessage());
        $this->assertStringContainsString('авторизация', $e->getMessage());
    }

    public function test_no_response_is_not_called_a_rejection(): void {
        // curl_getinfo отдает 0, когда ответа не было вовсе. «Запрос отклонен» тут утверждает,
        // будто сервис отверг то, чего не получал (найдено ревью 2026-08-25).
        $m = ai_error::message('GigaChat', 0, '');

        $this->assertStringNotContainsString('отклонен', mb_strtolower($m));
        $this->assertStringContainsString('позже', mb_strtolower($m));
    }

    public function test_health_page_still_recognises_unpaid_package(): void {
        // Страница здоровья ищет в тексте «402» либо «payment required» и по этому признаку
        // ведет администратора к оплате. Однажды проверка уже промахивалась мимо реального
        // ответа Сбера, поэтому формат сообщения обязан сохранять признак.
        $m = ai_error::message('SaluteSpeech', 402, 'Payment Required');

        $lower = mb_strtolower($m);
        $this->assertTrue(strpos($lower, '402') !== false
            || strpos($lower, 'payment required') !== false,
            'иначе страница здоровья уведет в ветку «проверьте ключ и интернет»');
    }
}
