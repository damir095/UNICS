<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class ai_generator {

    private string $groq_key;
    private string $groq_model;
    private string $voicerss_key;

    public function __construct() {
        $this->groq_key    = (string) get_config('local_unics', 'groq_api_key');
        $this->groq_model  = get_config('local_unics', 'groq_model') ?: 'llama-3.1-8b-instant';
        $this->voicerss_key = (string) get_config('local_unics', 'voicerss_api_key');
    }

    // ----------------------------------------------------------------
    // Формирование промпта на основе профиля учащегося
    // ----------------------------------------------------------------
    public function build_prompt(array $profile, string $topic): string {
        $categories = [1 => 'ОВЗ', 2 => 'семейное обучение', 3 => 'длительное лечение', 4 => 'одарённый'];
        $levels     = [1 => 'базовый', 2 => 'стандартный', 3 => 'продвинутый'];

        $category  = $categories[$profile['category']] ?? 'стандартный';
        $level     = $levels[$profile['difficulty_level']] ?? 'стандартный';
        $class     = $profile['class_number'] ?? 5;
        $avg_score = $profile['avg_score'] ?? 70;

        $special = '';
        if (($profile['category'] ?? 0) === 1) {
            $special = "Учащийся имеет особые образовательные потребности (ОВЗ). "
                     . "Используй простые, короткие предложения. Избегай перегруженных абзацев.";
        }

        return "Ты — опытный педагог, создающий учебные материалы для российских школьников.

Задача: напиши учебный текст по теме «{$topic}» для ученика {$class} класса.

Профиль учащегося:
- Категория: {$category}
- Уровень подготовки: {$level}
- Средний балл за последние 5 тестов: {$avg_score}%
{$special}

Требования:
- Объём: 400–600 слов
- Язык: русский, доступный для возраста учащегося
- Структура: краткое введение → 3–4 смысловых абзаца → вывод
- Уровень сложности должен соответствовать указанному
- Включи 2–3 примера из реальной жизни или природы
- Не используй markdown-разметку (только чистый текст)";
    }

    // ----------------------------------------------------------------
    // Генерация текста через Groq API (OpenAI-совместимый формат)
    // ----------------------------------------------------------------
    public function generate_text(string $prompt): string {
        if (empty($this->groq_key)) {
            throw new \moodle_exception('Groq API key не настроен в настройках плагина');
        }

        $payload = [
            'model'    => $this->groq_model,
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens'  => 1024,
            'temperature' => 0.7,
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->groq_key,
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('Groq cURL error: ' . $curl_err);
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('Groq HTTP error: ' . $http_code . ' — ' . $response);
        }

        $decoded = json_decode($response, true);
        $text = $decoded['choices'][0]['message']['content'] ?? '';

        if (empty($text)) {
            throw new \moodle_exception('Groq вернул пустой ответ');
        }

        return $text;
    }

    // ----------------------------------------------------------------
    // Генерация аудио через VoiceRSS API
    // Возвращает бинарные данные MP3
    // ----------------------------------------------------------------
    public function generate_audio(string $text): string {
        if (empty($this->voicerss_key)) {
            throw new \moodle_exception('VoiceRSS API key не настроен в настройках плагина');
        }

        $text = mb_substr($text, 0, 2999);

        $params = http_build_query([
            'key' => $this->voicerss_key,
            'src' => $text,
            'hl'  => 'ru-ru',
            'r'   => '-2',
            'c'   => 'mp3',
            'f'   => '44khz_16bit_stereo',
        ]);

        $ch = curl_init('https://api.voicerss.org/?' . $params);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $audio     = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('VoiceRSS cURL error: ' . $curl_err);
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('VoiceRSS HTTP error: ' . $http_code);
        }
        if (str_starts_with((string)$audio, 'ERROR')) {
            throw new \moodle_exception('VoiceRSS error: ' . $audio);
        }
        if (strlen($audio) < 1000) {
            throw new \moodle_exception('VoiceRSS вернул некорректные аудиоданные');
        }

        return $audio;
    }

    // ----------------------------------------------------------------
    // Скользящее среднее последних 5 тестов учащегося (%)
    // ----------------------------------------------------------------
    public function get_avg_score(int $mdl_user_id): float {
        global $DB;

        $sql = "SELECT g.finalgrade, gi.grademax
                FROM {grade_grades} g
                JOIN {grade_items} gi ON gi.id = g.itemid
                WHERE g.userid = :userid
                  AND gi.itemtype = 'mod'
                  AND gi.itemmodule = 'quiz'
                  AND g.finalgrade IS NOT NULL
                  AND gi.grademax > 0
                ORDER BY g.timemodified DESC
                LIMIT 5";

        $rows = $DB->get_records_sql($sql, ['userid' => $mdl_user_id]);
        if (empty($rows)) {
            return 70.0;
        }

        $total = 0;
        foreach ($rows as $r) {
            $total += ($r->finalgrade / $r->grademax) * 100;
        }
        return round($total / count($rows), 1);
    }
}
