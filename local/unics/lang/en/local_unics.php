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

// Privacy API.
$string['privacy:metadata:field:userid'] = 'ID of the user';
$string['privacy:metadata:field:student'] = 'Student profile ID';
$string['privacy:metadata:field:teacher'] = 'Teacher profile ID';
$string['privacy:metadata:field:createdby'] = 'Who performed/created the record';
$string['privacy:metadata:field:time'] = 'Timestamp';
$string['privacy:metadata:field:grade'] = 'Grade/performance indicator';
$string['privacy:metadata:unics_user_org'] = 'Binding of the user to a UNICS role and visibility scope';
$string['privacy:metadata:unics_user_org:unics_role'] = 'Applied role of the user';
$string['privacy:metadata:unics_user_org:organization_id'] = 'Scope organisation';
$string['privacy:metadata:unics_students'] = 'Student profile (special category of personal data: health information)';
$string['privacy:metadata:unics_student_category'] = 'Student categories (normalised storage)';
$string['privacy:metadata:unics_student_ovz'] = 'Student disability types (normalised storage; special category of personal data)';
$string['privacy:metadata:unics_students:category'] = 'Student category (SEN, family education, treatment, gifted)';
$string['privacy:metadata:unics_students:ovz_type'] = 'Type of health limitation';
$string['privacy:metadata:unics_students:diagnosed'] = 'Confirmed diagnosis flag';
$string['privacy:metadata:unics_students:special_needs'] = 'Special educational needs (text)';
$string['privacy:metadata:unics_students:birth_date'] = 'Date of birth';
$string['privacy:metadata:unics_students:class_number'] = 'School grade';
$string['privacy:metadata:unics_students:difficulty_level'] = 'Current difficulty level';
$string['privacy:metadata:unics_students:points'] = 'Points balance';
$string['privacy:metadata:unics_teachers'] = 'Teacher profile';
$string['privacy:metadata:unics_teachers:subjects'] = 'Subjects taught';
$string['privacy:metadata:unics_teachers:qualification'] = 'Qualification';
$string['privacy:metadata:unics_teacher_student'] = 'Teacher-student assignment';
$string['privacy:metadata:unics_parent_student'] = 'Parent-student link';
$string['privacy:metadata:unics_teacher_subject'] = 'Teacher subjects';
$string['privacy:metadata:unics_retakes'] = 'Automatic retakes of final quizzes';
$string['privacy:metadata:unics_topic_retries'] = 'Topic returns based on quiz results';
$string['privacy:metadata:unics_level_history'] = 'Difficulty level change history';
$string['privacy:metadata:unics_level_history:old_level'] = 'Previous level';
$string['privacy:metadata:unics_level_history:new_level'] = 'New level';
$string['privacy:metadata:unics_learning_path'] = 'Individual learning path';
$string['privacy:metadata:unics_learning_path:goal'] = 'Path goal';
$string['privacy:metadata:unics_skill_mastery'] = 'Mastery of content elements';
$string['privacy:metadata:unics_skill_mastery:score'] = 'Mastery score';
$string['privacy:metadata:unics_skill_mastery:theta'] = 'Ability estimate (IRT)';
$string['privacy:metadata:unics_mastery_history'] = 'Mastery recalculation history';
$string['privacy:metadata:unics_adaptive_suggestion'] = 'Adaptive suggestions about the student';
$string['privacy:metadata:unics_adaptive_suggestion:kind'] = 'Suggestion kind';
$string['privacy:metadata:unics_cat_session'] = 'Adaptive testing (CAT) sessions';
$string['privacy:metadata:unics_points_log'] = 'Points award/spend log';
$string['privacy:metadata:unics_points_log:points'] = 'Points change';
$string['privacy:metadata:unics_points_log:reason_text'] = 'Operation reason';
$string['privacy:metadata:unics_achievements'] = 'Achievement badges';
$string['privacy:metadata:unics_achievements:badge_type'] = 'Badge type';
$string['privacy:metadata:unics_purchases'] = 'Reward shop purchases';
$string['privacy:metadata:unics_purchases:item_id'] = 'Purchased item';
$string['privacy:metadata:unics_equipped'] = 'Equipped reward items (active title and future slots)';
$string['privacy:metadata:unics_equipped:slot'] = 'Equipment slot';
$string['privacy:metadata:unics_equipped:item_id'] = 'Equipped item';
$string['privacy:metadata:unics_umk_students'] = 'Learning materials assigned to the student';
$string['privacy:metadata:unics_comments'] = 'Teacher notes about students';
$string['privacy:metadata:unics_comments:body'] = 'Note text';
$string['privacy:metadata:unics_comment_seen'] = 'Note read marks';
$string['privacy:metadata:unics_notifications'] = 'User notifications';
$string['privacy:metadata:unics_notifications:subject'] = 'Notification subject';
$string['privacy:metadata:unics_notifications:body'] = 'Notification text';
$string['privacy:metadata:unics_stats_student_course'] = 'Aggregated learning statistics per course';
$string['privacy:metadata:unics_reports'] = 'Generated reports';
$string['privacy:metadata:unics_audit_log'] = 'Action audit log (subject is anonymised on data deletion)';
$string['privacy:metadata:unics_audit_log:action'] = 'Performed action';
$string['privacy:metadata:unics_audit_log:ip_address'] = 'IP address';
$string['privacy:metadata:unics_ai_queue'] = 'AI generation queue (transient student references)';
$string['privacy:metadata:unics_ai_queue:student_ids'] = 'Students the generation was requested for';
$string['privacy:metadata:gigachat'] = 'External service GigaChat (Sber): generation and checking of learning materials';
$string['privacy:metadata:gigachat:profile'] = 'De-identified profile parameters (category, level, grade) used to adapt material';
$string['privacy:metadata:gigachat:texts'] = 'Texts of student works sent for checking';
$string['privacy:metadata:salutespeech'] = 'External service SaluteSpeech (Sber): speech synthesis';
$string['privacy:metadata:salutespeech:texts'] = 'Material texts sent for voicing';
$string['privacy:export:profile'] = 'Profile and roles';
$string['privacy:export:links'] = 'Participant links';
$string['privacy:export:learning'] = 'Learning process';
$string['privacy:export:motivation'] = 'Motivation';
$string['privacy:export:communication'] = 'Communication';
$string['privacy:export:audit'] = 'Action audit';

// События (этап 2.4 аудита).
$string['eventpointsawarded'] = 'Points awarded';
$string['eventpointsspent'] = 'Points spent';
$string['eventlevelchanged'] = 'Student level changed';
$string['eventumkpublished'] = 'UMK published';

// События аудита админ-мутаций (этап 4.4).
$string['eventusercreated'] = 'User created';
$string['eventuserupdated'] = 'User updated';
$string['eventteacherstudentassigned'] = 'Teacher assigned to student';
$string['eventparentstudentassigned'] = 'Parent assigned to student';
$string['eventorganizationcreated'] = 'Organization created';
$string['eventorganizationupdated'] = 'Organization updated';
$string['eventorganizationdeleted'] = 'Organization deleted';
$string['eventteacherstudentunassigned'] = 'Teacher unassigned from student';
$string['eventparentstudentunassigned'] = 'Parent unassigned from student';

// Ученический вид страницы курса (course/view.php, формат topics): карточки активностей,
// прогресс, next-step (course-student-view). Значения русские - как принято в плагине.
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
$string['type_material'] = 'Материал для чтения';
$string['type_quiz'] = 'Тест';
$string['type_task'] = 'Задание';
$string['type_cert'] = 'Сертификат';
$string['type_other'] = 'Активность';
$string['lock_completion'] = 'Откроется, когда пройдешь «{$a}».';
$string['lock_date'] = 'Откроется {$a}.';
$string['lock_grade'] = 'Откроется, когда наберешь нужный балл.';
$string['lock_level'] = 'Откроется на твоем уровне.';
$string['lock_generic'] = 'Откроется позже - сначала выполни предыдущие задания.';
