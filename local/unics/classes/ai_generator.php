<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class ai_generator {

    // Поддерживаемые текстовые провайдеры
    const PROVIDER_GROQ      = 'groq';
    const PROVIDER_GIGACHAT  = 'gigachat';
    const PROVIDER_DEEPSEEK  = 'deepseek';

    // Поддерживаемые TTS-провайдеры
    const TTS_VOICERSS      = 'voicerss';
    const TTS_SALUTE_SPEECH = 'salute_speech';

    private string $provider;
    private string $api_key;
    private string $model;
    private string $voicerss_key;
    private string $tts_provider;
    private string $salute_key;

    public function __construct() {
        $this->provider     = get_config('local_unics', 'ai_provider') ?: self::PROVIDER_GROQ;
        $this->api_key      = (string) get_config('local_unics', 'ai_api_key');
        $this->voicerss_key = (string) get_config('local_unics', 'voicerss_api_key');
        $this->tts_provider = get_config('local_unics', 'tts_provider') ?: self::TTS_VOICERSS;
        $this->salute_key   = (string) get_config('local_unics', 'salute_speech_api_key');

        // Модель по умолчанию для каждого провайдера
        $default_models = [
            self::PROVIDER_GROQ     => 'llama-3.1-8b-instant',
            self::PROVIDER_GIGACHAT => 'GigaChat',
            self::PROVIDER_DEEPSEEK => 'deepseek-chat',
        ];
        $configured = get_config('local_unics', 'ai_model');
        $this->model = $configured ?: ($default_models[$this->provider] ?? 'llama-3.1-8b-instant');
    }

    public function get_audio_ext(): string {
        return ($this->tts_provider === self::TTS_SALUTE_SPEECH) ? 'wav' : 'mp3';
    }

    // ----------------------------------------------------------------
    // Адаптивный алгоритм: корректирует уровень по среднему баллу
    // < 50% → понижение, > 85% → повышение (в пределах 1–3)
    // ----------------------------------------------------------------
    public function adapt_level(int $base_level, float $avg_score): int {
        if ($avg_score < 50 && $base_level > 1) {
            return $base_level - 1;
        }
        if ($avg_score > 85 && $base_level < 3) {
            return $base_level + 1;
        }
        return $base_level;
    }

    // ----------------------------------------------------------------
    // Формирование промпта на основе полного профиля учащегося
    // ----------------------------------------------------------------
    public function build_prompt(array $profile, string $topic, string $extra_context = ''): string {
        $categories = [1 => 'ОВЗ', 2 => 'семейное обучение', 3 => 'длительное лечение', 4 => 'одарённый'];
        $levels     = [1 => 'базовый', 2 => 'стандартный', 3 => 'продвинутый'];

        $ovz_labels = [
            1 => 'слабовидящий',
            2 => 'слабослышащий',
            3 => 'нарушение двигательного аппарата (НОДА)',
            4 => 'задержка психического развития (ЗПР)',
            5 => 'расстройство аутистического спектра (РАС)',
            6 => 'иное нарушение здоровья',
        ];
        $ovz_instructions = [
            1 => 'Избегай описаний, требующих точного зрения. Текст должен хорошо восприниматься на слух.',
            2 => 'Делай акцент на тексте. Не используй звуковые описания как ключевой элемент объяснения.',
            3 => 'Чёткие пошаговые инструкции. Не требуй от учащегося быстрых действий при выполнении.',
            4 => 'Очень короткие абзацы (2–3 предложения). Повторяй ключевые понятия несколько раз. Пошаговая структура обязательна.',
            5 => 'Строго предсказуемая структура текста. Только однозначные формулировки — без метафор и иносказаний.',
            6 => 'Доступный язык, короткие предложения, минимум специальных терминов без пояснений.',
        ];

        $avg_score     = (float)($profile['avg_score'] ?? 70);
        $base_level    = (int)($profile['difficulty_level'] ?? 2);
        $eff_level     = $this->adapt_level($base_level, $avg_score);
        $category_id   = (int)($profile['category'] ?? 2);
        $ovz_type      = (int)($profile['ovz_type'] ?? 0);
        $class_num     = (int)($profile['class_number'] ?? 5);
        $class_letter  = trim((string)($profile['class_letter'] ?? ''));
        $special_needs = trim((string)($profile['special_needs'] ?? ''));

        $category_label = $categories[$category_id] ?? 'стандартный';
        $level_label    = $levels[$eff_level] ?? 'стандартный';
        $class_str      = $class_num . ($class_letter !== '' ? " «{$class_letter}»" : '') . ' класс';

        // Объём в зависимости от уровня и категории
        $word_count = match ($eff_level) {
            1 => '300–400',
            3 => '600–800',
            default => '400–600',
        };
        if ($category_id === 3) {
            $word_count = '250–350'; // длительное лечение — короткие модули
        }

        // Блок особых указаний
        $special_parts = [];

        if ($category_id === 1) {
            if ($ovz_type > 0 && isset($ovz_labels[$ovz_type])) {
                $special_parts[] = "Тип ОВЗ учащегося: {$ovz_labels[$ovz_type]}.";
                $special_parts[] = $ovz_instructions[$ovz_type];
            } else {
                $special_parts[] = 'Учащийся имеет ОВЗ. Используй простые короткие предложения, избегай перегруженных абзацев.';
            }
        } elseif ($category_id === 3) {
            $special_parts[] = 'Учащийся на длительном лечении. Модуль должен читаться за 10–15 минут. Завершай текст коротким мотивирующим выводом.';
        } elseif ($category_id === 4) {
            $special_parts[] = 'Учащийся одарённый. Добавь углублённые факты, нестандартный угол зрения на тему и исследовательский вопрос в конце.';
        }

        if ($special_needs !== '') {
            $special_parts[] = "Дополнительные особенности учащегося: {$special_needs}";
        }

        if ($eff_level < $base_level) {
            $special_parts[] = "Уровень автоматически снижен (средний балл {$avg_score}% < 50%) — материал должен быть проще базового.";
        } elseif ($eff_level > $base_level) {
            $special_parts[] = "Уровень автоматически повышен (средний балл {$avg_score}% > 85%) — материал должен быть сложнее стандартного.";
        }

        $special_block = '';
        if (!empty($special_parts)) {
            $special_block = "\nОсобые указания:\n- " . implode("\n- ", $special_parts) . "\n";
        }

        $extra_block = '';
        if (trim($extra_context) !== '') {
            $extra_block = "\nДополнительные указания от педагога:\n" . trim($extra_context) . "\n";
        }

        return "Ты — опытный педагог, создающий учебные материалы для российских школьников.

Задача: напиши учебный текст по теме «{$topic}» для ученика {$class_str}.

Профиль учащегося:
- Категория: {$category_label}
- Уровень подготовки: {$level_label}
- Средний балл за последние 5 тестов: {$avg_score}%
{$special_block}{$extra_block}
Требования:
- Объём: {$word_count} слов
- Язык: русский, доступный для возраста учащегося
- Структура: краткое введение → 3–4 смысловых абзаца → вывод
- Сложность строго соответствует уровню «{$level_label}»
- Включи 2–3 примера из реальной жизни или природы
- Используй markdown: #### для заголовков разделов, **жирный** для ключевых понятий, - для коротких списков
- Для формул и математических выражений используй нотацию \(...\) (например: \(x^2 + y^2\)), НЕ используй знак доллара \$";
    }

    // ----------------------------------------------------------------
    // Генерация текста — маршрутизация по провайдеру
    // ----------------------------------------------------------------
    public function generate_text(string $prompt): string {
        if (empty($this->api_key)) {
            throw new \moodle_exception('API key не настроен: Настройки сайта → УНИКС → API-ключ ИИ');
        }

        switch ($this->provider) {
            case self::PROVIDER_GIGACHAT:
                return $this->generate_text_gigachat($prompt);
            case self::PROVIDER_DEEPSEEK:
                return $this->generate_text_openai_compat(
                    $prompt, 'https://api.deepseek.com/chat/completions'
                );
            case self::PROVIDER_GROQ:
            default:
                return $this->generate_text_openai_compat(
                    $prompt, 'https://api.groq.com/openai/v1/chat/completions'
                );
        }
    }

    // ----------------------------------------------------------------
    // Groq / DeepSeek / любой OpenAI-совместимый провайдер
    // ----------------------------------------------------------------
    private function generate_text_openai_compat(string $prompt, string $url): string {
        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => 1024,
            'temperature' => 0.7,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key,
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('ИИ cURL ошибка: ' . $curl_err);
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('ИИ HTTP ' . $http_code . ': ' . mb_substr($response, 0, 300));
        }

        $text = json_decode($response, true)['choices'][0]['message']['content'] ?? '';
        if (mb_strlen(trim($text)) < 50) {
            throw new \moodle_exception('ИИ вернул пустой или слишком короткий ответ');
        }

        return $text;
    }

    // ----------------------------------------------------------------
    // GigaChat (Sber) — OAuth 2.0 client_credentials
    // api_key здесь = Authorization key из личного кабинета (Base64)
    // ----------------------------------------------------------------
    private function generate_text_gigachat(string $prompt): string {
        // Шаг 1: получить access_token
        $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'scope=GIGACHAT_API_PERS',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Authorization: Basic ' . $this->api_key,
                'RqUID: ' . sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false, // Сбербанк использует собственный CA
        ]);

        $auth_resp = curl_exec($ch);
        $auth_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('GigaChat auth cURL ошибка: ' . $curl_err);
        }
        if ($auth_code !== 200) {
            throw new \moodle_exception('GigaChat auth HTTP ' . $auth_code . ': ' . $auth_resp);
        }

        $token = json_decode($auth_resp, true)['access_token'] ?? '';
        if (empty($token)) {
            throw new \moodle_exception('GigaChat: не удалось получить access_token');
        }

        // Шаг 2: запрос к API
        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => 1024,
            'temperature' => 0.7,
        ]);

        $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('GigaChat cURL ошибка: ' . $curl_err);
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('GigaChat HTTP ' . $http_code . ': ' . mb_substr($response, 0, 300));
        }

        $text = json_decode($response, true)['choices'][0]['message']['content'] ?? '';
        if (mb_strlen(trim($text)) < 50) {
            throw new \moodle_exception('GigaChat вернул пустой ответ');
        }

        return $text;
    }

    // ----------------------------------------------------------------
    // Генерация аудио — маршрутизация по TTS-провайдеру
    // Возвращает бинарные аудиоданные (MP3 или WAV)
    // ----------------------------------------------------------------
    public function generate_audio(string $text): string {
        $text = $this->strip_for_tts($text);
        if ($this->tts_provider === self::TTS_SALUTE_SPEECH) {
            return $this->generate_audio_salute($text);
        }
        return $this->generate_audio_voicerss($text);
    }

    // ----------------------------------------------------------------
    // Очистка текста перед передачей в TTS:
    // убирает markdown-разметку и LaTeX-формулы
    // ----------------------------------------------------------------
    private function strip_for_tts(string $text): string {
        // display math \[...\] и $$...$$ → "формула"
        $text = preg_replace('/\\\\\[.*?\\\\\]/su', 'формула', $text);
        $text = preg_replace('/\$\$.*?\$\$/su', 'формула', $text);

        // inline math \(...\) → содержимое без тегов
        $text = preg_replace('/\\\\\((.+?)\\\\\)/su', '$1', $text);

        // оставшиеся знаки $ (LaTeX $...$) → "формула"
        $text = preg_replace('/\$[^$\n]{1,200}\$/su', 'формула', $text);

        // markdown-заголовки (#### Заголовок → Заголовок)
        $text = preg_replace('/^#{1,6}\h+/mu', '', $text);

        // жирный и курсив (**text**, *text*, __text__, _text_)
        $text = preg_replace('/\*{2,3}(.+?)\*{2,3}/su', '$1', $text);
        $text = preg_replace('/_{2}(.+?)_{2}/su', '$1', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/su', '$1', $text);
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/su', '$1', $text);

        // маркированные списки (- пункт → пункт)
        $text = preg_replace('/^[-*+]\h+/mu', '', $text);

        // лишние пробелы и пустые строки
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    // ----------------------------------------------------------------
    // VoiceRSS TTS — возвращает MP3
    // ----------------------------------------------------------------
    private function generate_audio_voicerss(string $text): string {
        if (empty($this->voicerss_key)) {
            throw new \moodle_exception('VoiceRSS API key не настроен в настройках плагина');
        }

        $params = http_build_query([
            'key' => $this->voicerss_key,
            'src' => mb_substr($text, 0, 2999),
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
    // SaluteSpeech (Sber) TTS — возвращает WAV
    // Использует тот же OAuth-endpoint, что и GigaChat,
    // но со scope=SALUTE_SPEECH_PERS
    // ----------------------------------------------------------------
    private function generate_audio_salute(string $text): string {
        if (empty($this->salute_key)) {
            throw new \moodle_exception('SaluteSpeech API key не настроен в настройках плагина');
        }

        // Шаг 1: OAuth-токен
        $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'scope=SALUTE_SPEECH_PERS',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Authorization: Basic ' . $this->salute_key,
                'RqUID: ' . sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $auth_resp = curl_exec($ch);
        $auth_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('SaluteSpeech auth cURL ошибка: ' . $curl_err);
        }
        if ($auth_code !== 200) {
            throw new \moodle_exception('SaluteSpeech auth HTTP ' . $auth_code . ': ' . $auth_resp);
        }
        $token = json_decode($auth_resp, true)['access_token'] ?? '';
        if (empty($token)) {
            throw new \moodle_exception('SaluteSpeech: не удалось получить access_token');
        }

        // Шаг 2: синтез речи
        $voice = get_config('local_unics', 'salute_voice') ?: 'Nec_24000';
        $text  = mb_substr($text, 0, 1999); // лимит REST API

        $ch = curl_init(
            'https://smartspeech.sber.ru/rest/v1/text:synthesize?format=wav16&voice=' . urlencode($voice)
        );
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $text,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/text',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $audio     = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('SaluteSpeech cURL ошибка: ' . $curl_err);
        }
        if ($http_code !== 200) {
            $err = json_decode((string)$audio, true);
            throw new \moodle_exception(
                'SaluteSpeech HTTP ' . $http_code . ': ' . ($err['message'] ?? mb_substr((string)$audio, 0, 200))
            );
        }
        if (strlen($audio) < 1000) {
            throw new \moodle_exception('SaluteSpeech вернул некорректные аудиоданные');
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
