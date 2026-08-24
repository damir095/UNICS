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
     * Ниже скольки символов ответ считается пустым.
     *
     * Поле, а не константа: у выходов разная законная длина. Учебный текст короче полусотни
     * символов - всегда сбой, а ответ слепого судьи на ОДИН вопрос занимает 39 символов, и
     * жесткий порог делал третий ярус проверки мертвым для малых комплектов, докладывая о себе
     * как об отказе сети (найдено ревью задачи 3). Сигнатуру шва generate_text_gigachat()
     * менять нельзя - ее переопределяют тесты, - поэтому порог приходит полем.
     */
    private int $min_reply_len = self::MIN_REPLY_LEN;

    /** Порог «пустого ответа» по умолчанию: рассчитан на связные тексты. */
    public const MIN_REPLY_LEN = 50;

    /** Порог для коротких служебных ответов вроде выбора судьи. */
    public const MIN_REPLY_LEN_SHORT = 12;
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

    /**
     * Сколько картинок подряд должно не получиться, чтобы перестать ходить в сеть.
     *
     * Замер 2026-08-10: отказы приходят ПАЧКАМИ. На мертвом сервисе комплект честно
     * перебирал все девять картинок - до девяти минут (9 x 2 попытки x 30 секунд
     * таймаута) ради нуля иллюстраций. Три подряд - достаточный признак, что дело не в
     * конкретном промте, а в сервисе.
     */
    const IMAGE_FAILURE_STREAK = 3;

    /** Сколько раз просить тест, если ответ не разобрался или все вопросы отбракованы. */
    public const QUIZ_PARSE_ATTEMPTS = 2;

    /**
     * Вид адаптационного блока промта ([[item-adaptation-design]]): указания про связный
     * учебный текст либо про формулировку тестовых заданий.
     */
    public const BLOCK_TEXT = 'text';
    public const BLOCK_ITEMS = 'items';

    /** Счетчик подряд идущих неудач картинок; удачная генерация его обнуляет. */
    private int $image_failures_in_row = 0;
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

    /**
     * Диагностический след.
     *
     * Под CLI - mtrace: вывод adhoc-задачи сохраняется в task_log.output и переживает
     * сбой, поэтому пачку отказов можно разобрать через час, а не только поймать вживую.
     * В вебе - debugging: generate_text() вызывается и из essay_check.php, а mtrace там
     * печатает прямо в страницу и ломает верстку.
     *
     * Замерено 2026-08-10: debugging() из воркера не попадает в task_log вообще, и живой
     * отказ не оставил ни одной строки следа ([[ai-refusal-trace-design]], раздел 1.2).
     */
    private function trace(string $message, int $level = DEBUG_NORMAL): void {
        global $CFG;

        // Веб-cron сюда тоже попадает: admin/cron.php объявляет CLI_SCRIPT = true
        // («фальшивый CLI-скрипт, эмулирующий CLI через веб»), так что след не теряется.
        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            // Уровень уважаем и здесь. У generate_rationale он DEBUG_DEVELOPER: при
            // недоступности ИИ иначе в журнал задачи сыпалась бы строка на КАЖДУЮ
            // подсказку, и полезный след утонул бы в шуме (найдено ревью).
            if ($level === DEBUG_DEVELOPER && empty($CFG->debugdeveloper)) {
                return;
            }
            mtrace($message);
            return;
        }
        debugging($message, $level);
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

        // Те же виды ОВЗ, но указания про ФОРМУЛИРОВКУ ЗАДАНИЯ, а не про учебный текст
        // ([[item-adaptation-design]]). Набор выше написан про связный текст - «короткие абзацы»,
        // «пошаговая структура», - и в промте теста он не значил ничего: у вопроса с четырьмя
        // вариантами нет ни абзацев, ни структуры изложения. Барьер к правильному ответу у
        // каждого нарушения свой, и снимается он в формулировке вопроса и вариантов.
        $ovz_item_instructions = [
            // Зрение: барьер - опора на невербальное. Отсылка «на рисунке выше» делает задание
            // нерешаемым, а не сложным.
            1 => 'Вопрос и варианты не должны опираться на изображение, цвет или взаимное '
               . 'расположение объектов. Никаких отсылок вида «на рисунке выше»: все нужные для '
               . 'ответа сведения приводи словами в самом вопросе.',
            // Слух: то же самое, но про звучащее. Плюс запрет на устный контекст занятия.
            2 => 'Не строй вопрос на звучании, интонации или произношении. Не опирайся на то, '
               . 'что было сказано устно: условие полностью содержится в тексте вопроса.',
            // НОДА: сохранное мышление при затрудненном действии. Барьер - объем перебора и
            // требование скорости, а не сложность содержания.
            3 => 'Не ограничивай время на обдумывание и не строй вопрос на действии с предметом. '
               . 'Варианты делай короткими и заметно разными, чтобы выбор не требовал долгого '
               . 'перебора и перечитывания.',
            // ЗПР: узкий объем рабочей памяти. Двойное условие и отрицание требуют удерживать
            // несколько операций разом - ребенок теряет верный ответ, зная материал.
            4 => 'Один вопрос - одна мысль. Формулировка не длиннее одного короткого '
               . 'предложения, без двойных условий и без отрицаний («не», «кроме», «неверно»). '
               . 'Варианты короткие, явно различаются между собой, без вложенных перечислений.',
            // РАС: буквальное понимание. «Самый подходящий» требует ранжировать одинаково верные
            // варианты по неявному признаку - для ребенка с РАС задание неразрешимо в принципе.
            5 => 'Только буквальные формулировки: без метафор, иносказаний и вопросов вида «что '
               . 'лучше» или «какой ответ самый подходящий». Верный ответ должен быть '
               . 'единственным и однозначным, а неверные - заведомо неверными, а не менее '
               . 'точными.',
            6 => 'Простой язык вопроса, минимум специальных терминов. Термин, без которого на '
               . 'вопрос не ответить, поясняй прямо в тексте вопроса.',
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
        //
        // Наборов ДВА: $special_parts описывает связный учебный текст, $special_parts_items -
        // формулировку тестовых заданий ([[item-adaptation-design]]). Раньше набор был один и
        // написан про текст, а уходил во все промты: генератор теста получал требования к
        // абзацам и к длительности чтения модуля - указания, не имеющие смысла для вопроса с
        // четырьмя вариантами ответа.
        $special_parts = [];
        $special_parts_items = [];

        if (in_array(1, $category_ids, true)) {
            if (!empty($valid_ovz_types)) {
                $types_line = 'Типы ОВЗ учащегося: ' . implode('; ', $type_labels) . '.';
                $special_parts[] = $types_line;
                $special_parts_items[] = $types_line;
                foreach ($valid_ovz_types as $t) {
                    $special_parts[] = $ovz_instructions[$t];
                    $special_parts_items[] = $ovz_item_instructions[$t];
                }
            } else {
                $special_parts[] = 'Учащийся имеет ОВЗ. Используй простые короткие предложения, избегай перегруженных абзацев.';
                $special_parts_items[] = 'Учащийся имеет ОВЗ. Формулируй вопрос коротко и '
                    . 'однозначно, без отрицаний и двойных условий; варианты ответа делай '
                    . 'короткими и заметно разными.';
            }
        }
        if (in_array(3, $category_ids, true)) {
            $special_parts[] = 'Учащийся на длительном лечении. Модуль должен читаться за 10–15 минут. Завершай текст коротким мотивирующим выводом.';
            // Утомляемость: барьер - объем удерживаемого условия, а не сложность темы.
            $special_parts_items[] = 'Учащийся на длительном лечении. Задание должно решаться '
                . 'без долгого удержания условия в уме: никаких длинных вводных с несколькими '
                . 'данными сразу.';
        }
        if (in_array(4, $category_ids, true)) {
            $special_parts[] = 'Учащийся одарённый. Добавь углублённые факты, нестандартный угол зрения на тему и исследовательский вопрос в конце.';
            $special_parts_items[] = 'Учащийся одарённый. Спрашивай о понимании и применении, а '
                . 'не об узнавании определения; неверные варианты делай правдоподобными, но '
                . 'однозначно неверными.';
        }
        if (in_array(2, $category_ids, true)) {
            $special_parts[] = 'Учащийся на семейном обучении. Допускай гибкий темп изучения и явные точки самопроверки.';
            $special_parts_items[] = 'Учащийся на семейном обучении. Задания служат '
                . 'самопроверкой: спрашивай только о том, что разобрано в учебном материале.';
        }

        if ($special_needs !== '') {
            // Свободное поле педагога - про самого ребенка, а не про формат выхода, поэтому
            // относится к обоим наборам.
            $special_parts[] = "Дополнительные особенности учащегося: {$special_needs}";
            $special_parts_items[] = "Дополнительные особенности учащегося: {$special_needs}";
        }

        $level_change_reason = null;
        $level_change_items = null;
        if ($eff_level < $base_level) {
            $level_change_reason = "Уровень автоматически снижен (средний балл {$avg_band}) - материал должен быть проще базового.";
            $level_change_items = "Уровень автоматически снижен (средний балл {$avg_band}) - задания должны быть проще базового уровня.";
        } elseif ($eff_level > $base_level) {
            $level_change_reason = "Уровень автоматически повышен (средний балл {$avg_band}) - материал должен быть сложнее стандартного.";
            $level_change_items = "Уровень автоматически повышен (средний балл {$avg_band}) - задания должны быть сложнее стандартного уровня.";
        }
        if ($level_change_reason !== null) {
            $special_parts[] = $level_change_reason;
            $special_parts_items[] = $level_change_items;
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
            'special_parts_items' => $special_parts_items,
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
     * Вид блока выбирает набор особых указаний ([[item-adaptation-design]]): учебному тексту
     * нужны требования к изложению, тестовым заданиям - к формулировке вопроса и вариантов.
     * До разделения набор был один, написанный про текст, и генератор теста получал указания
     * про абзацы.
     *
     * @param array $criteria результат build_criteria()
     * @param string $kind BLOCK_TEXT (по умолчанию) или BLOCK_ITEMS
     */
    public function adaptation_block(array $criteria, string $kind = self::BLOCK_TEXT): string {
        $items = ($kind === self::BLOCK_ITEMS);
        $parts = $items ? ($criteria['special_parts_items'] ?? []) : ($criteria['special_parts'] ?? []);
        $heading = $items ? 'Особые указания к формулировке заданий:' : 'Особые указания:';

        $special = '';
        if (!empty($parts)) {
            $special = "\n" . $heading . "\n- " . implode("\n- ", $parts) . "\n";
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
    public function generate_text(string $prompt, int $max_tokens = 1024,
                                  int $minlen = self::MIN_REPLY_LEN): string {
        $this->min_reply_len = $minlen;
        if (empty($this->api_key)) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'API key не настроен: Настройки сайта → УНИКС → API-ключ ИИ');
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

            $reason = refusal_detector::reason_for($raw, $this->last_finish_reason);
            if ($reason !== null) {
                // След обязателен: без него удачный повтор неотличим от чистого прогона, и
                // мы не узнаем ни частоту отказов, ни какой сигнал сработал. Эта задача и
                // возникла из-за сбоя, который молчал.
                $this->trace('  [warn] Отказ ИИ, попытка ' . $attempt . ' из 2. Сигнал: '
                    . $reason . '. Ответ: ' . mb_substr(trim($raw), 0, 120));
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat auth cURL ошибка: ' . $curl_err);
        }
        if ($auth_code !== 200) {
            throw new \moodle_exception('GigaChat auth HTTP ' . $auth_code . ': ' . $auth_resp);
        }

        return self::parse_token_response((string)$auth_resp);
    }

    /**
     * Разбор ответа OAuth. Отдельным чистым методом, чтобы перевод миллисекунд и
     * запасное значение проверялись тестом: сам fetch_gigachat_token ходит в сеть.
     *
     * @return array{token: string, expires_at: int} expires_at в СЕКУНДАХ Unix
     */
    public static function parse_token_response(string $json): array {
        $decoded = json_decode($json, true);
        $token   = $decoded['access_token'] ?? '';
        if (empty($token)) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat: не удалось получить access_token');
        }

        // Сбер отдает expires_at в МИЛЛИСЕКУНДАХ (замерено: 1786308715480). Без перевода
        // в секунды сравнение с time() считало бы токен вечным, и после реального
        // истечения все посыпалось бы с 401.
        $expires_ms = (int)($decoded['expires_at'] ?? 0);
        if ($expires_ms <= 0) {
            // Поле пропало или переименовано. Ноль всегда провалит проверку запаса, и мы
            // тихо вернулись бы к авторизации на каждый вызов - ровно к тому, что чинили.
            // Осторожные 25 минут при заявленных Сбером тридцати.
            return ['token' => $token, 'expires_at' => time() + 1500];
        }

        return ['token' => $token, 'expires_at' => (int)($expires_ms / 1000)];
    }

    /**
     * Сбросить кеш токена. Вызывается при 401: до кеша каждый вызов брал свежий токен и
     * протухший токен лечился сам, а с кешем экземпляр тащил бы негодный до конца прогона.
     */
    protected function invalidate_gigachat_token(): void {
        $this->token_cache      = '';
        $this->token_expires_at = 0;
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

    /**
     * Живая проверка доступности сервиса для страницы здоровья: только авторизация, без
     * генерации. Отдельный публичный метод нужен потому, что получение токена защищено, а
     * проверке нельзя ни лезть в защищенное, ни тратить токены на генерацию ради «пинга».
     *
     * @return array{ok: bool, message: string}
     */
    public function probe_auth(): array {
        try {
            $token = $this->get_gigachat_token();
            return ['ok' => $token !== '', 'message' => $token !== '' ? 'ok' : 'токен не получен'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat cURL ошибка: ' . $curl_err);
        }
        if ($http_code !== 200) {
            // 401 = токен негоден раньше вычисленного срока (перекос часов, отзыв, смена
            // ключа на ходу). Сбрасываем кеш, иначе экземпляр тащил бы его до конца прогона.
            if ($http_code === 401) {
                $this->invalidate_gigachat_token();
            }
            throw new \moodle_exception('GigaChat HTTP ' . $http_code . ': ' . mb_substr($response, 0, 300));
        }

        $decoded = json_decode($response, true);
        $this->last_finish_reason = (string)($decoded['choices'][0]['finish_reason'] ?? '');
        $text = $decoded['choices'][0]['message']['content'] ?? '';
        if (mb_strlen(trim($text)) < $this->min_reply_len) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat вернул пустой ответ');
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'SaluteSpeech auth cURL ошибка: ' . $curl_err);
        }
        if ($auth_code !== 200) {
            throw new \moodle_exception('SaluteSpeech auth HTTP ' . $auth_code . ': ' . $auth_resp);
        }
        $token = json_decode($auth_resp, true)['access_token'] ?? '';
        if (empty($token)) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'SaluteSpeech: не удалось получить access_token');
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'SaluteSpeech cURL ошибка: ' . $curl_err);
        }
        return [$http_code, (string)$audio];
    }

    private function generate_audio_salute(string $text): string {
        if (empty($this->salute_key)) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'SaluteSpeech API key не настроен в настройках плагина');
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'SaluteSpeech вернул некорректные аудиоданные');
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
        // Набор указаний для ЗАДАНИЙ, а не для учебного текста ([[item-adaptation-design]]):
        // сюда уходили «очень короткие абзацы» и «пошаговая структура» - требования к
        // изложению, ничего не говорящие про формулировку вопроса.
        $block     = $this->adaptation_block($c, self::BLOCK_ITEMS);
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
- Вопрос ОДНИМ предложением, без условия перед вопросом
- НЕ проси несколько ответов: «выберите все», «какие два», «перечислите» запрещены
- Варианты взаимоисключающие: ни один не должен входить в другой по смыслу
- Без двойных отрицаний
- ЗАПРЕЩЕНО использовать LaTeX-формулы, символы $ и обратную косую черту \\. Все формулы и уравнения записывай ТОЛЬКО обычным текстом: например «y = kx + b», «x в квадрате», «дробь k/x».

- Если в вопросе есть вычисление, покажи его в поле solution: «2/5 + 1/5 = 3/5». Правильный ответ обязан совпадать с результатом вычисления.

Верни ответ СТРОГО в формате JSON, без пояснений и без markdown-тегов:
{\"questions\":[{\"text\":\"Текст вопроса?\",\"answers\":[\"Вариант А\",\"Вариант Б\",\"Вариант В\",\"Вариант Г\"],\"correct\":0,\"solution\":\"вычисление или пусто\"}]}
correct - индекс правильного ответа (0, 1, 2 или 3).";

        // Две попытки: разовая порча ответа оставляла комплект без теста, а статус у него был
        // «готов» (найдено зондом 2026-08-20). У генерации структуры кодификатора и разметки
        // банка вторая попытка есть давно, а самый важный выход был защищен слабее всех.
        $last = null;
        for ($attempt = 1; $attempt <= self::QUIZ_PARSE_ATTEMPTS; $attempt++) {
            $raw = $this->generate_text($prompt, 4096);
            try {
                return $this->questions_from_reply($raw, $num);
            } catch (\moodle_exception $e) {
                // След обязателен: без него удачный повтор неотличим от чистого прогона, и
                // частота порчи первого ответа остается невидимой.
                $this->trace('  [warn] Тест не разобрался, попытка ' . $attempt . ' из '
                    . self::QUIZ_PARSE_ATTEMPTS);
                $last = $e;
            }
        }
        throw $last;
    }

    /**
     * Разбор ответа и проверка каждого вопроса ([[quiz-answer-verification-design]]).
     *
     * Задание с неверным ключом ребенку не показываем: ключ либо переезжает на верный вариант,
     * либо вопрос выбывает. Зонд 2026-08-20 нашел шесть неверных ключей из десяти - модель
     * складывает знаменатели, ребенок с верным ответом получал «неверно», а калибровка IRT
     * измеряла трудность по ошибочному ключу.
     *
     * @param int $num сколько вопросов просили у модели
     * @throws \moodle_exception если после проверки не осталось ни одного вопроса
     */
    private function questions_from_reply(string $raw, int $num): array {
        // Разбор с восстановлением обрезанного ответа - общий для всех JSON-выходов
        // ([[codifier-ai-proposal-design]], раздел 4).
        $data = json_reply::decode($raw, 'questions') ?? [];

        $survived = [];
        $fixed = 0;
        $notes = [];
        $dropped = 0;
        $bysigns = 0;
        foreach ((array)($data['questions'] ?? []) as $q) {
            if (!is_array($q) || empty($q['text']) || empty($q['answers']) || !is_array($q['answers'])) {
                continue;
            }
            $text = output_style::strip_math_markup((string)$q['text']);
            $answers = array_map(static function ($a): string {
                return output_style::strip_math_markup((string)$a);
            }, array_values($q['answers']));
            // Индекс ключа НЕ зажимаем: раньше correct = 7 при четырех вариантах молча
            // объявлял верным последний, и ребенок получал «неверно» за верный ответ.
            // Битый индекс - признак того, что модель потеряла соответствие ключа вариантам,
            // и его ловит question_sanity ([[answer-judge-design]], раздел 2.1).
            $correct = (int)($q['correct'] ?? 0);

            // Решение тоже чистим: иначе запасной источник выражения мертв ровно тогда, когда
            // модель шлет LaTeX или символы дробей - то есть в большинстве живых ответов.
            $check = arithmetic_checker::verdict($text, $answers, $correct,
                output_style::strip_math_markup((string)($q['solution'] ?? '')));
            if ($check['verdict'] === 'drop') {
                $dropped++;
                continue;
            }
            $correct = $check['correct'];

            // Второй ярус: то, что видно по самой разметке, без знания предмета.
            $signs = question_sanity::verdict($text, $answers, $correct);
            if ($signs['verdict'] === 'drop') {
                $bysigns++;
                // Обычный уровень, а не DEBUG_DEVELOPER: на стенде debugdeveloper выключен, и
                // причина отбраковки не попадала бы никуда - остался бы только общий счетчик.
                $this->trace('  Признаки брака: ' . $signs['reason']);
                continue;
            }
            foreach ($signs['notes'] as $note) {
                $notes[] = $note;
            }

            $survived[] = ['text' => $text, 'answers' => $answers, 'correct' => $correct,
                // Посчитанное расчетом судье не показываем: его мнение не отменяет
                // арифметику. Иначе исправленный нами ключ отбрасывался бы догадкой модели.
                'computed' => $check['verdict'] !== 'unverifiable',
                'wasfixed' => $check['verdict'] === 'fixed'];
            // Обрезать до $num здесь нельзя: судья отбросит часть, и добрать было бы уже
            // неоткуда, хотя модель нередко присылает вопросов больше, чем просили.
        }

        // Третий ярус: один вызов судьи на пережившее. Спрашиваем в самом конце - и потому,
        // что судья дорог (обращение к сети), и потому, что нет смысла спрашивать про вопросы,
        // уже выбитые разметкой или решенные расчетом.
        [$kept, $byjudge] = $this->judge_survivors($survived);

        // Считаем исправленными только те ключи, что ДОШЛИ до ребенка: вопрос, где ключ
        // починили, а потом выбросили признаки или судья, попадал в обе колонки разом.
        $result = [];
        foreach ($kept as $q) {
            if (!empty($q['wasfixed'])) {
                $fixed++;
            }
            unset($q['wasfixed']);
            $result[] = $q;
            // Лишнее, что прислала модель, отсекаем в самом конце - когда добирать уже нечем.
            if (count($result) >= $num) {
                break;
            }
        }

        if ($fixed || $dropped || $bysigns || $byjudge) {
            $this->trace('  Проверка заданий: исправлено ключей ' . $fixed
                . ', отброшено арифметикой ' . $dropped
                . ', признаками ' . $bysigns
                . ', судьей ' . $byjudge);
        }
        if ($notes) {
            // Считаем повторы, а не печатаем список: на комплекте из пяти вопросов с четырьмя
            // видами подозрений строка иначе разрасталась до двадцати одинаковых кусков.
            $counted = [];
            foreach (array_count_values($notes) as $note => $times) {
                $counted[] = $times > 1 ? $note . ' (x' . $times . ')' : $note;
            }
            $this->trace('  Подозрения (вопросы все равно приняты): ' . implode('; ', $counted));
        }
        if (empty($result)) {
            if ($dropped || $bysigns || $byjudge) {
                // Ответ разобрался, вопросы выбили проверки. Называть это «некорректным
                // форматом» - отправлять разбирающегося смотреть на разбор JSON, хотя
                // причина в содержании (найдено ревью).
                throw new \moodle_exception('generalexceptionmessage', 'error', '',
                    'Все вопросы отбракованы проверками: арифметикой ' . $dropped
                    . ', признаками ' . $bysigns . ', судьей ' . $byjudge);
            }
            // Начало И хвост: по одному началу нельзя отличить обрыв ответа от порчи разметки,
            // а причина всегда в конце ([[codifier-ai-proposal-design]], раздел 11).
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'ИИ вернул некорректный формат теста. ' . json_reply::head_and_tail($raw));
        }

        return $result;
    }

    /**
     * Спросить слепого судью про пережившие вопросы ([[answer-judge-design]], раздел 2.2).
     *
     * Отказ судьи и срабатывание предохранителя ОБЯЗАНЫ оставлять след: без него молчание
     * проверки неотличимо от чистого прогона, и ярус мог бы годами не работать незаметно -
     * ровно так проект уже обжегся на озвучке.
     *
     * @param array $survived вопросы, дожившие до третьего яруса
     * @return array [оставшиеся вопросы, сколько отброшено судьей]
     */
    private function judge_survivors(array $survived): array {
        // Судить нечего там, где посчитал расчет: вердикт арифметики надежнее догадки модели.
        // Ключи сохраняем - вердикты вернутся с ними и не разъедутся с вопросами.
        $ask = array_filter($survived, static fn(array $q): bool => empty($q['computed']));
        $verdicts = [];
        $byjudge = 0;

        if (!$ask) {
            $this->trace('  Судья не спрашивался: все вопросы решены расчетом');
        } else {
            $out = (new answer_judge($this))->review($ask);
            switch ($out['status']) {
                case answer_judge::STATUS_FAILED:
                    $this->trace('  [warn] Судья не ответил, вопросы приняты без его проверки');
                    break;
                case answer_judge::STATUS_UNUSABLE:
                    // Отдельно от отказа сети: устойчивое расхождение форматов лечится правкой
                    // промта, а не ожиданием, когда связь наладится.
                    $this->trace('  [warn] Судья ответил, но ни один выбор не сошелся с '
                        . 'вариантами - проверка не состоялась');
                    break;
                case answer_judge::STATUS_DISTRUST:
                    $this->trace('  [warn] Судья спорит с ' . $out['disagreed'] . ' ответами из '
                        . $out['judged'] . ', но при переспросе ответил иначе - вердикты сняты');
                    break;
                case answer_judge::STATUS_CONFIRMED:
                    // Массовое расхождение бывает настоящим: на истории Петра I модель выдала
                    // три неверных ключа из четырех. Переспрос это подтвердил.
                    $this->trace('  [warn] Судья спорит с ' . $out['disagreed'] . ' ответами из '
                        . $out['judged'] . ' и подтвердил это переспросом');
                    $verdicts = $out['verdicts'];
                    break;
                default:
                    // След нужен и на удачном исходе: молчание яруса иначе неотличимо от того,
                    // что его перестали звать вовсе.
                    $this->trace('  Судья проверил вопросов: ' . $out['judged']
                        . ', спорных: ' . $out['disagreed']);
                    $verdicts = $out['verdicts'];
            }
        }

        $kept = [];
        foreach ($survived as $i => $q) {
            if (($verdicts[$i] ?? 'ok') === 'drop') {
                $byjudge++;
                continue;
            }
            unset($q['computed']);
            $kept[] = $q;
        }
        return [$kept, $byjudge];
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'ИИ вернул некорректный формат видеосценария: ' . mb_substr($raw, 0, 300));
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'ИИ не вернул ни одного слайда');
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat image cURL ошибка: ' . $curl_err);
        }
        if ($http_code !== 200) {
            if ($http_code === 401) {
                $this->invalidate_gigachat_token();
            }
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat image: UUID изображения не найден в ответе');
        }

        return $uuid;
    }

    /**
     * Скачать готовую картинку по UUID и проверить, что это вообще данные.
     *
     * Повтор сюда НЕ распространяется - и это теперь правда, а не только обещание в
     * докблоке: скачивание вынесено ЗА цикл повтора в generate_image(). Иначе сбой на
     * скачивании выбрасывал бы уже готовое изображение, чтобы заплатить за новое.
     *
     * Проверка размера обязательна: HTTP 200 с пустым телом возвращался как успех, воркер
     * молча клал пустой файл, и в логе не оставалось ни следа. У озвучки такая проверка
     * есть с самого начала.
     */
    protected function download_image(string $uuid): string {
        $data = $this->raw_download_image($uuid);

        if (strlen($data) < 1000) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat image download: пустой ответ ('
                . strlen($data) . ' байт)');
        }

        return $data;
    }

    /**
     * Сам HTTP скачивания. protected - шов для тестов.
     *
     * Токен берется ВНУТРИ метода, а не аргументом: иначе подменивший этот шов тест все
     * равно уходил бы в реальный OAuth, потому что аргумент вычисляется до вызова.
     */
    protected function raw_download_image(string $uuid): string {
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
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat image download cURL ошибка: ' . $curl_err);
        }
        if ($http_code !== 200) {
            if ($http_code === 401) {
                $this->invalidate_gigachat_token();
            }
            throw new \moodle_exception('GigaChat image download HTTP ' . $http_code);
        }

        return (string) $img_data;
    }

    /**
     * Пауза перед повторной попыткой обращения к ИИ.
     *
     * Раньше повтор бил в ту же секунду, а отказы приходят пачками - на пачке такая
     * попытка обречена. Вынесено отдельным методом: это шов для тестов, иначе каждый
     * прогон сьюта честно спал бы секунды.
     */
    protected function pause_before_retry(int $attempt): void {
        sleep(2);
    }

    public function generate_image(string $prompt): string {
        if (empty($this->api_key)) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'API key не настроен: Настройки сайта → УНИКС → API-ключ ИИ');
        }

        // Один повтор ТОЛЬКО на запрос за UUID: именно он виснет (в ошибке «0 bytes
        // received»), и именно он стоил 90 секунд простоя. Скачивание вынесено за цикл -
        // иначе сбой на нем выбрасывал бы уже готовое изображение ради нового.
        //
        // Арифметика зависания: было 90 секунд и потерянная картинка, стало 30 + 14 = 44
        // секунды с картинкой либо 60 секунд без нее.
        //
        // Причина неудачи не разбирается. Честная оговорка: устойчивый отказ рисовать
        // возвращается не мгновенно, так что вторая попытка на нем стоит полных секунд, а
        // не «проваливается быстро». Классификатор сюда не добавлен намеренно - потолок
        // потерь ограничен (девять картинок на комплект), а угадывать вид ошибки по тексту
        // значит завести вторую эвристику там, где хватает верхней границы.
        // Предохранитель: сервис уже отказал IMAGE_FAILURE_STREAK раз подряд, значит дело
        // не в промте. Дальше ходить в сеть - это минуты ожидания ради того же нуля.
        if ($this->image_failures_in_row >= self::IMAGE_FAILURE_STREAK) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'Сервис картинок не отвечает: подряд неудачных - '
                . $this->image_failures_in_row . ', попытки прекращены до конца комплекта.');
        }

        $uuid = null;
        $last = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $uuid = $this->fetch_image_uuid($prompt);
                break;
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempt === 1) {
                    $this->trace('  [warn] Картинка не создана с первой попытки, повтор. '
                        . $e->getMessage());
                    $this->pause_before_retry($attempt);
                }
            }
        }

        if ($uuid === null) {
            $this->image_failures_in_row++;
            throw $last;
        }

        // Получилось - значит сервис жив, и прошлые неудачи были про конкретные промты.
        $this->image_failures_in_row = 0;

        return $this->download_image($uuid);
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
            $this->trace('  [warn] Генерация обоснования шага не удалась: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
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
