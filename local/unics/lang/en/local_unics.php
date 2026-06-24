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
$string['class_number']          = 'Класс';
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

// Задачи
$string['task_calibrate_irt'] = 'Калибровка параметров заданий (IRT)';

// CAT (Компьютерное адаптивное тестирование)
$string['cat_disabled'] = 'Адаптивная проверка отключена администратором.';
$string['cat_no_items'] = 'По этой теме пока нет подготовленных вопросов.';
$string['cat_service_down'] = 'Адаптивная проверка временно недоступна. Попробуйте позже или пройдите обычный тест.';
