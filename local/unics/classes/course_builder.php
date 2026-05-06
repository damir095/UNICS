<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class course_builder {

    /**
     * Добавить текстовую страницу (mod_page) в секцию курса.
     * Возвращает cmid.
     */
    public function add_text_page(int $course_id, int $section_num, string $title, string $content): int {
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

        return $this->attach_to_section($course_id, $section_num, 'page', $page->id);
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
     * Создать тест с вопросами, сгенерированными ИИ (Moodle 4.x).
     * $questions — массив из ai_generator::generate_quiz().
     * Возвращает cmid теста.
     */
    public function add_quiz_with_questions(
        int    $course_id,
        int    $section_num,
        string $title,
        array  $questions
    ): int {
        global $DB;

        $quiz_cmid = $this->add_quiz($course_id, $section_num, $title . ' — тест');
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
        $quiz_ctx   = \context_module::instance($quiz_cmid);
        $course_ctx = \context_course::instance($course_id);

        // Категория вопросов для курса
        $qcat = $DB->get_record('question_categories', ['contextid' => $course_ctx->id, 'parent' => 0]);
        if (!$qcat) {
            $qcat               = new \stdClass();
            $qcat->name         = get_string('defaultfor', 'question', '');
            $qcat->info         = '';
            $qcat->infoformat   = FORMAT_PLAIN;
            $qcat->contextid    = $course_ctx->id;
            $qcat->parent       = 0;
            $qcat->sortorder    = 999;
            $qcat->stamp        = make_unique_id_code();
            $qcat->id = $DB->insert_record('question_categories', $qcat);
        }

        $slot_num  = 0;
        $sumgrades = 0;

        foreach ($questions as $q) {
            $slot_num++;

            // question_bank_entries (Moodle 4.x)
            $qbe_id = null;
            if ($has_qbe) {
                $qbe = new \stdClass();
                $qbe->questioncategoryid = $qcat->id;
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
            $question->category             = $qcat->id;
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
                    'entryid'    => (int)$qbe_id,
                    'version'    => 1,
                    'questionid' => (int)$question->id,
                    'status'     => 'ready',
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

            // quiz_slots
            $slot_id = (int)$DB->insert_record('quiz_slots', (object)[
                'quizid'          => $quiz_id,
                'slot'            => $slot_num,
                'page'            => $slot_num,
                'requireprevious' => 0,
                'maxmark'         => 1.0,
            ]);

            // question_references (Moodle 4.x)
            // usingcontextid must be the QUIZ MODULE context — qbank_helper.php
            // filters by quizcontextid = context_module::instance(cmid)->id.
            if ($has_qref && $qbe_id) {
                $DB->insert_record('question_references', (object)[
                    'usingcontextid'      => (int)$quiz_ctx->id,
                    'component'           => 'mod_quiz',
                    'questionarea'        => 'slot',
                    'itemid'              => (int)$slot_id,
                    'questionbankentryid' => (int)$qbe_id,
                    'version'             => null,
                ]);
            }

            $sumgrades++;
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
     * $slides      — массив из ai_generator::generate_video_script().
     * $slide_audios — индексированный массив бинарных WAV-строк (по одной на слайд).
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
                ? '<span id="unics-audio-icon-' . $i . '" style="margin-left:8px;font-size:.8em;color:#FFAB91" title="Озвучка активна">🔊</span>'
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
<div id="unics-pres" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;max-width:860px;margin:0 auto;border:1px solid #FFCCBC;border-radius:10px;overflow:hidden;box-shadow:0 4px 18px rgba(230,81,0,.15)">
  <div style="background:#E65100;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center">
    <span style="font-size:1.1em;font-weight:700;letter-spacing:.01em">{$title_esc}</span>
    <span id="unics-counter" style="font-size:.9em;opacity:.85;white-space:nowrap;margin-left:12px">1 / {$total}</span>
  </div>
  <div style="min-height:420px;padding:32px 40px;background:#FFF8F4">
    <style>
      .unics-slide-title{color:#E65100;margin-top:0;margin-bottom:16px;font-size:1.5em;font-weight:700;line-height:1.3}
      .unics-slide-content{line-height:1.85;color:#263238;margin-bottom:20px;font-size:1.05em}
      .unics-kp{background:#FFF3E0;border-left:4px solid #E65100;padding:14px 18px;border-radius:6px;margin-top:4px}
      .unics-kp ul{margin:8px 0 0;padding-left:22px}
      .unics-kp li{margin-bottom:5px;color:#37474F;font-size:1em}
      .unics-dot{display:inline-block;width:11px;height:11px;border-radius:50%;background:#FFCCBC;margin:0 4px;cursor:pointer;transition:background .2s}
      .unics-dot.active{background:#E65100}
    </style>
    {$slides_html}
  </div>
  {$audio_html}
  <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 24px;background:#FFF3E0;border-top:1px solid #FFCCBC">
    <button id="unics-prev" onclick="unicsNav(-1)"
      style="padding:8px 22px;background:#E65100;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.95em;font-weight:600;transition:background .15s"
      onmouseover="this.style.background='#BF360C'" onmouseout="this.style.background='#E65100'">
      ← Назад
    </button>
    <div id="unics-dots"></div>
    <button id="unics-next" onclick="unicsNav(1)"
      style="padding:8px 22px;background:#E65100;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.95em;font-weight:600;transition:background .15s"
      onmouseover="this.style.background='#BF360C'" onmouseout="this.style.background='#E65100'">
      Далее →
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
        $idnumber = 'umk_s' . $mdl_user_id . '_c' . $course_id;

        $group = $DB->get_record('groups', ['courseid' => $course_id, 'idnumber' => $idnumber]);
        if (!$group) {
            $user = $DB->get_record('user', ['id' => $mdl_user_id]);
            $data             = new \stdClass();
            $data->courseid   = $course_id;
            $data->name       = 'УМК: ' . fullname($user);
            $data->idnumber   = $idnumber;
            $data->id = groups_create_group($data);
            $group = $DB->get_record('groups', ['id' => $data->id]);
        }

        if (!groups_is_member($group->id, $mdl_user_id)) {
            groups_add_member($group->id, $mdl_user_id);
        }

        $section = $DB->get_record('course_sections', ['course' => $course_id, 'section' => $section_num]);
        if (!$section) {
            return;
        }

        $DB->set_field('course_sections', 'availability', json_encode([
            'op'    => '&',
            'c'     => [['type' => 'group', 'id' => (int)$group->id]],
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
     * idnumber = umk_lvl{level}_c{course_id}_{hash(topic)} — гарантирует уникальность.
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
        $data->name     = mb_substr($topic, 0, 60) . ' — ' . $level_label;
        $data->idnumber = $idnumber;
        return (int)groups_create_group($data);
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
     * show:false — педагоги с capability ignoreavailabilityrestrictions видят всё.
     */
    public function restrict_activity_to_student_group(int $cmid, int $course_id, int $mdl_user_id): void {
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
            $data->id = groups_create_group($data);
            $group = $DB->get_record('groups', ['id' => $data->id]);
        }

        if (!groups_is_member($group->id, $mdl_user_id)) {
            groups_add_member($group->id, $mdl_user_id);
        }

        $DB->set_field('course_modules', 'availability', json_encode([
            'op'    => '&',
            'c'     => [['type' => 'group', 'id' => (int)$group->id]],
            'showc' => [false],
        ]), ['id' => $cmid]);
    }

    /**
     * Прикрепить экземпляр модуля к секции курса.
     * Если секции не существует — создаёт её.
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

        $cm           = new \stdClass();
        $cm->course   = $course_id;
        $cm->module   = $module->id;
        $cm->instance = $instance_id;
        $cm->section  = $section->id;
        $cm->visible  = 1;
        $cm->added    = time();
        $cm->id = $DB->insert_record('course_modules', $cm);

        $seq = array_filter(explode(',', $section->sequence ?? ''));
        $seq[] = $cm->id;
        $DB->set_field('course_sections', 'sequence', implode(',', $seq), ['id' => $section->id]);

        rebuild_course_cache($course_id, true);
        return $cm->id;
    }
}
