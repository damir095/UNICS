<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

class ai_generator {

    const PROVIDER_GIGACHAT  = 'gigachat';
    const TTS_SALUTE_SPEECH = 'salute_speech';

    private string $provider;
    private string $api_key;
    private string $model;
    private string $image_model;
    /**
     * finish_reason последнего ответа GigaChat. Поле, а не возврат метода: сигнатуру
     * generate_text_gigachat() менять нельзя - это объявленный шов для подмены сети, и
     * его переопределяют adaptation_block_test и output_style_test. Подменившие шов
     * тесты поле не трогают, у них остается пустая строка.
     */
    private string $last_finish_reason = '';
    /**
     * Кеш OAuth-токена GigaChat: сам токен и время истечения (секунды Unix).
     *
     * Токен живет 30 минут, а запрашивался на КАЖДЫЙ вызов ИИ - комплект с девятью
     * картинками делал около 11 авторизаций вместо одной. Кеш уровня экземпляра
     * достаточен: umk_processor держит один ai_generator на весь прогон
     * ([[ai-image-reliability-design]], раздел 2.3).
     */
    private string $token_cache = '';
    private int $token_expires_at = 0;
    private string $tts_provider;
    private string $salute_key;

    public function __construct() {
        $this->provider     = get_config('local_unics', 'ai_provider') ?: self::PROVIDER_GIGACHAT;
        $this->api_key      = (string) get_config('local_unics', 'ai_api_key');
        $this->tts_provider = get_config('local_unics', 'tts_provider') ?: self::TTS_SALUTE_SPEECH;
        $this->salute_key   = (string) get_config('local_unics', 'salute_speech_api_key');

        $configured  = get_config('local_unics', 'ai_model');
        $this->model = $configured ?: 'GigaChat';

        // Картинки требуют модели со встроенной функцией text2image. GigaChat без цифры
        // на такой запрос не отвечает вовсе (HTTP 0, замерено 2026-08-09), поэтому модель
        // картинок отвязана от текстовой: текстовая генерация работает и трогать ее незачем.
        $this->image_model = get_config('local_unics', 'ai_image_model') ?: 'GigaChat-2';
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
    // Критерии генерации по профилю учащегося (без вызова ИИ).
    // Единственный источник правды: превью (A3) и build_prompt.
    // ----------------------------------------------------------------
    public function build_criteria(array $profile): array {
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
            5 => 'Строго предсказуемая структура текста. Только однозначные формулировки - без метафор и иносказаний.',
            6 => 'Доступный язык, короткие предложения, минимум специальных терминов без пояснений.',
        ];

        $avg_score     = (float)($profile['avg_score'] ?? 70);
        $base_level    = (int)($profile['difficulty_level'] ?? 2);
        $eff_level     = $this->adapt_level($base_level, $avg_score);
        $class_num     = (int)($profile['class_number'] ?? 5);
        $class_letter  = trim((string)($profile['class_letter'] ?? ''));
        $special_needs = trim((string)($profile['special_needs'] ?? ''));

        // Полоса балла. Точное число печатать нельзя: оно уходит в промт и в отпечаток
        // профиля, и совпадение до процента развалило бы схлопывание одинаковых профилей
        // ([[umk-per-student-design]], раздел 5). Границы - те же, что у adapt_level().
        $avg_band = $avg_score < 50 ? 'менее 50%' : ($avg_score > 85 ? 'более 85%' : '50-85%');

        // Множественные категории / виды ОВЗ. Fallback на одиночные поля для бэк-компата.
        $category_ids = $profile['categories'] ?? null;
        if (!is_array($category_ids) || empty($category_ids)) {
            $category_ids = [(int)($profile['category'] ?? 2)];
        }
        $ovz_type_ids = $profile['ovz_types'] ?? null;
        if (!is_array($ovz_type_ids) || empty($ovz_type_ids)) {
            $ovz_legacy   = (int)($profile['ovz_type'] ?? 0);
            $ovz_type_ids = $ovz_legacy > 0 ? [$ovz_legacy] : [];
        }

        $category_labels_arr = [];
        foreach ($category_ids as $cid) {
            if (isset($categories[$cid])) {
                $category_labels_arr[] = $categories[$cid];
            }
        }
        $category_label = $category_labels_arr ? implode('; ', $category_labels_arr) : 'стандартный';
        $level_label    = $levels[$eff_level] ?? 'стандартный';
        $class_str      = $class_num . ($class_letter !== '' ? " «{$class_letter}»" : '') . ' класс';

        // Объём - берём минимум среди всех применимых правил (наиболее ограничивающее).
        $word_count = match ($eff_level) {
            1 => '300–400',
            3 => '600–800',
            default => '400–600',
        };
        if (in_array(3, $category_ids, true)) {
            $word_count = '250–350'; // длительное лечение - короткие модули
        }

        // Валидные типы ОВЗ (с известными метками): в критериях - всегда,
        // в особые указания попадают только при категории 1 (как раньше).
        $valid_ovz_types = array_values(array_filter($ovz_type_ids, fn($t) => isset($ovz_labels[$t])));
        $type_labels     = array_map(fn($t) => $ovz_labels[$t], $valid_ovz_types);

        // Блок особых указаний - union по всем категориям и видам ОВЗ.
        $special_parts = [];

        if (in_array(1, $category_ids, true)) {
            if (!empty($valid_ovz_types)) {
                $special_parts[] = 'Типы ОВЗ учащегося: ' . implode('; ', $type_labels) . '.';
                foreach ($valid_ovz_types as $t) {
                    $special_parts[] = $ovz_instructions[$t];
                }
            } else {
                $special_parts[] = 'Учащийся имеет ОВЗ. Используй простые короткие предложения, избегай перегруженных абзацев.';
            }
        }
        if (in_array(3, $category_ids, true)) {
            $special_parts[] = 'Учащийся на длительном лечении. Модуль должен читаться за 10–15 минут. Завершай текст коротким мотивирующим выводом.';
        }
        if (in_array(4, $category_ids, true)) {
            $special_parts[] = 'Учащийся одарённый. Добавь углублённые факты, нестандартный угол зрения на тему и исследовательский вопрос в конце.';
        }
        if (in_array(2, $category_ids, true)) {
            $special_parts[] = 'Учащийся на семейном обучении. Допускай гибкий темп изучения и явные точки самопроверки.';
        }

        if ($special_needs !== '') {
            $special_parts[] = "Дополнительные особенности учащегося: {$special_needs}";
        }

        $level_change_reason = null;
        if ($eff_level < $base_level) {
            $level_change_reason = "Уровень автоматически снижен (средний балл {$avg_band}) - материал должен быть проще базового.";
        } elseif ($eff_level > $base_level) {
            $level_change_reason = "Уровень автоматически повышен (средний балл {$avg_band}) - материал должен быть сложнее стандартного.";
        }
        if ($level_change_reason !== null) {
            $special_parts[] = $level_change_reason;
        }

        return [
            'base_level'          => $base_level,
            'eff_level'           => $eff_level,
            'level_label'         => $level_label,
            'level_change_reason' => $level_change_reason,
            'avg_score'           => $avg_score,
            'avg_band'            => $avg_band,
            'class_str'           => $class_str,
            'category_ids'        => array_values(array_map('intval', $category_ids)),
            'category_label'      => $category_label,
            'ovz_type_ids'        => $valid_ovz_types,
            'ovz_labels'          => $type_labels,
            'word_count'          => $word_count,
            'special_parts'       => $special_parts,
        ];
    }

    // ----------------------------------------------------------------
    // Формирование промпта на основе полного профиля учащегося.
    // Строка ЗАМОРОЖЕНА (golden-тест) - вычисления в build_criteria.
    // ----------------------------------------------------------------
    /**
     * Блок промта про самого ребенка: профиль плюс особые указания
     * ([[adaptation-full-kit-design]]).
     *
     * Вынесен из build_prompt(), чтобы тест, задание и видеосценарий адаптировались по тому же
     * профилю, что и учебный текст. До этого они читали из профиля два поля - класс и сырой
     * difficulty_level, - и ребенок с ЗПР получал адаптированный текст и неадаптированную
     * проверку знаний по нему же.
     *
     * @param array $criteria результат build_criteria()
     */
    public function adaptation_block(array $criteria): string {
        $special = '';
        if (!empty($criteria['special_parts'])) {
            $special = "\nОсобые указания:\n- " . implode("\n- ", $criteria['special_parts']) . "\n";
        }

        return "Профиль учащегося:\n"
             . "- Категория: {$criteria['category_label']}\n"
             . "- Уровень подготовки: {$criteria['level_label']}\n"
             . "- Средний балл за последние 5 тестов: {$criteria['avg_band']}\n"
             . $special;
    }

    /**
     * Свободные указания педагога ([[teacher-extra-prompt-design]]).
     *
     * Вынесено из build_prompt(), чтобы тест, задание и видеосценарий тоже их получали: поле
     * задумано под предметный контекст («предмет - биология, избегать латинских терминов без
     * пояснений»), и к вопросам теста это относится не меньше, чем к учебному тексту.
     */
    public function extra_block(string $extra_context): string {
        if (trim($extra_context) === '') {
            return '';
        }
        return "\nДополнительные указания от педагога:\n" . trim($extra_context) . "\n";
    }

    public function build_prompt(array $profile, string $topic, string $extra_context = ''): string {
        $c           = $this->build_criteria($profile);
        $block       = $this->adaptation_block($c);
        $extra_block = $this->extra_block($extra_context);

        return "Ты - опытный педагог, создающий учебные материалы для российских школьников.

Задача: напиши учебный текст по теме «{$topic}» для ученика {$c['class_str']}.

{$block}{$extra_block}
Требования:
- Объём: {$c['word_count']} слов
- Язык: русский, доступный для возраста учащегося
- Структура: краткое введение → 3–4 смысловых абзаца → вывод
- Сложность строго соответствует уровню «{$c['level_label']}»
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
        // Отказ модели - это болванка вместо материала, и приходит он с HTTP 200 и непустым
        // текстом, то есть штатными проверками не ловится. 2026-08-09 такая болванка легла в
        // курс страницей урока, а УМК получил статус «готов» ([[ai-refusal-detector-design]]).
        //
        // Один повтор, а не сразу ошибка: тот отказ оказался транзиентным - на следующий день
        // тот же промт дал 3180 символов нормального текста. Второго повтора нет: транзиентный
        // сбой лечится первым, а устойчивая блокировка не вылечится и десятым.
        //
        // Проверка идет по СЫРОМУ ответу, до output_style::clean(): чистка меняет тире и режет
        // эмодзи, а проверять осмысленно то, что пришло от модели.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->last_finish_reason = '';
            $raw = $this->generate_text_gigachat($prompt, $max_tokens);

            if (refusal_detector::is_refusal($raw, $this->last_finish_reason)) {
                // След обязателен: без него удачный повтор неотличим от чистого прогона, и
                // мы не узнаем ни частоту отказов, ни какой сигнал сработал. Эта задача и
                // возникла из-за сбоя, который молчал.
                debugging('local_unics: отказ ИИ, попытка ' . $attempt . ' из 2, finish_reason: ['
                    . $this->last_finish_reason . '], ответ: '
                    . mb_substr(trim($raw), 0, 120), DEBUG_NORMAL);
            } else {
                // Единственное горлышко всех шести выходов ИИ ([[ai-output-style-design]]): чистка
                // стоит здесь, чтобы ни один вызывающий не мог о ней забыть. Для JSON-выходов
                // (тест, слайды) это безопасно: вырезание эмодзи и замена тире не меняют ни
                // скобок, ни кавычек, ни экранирования, поэтому восстановление обрезанного JSON
                // в generate_quiz не страдает.
                return output_style::clean($raw);
            }
        }

        // generalexceptionmessage, а не голая строка: moodle_exception трактует первый аргумент
        // как идентификатор языковой строки, и педагог видел бы «error/ИИ отказался...».
        // Это сообщение единственное написано для педагога, а не для лога.
        throw new \moodle_exception('generalexceptionmessage', 'error', '',
            'ИИ отказался отвечать по этой теме (сработал фильтр модели). '
            . 'Попробуйте переформулировать тему материала.');
    }

    // ----------------------------------------------------------------
    // GigaChat OAuth 2.0 - получить Bearer-токен
    // ----------------------------------------------------------------
    /**
     * Сырой OAuth. protected - шов для подмены сети в тестах.
     *
     * @return array{token: string, expires_at: int} expires_at в СЕКУНДАХ Unix
     */
    protected function fetch_gigachat_token(): array {
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

        $decoded = json_decode($auth_resp, true);
        $token   = $decoded['access_token'] ?? '';
        if (empty($token)) {
            throw new \moodle_exception('GigaChat: не удалось получить access_token');
        }

        // Сбер отдает expires_at в МИЛЛИСЕКУНДАХ (замерено: 1786308715480). Без перевода
        // в секунды сравнение с time() считало бы токен вечным, и после реального
        // истечения все посыпалось бы с 401.
        $expires_ms = (int)($decoded['expires_at'] ?? 0);

        return ['token' => $token, 'expires_at' => (int)($expires_ms / 1000)];
    }

    /**
     * Токен с кешем. Запас в 60 секунд обязателен: без него токен мог бы истечь между
     * проверкой и самим запросом к ИИ, и вызов упал бы с 401 на ровном месте.
     */
    protected function get_gigachat_token(): string {
        if ($this->token_cache !== '' && $this->token_expires_at - time() > 60) {
            return $this->token_cache;
        }

        $fresh = $this->fetch_gigachat_token();
        $this->token_cache      = $fresh['token'];
        $this->token_expires_at = $fresh['expires_at'];

        return $this->token_cache;
    }

    // ----------------------------------------------------------------
    // GigaChat (Sber) - OAuth 2.0 client_credentials
    // api_key здесь = Authorization key из личного кабинета (Base64)
    // ----------------------------------------------------------------
    // protected, а не private: это шов для подмены сети в тестах. Публичный generate_text()
    // остается единственной точкой входа, поведение снаружи не меняется.
    protected function generate_text_gigachat(string $prompt, int $max_tokens = 1024): string {
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

        $decoded = json_decode($response, true);
        $this->last_finish_reason = (string)($decoded['choices'][0]['finish_reason'] ?? '');
        $text = $decoded['choices'][0]['message']['content'] ?? '';
        if (mb_strlen(trim($text)) < 50) {
            throw new \moodle_exception('GigaChat вернул пустой ответ');
        }

        return $text;
    }

    // ----------------------------------------------------------------
    // Генерация аудио - SaluteSpeech Sber, возвращает WAV
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
    // SaluteSpeech (Sber) TTS - возвращает WAV
    // Использует тот же OAuth-endpoint, что и GigaChat,
    // но со scope=SALUTE_SPEECH_PERS
    // ----------------------------------------------------------------
    /**
     * OAuth-токен SmartSpeech. protected - шов для подмены сети в тестах, как у
     * generate_text_gigachat(). Тело перенесено из generate_audio_salute() без правок.
     */
    protected function salute_token(): string {
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
        return $token;
    }

    /**
     * Сам запрос синтеза. protected - тот же шов для подмены сети в тестах.
     *
     * @return array{0: int, 1: string} код ответа и тело
     */
    protected function salute_synthesize(string $text, string $voice): array {
        $ch = curl_init(
            'https://smartspeech.sber.ru/rest/v1/text:synthesize?format=wav16&voice=' . urlencode($voice)
        );
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $text,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/text',
                'Authorization: Bearer ' . $this->salute_token(),
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
        return [$http_code, (string)$audio];
    }

    private function generate_audio_salute(string $text): string {
        if (empty($this->salute_key)) {
            throw new \moodle_exception('SaluteSpeech API key не настроен в настройках плагина');
        }

        $voice = get_config('local_unics', 'salute_voice') ?: 'Nec_24000';
        $text  = mb_substr($text, 0, 1999); // лимит REST API

        [$http_code, $audio] = $this->salute_synthesize($text, $voice);

        if ($http_code !== 200) {
            $err = json_decode($audio, true);
            // Запасное значение обязательно: mark_unavailable() на пустой причине выходит
            // молча, и ответ без тела оставил бы галочку активной навсегда.
            $message = trim((string)($err['message'] ?? mb_substr($audio, 0, 200)));
            if ($message === '') {
                $message = 'HTTP ' . $http_code . ' без пояснения от сервиса';
            }

            // 402 - устойчивое состояние оплаты аккаунта, а не сбой: гасим галочку на форме,
            // чтобы педагог не тратил запуск на заведомо недоступный материал. Любой другой
            // код (таймаут, пятисотка) метку НЕ ставит - это временные неурядицы
            // ([[tts-honest-availability-design]], раздел 3.2).
            if ($http_code === 402) {
                tts_status::mark_unavailable($message);
            }

            throw new \moodle_exception('SaluteSpeech HTTP ' . $http_code . ': ' . $message);
        }
        if (strlen($audio) < 1000) {
            throw new \moodle_exception('SaluteSpeech вернул некорректные аудиоданные');
        }

        // Синтез удался - значит пакет оплачен. Метка снимается сама, без администратора.
        tts_status::mark_available();

        return $audio;
    }

    // ----------------------------------------------------------------
    // Генерация вопросов для теста
    // Возвращает массив: [['text'=>..., 'answers'=>[...], 'correct'=>0], ...]
    // ----------------------------------------------------------------
    public function generate_quiz(array $profile, string $topic, string $source_text = '',
                                  int $num = 5, string $extra_context = ''): array {
        // Уровень берется из build_criteria - он ЭФФЕКТИВНЫЙ (с поправкой adapt_level на балл).
        // Раньше здесь стоял сырой difficulty_level, и у ребенка с базовым 3 и баллом 40% текст
        // писался на уровне 2, а тест по этому же тексту - на уровне 3
        // ([[adaptation-full-kit-design]]).
        $c         = $this->build_criteria($profile);
        $class_num = $profile['class_number'] ?? 5;
        $level     = $c['level_label'];
        $block     = $this->adaptation_block($c);
        $extra     = $this->extra_block($extra_context);

        $src = $source_text !== ''
            ? "\n\nОпирайся на следующий учебный текст:\n---\n" . mb_substr($source_text, 0, 2000) . "\n---"
            : '';

        $prompt = "Ты - педагог, составляющий тестовые задания для российских школьников.

Составь ровно {$num} вопросов с множественным выбором по теме «{$topic}» для ученика {$class_num} класса (уровень: {$level}).{$src}

{$block}{$extra}
Требования:
- 4 варианта ответа для каждого вопроса
- Ровно один правильный ответ
- Вопросы проверяют понимание, а не механическое запоминание
- Язык соответствует возрасту и уровню «{$level}»
- ЗАПРЕЩЕНО использовать LaTeX-формулы, символы $ и обратную косую черту \\. Все формулы и уравнения записывай ТОЛЬКО обычным текстом: например «y = kx + b», «x в квадрате», «дробь k/x».

Верни ответ СТРОГО в формате JSON, без пояснений и без markdown-тегов:
{\"questions\":[{\"text\":\"Текст вопроса?\",\"answers\":[\"Вариант А\",\"Вариант Б\",\"Вариант В\",\"Вариант Г\"],\"correct\":0}]}
correct - индекс правильного ответа (0, 1, 2 или 3).";

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

        // Извлекаем JSON - GigaChat иногда добавляет пояснения вокруг
        $json_str = '';
        if (preg_match('/\{.*\}/su', $raw, $m)) {
            $json_str = $m[0];
        } else {
            $json_str = $raw;
        }

        $data = json_decode($json_str, true) ?? json_decode($fix_escapes($json_str), true);

        // Восстановление частичного JSON: если массив обрезан - закрываем его вручную
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

        // Последний резерв - поиск отдельных question-объектов в сыром тексте
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
    public function generate_assignment_description(array $profile, string $topic,
                                                    string $source_text = '', string $extra_context = ''): string {
        // Эффективный уровень и профиль ребенка - как в промте учебного текста
        // ([[adaptation-full-kit-design]]).
        $c         = $this->build_criteria($profile);
        $class_num = $profile['class_number'] ?? 5;
        $level     = $c['level_label'];
        $block     = $this->adaptation_block($c);
        $extra     = $this->extra_block($extra_context);

        $src = $source_text !== ''
            ? "\n\nУчебный текст по теме:\n---\n" . mb_substr($source_text, 0, 1500) . "\n---"
            : '';

        $prompt = "Ты - педагог, составляющий практические задания для российских школьников.

Составь одно письменное практическое задание по теме «{$topic}» для ученика {$class_num} класса (уровень: {$level}).{$src}

{$block}{$extra}
Задание должно:
- Опираться на изученный материал
- Требовать развёрнутого ответа (3–7 предложений)
- Соответствовать уровню «{$level}»
- Быть конкретным и однозначно сформулированным

Верни только текст задания. Без заголовков, без вводных слов - только само задание.";

        return $this->generate_text($prompt);
    }

    // ----------------------------------------------------------------
    // Генерация сценария видеопрезентации (5 слайдов)
    // Возвращает массив: [['title'=>..., 'content'=>..., 'key_points'=>[...]], ...]
    // ----------------------------------------------------------------
    public function generate_video_script(array $profile, string $topic,
                                          string $source_text = '', string $extra_context = ''): array {
        // Эффективный уровень и профиль ребенка - как в промте учебного текста
        // ([[adaptation-full-kit-design]]).
        $c         = $this->build_criteria($profile);
        $class_num = $profile['class_number'] ?? 5;
        $level     = $c['level_label'];
        $block     = $this->adaptation_block($c);
        $extra     = $this->extra_block($extra_context);

        $src = $source_text !== ''
            ? "\n\nОпирайся на следующий учебный текст:\n---\n" . mb_substr($source_text, 0, 2000) . "\n---"
            : '';

        $prompt = "Составь сценарий видеоурока по теме «{$topic}» для ученика {$class_num} класса (уровень: {$level}).{$src}

{$block}{$extra}
Верни РОВНО 5 слайдов в формате JSON без пояснений и без markdown-обёртки:
{\"slides\":[{\"title\":\"...\",\"content\":\"...\",\"key_points\":[\"...\",\"...\"]}]}

Правила:
- title: заголовок слайда до 60 символов
- content: 3-4 предложения, доступный язык для {$class_num} класса, уровень «{$level}»
- key_points: ровно 2-3 ключевых понятия или факта (без формул, только текст)
- НЕ используй символы LaTeX, доллар \$ и обратную косую черту \\

Логика слайдов:
1. Введение - что такое тема и зачем её изучать
2. Основное понятие 1
3. Основное понятие 2
4. Применение или пример из жизни
5. Итог - главный вывод и вопрос для размышления";

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
    /**
     * Тело запроса на генерацию картинки.
     *
     * КЛЮЧЕВОЕ: массива `functions` тут быть НЕ ДОЛЖНО. Объявляя text2image в functions,
     * мы делали встроенную серверную функцию GigaChat клиентской - модель возвращала вызов
     * НАМ (finish_reason: function_call, content пуст), а UUID наш код ищет именно в content.
     * Отсюда «UUID изображения не найден в ответе» и ноль картинок за все время работы
     * функции ([[ai-lecture-images-design]], раздел 1).
     */
    public function build_image_payload(string $prompt): array {
        return [
            'model'         => $this->image_model,
            'messages'      => [['role' => 'user', 'content' => $prompt]],
            'function_call' => ['name' => 'text2image'],
        ];
    }

    /**
     * Запрос за UUID картинки. protected - шов для подмены сети в тестах.
     *
     * Таймаут 30, а не 90: замер 2026-08-10 показал, что удачные ответы приходят за
     * 13.8-14.1 секунды с разбросом в треть секунды. Медленных успехов не бывает, значит
     * девяносто секунд ждали заведомо мертвое соединение и стоили полторы минуты простоя
     * на каждом зависании ([[ai-image-reliability-design]], раздел 2.1).
     */
    protected function fetch_image_uuid(string $prompt): string {
        $payload = json_encode($this->build_image_payload($prompt));

        $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->get_gigachat_token(),
            ],
            CURLOPT_TIMEOUT        => 30,
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

        return $uuid;
    }

    /**
     * Скачать готовую картинку по UUID. protected - шов для тестов.
     *
     * Повтор сюда НЕ распространяется: за все живые прогоны скачивание не сбоило ни разу,
     * виснет всегда запрос за UUID (в ошибке «0 bytes received»).
     *
     * Токен берется ВНУТРИ метода, а не аргументом: иначе подменивший этот шов тест все
     * равно уходил бы в реальный OAuth, потому что аргумент вычисляется до вызова.
     */
    protected function download_image(string $uuid): string {
        $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/files/' . $uuid . '/content');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/jpg',
                'Authorization: Bearer ' . $this->get_gigachat_token(),
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

    public function generate_image(string $prompt): string {
        if (empty($this->api_key)) {
            throw new \moodle_exception('API key не настроен: Настройки сайта → УНИКС → API-ключ ИИ');
        }

        // Один повтор. Арифметика лучше по обеим веткам: зависание сегодня стоит 90 секунд
        // и потерянную картинку, а станет 30 + 14 = 44 секунды с картинкой либо 60 секунд
        // без нее - то есть даже неудачный повтор быстрее нынешнего одиночного провала.
        // Причина неудачи не разбирается намеренно: устойчивая ошибка провалится второй раз
        // быстро, и лишний быстрый запрос дешевле пропущенной классификации.
        $last = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return $this->download_image($this->fetch_image_uuid($prompt));
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempt === 1) {
                    debugging('local_unics: картинка не создана с первой попытки, повтор. '
                        . $e->getMessage(), DEBUG_NORMAL);
                }
            }
        }

        throw $last;
    }

    // ----------------------------------------------------------------
    // S3: обоснование «почему этот шаг» (GigaChat). Живой вызов гейтит
    // вызывающая сторона (задача) + наличие ключа. Graceful: нет ключа /
    // сбой -> null (цикл и UI работают без пояснения).
    // ----------------------------------------------------------------

    /** Чистый сборщик промпта обоснования (БД/сеть не трогает - тестируемо). */
    public function build_rationale_prompt(array $ctx): string {
        $kind   = trim((string)($ctx['kind_label'] ?? 'адаптивный шаг'));
        $skill  = trim((string)($ctx['skill_title'] ?? ''));
        $level  = trim((string)($ctx['target_level_label'] ?? ''));
        $score  = $ctx['last_score'] ?? null;

        $facts = "Тип шага: {$kind}.\n";
        $facts .= 'Навык: ' . ($skill !== '' ? $skill : 'общий уровень сложности') . ".\n";
        if ($level !== '') {
            $facts .= "Целевой уровень: {$level}.\n";
        }
        if ($score !== null) {
            $facts .= 'Последний результат по навыку: ' . round((float)$score) . "%.\n";
        }

        return "Ты - педагог. Кратко (1-2 предложения, простым языком, без терминов и без markdown) "
            . "объясни родителю и педагогу, ПОЧЕМУ ученику рекомендован следующий учебный шаг. "
            . "Опирайся только на факты ниже, новых решений не принимай.\n\n"
            . $facts
            . "\nДай только текст объяснения, без вступлений и заголовков.";
    }

    /**
     * Сгенерировать обоснование. GRACEFUL: пустой ключ -> null (живого вызова НЕТ);
     * сбой GigaChat -> null. Живой вызов происходит ТОЛЬКО при непустом ключе.
     *
     * @return string|null текст обоснования (<=1000 симв.) либо null
     */
    public function generate_rationale(array $ctx): ?string {
        if (empty($this->api_key)) {
            return null; // нет ключа -> нет живого вызова, нет пояснения
        }
        try {
            $text = trim($this->generate_text($this->build_rationale_prompt($ctx), 256));
            return $text !== '' ? mb_substr($text, 0, 1000) : null;
        } catch (\Throwable $e) {
            debugging('local_unics: генерация rationale не удалась: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
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
