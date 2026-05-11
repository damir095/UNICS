<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class notification_manager {

    const TYPE_UMK_READY    = 1;  // учащемуся: УМК готов
    const TYPE_LOW_SCORE    = 2;  // педагогу: низкий балл
    const TYPE_NEW_ASSIGN   = 3;  // учащемуся: новое задание (зарезервировано)
    const TYPE_LEVEL_UP     = 4;  // учащемуся + родителям: уровень повышен
    const TYPE_LEVEL_DOWN   = 5;  // учащемуся + родителям: уровень понижен
    const TYPE_BADGE_EARNED = 6;  // учащемуся + родителям: получен значок
    const TYPE_NEW_COMMENT  = 7;  // учащемуся: педагог оставил заметку

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
            $body .= '<p>🪙 Вам начислено <strong>' . $points_awarded . ' баллов</strong> за новый материал!</p>';
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
        $icon        = $new_level > $old_level ? '📈' : '📉';
        $type        = $new_level > $old_level ? self::TYPE_LEVEL_UP : self::TYPE_LEVEL_DOWN;

        $subject = "{$icon} Ваш уровень сложности {$direction}";
        $body    = '<p>Ваш уровень обучения <strong>' . $direction . '</strong> '
                 . 'на основе ваших результатов:</p>'
                 . '<p>' . ($level_names[$old_level] ?? $old_level) . ' → '
                 . '<strong>' . ($level_names[$new_level] ?? $new_level) . '</strong></p>'
                 . '<p>Средний балл по последним тестам: <strong>' . round($avg, 1) . '%</strong>.</p>';

        if ($points_awarded > 0) {
            $body .= '<p>🪙 Вам начислено <strong>' . $points_awarded . ' баллов</strong>!</p>';
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
        $icon        = $new_level > $old_level ? '📈' : '📉';
        $type        = $new_level > $old_level ? self::TYPE_LEVEL_UP : self::TYPE_LEVEL_DOWN;

        $subject = "{$icon} Уровень учащегося {$direction}: {$student_name}";
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
        $subject = "{$badge_icon} Новый значок: «{$badge_name}»!";
        $body    = '<p>Поздравляем! Вы получили значок <strong>'
                 . $badge_icon . ' ' . htmlspecialchars($badge_name) . '</strong>.</p>';

        if ($points_awarded > 0) {
            $body .= '<p>🪙 Вам начислено <strong>' . $points_awarded . ' баллов</strong> за достижение!</p>';
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

        $subject = "{$badge_icon} {$student_name} получил значок «{$badge_name}»!";
        $body    = '<p>Ваш ребёнок <strong>' . htmlspecialchars($student_name) . '</strong>'
                 . ' получил новый значок <strong>'
                 . $badge_icon . ' ' . htmlspecialchars($badge_name) . '</strong>.</p>'
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
