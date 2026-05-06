<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class ai_generator {

    const PROVIDER_GIGACHAT  = 'gigachat';
    const TTS_SALUTE_SPEECH = 'salute_speech';

    private string $provider;
    private string $api_key;
    private string $model;
    private string $tts_provider;
    private string $salute_key;

    public function __construct() {
        $this->provider     = get_config('local_unics', 'ai_provider') ?: self::PROVIDER_GIGACHAT;
        $this->api_key      = (string) get_config('local_unics', 'ai_api_key');
        $this->tts_provider = get_config('local_unics', 'tts_provider') ?: self::TTS_SALUTE_SPEECH;
        $this->salute_key   = (string) get_config('local_unics', 'salute_speech_api_key');

        $configured  = get_config('local_unics', 'ai_model');
        $this->model = $configured ?: 'GigaChat';
    }

    public function get_audio_ext(): string {
        return 'wav';
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
    // Генерация текста
    // ----------------------------------------------------------------
    public function generate_text(string $prompt, int $max_tokens = 1024): string {
        if (empty($this->api_key)) {
            throw new \moodle_exception('API key не настроен: Настройки сайта → УНИКС → API-ключ ИИ');
        }
        return $this->generate_text_gigachat($prompt, $max_tokens);
    }

    // ----------------------------------------------------------------
    // GigaChat OAuth 2.0 — получить Bearer-токен
    // ----------------------------------------------------------------
    private function get_gigachat_token(): string {
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
            CURLOPT_SSL_VERIFYPEER => false,
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

        return $token;
    }

    // ----------------------------------------------------------------
    // GigaChat (Sber) — OAuth 2.0 client_credentials
    // api_key здесь = Authorization key из личного кабинета (Base64)
    // ----------------------------------------------------------------
    private function generate_text_gigachat(string $prompt, int $max_tokens = 1024): string {
        // Шаг 1: получить access_token
        $token = $this->get_gigachat_token();

        // Шаг 2: запрос к API
        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => $max_tokens,
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
    // Генерация аудио — SaluteSpeech Sber, возвращает WAV
    // ----------------------------------------------------------------
    public function generate_audio(string $text): string {
        $text = $this->strip_for_tts($text);
        return $this->generate_audio_salute($text);
    }

    // ----------------------------------------------------------------
    // Очистка текста перед передачей в TTS:
    // убирает markdown-разметку и LaTeX-формулы
    // ----------------------------------------------------------------
    public function strip_for_tts(string $text): string {
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
    // Генерация вопросов для теста
    // Возвращает массив: [['text'=>..., 'answers'=>[...], 'correct'=>0], ...]
    // ----------------------------------------------------------------
    public function generate_quiz(array $profile, string $topic, string $source_text = '', int $num = 5): array {
        $levels    = [1 => 'базовый', 2 => 'стандартный', 3 => 'продвинутый'];
        $class_num = $profile['class_number'] ?? 5;
        $level     = $levels[$profile['difficulty_level'] ?? 2] ?? 'стандартный';

        $src = $source_text !== ''
            ? "\n\nОпирайся на следующий учебный текст:\n---\n" . mb_substr($source_text, 0, 2000) . "\n---"
            : '';

        $prompt = "Ты — педагог, составляющий тестовые задания для российских школьников.

Составь ровно {$num} вопросов с множественным выбором по теме «{$topic}» для ученика {$class_num} класса (уровень: {$level}).{$src}

Требования:
- 4 варианта ответа для каждого вопроса
- Ровно один правильный ответ
- Вопросы проверяют понимание, а не механическое запоминание
- Язык соответствует возрасту и уровню «{$level}»
- ЗАПРЕЩЕНО использовать LaTeX-формулы, символы $ и обратную косую черту \\. Все формулы и уравнения записывай ТОЛЬКО обычным текстом: например «y = kx + b», «x в квадрате», «дробь k/x».

Верни ответ СТРОГО в формате JSON, без пояснений и без markdown-тегов:
{\"questions\":[{\"text\":\"Текст вопроса?\",\"answers\":[\"Вариант А\",\"Вариант Б\",\"Вариант В\",\"Вариант Г\"],\"correct\":0}]}
correct — индекс правильного ответа (0, 1, 2 или 3).";

        $raw = $this->generate_text($prompt, 4096);

        // Нормализуем невалидные escape-последовательности перед парсингом
        $fix_escapes = static function (string $s): string {
            return preg_replace_callback('/\\\\(.)/u', static function (array $m): string {
                if (in_array($m[1], ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true)) {
                    return $m[0];
                }
                return '\\\\' . $m[1];
            }, $s) ?? $s;
        };

        // Извлекаем JSON — GigaChat иногда добавляет пояснения вокруг
        $json_str = '';
        if (preg_match('/\{.*\}/su', $raw, $m)) {
            $json_str = $m[0];
        } else {
            $json_str = $raw;
        }

        $data = json_decode($json_str, true) ?? json_decode($fix_escapes($json_str), true);

        // Восстановление частичного JSON: если массив обрезан — закрываем его вручную
        if (!isset($data['questions']) && $json_str !== '') {
            $recovered = $json_str;
            // Считаем непарные { и [, закрываем их
            $open_brace   = substr_count($recovered, '{') - substr_count($recovered, '}');
            $open_bracket = substr_count($recovered, '[') - substr_count($recovered, ']');
            $recovered .= str_repeat('}', max(0, $open_brace));
            $recovered .= str_repeat(']', max(0, $open_bracket));
            // Закрываем ещё раз внешний объект если нужно
            if (substr_count($recovered, '{') > substr_count($recovered, '}')) {
                $recovered .= '}';
            }
            $data = json_decode($recovered, true) ?? json_decode($fix_escapes($recovered), true);
        }

        // Последний резерв — поиск отдельных question-объектов в сыром тексте
        $result = [];
        if (isset($data['questions']) && is_array($data['questions'])) {
            foreach ($data['questions'] as $q) {
                if (empty($q['text']) || empty($q['answers']) || !is_array($q['answers'])) {
                    continue;
                }
                $correct = max(0, min((int)($q['correct'] ?? 0), count($q['answers']) - 1));
                $result[] = [
                    'text'    => trim($q['text']),
                    'answers' => array_values($q['answers']),
                    'correct' => $correct,
                ];
            }
        }

        if (empty($result)) {
            throw new \moodle_exception('ИИ вернул некорректный формат теста: ' . mb_substr($raw, 0, 300));
        }

        return $result;
    }

    // ----------------------------------------------------------------
    // Генерация текста задания (mod_assign)
    // ----------------------------------------------------------------
    public function generate_assignment_description(array $profile, string $topic, string $source_text = ''): string {
        $levels    = [1 => 'базовый', 2 => 'стандартный', 3 => 'продвинутый'];
        $class_num = $profile['class_number'] ?? 5;
        $level     = $levels[$profile['difficulty_level'] ?? 2] ?? 'стандартный';

        $src = $source_text !== ''
            ? "\n\nУчебный текст по теме:\n---\n" . mb_substr($source_text, 0, 1500) . "\n---"
            : '';

        $prompt = "Ты — педагог, составляющий практические задания для российских школьников.

Составь одно письменное практическое задание по теме «{$topic}» для ученика {$class_num} класса (уровень: {$level}).{$src}

Задание должно:
- Опираться на изученный материал
- Требовать развёрнутого ответа (3–7 предложений)
- Соответствовать уровню «{$level}»
- Быть конкретным и однозначно сформулированным

Верни только текст задания. Без заголовков, без вводных слов — только само задание.";

        return $this->generate_text($prompt);
    }

    // ----------------------------------------------------------------
    // Генерация сценария видеопрезентации (5 слайдов)
    // Возвращает массив: [['title'=>..., 'content'=>..., 'key_points'=>[...]], ...]
    // ----------------------------------------------------------------
    public function generate_video_script(array $profile, string $topic, string $source_text = ''): array {
        $levels    = [1 => 'базовый', 2 => 'стандартный', 3 => 'продвинутый'];
        $class_num = $profile['class_number'] ?? 5;
        $level     = $levels[$profile['difficulty_level'] ?? 2] ?? 'стандартный';

        $src = $source_text !== ''
            ? "\n\nОпирайся на следующий учебный текст:\n---\n" . mb_substr($source_text, 0, 2000) . "\n---"
            : '';

        $prompt = "Составь сценарий видеоурока по теме «{$topic}» для ученика {$class_num} класса (уровень: {$level}).{$src}

Верни РОВНО 5 слайдов в формате JSON без пояснений и без markdown-обёртки:
{\"slides\":[{\"title\":\"...\",\"content\":\"...\",\"key_points\":[\"...\",\"...\"]}]}

Правила:
- title: заголовок слайда до 60 символов
- content: 3-4 предложения, доступный язык для {$class_num} класса, уровень «{$level}»
- key_points: ровно 2-3 ключевых понятия или факта (без формул, только текст)
- НЕ используй символы LaTeX, доллар \$ и обратную косую черту \\

Логика слайдов:
1. Введение — что такое тема и зачем её изучать
2. Основное понятие 1
3. Основное понятие 2
4. Применение или пример из жизни
5. Итог — главный вывод и вопрос для размышления";

        $raw = $this->generate_text($prompt, 3000);

        // Извлекаем JSON
        $json_str = '';
        if (preg_match('/\{.*\}/su', $raw, $m)) {
            $json_str = $m[0];
        } else {
            $json_str = $raw;
        }

        $data = json_decode($json_str, true);

        if ($data === null) {
            $fixed = preg_replace_callback('/\\\\(.)/u', static function (array $m): string {
                if (in_array($m[1], ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true)) {
                    return $m[0];
                }
                return '\\\\' . $m[1];
            }, $json_str);
            $data = json_decode($fixed, true);
        }

        if (!isset($data['slides']) || !is_array($data['slides'])) {
            throw new \moodle_exception('ИИ вернул некорректный формат видеосценария: ' . mb_substr($raw, 0, 300));
        }

        $result = [];
        foreach ($data['slides'] as $s) {
            if (empty($s['title']) || empty($s['content'])) {
                continue;
            }
            $result[] = [
                'title'      => trim((string)$s['title']),
                'content'    => trim((string)$s['content']),
                'key_points' => array_values((array)($s['key_points'] ?? [])),
            ];
        }

        if (empty($result)) {
            throw new \moodle_exception('ИИ не вернул ни одного слайда');
        }

        return $result;
    }

    // ----------------------------------------------------------------
    // Генерация изображения через GigaChat text2image
    // Возвращает бинарные данные JPEG или пустую строку при ошибке
    // ----------------------------------------------------------------
    public function generate_image(string $prompt): string {
        if (empty($this->api_key)) {
            throw new \moodle_exception('API key не настроен: Настройки сайта → УНИКС → API-ключ ИИ');
        }

        $token = $this->get_gigachat_token();

        $payload = json_encode([
            'model'         => $this->model,
            'messages'      => [['role' => 'user', 'content' => $prompt]],
            'function_call' => 'auto',
            'functions'     => [[
                'name'        => 'text2image',
                'description' => 'Generates an image from a text description',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Image generation prompt'],
                    ],
                    'required' => ['query'],
                ],
            ]],
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
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('GigaChat image cURL ошибка: ' . $curl_err);
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('GigaChat image HTTP ' . $http_code . ': ' . mb_substr($response, 0, 200));
        }

        $data    = json_decode($response, true);
        $content = (string)($data['choices'][0]['message']['content'] ?? '');

        $uuid = '';
        // Формат 1: <img src="UUID"/> или <img fuse="true" src="UUID"/>
        if (preg_match('/<img[^>]+src=["\']?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})["\']?/i', $content, $m)) {
            $uuid = $m[1];
        }
        // Формат 2: поле attachments (строка или объект)
        if (empty($uuid)) {
            $attachments = $data['choices'][0]['message']['attachments'] ?? [];
            if (is_array($attachments) && !empty($attachments)) {
                $first = $attachments[0];
                $uuid  = is_array($first) ? (string)($first['id'] ?? reset($first)) : (string)$first;
            }
        }
        // Формат 3: любой UUID в content (последний резерв)
        if (empty($uuid) && $content !== '') {
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $content, $m)) {
                $uuid = $m[1];
            }
        }

        if (empty($uuid)) {
            throw new \moodle_exception('GigaChat image: UUID изображения не найден в ответе');
        }

        // Скачиваем содержимое файла
        $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/files/' . $uuid . '/content');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/jpg',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $img_data  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            throw new \moodle_exception('GigaChat image download cURL ошибка: ' . $curl_err);
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('GigaChat image download HTTP ' . $http_code);
        }

        return (string) $img_data;
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
