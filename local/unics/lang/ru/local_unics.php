<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']            = 'Управление пользователями УНИКС';

// Навигация
$string['users']                 = 'Пользователи';
$string['create_user']           = 'Создать пользователя';
$string['assignments']           = 'Привязки';

// Форма пользователя
$string['firstname']             = 'Имя';
$string['lastname']              = 'Фамилия';
$string['middlename']            = 'Отчество';
$string['email']                 = 'Email';
$string['username']              = 'Логин';
$string['password']              = 'Пароль';
$string['organization']          = 'Организация';
$string['unics_role']            = 'Роль в УНИКС';
$string['select_role']           = '- Выберите роль -';
$string['select_org']            = '- Выберите организацию -';

// Роли УНИКС (переработка ролевой модели 2026-05-23)
$string['role_teacher']            = 'Педагог';                  // код 6, non-editing
$string['role_editingteacher']     = 'Педагог, создающий курсы'; // код 5
$string['role_student']            = 'Учащийся';
$string['role_parent']             = 'Родитель';
$string['role_org_admin']          = 'Администратор организации'; // legacy, из селекта убран
$string['role_methodist']          = 'Методист организации';      // код 4
$string['role_district_methodist'] = 'Муниципальный методист';     // код 9
$string['role_region_admin']       = 'Региональный администратор'; // код 1
$string['role_region_methodist']   = 'Региональный методист';      // код 10 (v3 фаза 2)
// role_district_admin (код 2) удалён в v3 [[role-model-v3-2026-06-11]] — слит в district_methodist (9)

// Поля учащегося
$string['student_category']      = 'Категория учащегося';
$string['category_ovz']          = 'ОВЗ';
$string['category_family']       = 'Семейное обучение';
$string['category_treatment']    = 'Длительное лечение';
$string['category_gifted']       = 'Одарённый ребёнок';
$string['category_normal']       = 'Обычный';
$string['difficulty_level']      = 'Уровень подготовки';
$string['level_weak']            = '1 - Слабый';
$string['level_normal']          = '2 - Обычный';
$string['level_gifted']          = '3 - Одарённый';
$string['class_number']          = 'Класс (номер)';
$string['class_letter']          = 'Буква класса';
$string['special_needs']         = 'Особые образовательные потребности';
// Виды ОВЗ показываются абстрактными номерами категорий — расшифровка (какой
// номер какому диагнозу соответствует) хранится ТОЛЬКО в вики (student-categories.md),
// в системе её быть не должно (приватность, анти-стигматизация, 152-ФЗ).
$string['ovz_type']              = 'Категория ОВЗ';
$string['ovz_blind']             = 'ОВЗ 1 категории';
$string['ovz_deaf']              = 'ОВЗ 2 категории';
$string['ovz_motor']             = 'ОВЗ 3 категории';
$string['ovz_zpd']               = 'ОВЗ 4 категории';
$string['ovz_ras']               = 'ОВЗ 5 категории';
$string['ovz_other']             = 'ОВЗ 6 категории';

// Поля педагога
$string['subjects']              = 'Преподаваемые предметы';
$string['subject_categories']    = 'Преподаваемые предметы';
$string['subject_categories_help'] = 'Предметы соответствуют категориям курсов. Можно выбрать несколько. Новые предметы добавляются как категории курсов.';
$string['qualification']         = 'Квалификация';
$string['grade_range']           = 'Диапазон классов';
$string['grade_from']            = 'С класса';
$string['grade_to']              = 'По класс';
$string['grade_any']             = '- любой -';
$string['grade_range_help']      = 'Классы, с которыми работает педагог (мягкий фильтр для назначений и записи на курсы). Можно не указывать.';
$string['err_grade_range']       = 'Нижняя граница диапазона классов не может быть больше верхней';
$string['teacher_type']          = 'Тип педагога';
$string['teacher_type_help']     = 'Роль педагога в школе: предметник, учитель начальных классов или специалист сопровождения. Характеристика для отображения, на доступ пока не влияет. Можно не указывать.';
$string['teacher_type_none']     = '- не указан -';
$string['teacher_type_subject']  = 'Предметник (5-11 классы)';
$string['teacher_type_primary']  = 'Учитель начальных классов (1-4 классы)';
$string['teacher_type_support']  = 'Специалист сопровождения (логопед, дефектолог, психолог)';

// Список пользователей
$string['filter_role']           = 'Фильтр по роли';
$string['filter_org']            = 'Фильтр по организации';
$string['all_roles']             = 'Все роли';
$string['all_orgs']              = 'Все организации';
$string['actions']               = 'Действия';
$string['edit']                  = 'Редактировать';
$string['no_users']              = 'Пользователи не найдены';

// Привязки
$string['teacher_student']       = 'Педагог - Учащийся';
$string['parent_student']        = 'Родитель - Учащийся';
$string['select_teacher']        = '- Выберите педагога -';
$string['select_student']        = '- Выберите учащегося -';
$string['select_parent']         = '- Выберите родителя -';
$string['assign']                = 'Привязать';
$string['assigned_pairs']        = 'Существующие привязки';
$string['remove']                = 'Удалить';

// Сообщения
$string['user_created']          = 'Пользователь успешно создан';
$string['user_create_error']     = 'Ошибка при создании пользователя';
$string['assigned_ok']           = 'Привязка добавлена';
$string['assign_error']          = 'Ошибка при создании привязки';
$string['removed_ok']            = 'Привязка удалена';

// Организации
$string['organizations']         = 'Организации';
$string['org_management']        = 'Управление организациями';
$string['add_region']            = 'Добавить регион';
$string['add_district']          = 'Добавить муниципалитет';
$string['add_org']               = 'Добавить организацию';
$string['org_type_school']       = 'Школа';
$string['org_type_cdo']          = 'Центр дистанционного обучения';
$string['org_type_hospital']     = 'Больничная школа';
$string['org_type_boarding']     = 'Интернат';
$string['moodle_category_id']    = 'Категория Moodle (ID)';
$string['not_linked']            = 'не привязана';
$string['saved']                 = 'Сохранено';

// Права
$string['unics:manage']          = 'Управлять пользователями УНИКС';

// Баллы и магазин
$string['points']                = 'Баллы';
$string['points_balance']        = 'Баланс баллов';
$string['shop']                  = 'Магазин наград';
$string['shop_items']            = 'Товары магазина';
$string['buy']                   = 'Купить';
$string['already_bought']        = 'Уже куплено';
$string['not_enough_points']     = 'Недостаточно баллов';
$string['points_history']        = 'История баллов';

// Уведомления - новые типы
$string['notif_level_up']        = 'Уровень повышен';
$string['notif_level_down']      = 'Уровень понижен';
$string['notif_badge_earned']    = 'Новый значок';
$string['notif_new_comment']     = 'Заметка педагога';

// CAT (Компьютерное адаптивное тестирование)
$string['cat_disabled'] = 'Адаптивная проверка отключена администратором.';
$string['cat_no_items'] = 'По этой теме пока нет подготовленных вопросов.';
$string['cat_service_down'] = 'Адаптивная проверка временно недоступна. Попробуйте позже или пройдите обычный тест.';

// Privacy API (описание персональных данных плагина).
$string['privacy:metadata:field:userid'] = 'Идентификатор пользователя';
$string['privacy:metadata:field:student'] = 'Идентификатор профиля обучающегося';
$string['privacy:metadata:field:teacher'] = 'Идентификатор профиля педагога';
$string['privacy:metadata:field:createdby'] = 'Кто выполнил/создал запись';
$string['privacy:metadata:field:time'] = 'Отметка времени';
$string['privacy:metadata:field:grade'] = 'Оценка/показатель успеваемости';
$string['privacy:metadata:unics_user_org'] = 'Привязка пользователя к роли УНИКС и области видимости';
$string['privacy:metadata:unics_user_org:unics_role'] = 'Прикладная роль пользователя';
$string['privacy:metadata:unics_user_org:organization_id'] = 'Организация области видимости';
$string['privacy:metadata:unics_students'] = 'Профиль обучающегося (особая категория ПДн: сведения о здоровье)';
$string['privacy:metadata:unics_student_category'] = 'Категории обучающегося (нормализованное хранение)';
$string['privacy:metadata:unics_student_ovz'] = 'Виды ОВЗ обучающегося (нормализованное хранение; особая категория ПДн)';
$string['privacy:metadata:unics_students:category'] = 'Категория обучающегося (ОВЗ, семейное обучение, лечение, одаренный)';
$string['privacy:metadata:unics_students:ovz_type'] = 'Вид ограничения возможностей здоровья';
$string['privacy:metadata:unics_students:diagnosed'] = 'Отметка о подтвержденном диагнозе';
$string['privacy:metadata:unics_students:special_needs'] = 'Особые образовательные потребности (текст)';
$string['privacy:metadata:unics_students:birth_date'] = 'Дата рождения';
$string['privacy:metadata:unics_students:class_number'] = 'Класс обучения';
$string['privacy:metadata:unics_students:difficulty_level'] = 'Текущий уровень сложности';
$string['privacy:metadata:unics_students:points'] = 'Баланс учебных баллов';
$string['privacy:metadata:unics_teachers'] = 'Профиль педагога';
$string['privacy:metadata:unics_teachers:subjects'] = 'Преподаваемые предметы';
$string['privacy:metadata:unics_teachers:qualification'] = 'Квалификация';
$string['privacy:metadata:unics_teacher_student'] = 'Привязка педагога к обучающемуся';
$string['privacy:metadata:unics_parent_student'] = 'Привязка родителя к обучающемуся';
$string['privacy:metadata:unics_teacher_subject'] = 'Предметы педагога';
$string['privacy:metadata:unics_retakes'] = 'Автоматические пересдачи итоговых тестов';
$string['privacy:metadata:unics_topic_retries'] = 'Возвраты к темам по результатам тестов';
$string['privacy:metadata:unics_level_history'] = 'История смен уровня сложности';
$string['privacy:metadata:unics_level_history:old_level'] = 'Прежний уровень';
$string['privacy:metadata:unics_level_history:new_level'] = 'Новый уровень';
$string['privacy:metadata:unics_learning_path'] = 'Индивидуальный образовательный маршрут';
$string['privacy:metadata:unics_learning_path:goal'] = 'Цель маршрута';
$string['privacy:metadata:unics_skill_mastery'] = 'Владение элементами содержания';
$string['privacy:metadata:unics_skill_mastery:score'] = 'Показатель владения';
$string['privacy:metadata:unics_skill_mastery:theta'] = 'Оценка способности (IRT)';
$string['privacy:metadata:unics_mastery_history'] = 'История пересчетов владения';
$string['privacy:metadata:unics_adaptive_suggestion'] = 'Адаптивные предложения педагогу по обучающемуся';
$string['privacy:metadata:unics_adaptive_suggestion:kind'] = 'Тип предложения';
$string['privacy:metadata:unics_cat_session'] = 'Сессии адаптивной проверки (CAT)';
$string['privacy:metadata:unics_points_log'] = 'Журнал начисления и списания баллов';
$string['privacy:metadata:unics_points_log:points'] = 'Изменение баллов';
$string['privacy:metadata:unics_points_log:reason_text'] = 'Основание операции';
$string['privacy:metadata:unics_achievements'] = 'Полученные значки достижений';
$string['privacy:metadata:unics_achievements:badge_type'] = 'Тип значка';
$string['privacy:metadata:unics_purchases'] = 'Покупки в магазине поощрений';
$string['privacy:metadata:unics_purchases:item_id'] = 'Купленный товар';
$string['privacy:metadata:unics_equipped'] = 'Надетые предметы поощрений (активный титул и будущие слоты)';
$string['privacy:metadata:unics_equipped:slot'] = 'Слот экипировки';
$string['privacy:metadata:unics_equipped:item_id'] = 'Надетый предмет';
$string['privacy:metadata:unics_umk_students'] = 'Назначенные обучающемуся учебные материалы';
$string['privacy:metadata:unics_comments'] = 'Заметки педагогов об обучающихся';
$string['privacy:metadata:unics_comments:body'] = 'Текст заметки';
$string['privacy:metadata:unics_comment_seen'] = 'Отметки о прочтении заметок';
$string['privacy:metadata:unics_notifications'] = 'Уведомления пользователя';
$string['privacy:metadata:unics_notifications:subject'] = 'Тема уведомления';
$string['privacy:metadata:unics_notifications:body'] = 'Текст уведомления';
$string['privacy:metadata:unics_stats_student_course'] = 'Агрегированная учебная статистика по курсам';
$string['privacy:metadata:unics_reports'] = 'Сформированные отчеты';
$string['privacy:metadata:unics_audit_log'] = 'Журнал аудита действий (при удалении данных субъект анонимизируется)';
$string['privacy:metadata:unics_audit_log:action'] = 'Выполненное действие';
$string['privacy:metadata:unics_audit_log:ip_address'] = 'IP-адрес';
$string['privacy:metadata:unics_ai_queue'] = 'Очередь ИИ-генерации (транзитные ссылки на обучающихся)';
$string['privacy:metadata:unics_ai_queue:student_ids'] = 'Список обучающихся, для которых заказана генерация';
$string['privacy:metadata:gigachat'] = 'Внешний сервис GigaChat (Сбер): генерация и проверка учебных материалов';
$string['privacy:metadata:gigachat:profile'] = 'Обезличенные параметры профиля (категория, уровень, класс) для адаптации материала';
$string['privacy:metadata:gigachat:texts'] = 'Тексты учебных работ, отправляемые на проверку';
$string['privacy:metadata:salutespeech'] = 'Внешний сервис SaluteSpeech (Сбер): синтез речи';
$string['privacy:metadata:salutespeech:texts'] = 'Тексты материалов, отправляемые на озвучку';
$string['privacy:export:profile'] = 'Профиль и роли';
$string['privacy:export:links'] = 'Связи участников';
$string['privacy:export:learning'] = 'Учебный процесс';
$string['privacy:export:motivation'] = 'Мотивация';
$string['privacy:export:communication'] = 'Коммуникация';
$string['privacy:export:audit'] = 'Аудит действий';

// События (этап 2.4 аудита).
$string['eventpointsawarded'] = 'Баллы начислены';
$string['eventpointsspent'] = 'Баллы списаны';
$string['eventlevelchanged'] = 'Уровень учащегося изменен';
$string['eventumkpublished'] = 'УМК опубликован';

// События аудита админ-мутаций (этап 4.4).
$string['eventusercreated'] = 'Пользователь создан';
$string['eventuserupdated'] = 'Пользователь обновлен';
$string['eventteacherstudentassigned'] = 'Педагог привязан к учащемуся';
$string['eventparentstudentassigned'] = 'Родитель привязан к учащемуся';
$string['eventorganizationcreated'] = 'Организация создана';
$string['eventorganizationupdated'] = 'Организация обновлена';
$string['eventorganizationdeleted'] = 'Организация удалена';
$string['eventteacherstudentunassigned'] = 'Педагог отвязан от учащегося';
$string['eventparentstudentunassigned'] = 'Родитель отвязан от учащегося';

// Ученический вид страницы курса (course/view.php, формат topics): карточки активностей,
// прогресс, next-step (course-student-view).
$string['status_done'] = 'Выполнено';
$string['status_todo'] = 'Надо сделать';
$string['status_locked'] = 'Заблокировано';
$string['status_continue'] = 'Продолжить';
$string['status_open'] = 'Открыть';
$string['theme_done'] = 'Тема пройдена';
$string['course_done'] = 'Курс пройден!';
$string['course_progress_one'] = 'Пройдена {$a->done} тема из {$a->total}';
$string['course_progress_few'] = 'Пройдено {$a->done} темы из {$a->total}';
$string['course_progress_many'] = 'Пройдено {$a->done} тем из {$a->total}';
$string['course_encourage'] = 'Отличное начало! Продолжай в своем темпе.';
$string['continue_to'] = 'Продолжить: {$a}';
$string['section_progress'] = '{$a->done} из {$a->total}';
$string['section_progress_aria'] = 'Выполнено {$a->done} из {$a->total}';
$string['progress_course_name'] = 'Прогресс по курсу';
$string['progress_section_name'] = 'Прогресс по теме';
$string['type_material'] = 'Материал для чтения';
$string['type_audio'] = 'Аудиоматериал';
$string['type_video'] = 'Видео';
$string['type_quiz'] = 'Тест';
$string['type_task'] = 'Задание';
$string['type_cert'] = 'Сертификат';
$string['type_other'] = 'Активность';
// Уточнение под меткой типа активности: «Тест - 10 вопросов», «Задание - с проверкой».
$string['sub_quiz_one'] = '{$a} вопрос';
$string['sub_quiz_few'] = '{$a} вопроса';
$string['sub_quiz_many'] = '{$a} вопросов';
$string['sub_assign'] = 'с проверкой';
$string['lock_completion'] = 'Откроется, когда пройдешь «{$a}».';
$string['lock_date'] = 'Откроется {$a}.';
$string['lock_grade'] = 'Откроется, когда наберешь нужный балл.';
$string['lock_level'] = 'Откроется на твоем уровне.';
$string['lock_generic'] = 'Откроется позже - сначала выполни предыдущие задания.';

// Педагогский вид страницы курса (сигнал по классу).
$string['staff_done_label'] = 'сделали {$a->done} из {$a->total}';
$string['staff_grading_label'] = 'на проверке: {$a}';
$string['staff_stuck_label'] = 'застряли: {$a}';
$string['staff_section_progress_name'] = 'Прогресс класса по теме';
$string['staff_section_progress'] = 'прошли тему: {$a->done} из {$a->total}';
$string['staff_section_progress_aria'] = 'Прошли тему {$a->done} из {$a->total} учеников';
$string['staff_all_clear'] = 'Все работы проверены, класс движется';
$string['staff_attention_grading_one'] = '{$a} работа ждет проверки';
$string['staff_attention_grading_few'] = '{$a} работы ждут проверки';
$string['staff_attention_grading_many'] = '{$a} работ ждут проверки';
$string['staff_attention_stuck_one'] = '{$a} ученик застрял';
$string['staff_attention_stuck_few'] = '{$a} ученика застряли';
$string['staff_attention_stuck_many'] = '{$a} учеников застряли';

// Уровневые варианты активностей (педагогский вид страницы курса).
$string['level_name_1'] = 'Базовый';
$string['level_name_2'] = 'Стандартный';
$string['level_name_3'] = 'Продвинутый';
$string['level_name_other'] = 'Уровень {$a}';
$string['variant_group'] = 'для группы {$a}';
$string['variant_personal'] = 'для отдельного ученика';
$string['variant_audience_one'] = '{$a} ученик';
$string['variant_audience_few'] = '{$a} ученика';
$string['variant_audience_many'] = '{$a} учеников';
$string['variant_nobody'] = 'не видит никто';
$string['variant_hidden'] = 'скрыта от учеников';
$string['variant_label'] = '{$a->who} · {$a->state}';
$string['variant_orphans_one'] = '{$a} вариант тем не видит ни один ученик';
$string['variant_orphans_few'] = '{$a} варианта тем не видит ни один ученик';
$string['variant_orphans_many'] = '{$a} вариантов тем не видит ни один ученик';

// Хаб-страница курса ([[course-hub-design]]).
$string['hub_nav'] = 'УНИКС';
$string['hub_title'] = 'Инструменты курса';
$string['hub_back_to_course'] = '← В курс';
$string['hub_attention'] = 'Требует внимания';
$string['hub_nopermission'] = 'Недостаточно прав для просмотра инструментов курса.';
$string['hub_group_progress'] = 'Как идут дела';
$string['hub_group_setup'] = 'Настройка курса';
$string['hub_gradebook'] = 'Журнал курса';
$string['hub_gradebook_desc'] = 'оценки за тесты курса';
$string['hub_report'] = 'Отчет по курсу';
$string['hub_report_desc'] = 'группа риска и средние';
$string['hub_students'] = 'Учащиеся курса';
$string['hub_students_desc'] = 'состав класса и записи на курс';
$string['hub_levels'] = 'Адаптивные уровни';
$string['hub_levels_desc'] = 'текущий уровень каждого ученика';
$string['hub_umk'] = 'Сгенерировать УМК';
$string['hub_umk_desc'] = 'учебный материал с помощью ИИ';
$string['hub_codifier'] = 'Кодификатор';
$string['hub_codifier_desc'] = 'привязка активностей к элементам содержания';
$string['hub_diagnostic'] = 'Входная диагностика';
$string['hub_diagnostic_desc'] = 'тест, определяющий стартовый уровень';
$string['hub_exam'] = 'Итоговый экзамен';
$string['hub_exam_desc'] = 'итоговый тест курса и пересдачи';
$string['hub_milestones'] = 'Контрольные точки';
$string['hub_milestones_desc'] = 'промежуточная аттестация и сводка';
