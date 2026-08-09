<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Форма запроса на генерацию картинки ([[ai-lecture-images-design]], раздел 3).
 *
 * Сеть в юнит-тесте недоступна, а сломана была именно ФОРМА запроса: объявляя
 * text2image в массиве functions, мы превращали встроенную серверную функцию
 * GigaChat в клиентскую, и модель возвращала вызов нам вместо картинки. Тест
 * стережет, чтобы объявление не вернулось.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class ai_image_payload_test extends \advanced_testcase {

    public function test_payload_has_no_functions_declaration(): void {
        $this->resetAfterTest();
        $payload = (new ai_generator())->build_image_payload('нарисуй воду в природе');

        $this->assertArrayNotHasKey('functions', $payload);
        $this->assertSame(['name' => 'text2image'], $payload['function_call']);
    }

    public function test_payload_carries_prompt_as_user_message(): void {
        $this->resetAfterTest();
        $payload = (new ai_generator())->build_image_payload('нарисуй воду');

        $this->assertSame([['role' => 'user', 'content' => 'нарисуй воду']], $payload['messages']);
    }

    public function test_image_model_defaults_to_gigachat_2(): void {
        $this->resetAfterTest();
        set_config('ai_image_model', '', 'local_unics');

        $this->assertSame('GigaChat-2',
            (new ai_generator())->build_image_payload('x')['model']);
    }

    public function test_image_model_honors_setting(): void {
        $this->resetAfterTest();
        set_config('ai_image_model', 'GigaChat-2-Pro', 'local_unics');

        $this->assertSame('GigaChat-2-Pro',
            (new ai_generator())->build_image_payload('x')['model']);
    }

    /**
     * Модель картинок отвязана от текстовой намеренно: текст работает на GigaChat,
     * а GigaChat на запрос картинки не отвечает вовсе (HTTP 0, замерено 2026-08-09).
     */
    public function test_image_model_is_independent_of_text_model(): void {
        $this->resetAfterTest();
        set_config('ai_model', 'GigaChat', 'local_unics');
        set_config('ai_image_model', '', 'local_unics');

        $this->assertSame('GigaChat-2',
            (new ai_generator())->build_image_payload('x')['model']);
    }
}
