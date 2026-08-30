<?php
namespace local_unics\ai;

/**
 * ИИ-ассистент ученика ([[assistant-design]]).
 *
 * Функция была заявлена руководителем (задача 8) и СНЯТА решением пользователя 2026-05-15 с тремя
 * причинами: стоимость токенов, модерация ответов детям, объем. Возвращена 2026-08-30, и каждая из
 * трех причин закрыта отдельным предохранителем, а не обещанием:
 *
 *  - стоимость - дневной лимит обращений на ученика;
 *  - модерация - ВЕСЬ диалог пишется в журнал, педагог его читает;
 *  - объем - ассистент отвечает ТОЛЬКО по учебному тексту УМК этого ребенка. Он не энциклопедия:
 *    нет материала - честно говорит, что рассказать нечего, вместо выдумывания.
 *
 * Четвертый предохранитель добавлен сверх причин: ассистент не решает задания за ребенка. Вопрос,
 * дословно повторяющий задание из банка курса, получает отказ с подсказкой, на что опереться.
 *
 * @package local_unics
 */
class assistant {

    /** Сколько вопросов в сутки может задать один ученик. */
    public const DAILY_LIMIT = 30;

    /** Сколько знаков учебного текста уходит в промт. */
    public const SOURCE_LEN = 6000;

    /** Исходы, они же значения поля outcome. */
    public const ANSWERED = 'answered';
    public const NO_MATERIAL = 'no_material';
    public const LOOKS_LIKE_TASK = 'looks_like_task';
    public const LIMIT = 'limit';
    public const AI_FAILED = 'ai_failed';

    /** @var ai_generator */
    private ai_generator $gen;

    public function __construct(?ai_generator $gen = null) {
        $this->gen = $gen ?? new ai_generator();
    }

    /**
     * Ответить ребенку и записать обмен в журнал.
     *
     * Пишем ВСЕГДА, в том числе отказ: педагогу важно видеть не только ответы, но и то, о чем
     * ребенок спрашивал впустую - это и подсказка, чего не хватает в материале.
     *
     * @return object {outcome, answer, id}
     */
    public function ask(int $userid, int $courseid, string $question): object {
        global $DB;

        $question = trim($question);
        if ($question === '') {
            return $this->log($userid, $courseid, $question, null, self::NO_MATERIAL);
        }

        if ($this->asked_today($userid) >= self::DAILY_LIMIT) {
            return $this->log($userid, $courseid, $question, null, self::LIMIT);
        }

        $source = $this->course_material($userid, $courseid);
        if ($source === '') {
            return $this->log($userid, $courseid, $question, null, self::NO_MATERIAL);
        }

        if ($this->looks_like_task($courseid, $question)) {
            return $this->log($userid, $courseid, $question, null, self::LOOKS_LIKE_TASK);
        }

        try {
            $answer = $this->gen->generate_text($this->build_prompt($userid, $source, $question), 700);
        } catch (\Throwable $e) {
            // Отказ сети не должен выглядеть как ответ. Причину пишем в журнал: педагог увидит,
            // что ребенок спрашивал, а ассистент молчал не по своей воле.
            return $this->log($userid, $courseid, $question, null, self::AI_FAILED);
        }

        return $this->log($userid, $courseid, $question, trim($answer), self::ANSWERED);
    }

    /** Сколько вопросов ученик задал за последние сутки. */
    public function asked_today(int $userid): int {
        global $DB;
        return $DB->count_records_select('unics_assistant_message',
            'mdl_user_id = :uid AND timecreated > :since',
            ['uid' => $userid, 'since' => time() - DAYSECS]);
    }

    /**
     * Учебный текст УМК этого ребенка по этому курсу.
     *
     * Именно ЕГО УМК, а не любой: комплекты разведены по группам доступа, и чужой текст ребенку
     * не показывают ([[umk-per-student-design]]). Пустая строка означает «материала нет».
     */
    public function course_material(int $userid, int $courseid): string {
        global $DB;

        $sql = "SELECT p.content
                  FROM {unics_umk} u
                  JOIN {unics_umk_materials} m ON m.umk_id = u.id AND m.material_type = 1
                  JOIN {course_modules} cm ON cm.id = m.mdl_course_module_id
                  JOIN {page} p ON p.id = cm.instance
                 WHERE u.mdl_course_id = :cid
                   AND u.published_at IS NOT NULL
                   AND (u.mdl_group_id IS NULL OR u.mdl_group_id IN (
                           SELECT gm.groupid FROM {groups_members} gm WHERE gm.userid = :uid))
              ORDER BY u.id DESC";
        $rows = $DB->get_fieldset_sql($sql, ['cid' => $courseid, 'uid' => $userid]);

        $text = '';
        foreach ($rows as $row) {
            $text .= "\n\n" . html_to_text((string)$row, 0, false);
            if (\core_text::strlen($text) >= self::SOURCE_LEN) {
                break;
            }
        }
        return \core_text::substr(trim($text), 0, self::SOURCE_LEN);
    }

    /**
     * Похож ли вопрос на дословно списанное задание курса.
     *
     * Ассистент не решает за ребенка. Сравниваем нормализованный текст вопроса с текстами заданий
     * банка этого курса: списанное целиком задание совпадет, а свой вопрос про ту же тему - нет.
     * Это узкая проверка НАРОЧНО: широкая («вопрос про то же самое») запрещала бы учиться.
     */
    public function looks_like_task(int $courseid, string $question): bool {
        global $DB;

        $needle = question_sanity::normalize(html_to_text($question, 0, false));
        if (\core_text::strlen($needle) < 15) {
            // Короткое совпадение случайно: «сколько будет 2+2» встречается и в задании, и в
            // живом вопросе ребенка.
            return false;
        }

        $ctx = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$ctx) {
            return false;
        }
        $texts = $DB->get_fieldset_sql(
            "SELECT q.questiontext
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {context} c ON c.id = qc.contextid
              WHERE " . $DB->sql_like('c.path', ':path'),
            ['path' => $ctx->path . '%']);

        foreach ($texts as $text) {
            if (question_sanity::normalize(html_to_text((string)$text, 0, false)) === $needle) {
                return true;
            }
        }
        return false;
    }

    /** Промт: роль, материал, правила и сам вопрос. */
    public function build_prompt(int $userid, string $source, string $question): string {
        $adapt = '';
        $student = \local_unics\access::student_record($userid);
        if ($student) {
            $profile = profile_fingerprint::profile_of((int)$student->id, $this->gen);
            if ($profile !== null) {
                // Набор для СВЯЗНОГО ТЕКСТА: ответ ассистента - проза, а не тестовое задание.
                $adapt = $this->gen->adaptation_block(
                    $this->gen->build_criteria($profile), ai_generator::BLOCK_TEXT);
            }
        }

        return "Ты - помощник ученика в школьном курсе. Отвечай коротко, доброжелательно и на «ты».

{$adapt}
Материал курса, по которому отвечаешь:
---
{$source}
---

Правила:
- Отвечай ТОЛЬКО по материалу выше. Если в нем нет ответа, так и скажи: «В нашем материале об этом не написано» - и предложи спросить педагога. НЕ придумывай.
- НЕ решай задания и не давай готовых ответов на вопросы теста. Объясни, как подступиться, и на какое место в материале опереться.
- Одна мысль - одно короткое предложение. Не больше пяти предложений.
- Без списков команд, без кода, без ссылок наружу.

Вопрос ученика: {$question}";
    }

    /**
     * Чьи диалоги вправе читать этот сотрудник.
     *
     * Те же три режима, что у «Моих учащихся», и это не совпадение: журнал ассистента - такой же
     * персональный материал ребенка, и область видимости у него обязана быть той же, иначе
     * педагог соседней школы прочитает переписку чужого ученика.
     *
     * @return int[]|null список mdl_user_id; null означает «все» (полный админ)
     */
    public static function visible_student_userids(int $viewerid): ?array {
        global $DB;

        $ctx = \context_system::instance();
        $teacher = $DB->get_record('unics_teachers', ['mdl_user_id' => $viewerid], 'id');

        // Полный админ без педагогического профиля видит всех. Проверка manageorg идет ДО ветки
        // педагога: у методиста тоже есть запись в unics_teachers.
        if (!$teacher && has_capability('local/unics:manage', $ctx, $viewerid)) {
            return null;
        }

        if (has_capability('local/unics:manageorg', $ctx, $viewerid)) {
            [$where, $params] = \local_unics\identity\scope_checker::org_filter_sql($viewerid, 'o');
            return array_map('intval', $DB->get_fieldset_sql(
                "SELECT s.mdl_user_id
                   FROM {unics_students} s
                   JOIN {unics_organizations} o ON o.id = s.organization_id
                  WHERE {$where}", $params));
        }

        if ($teacher) {
            return array_map('intval', $DB->get_fieldset_sql(
                "SELECT s.mdl_user_id
                   FROM {unics_teacher_student} ts
                   JOIN {unics_students} s ON s.id = ts.student_id
                  WHERE ts.teacher_id = :tid", ['tid' => (int)$teacher->id]));
        }

        // Ни админ, ни методист, ни педагог - читать нечего.
        return [];
    }

    /** Запись в журнал и возврат исхода. */
    private function log(int $userid, int $courseid, string $question, ?string $answer,
                         string $outcome): object {
        global $DB;
        $id = (int)$DB->insert_record('unics_assistant_message', (object)[
            'mdl_user_id'   => $userid,
            'mdl_course_id' => $courseid > 0 ? $courseid : null,
            'question'      => $question,
            'answer'        => $answer,
            'outcome'       => $outcome,
            'timecreated'   => time(),
        ]);
        return (object)['id' => $id, 'outcome' => $outcome, 'answer' => $answer];
    }
}
