<?php
/**
 * Общая заглушка генератора для тестов ИИ-конвейера.
 *
 * До нее каждый тест заводил свой анонимный наследник `ai_generator`, и все они заново решали одно
 * и то же: отличить промт слепого судьи от промта генерации, не позвать родительский конструктор
 * (он читает ключ API из настроек) и запомнить, что спрашивали. К 2026-08-27 таких копий набралось
 * шестнадцать в семи файлах - ровно та беда, из-за которой в свое время появилась
 * `answer_judge::PROMPT_MARKER` ([[answer-judge-design]]).
 *
 * Наследник обязан дать `quiz_reply()`; `judge_reply()` по умолчанию молчит, и этого хватает
 * тестам, которым третий ярус не интересен.
 *
 * ВАЖНО: подменяется `generate_text()`, то есть выше `output_style::clean()`. Тесту, которому нужна
 * боевая чистка, эта заглушка не подходит - там подменяют `generate_text_gigachat()`.
 *
 * @package local_unics
 */

namespace local_unics\tests;

use local_unics\ai\ai_generator;
use local_unics\ai\answer_judge;

abstract class fake_ai_generator extends ai_generator {

    /** Промты генерации, по порядку. Промт судьи сюда НЕ попадает. */
    public array $prompts = [];

    /** Потолок токенов каждого вызова генерации, по порядку. */
    public array $limits = [];

    /** Сколько раз спросили слепого судью. */
    public int $judge_calls = 0;

    /** Родительский конструктор не зовем намеренно: он читает ключ API из настроек. */
    public function __construct() {
    }

    public function generate_text(string $prompt, int $max_tokens = 1024,
                                  int $minlen = self::MIN_REPLY_LEN): string {
        if (str_contains($prompt, answer_judge::PROMPT_MARKER)) {
            $this->judge_calls++;
            return $this->judge_reply($prompt);
        }
        $this->prompts[] = $prompt;
        $this->limits[] = $max_tokens;
        return $this->quiz_reply($prompt);
    }

    /** Последний промт генерации или пустая строка. */
    public function last_prompt(): string {
        return $this->prompts ? (string)end($this->prompts) : '';
    }

    /** Ответ на промт генерации. */
    abstract protected function quiz_reply(string $prompt): string;

    /**
     * Ответ слепого судьи. По умолчанию молчание - оно дает статус «проверка не состоялась»,
     * при котором вердиктов нет и комплект принимается как есть.
     */
    protected function judge_reply(string $prompt): string {
        return '';
    }
}

/**
 * Заглушка НИЖНЕГО уровня: подменяется сам поход в сеть.
 *
 * Отличие от fake_ai_generator принципиальное - ответ проходит боевой путь `generate_text()`, то
 * есть детектор отказа и `output_style::clean()`. Тестам, которые смотрят на промт или проверяют
 * чистку, нужна именно она.
 *
 * Родительский конструктор здесь ЗОВЕТСЯ: `generate_text()` проверяет ключ API и без него бросает.
 * Тест обязан выставить `set_config('ai_api_key', ..., 'local_unics')` до создания заглушки.
 */
abstract class fake_raw_generator extends ai_generator {

    /** Промты генерации, по порядку. Промт судьи сюда НЕ попадает. */
    public array $prompts = [];

    protected function generate_text_gigachat(string $prompt, int $max_tokens = 1024): string {
        // Промт слепого судьи идет последним и перебивал бы сохраненный промт генерации, ради
        // которого заглушка и заведена.
        if (!str_contains($prompt, answer_judge::PROMPT_MARKER)) {
            $this->prompts[] = $prompt;
        }
        return $this->reply($prompt);
    }

    /** Последний промт генерации или пустая строка. */
    public function last_prompt(): string {
        return $this->prompts ? (string)end($this->prompts) : '';
    }

    /** Что «вернула сеть». */
    abstract protected function reply(string $prompt): string;
}
