<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category(
        'local_unics_cat',
        get_string('pluginname', 'local_unics')
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_users',
        get_string('users', 'local_unics'),
        new moodle_url('/local/unics/pages/users.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_import',
        'Импорт пользователей (CSV)',
        new moodle_url('/local/unics/pages/import_users.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_orgs',
        get_string('organizations', 'local_unics'),
        new moodle_url('/local/unics/pages/organizations.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_assign',
        get_string('assignments', 'local_unics'),
        new moodle_url('/local/unics/pages/assign.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_enrol',
        'Запись учащихся на курс',
        new moodle_url('/local/unics/pages/enrol_students.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_templates',
        'Шаблоны курсов',
        new moodle_url('/local/unics/pages/course_templates.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_demo',
        'Демонстрационные курсы',
        new moodle_url('/local/unics/pages/demo_courses.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_enrol_teachers',
        'Запись педагогов на курс',
        new moodle_url('/local/unics/pages/enrol_teachers.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_my_students',
        'Мои учащиеся',
        new moodle_url('/local/unics/pages/my_students.php'),
        'local/unics:viewstudents'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_adaptive_suggestions',
        'Адаптивные предложения',
        new moodle_url('/local/unics/pages/adaptive_suggestions.php'),
        'local/unics:viewstudents'
    ));

    // Подраздел «Отчёты»
    $ADMIN->add('local_unics_cat', new admin_category('local_unics_reports_cat', 'Отчёты'));

    $ADMIN->add('local_unics_reports_cat', new admin_externalpage(
        'local_unics_org_report',
        'Отчёт по организации',
        new moodle_url('/local/unics/pages/org_report.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_umk',
        'Генерация УМК (ИИ)',
        new moodle_url('/local/unics/pages/generate_umk.php'),
        'local/unics:viewstudents'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_umk_status',
        'История генерации УМК',
        new moodle_url('/local/unics/pages/umk_status.php'),
        'local/unics:manage'
    ));

    // Настройки ИИ-генерации
    $settings = new admin_settingpage('local_unics_ai', 'Настройки ИИ');
    $ADMIN->add('local_unics_cat', $settings);

    $settings->add(new admin_setting_heading(
        'local_unics/ai_heading', 'Провайдер генерации текста', ''
    ));

    $settings->add(new admin_setting_configselect(
        'local_unics/ai_provider',
        'Провайдер ИИ',
        'GigaChat - Сбербанк, только для РФ, нужен Sber Developer аккаунт.',
        'gigachat',
        [
            'gigachat' => 'GigaChat Sber - бесплатно для РФ, нужен Sber Developer аккаунт',
        ]
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_unics/ai_api_key',
        'API-ключ GigaChat',
        'Личный кабинет developers.sber.ru → Authorization Key (Base64).',
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_unics/ai_model',
        'Модель (необязательно)',
        'GigaChat, GigaChat-Plus. Оставьте пустым - будет выбрана модель по умолчанию.',
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_unics/ai_image_model',
        'Модель для изображений',
        'Нужна модель со встроенной функцией text2image. Проверено: GigaChat-2. '
            . 'Модель GigaChat (без цифры) на запрос картинки не отвечает вовсе. '
            . 'Оставьте пустым - будет использована GigaChat-2.',
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_unics/umk_max_per_run',
        'Потолок комплектов за один запуск',
        'Сколько различных комплектов УМК разрешено поставить в очередь за одну генерацию. '
            . 'Один комплект - это отдельное обращение к ИИ на каждый выбранный материал. '
            . '0 - без ограничения.',
        \local_unics\ai\umk_launcher::DEFAULT_LIMIT, PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_unics/tts_heading', 'Провайдер синтеза речи (TTS)', ''
    ));

    $settings->add(new admin_setting_heading(
        'local_unics/salute_heading', 'SaluteSpeech Sber', ''
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_unics/salute_speech_api_key',
        'SaluteSpeech API Key',
        'Authorization Key (Base64) из developers.sber.ru, раздел SaluteSpeech. Это ОТДЕЛЬНЫЙ '
            . 'ключ, НЕ тот же, что у GigaChat: ключ GigaChat на scope озвучки отвечает '
            . '400 «scope from db not fully includes consumed scope» (замерено 2026-08-09). '
            . 'Синтезу нужен оплаченный пакет SmartSpeech, иначе приходит 402 Payment Required.',
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'local_unics/salute_voice',
        'Голос',
        '',
        'Nec_24000',
        [
            'Nec_24000' => 'Наталья (женский, нейтральный)',
            'May_24000' => 'Майя (женский, живой)',
            'Tur_24000' => 'Александр (мужской)',
            'Bys_24000' => 'Сергей (мужской)',
        ]
    ));

    // Настройки доступности (A1).
    $a11y = new admin_settingpage('local_unics_a11y', 'Доступность');
    $ADMIN->add('local_unics_cat', $a11y);

    $a11y->add(new admin_setting_heading(
        'local_unics/voice_heading', 'Голосовой ввод ответов', ''
    ));
    $a11y->add(new admin_setting_configcheckbox(
        'local_unics/voice_input_enabled',
        'Включить голосовой ввод',
        'Добавляет кнопку «Диктовать» к полям ответа в заданиях и тестах '
        . '(браузерный Web Speech API). ВНИМАНИЕ: в некоторых браузерах аудио '
        . 'обрабатывается на внешнем сервере - убедитесь, что это допустимо политикой '
        . 'обработки персональных данных. По умолчанию выключено.',
        0
    ));

    // Настройки адаптивного обучения (S2).
    $adaptive = new admin_settingpage('local_unics_adaptive', 'Адаптивное обучение');
    $ADMIN->add('local_unics_cat', $adaptive);

    $adaptive->add(new admin_setting_configtext(
        'local_unics/adaptive_autoapply_days',
        'Авто-применение предложений (дней)',
        'Через сколько дней не рассмотренное педагогом адаптивное предложение (смена уровня, '
        . 'ремедиация, продвижение) применяется автоматически. 0 - применять сразу, без ожидания '
        . 'педагога (полный авто-режим). По умолчанию 7.',
        '7', PARAM_INT
    ));

    $adaptive->add(new admin_setting_configcheckbox(
        'local_unics/adaptive_ai_explanations',
        'ИИ-обоснования предложений (GigaChat)',
        'Если включено, фоновая задача генерирует через GigaChat короткое обоснование «почему этот '
        . 'шаг» для адаптивных предложений (показывается педагогу). Требует настроенного API-ключа ИИ. '
        . 'ВНИМАНИЕ: при включении данные о навыке и результатах учащегося отправляются во внешний '
        . 'сервис (Sber GigaChat). По умолчанию ВЫКЛЮЧЕНО (действует заморозка ИИ-функций).',
        0
    ));

    // Точка расширения: оценщик владения навыком. Пусто - встроенный расчет ядра (EWMA).
    // Варианты набираются из установленных подплагинов типа unicsest, ядро имен не знает.
    // [[estimator-subplugin-design]]
    $estimatoroptions = ['' => 'Встроенный (скользящее среднее)']
        + \local_unics\adaptive\estimator_factory::installed();
    $adaptive->add(new admin_setting_configselect(
        'local_unics/mastery_estimator',
        'Оценщик владения навыком',
        'Чем считается владение навыком по результатам попыток. Встроенный расчет работает '
        . 'всегда и не требует внешних сервисов. Дополнительные оценщики устанавливаются как '
        . 'подплагины в local/unics/estimator. При отказе выбранного оценщика система молча '
        . 'возвращается на встроенный.',
        '',
        $estimatoroptions
    ));

    $adaptive->add(new admin_setting_heading(
        'local_unics/irt_heading', 'ML-микросервис (IRT)', ''
    ));
    $adaptive->add(new admin_setting_configcheckbox(
        'local_unics/adaptive_irt_enabled',
        'Включить IRT-оценщик (Python-сервис)',
        'Если включено, владение навыком оценивается внешним Python-микросервисом (модель Раша) по '
        . 'ответам на привязанные к кодификатору вопросы. По сети уходят только числовые признаки (без '
        . 'персональных данных). При недоступности сервиса система откатывается на встроенный расчет. '
        . 'По умолчанию выключено.',
        0
    ));
    $adaptive->add(new admin_setting_configtext(
        'local_unics/irt_service_url',
        'URL микросервиса IRT',
        'Базовый адрес Python-сервиса, например http://127.0.0.1:8000.',
        'http://127.0.0.1:8000', PARAM_URL
    ));
    $adaptive->add(new admin_setting_configpasswordunmask(
        'local_unics/irt_service_token',
        'Токен микросервиса IRT',
        'Общий секрет (заголовок X-UNICS-Token). Должен совпадать с переменной окружения '
        . 'UNICS_IRT_TOKEN сервиса. Можно оставить пустым для локальной разработки.',
        ''
    ));
    $adaptive->add(new admin_setting_configcheckbox(
        'local_unics/adaptive_recommender_ml',
        'ML-рекомендатель (Python-сервис)',
        'Если включено, адаптивные предложения (что повторить / куда продвигаться) ранжирует внешний '
        . 'Python-микросервис (content_v1: тяжесть пробела + уверенность + фундаментальность навыка), и '
        . 'создаются только для топ-приоритетных навыков. По сети уходят только числовые признаки. При '
        . 'недоступности сервиса используется встроенный расчет по правилам. По умолчанию выключено.',
        0
    ));
    $adaptive->add(new admin_setting_heading(
        'local_unics/cat_heading', 'Адаптивная проверка (CAT)', ''
    ));
    $adaptive->add(new admin_setting_configcheckbox(
        'local_unics/adaptive_cat_enabled',
        'Включить адаптивную проверку (CAT)',
        'Если включено, учащийся может пройти адаптивную проверку по навыку: вопросы выдаются по '
        . 'одному, сложность подстраивается под ответы (нужен включенный Python-сервис IRT). По сети '
        . 'уходят только числовые признаки. При недоступности сервиса проверка недоступна (обычные '
        . 'тесты работают). По умолчанию выключено.',
        0
    ));
    $adaptive->add(new admin_setting_configtext(
        'local_unics/cat_se_threshold',
        'CAT: порог стандартной ошибки (SE)',
        'Сессия останавливается, когда стандартная ошибка оценки опускается ниже этого порога '
        . '(но не раньше минимального числа вопросов). По умолчанию 0.3.',
        '0.3', PARAM_FLOAT
    ));
    $adaptive->add(new admin_setting_configtext(
        'local_unics/cat_min_items',
        'CAT: минимум вопросов',
        'Минимальное число вопросов до возможной остановки. По умолчанию 5.',
        '5', PARAM_INT
    ));
    $adaptive->add(new admin_setting_configtext(
        'local_unics/cat_max_items',
        'CAT: максимум вопросов',
        'Максимальное число вопросов в сессии. По умолчанию 20.',
        '20', PARAM_INT
    ));
}
