<?php
namespace local_unics\social;

defined('MOODLE_INTERNAL') || die();

/**
 * A4 фаза 2: заметки педагога с адресностью (audience) и бейджами «N новых».
 * [[teacher-notes-redesign]]
 *
 * Заметка - канал вокруг РЕБЁНКА (не предмета). Видимость определяется командой
 * ребёнка (teacher_student / методист орг. / админ) + семьёй, по кумулятивной
 * шкале audience: private < staff < student < family.
 *
 * Единственный источник правды по матрице видимости - этот класс. Страницы НЕ
 * хранят `if ($is_admin || $is_teacher)` для заметок, а спрашивают сервис.
 *
 * Матрица (кого видит заметка с данным audience):
 *   private(1): автор + админ
 *   staff(2):   + другие педагоги ученика, методист орг.
 *   student(3): + сам ученик
 *   family(4):  + родители ученика
 * Автор всегда видит свою заметку независимо от audience.
 *
 * Read-трекинг = «последний просмотр» (unics_comment_seen, одна строка на
 * зрителя-по-ученику). Бейдж = число видимых заметок с created_at > last_seen.
 */
class comment_manager {

    const AUDIENCE_PRIVATE = 1;
    const AUDIENCE_STAFF   = 2;
    const AUDIENCE_STUDENT = 3;
    const AUDIENCE_FAMILY  = 4;

    /** Порог видимости по «тиру» зрителя (минимальный audience, который он видит у чужих заметок). */
    const TIER_THRESHOLD = [
        'admin'   => self::AUDIENCE_PRIVATE, // видит всё, включая чужой private
        'staff'   => self::AUDIENCE_STAFF,
        'student' => self::AUDIENCE_STUDENT,
        'parent'  => self::AUDIENCE_FAMILY,
    ];

    // -----------------------------------------------------------------
    // Метки audience
    // -----------------------------------------------------------------

    /** Варианты для селектора в форме создания (значение => подпись). */
    public static function audience_options(): array {
        return [
            self::AUDIENCE_PRIVATE => 'Только мне (служебная запись)',
            self::AUDIENCE_STAFF   => 'Педагогам ученика и админу',
            self::AUDIENCE_STUDENT => 'Ученику и педагогам',
            self::AUDIENCE_FAMILY  => 'Семье - ученику и родителям',
        ];
    }

    public static function audience_label(int $audience): string {
        return self::audience_options()[$audience] ?? '-';
    }

    /** Короткий бейдж для отображения у заметки. */
    public static function audience_badge(int $audience): array {
        // [текст, bootstrap-класс]
        return [
            self::AUDIENCE_PRIVATE => ['Только мне', 'secondary'],
            self::AUDIENCE_STAFF   => ['Педагогам',  'info'],
            self::AUDIENCE_STUDENT => ['Ученику',    'primary'],
            self::AUDIENCE_FAMILY  => ['Семье',      'success'],
        ][$audience] ?? ['-', 'secondary'];
    }

    public static function audience_hint(int $audience): string {
        return [
            self::AUDIENCE_PRIVATE => 'Служебная запись - не видна ни ученику, ни родителям, ни другим педагогам.',
            self::AUDIENCE_STAFF   => 'Видна другим педагогам ученика и администратору. Ученик и родители не видят.',
            self::AUDIENCE_STUDENT => 'Видна ученику и педагогам. Родители не видят.',
            self::AUDIENCE_FAMILY  => 'Видна ученику и его родителям (и всем педагогам).',
        ][$audience] ?? '';
    }

    // -----------------------------------------------------------------
    // Роль зрителя относительно ученика
    // -----------------------------------------------------------------

    /**
     * Тир зрителя относительно ученика: 'admin' | 'staff' | 'student' | 'parent' | 'none'.
     * Зеркалит контроль доступа student_report + добавляет scope-админа в его скоупе.
     */
    public static function viewer_tier(int $student_id, int $viewer_id): string {
        global $DB;
        $student = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$student) {
            return 'none';
        }
        $ctx = \context_system::instance();

        // Системный администратор.
        if (has_capability('local/unics:manage', $ctx, $viewer_id)) {
            return 'admin';
        }
        // Региональный / районный администратор в своём скоупе - полный read как у админа.
        if (\local_unics_is_scoped_admin($viewer_id)
                && \local_unics\scope_checker::user_can_access_org($viewer_id, (int)$student->organization_id)) {
            return 'admin';
        }

        $is_teacher = has_capability('local/unics:viewstudents', $ctx, $viewer_id);
        if ($is_teacher) {
            // Методист организации - команда ребёнка своей организации.
            if (\local_unics_is_methodist($viewer_id)) {
                $rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $viewer_id]);
                $org = ($rec && $rec->organization_id) ? (int)$rec->organization_id : 0;
                if ($org > 0 && (int)$student->organization_id === $org) {
                    return 'staff';
                }
            } else {
                // Педагог - команда ребёнка по привязке teacher_student.
                $rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $viewer_id]);
                if ($rec && $DB->record_exists('unics_teacher_student',
                        ['teacher_id' => $rec->id, 'student_id' => $student_id])) {
                    return 'staff';
                }
            }
        }
        // Сам ученик.
        if ((int)$student->mdl_user_id === $viewer_id) {
            return 'student';
        }
        // Родитель ученика.
        if ($DB->record_exists('unics_parent_student',
                ['parent_mdl_user_id' => $viewer_id, 'student_id' => $student_id])) {
            return 'parent';
        }
        return 'none';
    }

    /** Может ли зритель создавать заметки об ученике (педагог/методист/админ). */
    public static function can_author(int $student_id, int $viewer_id): bool {
        return in_array(self::viewer_tier($student_id, $viewer_id), ['admin', 'staff'], true);
    }

    /** Видна ли конкретная заметка зрителю данного тира. */
    public static function is_visible(object $c, string $tier, int $viewer_id): bool {
        if ((int)$c->teacher_mdl_user_id === $viewer_id) {
            return true; // автор видит свою всегда
        }
        $thr = self::TIER_THRESHOLD[$tier] ?? null;
        return $thr !== null && (int)$c->audience >= $thr;
    }

    // -----------------------------------------------------------------
    // Выборка видимых заметок
    // -----------------------------------------------------------------

    /**
     * Видимые зрителю заметки об ученике с применением матрицы audience.
     *
     * @param array $filters cmid (int|null), type ('all'|'activity'|'general'),
     *              archived ('active'|'archived'|'all')
     * @return array строки unics_comments + lastname/firstname/middlename автора
     */
    public static function get_visible_for_student(int $student_id, int $viewer_id, array $filters = []): array {
        global $DB;
        $tier = self::viewer_tier($student_id, $viewer_id);
        if ($tier === 'none') {
            return [];
        }
        $thr = self::TIER_THRESHOLD[$tier];

        $where  = ['c.student_id = :sid'];
        $params = ['sid' => $student_id];

        // Автор видит свою при любом audience; остальные - по порогу тира.
        $where[] = '(c.audience >= :thr OR c.teacher_mdl_user_id = :viewer)';
        $params['thr']    = $thr;
        $params['viewer'] = $viewer_id;

        if (array_key_exists('cmid', $filters)) {
            if ($filters['cmid'] === null) {
                $where[] = 'c.cmid IS NULL';
            } else {
                $where[] = 'c.cmid = :cmid';
                $params['cmid'] = (int)$filters['cmid'];
            }
        }
        $type = $filters['type'] ?? 'all';
        if ($type === 'activity') {
            $where[] = 'c.cmid IS NOT NULL';
        } else if ($type === 'general') {
            $where[] = 'c.cmid IS NULL';
        }
        $arch = $filters['archived'] ?? 'active';
        if ($arch === 'active') {
            $where[] = 'c.archived_at IS NULL';
        } else if ($arch === 'archived') {
            $where[] = 'c.archived_at IS NOT NULL';
        }

        return $DB->get_records_sql(
            "SELECT c.*, u.lastname, u.firstname, u.middlename
               FROM {unics_comments} c
               JOIN {user} u ON u.id = c.teacher_mdl_user_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY c.created_at DESC",
            $params
        );
    }

    // -----------------------------------------------------------------
    // Архивация
    // -----------------------------------------------------------------

    /** Может ли зритель архивировать заметку (её автор или системный админ). */
    public static function can_archive(object $c, int $viewer_id): bool {
        if ((int)$c->teacher_mdl_user_id === $viewer_id) {
            return true;
        }
        return has_capability('local/unics:manage', \context_system::instance(), $viewer_id);
    }

    public static function set_archived(int $comment_id, bool $archived): void {
        global $DB;
        $DB->set_field('unics_comments', 'archived_at', $archived ? time() : null, ['id' => $comment_id]);
    }

    // -----------------------------------------------------------------
    // Read-трекинг (last-seen) и бейджи
    // -----------------------------------------------------------------

    /** Отметить, что зритель просмотрел заметки ученика сейчас (вызывать на странице показа). */
    public static function mark_seen(int $student_id, int $viewer_id): void {
        global $DB;
        $now = time();
        $rec = $DB->get_record('unics_comment_seen',
            ['student_id' => $student_id, 'mdl_user_id' => $viewer_id]);
        if ($rec) {
            $DB->set_field('unics_comment_seen', 'last_seen_at', $now, ['id' => $rec->id]);
        } else {
            $DB->insert_record('unics_comment_seen', (object)[
                'student_id'   => $student_id,
                'mdl_user_id'  => $viewer_id,
                'last_seen_at' => $now,
            ]);
        }
    }

    /**
     * Число новых (непрочитанных) заметок ученика для зрителя.
     * Видимые по audience, не свои, активные, созданные после последнего просмотра.
     */
    public static function count_unread(int $student_id, int $viewer_id): int {
        global $DB;
        $tier = self::viewer_tier($student_id, $viewer_id);
        if ($tier === 'none') {
            return 0;
        }
        $thr  = self::TIER_THRESHOLD[$tier];
        $last = (int)($DB->get_field('unics_comment_seen', 'last_seen_at',
            ['student_id' => $student_id, 'mdl_user_id' => $viewer_id]) ?: 0);

        return (int)$DB->count_records_sql(
            "SELECT COUNT(c.id)
               FROM {unics_comments} c
              WHERE c.student_id = :sid
                AND c.archived_at IS NULL
                AND c.teacher_mdl_user_id <> :viewer
                AND c.audience >= :thr
                AND c.created_at > :last",
            ['sid' => $student_id, 'viewer' => $viewer_id, 'thr' => $thr, 'last' => $last]
        );
    }

    // -----------------------------------------------------------------
    // Получатели уведомлений по audience
    // -----------------------------------------------------------------

    /** mdl_user_id педагогов команды ученика (привязка teacher_student). */
    public static function team_teacher_userids(int $student_id): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT DISTINCT t.mdl_user_id
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
              WHERE ts.student_id = :sid",
            ['sid' => $student_id]
        );
        return array_map(static fn($r) => (int)$r->mdl_user_id, $rows);
    }

    /** mdl_user_id родителей ученика. */
    public static function parent_userids(int $student_id): array {
        global $DB;
        $rows = $DB->get_records('unics_parent_student', ['student_id' => $student_id],
            '', 'id, parent_mdl_user_id');
        return array_map(static fn($r) => (int)$r->parent_mdl_user_id, $rows);
    }
}
