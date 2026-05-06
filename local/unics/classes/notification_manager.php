<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class notification_manager {

    const TYPE_UMK_READY  = 1;
    const TYPE_LOW_SCORE  = 2;
    const TYPE_NEW_ASSIGN = 3;

    /**
     * Уведомление учащемуся: УМК готов.
     */
    public static function notify_umk_ready(
        int    $student_mdl_user_id,
        string $umk_title,
        string $course_name,
        int    $level
    ): void {
        $level_names = [1 => 'базовый', 2 => 'стандартный', 3 => 'продвинутый'];
        $lvl = $level_names[$level] ?? 'стандартный';

        $subject = "Готов учебный материал: {$umk_title}";
        $body    = '<p>Ваш учебный материал <strong>' . htmlspecialchars($umk_title) . '</strong>'
                 . ' (уровень: ' . $lvl . ') добавлен в курс'
                 . ' <strong>' . htmlspecialchars($course_name) . '</strong>.</p>'
                 . '<p>Войдите в курс, чтобы приступить к изучению.</p>';

        self::send($student_mdl_user_id, $subject, $body, self::TYPE_UMK_READY);
    }

    /**
     * Уведомление педагогу: низкий балл учащегося.
     * Не дублируется, если такое уведомление уже отправлялось за последние 24 часа.
     */
    public static function notify_low_score(
        int    $teacher_mdl_user_id,
        string $student_name,
        float  $avg_score,
        int    $student_unics_id
    ): void {
        global $DB;

        // Антиспам: не более одного уведомления о низком балле на учащегося в 24 часа
        $since = time() - 86400;
        $subject_prefix = 'Низкий балл учащегося:';
        $already_sent = $DB->record_exists_sql(
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

    /**
     * Базовый метод отправки. Использует Moodle message_send() и пишет в журнал.
     */
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

        $msg                     = new \core\message\message();
        $msg->component          = 'local_unics';
        $msg->name               = 'unics_notification';
        $msg->userfrom           = $from;
        $msg->userto             = $to;
        $msg->subject            = $subject;
        $msg->fullmessage        = strip_tags($body);
        $msg->fullmessageformat  = FORMAT_HTML;
        $msg->fullmessagehtml    = $body;
        $msg->smallmessage       = $subject;
        $msg->notification       = 1;

        $sent = 0;
        try {
            message_send($msg);
            $sent = 1;
        } catch (\Throwable $e) {
            // Не блокируем основной процесс — просто фиксируем в журнале как неотправленное
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
