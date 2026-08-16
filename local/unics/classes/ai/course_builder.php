<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

class course_builder {

    /**
     * Видимость (course_modules.visible), с которой создаются новые активности.
     * 1 = видима сразу (по умолчанию). 0 = скрыта (review-гейт УМК: ученик не видит
     * материал, пока педагог не опубликует). Меняется через set_default_visibility().
     */
    protected int $default_cm_visible = 1;

    /**
     * Задать видимость по умолчанию для последующих add_*-вызовов.
     * process_ai_queue ставит 0 перед сборкой УМК (черновик на проверке).
     */
    public function set_default_visibility(int $visible): void {
        $this->default_cm_visible = $visible ? 1 : 0;
    }

    /**
     * Показать/скрыть готовую активность (для публикации черновика УМК).
     * Использует нативный set_coursemodule_visible() — корректно обновляет
     * visibleold, видимость в ленте/календаре/оценках.
     */
    public function set_cm_visible(int $cmid, int $visible): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        set_coursemodule_visible($cmid, $visible ? 1 : 0);

        // set_coursemodule_visible не перестраивает кэш курса сам.
        $courseid = (int)$DB->get_field('course_modules', 'course', ['id' => $cmid]);
        if ($courseid) {
            rebuild_course_cache($courseid, true);
        }
    }

    /**
     * Добавить текстовую страницу (mod_page) в секцию курса.
     *
     * @param array $images ['имя файла' => бинарник] - иллюстрации лекции. В $content
     *        на них ссылаются через @@PLUGINFILE@@/<имя файла>; подстановку реального
     *        URL делает mod_page на выводе. Пустой бинарник пропускается: картинка не
     *        создалась, а материал важнее картинки ([[ai-lecture-images-design]]).
     * @return int cmid
     */
    public function add_text_page(int $course_id, int $section_num, string $title,
                                  string $content, array $images = []): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $page                = new \stdClass();
        $page->course        = $course_id;
        $page->name          = $title;
        $page->intro         = '';
        $page->introformat   = FORMAT_HTML;
        $page->content       = $content;
        $page->contentformat = FORMAT_MARKDOWN;
        $page->display       = 5;
        $page->timemodified  = time();
        $page->id = $DB->insert_record('page', $page);

        $cmid = $this->attach_to_section($course_id, $section_num, 'page', $page->id);

        if (!empty($images)) {
            $ctx = \context_module::instance($cmid);
            $fs  = get_file_storage();
            foreach ($images as $filename => $binary) {
                if ((string)$binary === '') {
                    continue;
                }
                try {
                    $fs->create_file_from_string([
                        'contextid'    => $ctx->id,
                        'component'    => 'mod_page',
                        'filearea'     => 'content',
                        'itemid'       => 0, // mod_page_pluginfile() читает область жестко с itemid 0.
                        'filepath'     => '/',
                        'filename'     => $filename,
                        'timecreated'  => time(),
                        'timemodified' => time(),
                    ], $binary);
                } catch (\Throwable $e) {
                    // Страница уже создана: падение на записи файла угробило бы весь УМК
                    // из-за одной картинки. Ссылка останется битой, зато материал выйдет.
                    mtrace('  [warn] Файл иллюстрации ' . $filename . ' не сохранен: '
                        . $e->getMessage());
                }
            }
        }

        return $cmid;
    }

    /**
     * Добавить аудиофайл (MP3) как ресурс (mod_resource).
     * Возвращает cmid.
     */
    public function add_audio_resource(int $course_id, int $section_num, string $title, string $audio_data, string $ext = 'mp3'): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $resource               = new \stdClass();
        $resource->course       = $course_id;
        $resource->name         = $title . ' (аудио)';
        $resource->intro        = '';
        $resource->introformat  = FORMAT_HTML;
        $resource->display      = 6; // display inline player
        $resource->timemodified = time();
        $resource->id = $DB->insert_record('resource', $resource);

        $cmid = $this->attach_to_section($course_id, $section_num, 'resource', $resource->id);

        $ctx = \context_module::instance($cmid);
        $fs  = get_file_storage();
        $fs->create_file_from_string([
            'contextid'    => $ctx->id,
            'component'    => 'mod_resource',
            'filearea'     => 'content',
            'itemid'       => 0,
            'filepath'     => '/',
            'filename'     => 'audio_umk_' . time() . '.' . $ext,
            'timecreated'  => time(),
            'timemodified' => time(),
        ], $audio_data);

        return $cmid;
    }

    /**
     * Категория банка для пула заданий: контекст КАТЕГОРИИ КУРСОВ, а не курса.
     *
     * Раньше вопросы жили в контексте курса (а еще раньше - модуля теста), и удаление курса
     * уносило их вместе с накопленной калибровкой: на стенде так осиротело 249 записей банка из
     * 284. Пул должен переживать и тест, и курс, поэтому живет уровнем выше.
     */
    public function pool_category(int $course_id): int {
        global $DB;
        $catid = (int)$DB->get_field('course', 'category', ['id' => $course_id]);
        $ctx   = \context_coursecat::instance($catid);

        // parent = 0 - служебная категория «top» самого Moodle, а не место для вопросов.
        // Интерфейс банка показывает только категории с parent <> 0
        // (question_bank_helper.php:250), поэтому вопросы, положенные прямо в top, не видны
        // НИГДЕ в интерфейсе. Для пула, который методист должен курировать годами, это
        // неприемлемо, поэтому заводим настоящую категорию под top (найдено ревью).
        $top = $DB->get_record('question_categories', ['contextid' => $ctx->id, 'parent' => 0]);
        if (!$top) {
            $top = (object)[
                'name'       => 'top',
                'info'       => '',
                'infoformat' => FORMAT_PLAIN,
                'contextid'  => $ctx->id,
                'parent'     => 0,
                'sortorder'  => 0,
                'stamp'      => make_unique_id_code(),
            ];
            $top->id = $DB->insert_record('question_categories', $top);
        }

        $name = 'УНИКС: пул заданий';
        $qcat = $DB->get_record('question_categories',
            ['contextid' => $ctx->id, 'parent' => $top->id, 'name' => $name]);
        if ($qcat) {
            return (int)$qcat->id;
        }
        try {
            return (int)$DB->insert_record('question_categories', (object)[
                'name'       => $name,
                'info'       => 'Задания, привязанные к элементам кодификатора. [[umk-item-pool-design]]',
                'infoformat' => FORMAT_PLAIN,
                'contextid'  => $ctx->id,
                'parent'     => (int)$top->id,
                'sortorder'  => 999,
                'stamp'      => make_unique_id_code(),
            ]);
        } catch (\Throwable $e) {
            // Параллельные воркеры (на стенде их три) могут создать категорию одновременно.
            // Перечитываем: две категории с одним именем развели бы пул надвое.
            $qcat = $DB->get_record('question_categories',
                ['contextid' => $ctx->id, 'parent' => $top->id, 'name' => $name], '*', IGNORE_MULTIPLE);
            if (!$qcat) {
                throw $e;
            }
            return (int)$qcat->id;
        }
    }

    /**
     * Создать тест с вопросами, сгенерированными ИИ (Moodle 4.x).
     * $questions - массив из ai_generator::generate_quiz().
     * $reuse_ids - готовые questionbankentryid из общего пула элемента: они ставятся в слоты
     * ПЕРЕД новыми и не создают ни одного вопроса заново ([[umk-item-pool-design]]).
     * Возвращает cmid теста.
     */
    public function add_quiz_with_questions(
        int    $course_id,
        int    $section_num,
        string $title,
        array  $questions,
        array  $reuse_ids = []
    ): int {
        global $DB;

        $quiz_cmid = $this->add_quiz($course_id, $section_num, $title . ' - тест');
        $quiz_id   = (int)$DB->get_field('course_modules', 'instance', ['id' => $quiz_cmid]);

        // В Moodle 4.x quiz требует хотя бы одной секции
        if (!$DB->record_exists('quiz_sections', ['quizid' => $quiz_id])) {
            $DB->insert_record('quiz_sections', (object)[
                'quizid'           => $quiz_id,
                'firstslot'        => 1,
                'heading'          => '',
                'shufflequestions' => 0,
            ]);
        }

        $dbman   = $DB->get_manager();
        $has_qbe  = $dbman->table_exists('question_bank_entries');
        $has_qv   = $dbman->table_exists('question_versions');
        $has_qref = $dbman->table_exists('question_references');

        // Moodle ищет question_references по контексту МОДУЛЯ теста, а не курса.
        $quiz_ctx = \context_module::instance($quiz_cmid);

        $qcat_id = $this->pool_category($course_id);

        $slot_num  = 0;
        $sumgrades = 0;

        // Слот ссылается на запись банка, а не хранит копию вопроса, поэтому переиспользуемые и
        // только что созданные задания ставятся в слоты одинаково.
        $put_in_slot = function (int $qbe_id) use ($quiz_id, $quiz_ctx, $has_qref, &$slot_num, &$sumgrades) {
            global $DB;
            $slot_num++;
            $slot_id = (int)$DB->insert_record('quiz_slots', (object)[
                'quizid'          => $quiz_id,
                'slot'            => $slot_num,
                'page'            => $slot_num,
                'requireprevious' => 0,
                'maxmark'         => 1.0,
            ]);
            if ($has_qref) {
                $DB->insert_record('question_references', (object)[
                    'usingcontextid'      => (int)$quiz_ctx->id,
                    'component'           => 'mod_quiz',
                    'questionarea'        => 'slot',
                    'itemid'              => $slot_id,
                    'questionbankentryid' => $qbe_id,
                    'version'             => null,
                ]);
            }
            $sumgrades++;
        };

        // Готовые задания идут первыми: они и есть общий измеритель.
        foreach ($reuse_ids as $reused) {
            $put_in_slot((int)$reused);
        }

        foreach ($questions as $q) {
            // question_bank_entries (Moodle 4.x)
            $qbe_id = null;
            if ($has_qbe) {
                $qbe = new \stdClass();
                $qbe->questioncategoryid = $qcat_id;
                $qbe->idnumber           = null;
                $qbe->ownerid            = null;
                $qbe->timecreated        = time();
                $qbe->timemodified       = time();
                $qbe->createdby          = null;
                $qbe->modifiedby         = null;
                $qbe->id = $DB->insert_record('question_bank_entries', $qbe);
                $qbe_id  = (int)$qbe->id;
            }

            // question
            $question                       = new \stdClass();
            $question->category             = $qcat_id;
            $question->parent               = 0;
            $question->name                 = mb_substr($q['text'], 0, 255);
            $question->questiontext         = '<p>' . s($q['text']) . '</p>';
            $question->questiontextformat   = FORMAT_HTML;
            $question->generalfeedback      = '';
            $question->generalfeedbackformat = FORMAT_HTML;
            $question->defaultmark          = 1;
            $question->penalty              = 0.3333333;
            $question->qtype                = 'multichoice';
            $question->length               = 1;
            $question->stamp                = make_unique_id_code();
            $question->timecreated          = time();
            $question->timemodified         = time();
            $question->createdby            = 0;
            $question->modifiedby           = 0;
            $question->id = (int)$DB->insert_record('question', $question);

            // question_versions (Moodle 4.x)
            if ($has_qv && $qbe_id) {
                $DB->insert_record('question_versions', (object)[
                    'questionbankentryid' => (int)$qbe_id,
                    'version'             => 1,
                    'questionid'          => (int)$question->id,
                    'status'              => 'ready',
                ]);
            }

            // question_answers
            foreach ($q['answers'] as $idx => $answer_text) {
                $DB->insert_record('question_answers', (object)[
                    'question'       => $question->id,
                    'answer'         => s($answer_text),
                    'answerformat'   => FORMAT_HTML,
                    'fraction'       => ($idx === (int)$q['correct']) ? 1.0 : 0.0,
                    'feedback'       => '',
                    'feedbackformat' => FORMAT_HTML,
                ]);
            }

            // Moodle 4.x: PK-колонка переименована question → questionid,
            // добавлена showstandardinstruction (default 1).
            $DB->execute(
                "INSERT INTO {qtype_multichoice_options}
                 (questionid, layout, single, shuffleanswers,
                  correctfeedback, correctfeedbackformat,
                  partiallycorrectfeedback, partiallycorrectfeedbackformat,
                  incorrectfeedback, incorrectfeedbackformat,
                  answernumbering, shownumcorrect, showstandardinstruction)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int)$question->id,
                    0, 1, 1,
                    'Верно!',   FORMAT_HTML,
                    '',         FORMAT_HTML,
                    'Неверно.', FORMAT_HTML,
                    'abc', 0, 1,
                ]
            );

            // Слот и question_references ставит замыкание выше: путь у переиспользованных и
            // только что созданных заданий обязан быть один, иначе они разъедутся.
            // usingcontextid там - контекст МОДУЛЯ теста: qbank_helper.php фильтрует по нему.
            if ($qbe_id) {
                $put_in_slot((int)$qbe_id);
            }
        }

        // sumgrades = сумма весов слотов; grade (макс. оценка теста) оставляем 10
        $DB->set_field('quiz', 'sumgrades', $sumgrades, ['id' => $quiz_id]);

        rebuild_course_cache($course_id, true);
        return $quiz_cmid;
    }

    /**
     * Добавить пустой тест (mod_quiz) в секцию курса.
     * Возвращает cmid.
     */
    public function add_quiz(
        int    $course_id,
        int    $section_num,
        string $title,
        int    $attempts  = 0,
        int    $timelimit = 0
    ): int {
        global $DB;

        $quiz                              = new \stdClass();
        $quiz->course                      = $course_id;
        $quiz->name                        = $title;
        $quiz->intro                       = '';
        $quiz->introformat                 = FORMAT_HTML;
        $quiz->timeopen                    = 0;
        $quiz->timeclose                   = 0;
        $quiz->timelimit                   = $timelimit;
        $quiz->overduehandling             = 'autosubmit';
        $quiz->graceperiod                 = 0;
        $quiz->preferredbehaviour          = 'deferredfeedback';
        $quiz->canredoquestions            = 0;
        $quiz->attemptonlast               = 0;
        $quiz->grademethod                 = 1;
        $quiz->decimalpoints               = 2;
        $quiz->questiondecimalpoints       = -1;
        $quiz->reviewattempt               = 69888;
        $quiz->reviewcorrectness           = 4352;
        $quiz->reviewmarks                 = 4352;
        $quiz->reviewspecificfeedback      = 4352;
        $quiz->reviewgeneralfeedback       = 4352;
        $quiz->reviewrightanswer           = 4352;
        $quiz->reviewoverallfeedback       = 4352;
        $quiz->questionsperpage            = 0;
        $quiz->navmethod                   = 'free';
        $quiz->shuffleanswers              = 1;
        $quiz->sumgrades                   = 0;
        $quiz->grade                       = 10;
        $quiz->timecreated                 = time();
        $quiz->timemodified                = time();
        $quiz->password                    = '';
        $quiz->subnet                      = '';
        $quiz->browsersecurity             = '-';
        $quiz->delay1                      = 0;
        $quiz->delay2                      = 0;
        $quiz->showuserpicture             = 0;
        $quiz->showblocks                  = 0;
        $quiz->completionattemptsexhausted = 0;
        $quiz->completionminattempts       = 0;
        $quiz->allowofflineattempts        = 0;
        $quiz->attempts                    = $attempts;
        $quiz->id = $DB->insert_record('quiz', $quiz);

        return $this->attach_to_section($course_id, $section_num, 'quiz', $quiz->id);
    }

    /**
     * Добавить задание (mod_assign) в секцию курса.
     * Возвращает cmid.
     */
    public function add_assignment(
        int    $course_id,
        int    $section_num,
        string $title,
        string $description,
        int    $duedate = 0
    ): int {
        global $DB;

        $assign                                    = new \stdClass();
        $assign->course                            = $course_id;
        $assign->name                              = $title;
        $assign->intro                             = '<p>' . s($description) . '</p>';
        $assign->introformat                       = FORMAT_HTML;
        $assign->alwaysshowdescription             = 1;
        $assign->nosubmissions                     = 0;
        $assign->submissiondrafts                  = 0;
        $assign->sendnotifications                 = 0;
        $assign->sendlatenotifications             = 0;
        $assign->sendstudentnotifications          = 1;
        $assign->duedate                           = $duedate;
        $assign->allowsubmissionsfromdate          = 0;
        $assign->grade                             = 100;
        $assign->timemodified                      = time();
        $assign->requiresubmissionstatement        = 0;
        $assign->completionsubmit                  = 0;
        $assign->cutoffdate                        = 0;
        $assign->gradingduedate                    = 0;
        $assign->teamsubmission                    = 0;
        $assign->requireallteammemberssubmit       = 0;
        $assign->teamsubmissiongroupingid          = 0;
        $assign->blindmarking                      = 0;
        $assign->hidegrader                        = 0;
        $assign->revealidentities                  = 0;
        $assign->attemptreopenmethod               = 'none';
        $assign->maxattempts                       = -1;
        $assign->markingworkflow                   = 0;
        $assign->markingallocation                 = 0;
        $assign->markinganonymous                  = 0;
        $assign->preventsubmissionnotingroup       = 0;
        $assign->activity                          = null;
        $assign->activityformat                    = 0;
        $assign->timelimit                         = 0;
        $assign->submissionattachments             = 0;
        $assign->gradepenalty                      = 0;
        $assign->id = $DB->insert_record('assign', $assign);

        // Enable online text submission
        $cfg             = new \stdClass();
        $cfg->assignment = $assign->id;
        $cfg->plugin     = 'onlinetext';
        $cfg->subtype    = 'assignsubmission';
        $cfg->name       = 'enabled';
        $cfg->value      = '1';
        $DB->insert_record('assign_plugin_config', $cfg);

        return $this->attach_to_section($course_id, $section_num, 'assign', $assign->id);
    }

    /**
     * Создать HTML5-видеопрезентацию из массива слайдов (mod_page).
     * $slides      - массив из ai_generator::generate_video_script().
     * $slide_audios - индексированный массив бинарных WAV-строк (по одной на слайд).
     *                 Пустая строка = нет аудио для этого слайда.
     * Возвращает cmid.
     */
    public function add_video_slideshow(
        int    $course_id,
        int    $section_num,
        string $title,
        array  $slides,
        array  $slide_audios = [],
        array  $slide_images = []
    ): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $total      = count($slides);
        $has_audio  = !empty($slide_audios) && count(array_filter($slide_audios, fn($a) => $a !== '')) > 0;
        $has_images = !empty($slide_images) && count(array_filter($slide_images, fn($img) => $img !== '')) > 0;

        // Строим HTML слайдов и hidden-аудиоэлементы
        $slides_html = '';
        $audio_html  = '';
        foreach ($slides as $i => $s) {
            $kp_html = '';
            if (!empty($s['key_points'])) {
                $kp_items = implode('', array_map(
                    fn($kp) => '<li>' . s((string)$kp) . '</li>',
                    $s['key_points']
                ));
                $kp_html = '<div class="unics-kp"><strong>Ключевые понятия:</strong><ul>'
                    . $kp_items . '</ul></div>';
            }

            $audio_icon = ($has_audio && !empty($slide_audios[$i]))
                ? '<span id="unics-audio-icon-' . $i . '" style="margin-left:8px;font-size:.8em;color:#F5845A" title="Озвучка активна">🔊</span>'
                : '';

            $img_html = '';
            if ($has_images && !empty($slide_images[$i])) {
                $img_b64  = base64_encode($slide_images[$i]);
                $img_html = '<div class="unics-slide-img"><img src="data:image/jpeg;base64,' . $img_b64
                    . '" alt="' . s($s['title']) . '" style="max-width:100%;max-height:280px;border-radius:8px;display:block;margin:0 auto 16px"></div>';
            }

            $display = $i === 0 ? 'block' : 'none';
            $slides_html .= '<div class="unics-slide" data-idx="' . $i . '" style="display:' . $display . '">'
                . '<h3 class="unics-slide-title">' . s($s['title']) . $audio_icon . '</h3>'
                . $img_html
                . '<div class="unics-slide-content">' . nl2br(s($s['content'])) . '</div>'
                . $kp_html
                . '</div>';

            if ($has_audio) {
                $wav = $slide_audios[$i] ?? '';
                $src = !empty($wav)
                    ? 'data:audio/wav;base64,' . base64_encode($wav)
                    : '';
                $audio_html .= '<audio id="unics-aud-' . $i . '" preload="auto" style="display:none">'
                    . ($src ? '<source src="' . $src . '" type="audio/wav">' : '')
                    . '</audio>';
            }
        }

        // JS: управление слайдами + автопроигрывание аудио
        $has_audio_js  = $has_audio ? 'true' : 'false';
        $autoplay_note = $has_audio
            ? '<p style="font-size:.85em;color:#888;margin:6px 0 0;text-align:center">🔊 Озвучка включена. Следующий слайд откроется автоматически по окончании аудио.</p>'
            : '';

        $title_esc = s($title);

        $html = <<<HTML
<div id="unics-pres" class="nolink" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;max-width:860px;margin:0 auto;border:1px solid #FCDDD6;border-radius:10px;overflow:hidden;box-shadow:0 4px 18px rgba(242,101,69,.15)">
  <div style="background:#F26545;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center">
    <span style="font-size:1.1em;font-weight:700;letter-spacing:.01em">{$title_esc}</span>
    <span id="unics-counter" style="font-size:.9em;opacity:.85;white-space:nowrap;margin-left:12px">1 / {$total}</span>
  </div>
  <div style="min-height:420px;padding:32px 40px;background:#F5F6F9">
    <style>
      .unics-slide-title{color:#F26545;margin-top:0;margin-bottom:16px;font-size:1.5em;font-weight:700;line-height:1.3}
      .unics-slide-content{line-height:1.85;color:#292F3B;margin-bottom:20px;font-size:1.05em}
      .unics-kp{background:#FEF3F0;border-left:4px solid #F26545;padding:14px 18px;border-radius:6px;margin-top:4px}
      .unics-kp ul{margin:8px 0 0;padding-left:22px}
      .unics-kp li{margin-bottom:5px;color:#292F3B;font-size:1em}
      .unics-dot{display:inline-block;width:11px;height:11px;border-radius:50%;background:#FCDDD6;margin:0 4px;cursor:pointer;transition:background .2s}
      .unics-dot.active{background:#F26545}
      #unics-pres a{color:inherit;text-decoration:none}
      #unics-pres a:hover{opacity:.75;text-decoration:underline}
    </style>
    {$slides_html}
  </div>
  {$audio_html}
  <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 24px;background:#FEF3F0;border-top:1px solid #FCDDD6">
    <button id="unics-prev" onclick="unicsNav(-1)"
      style="padding:8px 22px;background:#F26545;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.95em;font-weight:600;transition:background .15s"
      onmouseover="this.style.background='#D44E30'" onmouseout="this.style.background='#F26545'">
      Назад
    </button>
    <div id="unics-dots"></div>
    <button id="unics-next" onclick="unicsNav(1)"
      style="padding:8px 22px;background:#F26545;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.95em;font-weight:600;transition:background .15s"
      onmouseover="this.style.background='#D44E30'" onmouseout="this.style.background='#F26545'">
      Далее
    </button>
  </div>
  {$autoplay_note}
</div>
<script>
(function(){
  var slides   = document.querySelectorAll('#unics-pres .unics-slide');
  var counter  = document.getElementById('unics-counter');
  var dotsBox  = document.getElementById('unics-dots');
  var total    = slides.length;
  var cur      = 0;
  var hasAudio = {$has_audio_js};
  var autoTimer = null;

  // Точки-индикаторы
  for (var i = 0; i < total; i++) {
    var d = document.createElement('span');
    d.className = 'unics-dot' + (i === 0 ? ' active' : '');
    (function(idx){ d.onclick = function(){ unicsGo(idx); }; })(i);
    dotsBox.appendChild(d);
  }

  function stopCurrentAudio() {
    if (!hasAudio) return;
    var aud = document.getElementById('unics-aud-' + cur);
    if (aud) { aud.pause(); aud.currentTime = 0; }
    if (autoTimer) { clearTimeout(autoTimer); autoTimer = null; }
  }

  var started = false;

  function playSlideAudio(idx) {
    if (!hasAudio || !started) return;
    var aud = document.getElementById('unics-aud-' + idx);
    if (!aud || !aud.querySelector('source')) return;
    aud.play().catch(function(){});
    aud.onended = function() {
      if (idx < total - 1) {
        autoTimer = setTimeout(function(){ unicsGo(idx + 1); }, 1500);
      }
    };
  }

  function unicsGo(n) {
    stopCurrentAudio();
    slides[cur].style.display = 'none';
    dotsBox.querySelectorAll('.unics-dot')[cur].classList.remove('active');
    cur = Math.max(0, Math.min(total - 1, n));
    slides[cur].style.display = 'block';
    dotsBox.querySelectorAll('.unics-dot')[cur].classList.add('active');
    counter.textContent = (cur + 1) + ' / ' + total;
    document.getElementById('unics-prev').disabled = (cur === 0);
    document.getElementById('unics-next').disabled = (cur === total - 1);
    playSlideAudio(cur);
  }

  window.unicsNav = function(dir) { unicsGo(cur + dir); };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') unicsNav(1);
    if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   unicsNav(-1);
  });

  unicsGo(0);

  if (hasAudio) {
    var startFirst = function() {
      if (!started) {
        started = true;
        playSlideAudio(cur); // играем текущий слайд, а не hardcoded 0
      }
      document.removeEventListener('click', startFirst);
      document.removeEventListener('keydown', startFirst);
    };
    document.addEventListener('click', startFirst);
    document.addEventListener('keydown', startFirst);
  }
})();
</script>
HTML;

        $page                = new \stdClass();
        $page->course        = $course_id;
        $page->name          = $title . ' (видеопрезентация)';
        $page->intro         = '';
        $page->introformat   = FORMAT_HTML;
        $page->content       = $html;
        $page->contentformat = FORMAT_HTML;
        $page->display       = 5;
        $page->timemodified  = time();
        $page->id = $DB->insert_record('page', $page);

        return $this->attach_to_section($course_id, $section_num, 'page', $page->id);
    }

    /**
     * Ограничить видимость активности по уровню сложности (profile_field_unics_level).
     */
    public function set_cm_availability_level(int $cmid, int $level): void {
        global $DB;
        $DB->set_field('course_modules', 'availability',
            course_template::profile_level_availability($level),
            ['id' => $cmid]
        );
    }

    /**
     * Ограничить секцию курса так, чтобы её видел только указанный учащийся.
     * Педагоги видят всё автоматически через capability ignoreavailabilityrestrictions.
     */
    public function restrict_section_to_student_group(int $course_id, int $section_num, int $mdl_user_id): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Один постоянный idnumber на пару (курс, студент), чтобы не плодить группы
        $group_id = $this->get_or_create_student_group($course_id, $mdl_user_id);

        $section = $DB->get_record('course_sections', ['course' => $course_id, 'section' => $section_num]);
        if (!$section) {
            return;
        }

        $DB->set_field('course_sections', 'availability', json_encode([
            'op'    => '&',
            'c'     => [['type' => 'group', 'id' => $group_id]],
            'showc' => [false],
        ]), ['id' => $section->id]);

        rebuild_course_cache($course_id, true);
    }

    /**
     * Вернуть номер следующей свободной секции курса (max + 1).
     */
    public function get_next_section_num(int $course_id): int {
        global $DB;
        $max = (int)$DB->get_field_sql(
            "SELECT COALESCE(MAX(section), 0) FROM {course_sections} WHERE course = :course",
            ['course' => $course_id]
        );
        return $max + 1;
    }

    /**
     * Найти секцию курса по имени или создать новую.
     * Секции с одинаковым именем темы используются всеми учащимися совместно.
     * Возвращает номер секции (section).
     */
    public function get_or_create_topic_section(int $course_id, string $topic_name): int {
        global $DB;

        $existing = $DB->get_record_select(
            'course_sections',
            'course = :course AND name = :name',
            ['course' => $course_id, 'name' => $topic_name]
        );
        if ($existing) {
            return (int)$existing->section;
        }

        $section_num       = $this->get_next_section_num($course_id);
        $section           = new \stdClass();
        $section->course   = $course_id;
        $section->section  = $section_num;
        $section->name     = $topic_name;
        $section->summary  = '';
        $section->summaryformat = FORMAT_HTML;
        $section->sequence = '';
        $section->visible  = 1;
        $DB->insert_record('course_sections', $section);

        rebuild_course_cache($course_id, true);
        return $section_num;
    }

    /**
     * Создать или получить Moodle-группу для уровня сложности + тема.
     * idnumber = umk_lvl{level}_c{course_id}_{hash(topic)} - гарантирует уникальность.
     */
    public function get_or_create_level_group(int $course_id, int $level, string $topic): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        $level_label = $level_names[$level] ?? ('Ур.' . $level);
        $idnumber    = 'umk_lvl' . $level . '_c' . $course_id . '_' . substr(md5($topic), 0, 8);

        $group = $DB->get_record('groups', ['courseid' => $course_id, 'idnumber' => $idnumber]);
        if ($group) {
            return (int)$group->id;
        }

        $data           = new \stdClass();
        $data->courseid = $course_id;
        $data->name     = mb_substr($topic, 0, 60) . ' - ' . $level_label;
        $data->idnumber = $idnumber;
        return (int)groups_create_group($data);
    }

    /**
     * Группа доступа для комплекта, собранного по отпечатку профиля
     * ([[umk-per-student-design]], раздел 7).
     *
     * Ключ группы - пара «отпечаток + ТЕМА», как и у уровневой группы. Тема обязательна: без
     * нее группа переиспользуется между запусками, состав в ней НАКАПЛИВАЕТСЯ, и ученик прошлой
     * темы молча получает доступ к материалу следующей, для которого его не выбирали (найдено
     * ревью 2026-08-07). Экономия на числе групп такой цены не стоит.
     *
     * Имя не несет ни ФИО ребенка, ни диагноза: имя группы видно на странице курса. Нумерация
     * идет внутри темы, иначе номера росли бы через весь курс.
     */
    public function get_or_create_profile_group(int $course_id, string $profile_key, string $topic): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $topic_hash = substr(md5($topic), 0, 8);
        $idnumber   = 'umk_fp' . substr($profile_key, 0, 8) . '_c' . $course_id . '_' . $topic_hash;

        $group = $DB->get_record('groups', ['courseid' => $course_id, 'idnumber' => $idnumber]);
        if ($group) {
            return (int)$group->id;
        }

        $n = 1;
        foreach ($DB->get_records('groups', ['courseid' => $course_id], '', 'id, idnumber') as $g) {
            if (preg_match('/^umk_fp[0-9a-f]+_c\d+_' . $topic_hash . '$/', (string)$g->idnumber)) {
                $n++;
            }
        }

        $data           = new \stdClass();
        $data->courseid = $course_id;
        $data->name     = mb_substr($topic, 0, 40) . ' - вариант ' . $n;
        $data->idnumber = $idnumber;
        return (int)groups_create_group($data);
    }

    /**
     * Персональная группа выдачи «один курс - один ученик». Ученик сразу становится членом.
     * Имя несет ФИО, поэтому на странице курса оно не показывается
     * {@see \local_unics\output\course_variants::is_personal_umk_group()}.
     */
    public function get_or_create_student_group(int $course_id, int $mdl_user_id): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $idnumber = 'umk_s' . $mdl_user_id . '_c' . $course_id;
        $group = $DB->get_record('groups', ['courseid' => $course_id, 'idnumber' => $idnumber]);
        if (!$group) {
            $user             = $DB->get_record('user', ['id' => $mdl_user_id]);
            $data             = new \stdClass();
            $data->courseid   = $course_id;
            $data->name       = 'УМК: ' . fullname($user);
            $data->idnumber   = $idnumber;
            $data->id         = groups_create_group($data);
            $group            = $DB->get_record('groups', ['id' => $data->id]);
        }
        if (!groups_is_member($group->id, $mdl_user_id)) {
            groups_add_member($group->id, $mdl_user_id);
        }
        return (int)$group->id;
    }

    /**
     * Ограничить активность группой (group_id уже создан вызывающим кодом).
     */
    public function restrict_activity_to_group(int $cmid, int $group_id): void {
        global $DB;
        $DB->set_field('course_modules', 'availability', json_encode([
            'op'    => '&',
            'c'     => [['type' => 'group', 'id' => $group_id]],
            'showc' => [false],
        ]), ['id' => $cmid]);
    }

    /**
     * Ограничить одну активность (course_module) так, чтобы её видел только указанный учащийся.
     * Использует ту же группу-идентификатор, что и restrict_section_to_student_group.
     * show:false - педагоги с capability ignoreavailabilityrestrictions видят всё.
     */
    public function restrict_activity_to_student_group(int $cmid, int $course_id, int $mdl_user_id): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $group_id = $this->get_or_create_student_group($course_id, $mdl_user_id);

        $DB->set_field('course_modules', 'availability', json_encode([
            'op'    => '&',
            'c'     => [['type' => 'group', 'id' => $group_id]],
            'showc' => [false],
        ]), ['id' => $cmid]);
    }

    /**
     * B1/B8: включить отслеживание выполнения «по просмотру» для активности.
     * completion=AUTOMATIC + completionview=1 — Moodle отметит активность
     * выполненной, когда учащийся её откроет. Требует enablecompletion на курсе
     * (шаблон ставит 1). Нужно и для гейта теста (B1), и для критерия завершения (B8).
     */
    public function set_view_completion(int $cmid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, completion, completionview');
        if (!$cm) {
            return;
        }
        $DB->update_record('course_modules', (object)[
            'id'                       => $cmid,
            'completion'               => COMPLETION_TRACKING_AUTOMATIC,
            'completionview'           => 1,
            'completiongradeitemnumber' => null,
            'completionpassgrade'      => 0,
        ]);
        rebuild_course_cache((int)$cm->course, true);
    }

    /**
     * B1: гейт «материал освоен» — тест доступен, когда выполнены все материалы темы
     * И учащийся в группе своего уровня. Объединяет ограничение по группе (скрытое)
     * с условиями completion по каждому материалу (показываются как «недоступно, пока…»).
     *
     * @param int   $quiz_cmid      cmid теста
     * @param int   $group_id       группа уровня (как в restrict_activity_to_group)
     * @param int[] $material_cmids cmid материалов, которые надо выполнить (текст/аудио/видео)
     */
    public function gate_quiz_on_materials(int $quiz_cmid, int $group_id, array $material_cmids): void {
        global $DB;

        $conditions = [['type' => 'group', 'id' => $group_id]];
        $showc      = [false]; // группу скрываем полностью (чужой уровень не видит тест)

        foreach (array_unique(array_filter($material_cmids)) as $mcmid) {
            $conditions[] = ['type' => 'completion', 'cm' => (int)$mcmid, 'e' => 1]; // 1 = COMPLETION_COMPLETE
            $showc[]      = true; // показываем «доступно после прохождения материала»
        }

        $DB->set_field('course_modules', 'availability', json_encode([
            'op'    => '&',
            'c'     => $conditions,
            'showc' => $showc,
        ]), ['id' => $quiz_cmid]);

        $courseid = (int)$DB->get_field('course_modules', 'course', ['id' => $quiz_cmid]);
        if ($courseid) {
            rebuild_course_cache($courseid, true);
        }
    }

    /**
     * B8: пересобрать критерии завершения курса = «все активности с включённым
     * completion выполнены». Идемпотентно: удаляет прежние activity-критерии и
     * вставляет по одному на каждую такую активность. Агрегация по умолчанию = ALL.
     * Вызывать после создания/обновления активностей курса.
     */
    public function rebuild_course_completion_criteria(int $course_id): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');

        // Снять прежние activity-критерии (полная пересборка).
        $DB->delete_records('course_completion_criteria', [
            'course'       => $course_id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
        ]);

        // Активности курса с включённым отслеживанием выполнения.
        $cms = $DB->get_records_select('course_modules',
            'course = :course AND completion > 0 AND deletioninprogress = 0',
            ['course' => $course_id], 'id ASC', 'id');
        if (!$cms) {
            return;
        }

        $criterion = new \completion_criteria_activity();
        $data = new \stdClass();
        $data->id = $course_id; // update_config трактует ->id как courseid
        $data->criteria_activity = [];
        foreach ($cms as $cm) {
            $data->criteria_activity[(int)$cm->id] = 1;
        }
        $criterion->update_config($data);
    }

    /**
     * Прикрепить экземпляр модуля к секции курса.
     * Если секции не существует - создаёт её.
     */
    private function attach_to_section(int $course_id, int $section_num, string $mod_name, int $instance_id): int {
        global $DB;

        $module = $DB->get_record('modules', ['name' => $mod_name], '*', MUST_EXIST);

        // Убедимся что секция существует
        $section = $DB->get_record('course_sections', ['course' => $course_id, 'section' => $section_num]);
        if (!$section) {
            $section           = new \stdClass();
            $section->course   = $course_id;
            $section->section  = $section_num;
            $section->sequence = '';
            $section->visible  = 1;
            $section->id = $DB->insert_record('course_sections', $section);
        }

        $cm              = new \stdClass();
        $cm->course      = $course_id;
        $cm->module      = $module->id;
        $cm->instance    = $instance_id;
        $cm->section     = $section->id;
        $cm->visible     = $this->default_cm_visible;
        $cm->visibleold  = $this->default_cm_visible;
        $cm->added       = time();
        $cm->id = $DB->insert_record('course_modules', $cm);

        $seq = array_filter(explode(',', $section->sequence ?? ''));
        $seq[] = $cm->id;
        $DB->set_field('course_sections', 'sequence', implode(',', $seq), ['id' => $section->id]);

        rebuild_course_cache($course_id, true);
        return $cm->id;
    }
}
