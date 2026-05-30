<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * B3: диагностика пробелов (узел 15 диаграммы) - правило, без ИИ.
 *
 * По реальным попыткам тестов собирает, какие вопросы учащийся проваливает, и
 * группирует их по теме. «Тема» в наших курсах = сам тест (quiz): генерация УМК
 * кладёт все вопросы курса в ОДНУ question_category, поэтому категория вопросов
 * темы не различает - различает её тест («<Тема> - тест»).
 *
 * Источник - только ядровые таблицы question-движка (ничего не кешируем, схему
 * не меняем):
 *   quiz_attempts.uniqueid -> question_usages.id
 *   question_attempts.questionusageid = uniqueid (вопрос попытки: questionid, slot,
 *     responsesummary, rightanswer)
 *   последний question_attempt_steps (max sequencenumber) -> итоговый state/fraction.
 *
 * Учитываем ПОСЛЕДНЮЮ завершённую (finished) попытку каждого теста: это «текущие»
 * пробелы - пересдал верно, пробел снят. Охват - все тесты, которые проходил
 * учащийся (темы, итоговый, контрольные точки).
 *
 * Это аналитика, а не триггер цикла: ни уведомлений, ни записи в БД.
 */
class gap_manager {

    /**
     * Вопрос - «пробел», если его итоговое состояние оканчивается на wrong или
     * partial (gradedwrong/gradedpartial и ручные mangrwrong/mangrpartial). Это
     * покрывает «неверно» и «частично», не завязываясь на конкретный набор
     * автогрейд-состояний.
     */
    public static function is_gap_state(string $state): bool {
        return self::str_ends_with($state, 'wrong') || self::str_ends_with($state, 'partial');
    }

    /** PHP 7-совместимый str_ends_with (на случай старого PHP в XAMPP). */
    private static function str_ends_with(string $haystack, string $needle): bool {
        if (function_exists('str_ends_with')) {
            return \str_ends_with($haystack, $needle);
        }
        $len = strlen($needle);
        return $len === 0 || substr($haystack, -$len) === $needle;
    }

    /**
     * Пробелы учащегося, сгруппированные по теме (тесту).
     *
     * Возвращает только темы, где в последней завершённой попытке есть хотя бы
     * один ошибочный вопрос. Для каждой темы: курс, тест, всего/ошибок и список
     * самих ошибочных вопросов (что ответил учащийся).
     *
     * @return array<int,object> ключ = cmid; поля: cmid, course_id, course_name,
     *         quiz_name, total, wrong_count, questions(array из объектов
     *         {qname, response, state, label})
     */
    public static function student_gaps(int $userid): array {
        global $DB;

        // Шаг 1. Последняя завершённая попытка по каждому тесту учащегося.
        // attempt = MAX(attempt) среди finished-попыток того же теста.
        $attempts = $DB->get_records_sql(
            "SELECT qa.id AS attemptid, qa.uniqueid, qa.quiz, qa.timefinish,
                    cm.id AS cmid, c.id AS course_id,
                    c.fullname AS course_name, q.name AS quiz_name
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course} c ON c.id = q.course
               JOIN {modules} m ON m.name = 'quiz'
               JOIN {course_modules} cm
                    ON cm.instance = q.id AND cm.module = m.id
                   AND cm.course = q.course AND cm.deletioninprogress = 0
              WHERE qa.userid = :uid
                AND qa.state = 'finished'
                AND qa.attempt = (
                    SELECT MAX(qa2.attempt)
                      FROM {quiz_attempts} qa2
                     WHERE qa2.quiz = qa.quiz
                       AND qa2.userid = qa.userid
                       AND qa2.state = 'finished'
                )
              ORDER BY c.fullname, q.name",
            ['uid' => $userid]
        );
        if (empty($attempts)) {
            return [];
        }

        // uniqueid -> метаданные теста (для группировки результатов вопросов).
        $by_usage = [];
        foreach ($attempts as $a) {
            $by_usage[(int)$a->uniqueid] = $a;
        }

        // Шаг 2. Итоговое состояние каждого вопроса этих попыток одним запросом.
        // Последний шаг question_attempt_steps = max sequencenumber (уникальный
        // индекс questionattemptid+sequencenumber это гарантирует).
        [$in_sql, $in_params] = $DB->get_in_or_equal(array_keys($by_usage), SQL_PARAMS_NAMED, 'qu');
        $rows = $DB->get_records_sql(
            "SELECT qat.id AS qatid, qat.questionusageid, qat.slot,
                    qat.responsesummary, q.name AS qname,
                    qas.state
               FROM {question_attempts} qat
               JOIN {question} q ON q.id = qat.questionid
               JOIN {question_attempt_steps} qas ON qas.id = (
                       SELECT s.id
                         FROM {question_attempt_steps} s
                        WHERE s.questionattemptid = qat.id
                     ORDER BY s.sequencenumber DESC
                        LIMIT 1
                   )
              WHERE qat.questionusageid {$in_sql}
           ORDER BY qat.questionusageid, qat.slot",
            $in_params
        );

        // Шаг 3. Группировка по теме (тесту): всего вопросов, ошибки, перечень.
        $out = [];
        foreach ($rows as $r) {
            $usage = (int)$r->questionusageid;
            if (!isset($by_usage[$usage])) {
                continue;
            }
            $meta = $by_usage[$usage];
            $cmid = (int)$meta->cmid;

            if (!isset($out[$cmid])) {
                $out[$cmid] = (object)[
                    'cmid'        => $cmid,
                    'course_id'   => (int)$meta->course_id,
                    'course_name' => $meta->course_name,
                    'quiz_name'   => $meta->quiz_name,
                    'timefinish'  => (int)$meta->timefinish,
                    'total'       => 0,
                    'wrong_count' => 0,
                    'questions'   => [],
                ];
            }
            $out[$cmid]->total++;

            if (self::is_gap_state((string)$r->state)) {
                $out[$cmid]->wrong_count++;
                $out[$cmid]->questions[] = (object)[
                    'qname'    => $r->qname,
                    'response' => $r->responsesummary,
                    'state'    => (string)$r->state,
                ];
            }
        }

        // Оставляем только темы с пробелами (есть хотя бы один ошибочный вопрос).
        foreach ($out as $cmid => $t) {
            if ($t->wrong_count === 0) {
                unset($out[$cmid]);
            }
        }
        return $out;
    }

    /**
     * HTML-бейдж состояния ошибочного вопроса: «неверно» / «частично».
     */
    public static function state_html(string $state): string {
        if (self::str_ends_with($state, 'partial')) {
            return '<span class="badge badge-warning">частично</span>';
        }
        return '<span class="badge badge-danger">неверно</span>';
    }

    /**
     * Текст «ошибок: N из M» для шапки темы.
     */
    public static function summary_text(object $topic): string {
        return 'ошибок: ' . (int)$topic->wrong_count . ' из ' . (int)$topic->total;
    }
}
