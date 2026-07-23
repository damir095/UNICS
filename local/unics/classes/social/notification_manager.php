<?php
namespace local_unics\social;

defined('MOODLE_INTERNAL') || die();

class notification_manager {

    const TYPE_UMK_READY    = 1;  // учащемуся: УМК готов
    const TYPE_LOW_SCORE    = 2;  // педагогу: низкий балл
    const TYPE_NEW_ASSIGN   = 3;  // учащемуся: новое задание (зарезервировано)
    const TYPE_LEVEL_UP     = 4;  // учащемуся + родителям: уровень повышен
    const TYPE_LEVEL_DOWN   = 5;  // учащемуся + родителям: уровень понижен
    const TYPE_BADGE_EARNED = 6;  // учащемуся + родителям: получен значок
    const TYPE_NEW_COMMENT  = 7;  // учащемуся: педагог оставил заметку
    const TYPE_UMK_REVIEW   = 8;  // педагогу: УМК сгенерирован, ждёт проверки/публикации
    const TYPE_RETAKE       = 9;  // педагогу: учащийся провалил итоговый — нужна пересдача
    const TYPE_TOPIC_RETRY  = 10; // педагогу + учащемуся: провален тест темы, нужно повторить тему (B2)
    const TYPE_ADAPTIVE_SUGGESTION = 11; // педагогу: адаптивное предложение ждёт решения (S2)

    // ----------------------------------------------------------------
    // Уведомление учащемуся: УМК готов
    // ----------------------------------------------------------------
    public static function notify_umk_ready(
        int    $student_mdl_user_id,
        string $umk_title,
        string $course_name,
        int    $level,
        int    $points_awarded = 0
    ): void {
        $level_names = [1 => 'базовый', 2 => 'стандартный', 3 => 'продвинутый'];
        $lvl = $level_names[$level] ?? 'стандартный';

        $subject = "Готов учебный материал: {$umk_title}";
        $body    = '<p>Ваш учебный материал <strong>' . htmlspecialchars($umk_title) . '</strong>'
                 . ' (уровень: ' . $lvl . ') добавлен в курс'
                 . ' <strong>' . htmlspecialchars($course_name) . '</strong>.</p>'
                 . '<p>Войдите в курс, чтобы приступить к изучению.</p>';

        if ($points_awarded > 0) {
            $body .= '<p>Вам начислено <strong>' . $points_awarded . ' баллов</strong> за новый материал!</p>';
        }

        self::send($student_mdl_user_id, $subject, $body, self::TYPE_UMK_READY);
    }

    // ----------------------------------------------------------------
    // Уведомление педагогу: низкий балл учащегося (антиспам 24ч)
    // ----------------------------------------------------------------
    public static function notify_low_score(
        int    $teacher_mdl_user_id,
        string $student_name,
        float  $avg_score,
        int    $student_unics_id
    ): void {
        global $DB;

        $since          = time() - 86400;
        $subject_prefix = 'Низкий балл учащегося:';
        $already_sent   = $DB->record_exists_sql(
            "SELECT 1 FROM {unics_notifications}
              WHERE mdl_user_id = :uid
                AND notif_type  = :type
                AND subject     LIKE :subj
                AND created_at  > :since",
            [
                'uid'   => $teacher_mdl_user_id,
                'type'  => self::TYPE_LOW_SCORE,
                'subj'  => $DB->sql_like_escape($subject_prefix) . '%',
                'since' => $since,
            ]
        );
        if ($already_sent) {
            return;
        }

        $pct     = round($avg_score, 1);
        $subject = "Низкий балл учащегося: {$student_name}";
        $body    = '<p>Учащийся <strong>' . htmlspecialchars($student_name) . '</strong>'
                 . ' имеет низкий средний балл: <strong>' . $pct . '%</strong>.</p>'
                 . '<p>Рекомендуется проверить материалы и при необходимости'
                 . ' скорректировать уровень сложности в профиле учащегося.</p>';

        self::send($teacher_mdl_user_id, $subject, $body, self::TYPE_LOW_SCORE);
    }

    // ----------------------------------------------------------------
    // Уведомление об изменении уровня
    // ----------------------------------------------------------------

    /**
     * Уведомление учащемуся об изменении его уровня.
     */
    public static function notify_level_changed_student(
        int    $student_mdl_user_id,
        int    $old_level,
        int    $new_level,
        float  $avg,
        int    $points_awarded = 0
    ): void {
        $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        $direction   = $new_level > $old_level ? 'повышен' : 'понижен';
        $type        = $new_level > $old_level ? self::TYPE_LEVEL_UP : self::TYPE_LEVEL_DOWN;

        $subject = "Ваш уровень сложности {$direction}";
        $body    = '<p>Ваш уровень обучения <strong>' . $direction . '</strong> '
                 . 'на основе ваших результатов:</p>'
                 . '<p>' . ($level_names[$old_level] ?? $old_level) . ' → '
                 . '<strong>' . ($level_names[$new_level] ?? $new_level) . '</strong></p>'
                 . '<p>Средний балл по последним тестам: <strong>' . round($avg, 1) . '%</strong>.</p>';

        if ($points_awarded > 0) {
            $body .= '<p>Вам начислено <strong>' . $points_awarded . ' баллов</strong>!</p>';
        }

        self::send($student_mdl_user_id, $subject, $body, $type);
    }

    /**
     * Уведомление родителям об изменении уровня их ребёнка.
     */
    public static function notify_level_changed_parents(
        array  $parent_mdl_user_ids,
        string $student_name,
        int    $old_level,
        int    $new_level
    ): void {
        if (empty($parent_mdl_user_ids)) {
            return;
        }

        $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        $direction   = $new_level > $old_level ? 'повышен' : 'понижен';
        $type        = $new_level > $old_level ? self::TYPE_LEVEL_UP : self::TYPE_LEVEL_DOWN;

        $subject = "Уровень учащегося {$direction}: {$student_name}";
        $body    = '<p>Уровень вашего ребёнка <strong>' . htmlspecialchars($student_name) . '</strong>'
                 . ' автоматически <strong>' . $direction . '</strong>:</p>'
                 . '<p>' . ($level_names[$old_level] ?? $old_level) . ' → '
                 . '<strong>' . ($level_names[$new_level] ?? $new_level) . '</strong></p>'
                 . '<p>Это происходит автоматически по результатам тестов.</p>';

        foreach ($parent_mdl_user_ids as $parent_uid) {
            self::send((int)$parent_uid, $subject, $body, $type);
        }
    }

    // ----------------------------------------------------------------
    // Уведомление о получении значка
    // ----------------------------------------------------------------

    /**
     * Уведомление учащемуся о новом значке.
     */
    public static function notify_badge_earned_student(
        int    $student_mdl_user_id,
        string $badge_icon,
        string $badge_name,
        int    $points_awarded = 0
    ): void {
        $subject = "Новый значок: «{$badge_name}»!";
        $body    = '<p>Поздравляем! Вы получили значок <strong>'
                 . htmlspecialchars($badge_name) . '</strong>.</p>';

        if ($points_awarded > 0) {
            $body .= '<p>Вам начислено <strong>' . $points_awarded . ' баллов</strong> за достижение!</p>';
        }

        self::send($student_mdl_user_id, $subject, $body, self::TYPE_BADGE_EARNED);
    }

    /**
     * Уведомление родителям о новом значке ребёнка.
     */
    public static function notify_badge_earned_parents(
        array  $parent_mdl_user_ids,
        string $student_name,
        string $badge_icon,
        string $badge_name
    ): void {
        if (empty($parent_mdl_user_ids)) {
            return;
        }

        $subject = "{$student_name} получил значок «{$badge_name}»!";
        $body    = '<p>Ваш ребенок <strong>' . htmlspecialchars($student_name) . '</strong>'
                 . ' получил новый значок <strong>'
                 . htmlspecialchars($badge_name) . '</strong>.</p>'
                 . '<p>Поздравьте его!</p>';

        foreach ($parent_mdl_user_ids as $parent_uid) {
            self::send((int)$parent_uid, $subject, $body, self::TYPE_BADGE_EARNED);
        }
    }

    // ----------------------------------------------------------------
    // Уведомление учащемуся: педагог оставил заметку
    // ----------------------------------------------------------------
    public static function notify_new_comment(
        int    $student_mdl_user_id,
        string $teacher_name,
        string $context_label = ''
    ): void {
        $subject = "Новая заметка от педагога: {$teacher_name}";
        $body    = '<p>Педагог <strong>' . htmlspecialchars($teacher_name) . '</strong>'
                 . ' оставил новую заметку'
                 . ($context_label ? ' к «' . htmlspecialchars($context_label) . '»' : '')
                 . '.</p>'
                 . '<p>Войдите в портал УНИКС, чтобы прочитать.</p>';

        self::send($student_mdl_user_id, $subject, $body, self::TYPE_NEW_COMMENT);
    }

    /**
     * Уведомление родителям ученика о новой заметке педагога.
     */
    public static function notify_new_comment_parents(
        array  $parent_mdl_user_ids,
        string $teacher_name,
        string $student_name,
        string $context_label = ''
    ): void {
        if (empty($parent_mdl_user_ids)) {
            return;
        }

        $subject = "Новая заметка о ребёнке: {$student_name}";
        $body    = '<p>Педагог <strong>' . htmlspecialchars($teacher_name) . '</strong>'
                 . ' оставил новую заметку о вашем ребёнке <strong>'
                 . htmlspecialchars($student_name) . '</strong>'
                 . ($context_label ? ' к «' . htmlspecialchars($context_label) . '»' : '')
                 . '.</p>'
                 . '<p>Войдите в портал УНИКС, чтобы прочитать.</p>';

        foreach ($parent_mdl_user_ids as $parent_uid) {
            self::send((int)$parent_uid, $subject, $body, self::TYPE_NEW_COMMENT);
        }
    }

    // ----------------------------------------------------------------
    // Уведомление педагогу: УМК сгенерирован и ждёт проверки (review-гейт)
    // ----------------------------------------------------------------
    public static function notify_umk_review(
        int    $teacher_mdl_user_id,
        string $umk_title,
        string $course_name,
        int    $level
    ): void {
        $level_names = [1 => 'базовый', 2 => 'стандартный', 3 => 'продвинутый'];
        $lvl = $level_names[$level] ?? 'стандартный';

        $subject = "УМК на проверке: {$umk_title}";
        $body    = '<p>Сгенерирован учебный материал <strong>' . htmlspecialchars($umk_title) . '</strong>'
                 . ' (уровень: ' . $lvl . ') в курсе'
                 . ' <strong>' . htmlspecialchars($course_name) . '</strong>.</p>'
                 . '<p>Материал скрыт от учащихся. Проверьте его и нажмите'
                 . ' <strong>«Опубликовать»</strong> в истории генерации УМК, чтобы открыть доступ.</p>';

        self::send($teacher_mdl_user_id, $subject, $body, self::TYPE_UMK_REVIEW);
    }

    // ----------------------------------------------------------------
    // Уведомление педагогу: провал итогового экзамена → нужна пересдача (B7)
    // ----------------------------------------------------------------
    public static function notify_retake_needed(
        int    $teacher_mdl_user_id,
        string $student_name,
        string $course_name,
        string $quiz_name,
        float  $grade,
        float  $grademax
    ): void {
        $pct = $grademax > 0 ? round($grade / $grademax * 100, 1) : 0;

        $subject = "Нужна пересдача: {$student_name}";
        $body    = '<p>Учащийся <strong>' . htmlspecialchars($student_name) . '</strong>'
                 . ' не сдал итоговый экзамен <strong>' . htmlspecialchars($quiz_name) . '</strong>'
                 . ' в курсе <strong>' . htmlspecialchars($course_name) . '</strong>.</p>'
                 . '<p>Результат: <strong>' . rtrim(rtrim(number_format($grade, 2, '.', ''), '0'), '.')
                 . ' из ' . rtrim(rtrim(number_format($grademax, 2, '.', ''), '0'), '.')
                 . '</strong> (' . $pct . '%).</p>'
                 . '<p>Рекомендуется назначить пересдачу и при необходимости дополнительное обучение.</p>';

        self::send($teacher_mdl_user_id, $subject, $body, self::TYPE_RETAKE);
    }

    // ----------------------------------------------------------------
    // Уведомление педагогу: провал теста темы → нужно повторить тему (B2)
    // ----------------------------------------------------------------
    public static function notify_topic_retry_teacher(
        int    $teacher_mdl_user_id,
        string $student_name,
        string $course_name,
        string $quiz_name,
        float  $grade,
        float  $grademax
    ): void {
        $pct = $grademax > 0 ? round($grade / $grademax * 100, 1) : 0;

        $subject = "Нужно повторить тему: {$student_name}";
        $body    = '<p>Учащийся <strong>' . htmlspecialchars($student_name) . '</strong>'
                 . ' не прошёл тест темы <strong>' . htmlspecialchars($quiz_name) . '</strong>'
                 . ' в курсе <strong>' . htmlspecialchars($course_name) . '</strong>.</p>'
                 . '<p>Результат: <strong>' . rtrim(rtrim(number_format($grade, 2, '.', ''), '0'), '.')
                 . ' из ' . rtrim(rtrim(number_format($grademax, 2, '.', ''), '0'), '.')
                 . '</strong> (' . $pct . '%).</p>'
                 . '<p>Рекомендуется предложить учащемуся повторить материалы темы'
                 . ' и пройти тест заново.</p>';

        self::send($teacher_mdl_user_id, $subject, $body, self::TYPE_TOPIC_RETRY);
    }

    // ----------------------------------------------------------------
    // Уведомление учащемуся: провал теста темы → повтори тему (B2)
    // ----------------------------------------------------------------
    public static function notify_topic_retry_student(
        int    $student_mdl_user_id,
        string $course_name,
        string $quiz_name,
        float  $grade,
        float  $grademax
    ): void {
        $pct = $grademax > 0 ? round($grade / $grademax * 100, 1) : 0;

        $subject = "Повторите тему: {$quiz_name}";
        $body    = '<p>Тест <strong>' . htmlspecialchars($quiz_name) . '</strong>'
                 . ' в курсе <strong>' . htmlspecialchars($course_name) . '</strong>'
                 . ' пока не пройден.</p>'
                 . '<p>Ваш результат: <strong>' . $pct . '%</strong>.</p>'
                 . '<p>Повторите материалы темы и пройдите тест заново -'
                 . ' так вы лучше усвоите материал.</p>';

        self::send($student_mdl_user_id, $subject, $body, self::TYPE_TOPIC_RETRY);
    }

    // ----------------------------------------------------------------
    // Уведомление педагогу: адаптивное предложение ждёт решения (S2)
    // ----------------------------------------------------------------
    public static function notify_adaptive_suggestion(
        int    $teacher_mdl_user_id,
        string $student_name,
        string $kind_label
    ): void {
        $subject = "Адаптивное предложение: {$student_name}";
        $body    = '<p>Для учащегося <strong>' . htmlspecialchars($student_name) . '</strong>'
                 . ' сформировано предложение: <strong>' . htmlspecialchars($kind_label) . '</strong>.</p>'
                 . '<p>Откройте портал УНИКС, чтобы рассмотреть и принять или отклонить.'
                 . ' Если не рассмотреть, предложение применится автоматически по истечении срока.</p>';

        self::send($teacher_mdl_user_id, $subject, $body, self::TYPE_ADAPTIVE_SUGGESTION);
    }

    // ----------------------------------------------------------------
    // Уведомление учащемуся: педагог вручную добавил новое задание (этап 6.1
    // роадмапа, [[new-assignment-notification-design]]). Материалы УМК создаются
    // скрытыми (visible=0) и публикуются отдельным путем (см. notify_umk_ready) -
    // сюда не попадают, т.к. триггер смотрит только на СРАЗУ видимые модули.
    // ----------------------------------------------------------------

    /**
     * Точка входа из обсервера course_module_created. Фильтрует по видимости и
     * типу модуля (quiz/assign), уведомляет всех активных учащихся курса.
     */
    public static function notify_new_assign_for_module(int $cmid): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid],
            'id, course, module, instance, visible');
        if (!$cm || !$cm->visible) {
            return;
        }

        $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);
        $type_labels = ['quiz' => 'Тест', 'assign' => 'Задание'];
        if (!isset($type_labels[$modname])) {
            return;
        }

        $activity_name = $DB->get_field($modname, 'name', ['id' => $cm->instance]);
        $course_name   = (string)$DB->get_field('course', 'fullname', ['id' => $cm->course]);
        if ($activity_name === false || $activity_name === null) {
            return;
        }

        // Активные учащиеся курса: строка в unics_students (не архивная) +
        // активная запись на курс. Без учета раздельных групп/доступности (v1).
        $student_uids = $DB->get_fieldset_sql(
            "SELECT DISTINCT u.id
               FROM {user} u
               JOIN {unics_students} s ON s.mdl_user_id = u.id
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid
                AND ue.status = 0
                AND u.deleted = 0
                AND s.archived_at IS NULL",
            ['courseid' => $cm->course]
        );

        foreach ($student_uids as $uid) {
            self::notify_new_assign((int)$uid, (string)$activity_name,
                $type_labels[$modname], $course_name, $cmid);
        }
    }

    /**
     * Собственно отправка уведомления одному учащемуся.
     */
    public static function notify_new_assign(
        int    $student_mdl_user_id,
        string $activity_name,
        string $type_label,
        string $course_name,
        int    $cmid
    ): void {
        $subject = "Новое задание: {$activity_name}";
        $body    = '<p>' . htmlspecialchars($type_label) . ' <strong>'
                 . htmlspecialchars($activity_name) . '</strong> добавлено в курс'
                 . ' <strong>' . htmlspecialchars($course_name) . '</strong>.</p>'
                 . '<p>Войдите в курс, чтобы посмотреть.</p>';

        self::send($student_mdl_user_id, $subject, $body, self::TYPE_NEW_ASSIGN);
    }

    // ----------------------------------------------------------------
    // Базовый метод отправки
    // ----------------------------------------------------------------
    public static function send(
        int    $to_mdl_user_id,
        string $subject,
        string $body,
        int    $notif_type = self::TYPE_UMK_READY
    ): void {
        global $DB;

        $to = $DB->get_record('user', ['id' => $to_mdl_user_id, 'deleted' => 0]);
        if (!$to) {
            return;
        }

        $from = \core_user::get_noreply_user();

        $msg                    = new \core\message\message();
        $msg->component         = 'local_unics';
        $msg->name              = 'unics_notification';
        $msg->userfrom          = $from;
        $msg->userto            = $to;
        $msg->subject           = $subject;
        $msg->fullmessage       = strip_tags($body);
        $msg->fullmessageformat = FORMAT_HTML;
        $msg->fullmessagehtml   = $body;
        $msg->smallmessage      = $subject;
        $msg->notification      = 1;

        $sent = 0;
        try {
            message_send($msg);
            $sent = 1;
        } catch (\Throwable $e) {
            // Нефатально
            debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
        }

        $DB->insert_record('unics_notifications', (object)[
            'mdl_user_id' => $to_mdl_user_id,
            'notif_type'  => $notif_type,
            'subject'     => mb_substr($subject, 0, 200),
            'body'        => $body,
            'sent'        => $sent,
            'created_at'  => time(),
        ]);
    }
}
