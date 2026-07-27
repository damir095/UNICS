<?php
namespace local_unics;

/**
 * Смоук-тест mustache-шаблона отчета по учащемуся (2.5 аудита, слайс 2):
 * рендер local_unics/student_report с полным фикстурным контекстом (все
 * опциональные части разом) - шаблон валиден, маркеры секций и экранирование
 * на месте; минимальный контекст не рисует staff-части.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class student_report_template_test extends \advanced_testcase {

    /** Полный контекст: staff-вид со всеми опциональными частями. */
    private function full_context(): array {
        return [
            'toolbar_buttons' => [
                ['url' => '#', 'label' => 'Мои учащиеся', 'cls' => 'btn btn-outline-secondary btn-sm'],
                ['url' => '#', 'label' => 'Значки достижений', 'cls' => 'btn btn-outline-warning btn-sm'],
                ['url' => '#', 'label' => 'ИИ-проверка ответов', 'cls' => 'btn btn-outline-primary btn-sm'],
                ['url' => '#', 'label' => 'Сводный отчёт по организации', 'cls' => 'btn btn-outline-info btn-sm'],
                ['url' => '#', 'label' => 'Элементы содержания', 'cls' => 'btn btn-outline-primary btn-sm'],
            ],
            'card' => [
                'fio'          => 'Алексеева Полина & <тест>',
                'class_str'    => '8 «Б» класс',
                'staff_fields' => [
                    'cat_label'   => 'ОВЗ',
                    'ovz_label'   => 'нарушение зрения & <овз>',
                    'level_label' => 'Стандартный',
                ],
                'avg_badge_class' => 'warning',
                'avg_text'        => '2.8/5',
                'org_name'        => 'МАОУ СОШ №9 & <орг>',
                'email'           => 'uch@demo.unics.local',
            ],
            'path' => [
                'title'    => 'Образовательный маршрут',
                'has_path' => [
                    'done'    => 1,
                    'total'   => 3,
                    'current' => ['title' => 'Признаки & <шаг>'],
                ],
                'open_url' => '#',
                'builder'  => ['url' => '#', 'label' => 'Редактировать маршрут'],
            ],
            'chart' => [
                'title' => 'Динамика успеваемости',
                'html'  => '<div class="chart-area">CHARTMARK</div>',
            ],
            'grades' => [
                'rows' => [
                    [
                        'course'      => 'География. 7 класс',
                        'type_label'  => 'Тест',
                        'name'        => 'Нефть - тест & <имя>',
                        'raw_score'   => '6.5 / 10',
                        'badge_class' => 'warning',
                        'score_text'  => '3.3/5',
                        'date'        => '10.06.2026',
                        'note_btn'    => ['url' => '#', 'label' => '💬 2',
                                          'cls' => 'btn btn-sm btn-outline-info'],
                        'notes_row'   => ['notes' => [
                            ['author' => 'Лебедев Сергей', 'audience_label' => 'всем',
                             'audience_class' => 'info', 'date' => '10.06.2026',
                             'body' => 'Заметка & <xss>'],
                        ]],
                    ],
                    [
                        'course'      => 'География. 7 класс',
                        'type_label'  => 'Задание',
                        'name'        => 'Нефть - задание',
                        'raw_score'   => '64 / 100',
                        'badge_class' => 'danger',
                        'score_text'  => '1/5',
                        'date'        => '-',
                        'note_badge'  => ['count' => 1],
                    ],
                ],
            ],
            'milestones' => [
                'rows' => [
                    ['course' => 'География. 7 класс', 'name' => 'КТ 1 & <кт>',
                     'status_html' => '<span class="badge badge-success">пройдена</span>',
                     'grade_html'  => '7.0 / 10.0', 'date' => '12.06.2026'],
                ],
            ],
            'retries' => [
                'rows' => [
                    ['course' => 'География. 7 класс', 'name' => 'Части света - тест',
                     'grade_html' => '2,0 / 10,0 <span class="text-muted small">(порог 5,0)</span>',
                     'date' => '12.06.2026'],
                ],
            ],
            'gaps' => [
                'rows' => [
                    ['course' => 'География. 7 класс', 'name' => 'Нефть - тест',
                     'summary' => 'ошибок: 2 из 5',
                     'questions' => [
                        ['state_html' => '<span class="badge badge-danger">неверно</span>',
                         'qname' => 'Вопрос & <q>', 'has_response' => true,
                         'response' => 'ответ & <ответ>'],
                        ['state_html' => '<span class="badge badge-warning">частично</span>',
                         'qname' => 'Вопрос без ответа', 'has_response' => false,
                         'response' => null],
                     ]],
                ],
            ],
            'courses' => [
                'count' => 2,
                'rows'  => [
                    ['fullname' => 'География. 7 класс', 'date' => '10.06.2026',
                     'notes_btn' => ['url' => '#']],
                    ['fullname' => 'Математика. 7 класс', 'date' => '-'],
                ],
            ],
            'umk' => [
                'count' => 1,
                'rows'  => [
                    ['title' => 'Части света & <умк>', 'topic' => 'Части света',
                     'level' => 'Стандартный', 'course' => 'География. 7 класс',
                     'status_tone' => 'success', 'status_label' => 'Готов',
                     'date' => '12.06.2026'],
                ],
            ],
            'notes' => [
                'cards' => [
                    ['author' => 'Лебедев Сергей', 'audience_label' => 'родителям',
                     'audience_class' => 'warning', 'date' => '11.06.2026',
                     'body' => "Общая заметка & <script>alert(1)</script>\nвторая строка"],
                ],
                'all_link' => ['url' => '#', 'label' => 'Все комментарии и добавить новый →'],
            ],
        ];
    }

    public function test_full_context_renders_all_sections(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/student_report', $this->full_context());

        // Тулбар и карточка.
        $this->assertStringContainsString('Мои учащиеся', $html);
        $this->assertStringContainsString('ИИ-проверка ответов', $html);
        $this->assertStringContainsString('Сводный отчёт по организации', $html);
        $this->assertStringContainsString('Алексеева Полина &amp; &lt;тест&gt;', $html);
        $this->assertStringContainsString('Категория:', $html);
        $this->assertStringContainsString('нарушение зрения &amp; &lt;овз&gt;', $html);
        $this->assertStringContainsString('Уровень:', $html);
        $this->assertStringContainsString('Email:', $html);
        $this->assertStringContainsString('badge badge-warning', $html);

        // Маршрут: прогресс + обе кнопки.
        $this->assertStringContainsString('Образовательный маршрут', $html);
        $this->assertStringContainsString('Прогресс: <strong>1</strong> из <strong>3</strong> шагов.', $html);
        $this->assertStringContainsString('Признаки &amp; &lt;шаг&gt;', $html);
        $this->assertStringContainsString('Открыть маршрут', $html);
        $this->assertStringContainsString('Редактировать маршрут', $html);

        // График - пре-рендер ядра проходит без экранирования.
        $this->assertStringContainsString('Динамика успеваемости', $html);
        $this->assertStringContainsString('<div class="chart-area">CHARTMARK</div>', $html);

        // Таблица результатов: обе строки, кнопка/бейдж заметок, inline-заметка.
        $this->assertStringContainsString('Результаты тестов и заданий', $html);
        $this->assertStringContainsString('Нефть - тест &amp; &lt;имя&gt;', $html);
        $this->assertStringContainsString('💬 2', $html);
        $this->assertStringContainsString('💬 1', $html);
        $this->assertStringContainsString('unics-teacher-note', $html);
        $this->assertStringContainsString('Заметка &amp; &lt;xss&gt;', $html);

        // Контрольные точки / повторения / пробелы: пре-рендер бейджей цел.
        $this->assertStringContainsString('Контрольные точки', $html);
        $this->assertStringContainsString('КТ 1 &amp; &lt;кт&gt;', $html);
        $this->assertStringContainsString('<span class="badge badge-success">пройдена</span>', $html);
        $this->assertStringContainsString('Темы для повторения', $html);
        $this->assertStringContainsString('(порог 5,0)</span>', $html);
        $this->assertStringContainsString('Пробелы', $html);
        $this->assertStringContainsString('ошибок: 2 из 5', $html);
        $this->assertStringContainsString('<span class="badge badge-danger">неверно</span>', $html);
        $this->assertStringContainsString('- ответ: ответ &amp; &lt;ответ&gt;', $html);
        // У вопроса без ответа хвост «- ответ:» не рисуется.
        $this->assertSame(1, substr_count($html, '- ответ:'));

        // Курсы (счетчик в заголовке), УМК, общие заметки.
        $this->assertStringContainsString('Записан на курсы (2)', $html);
        $this->assertStringContainsString('Заметки по курсу', $html);
        $this->assertStringContainsString('История генерации УМК (1)', $html);
        $this->assertStringContainsString('Части света &amp; &lt;умк&gt;', $html);
        $this->assertStringContainsString('Общие заметки педагога', $html);
        $this->assertStringContainsString('Все комментарии и добавить новый →', $html);

        // Экранирование: пользовательский текст не протек тегами.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        // Единая система таблиц (задача 2 tables-redesign): все 6 таблиц отчета
        // (тесты/задания, контрольные точки, повторения, пробелы, курсы, УМК)
        // обернуты в table-responsive и несут table-striped table-hover unics-table;
        // старая разметка table-sm/table-bordered нигде не осталась.
        $this->assertSame(6, substr_count($html, 'table-responsive'));
        $this->assertSame(6, substr_count($html, 'table table-striped table-hover unics-table'));
        $this->assertStringNotContainsString('table-sm', $html);
        $this->assertStringNotContainsString('table-bordered', $html);
        // Числовые столбцы (балл/%): Баллы+Балл (тесты/задания, 2 строки), Балл
        // (контрольные точки), Результат (повторения) - заголовки + ячейки.
        $this->assertSame(10, substr_count($html, 'unics-num'));
    }

    /** Минимальный контекст = детский вид: staff-части не рисуются. */
    public function test_child_context_hides_staff_parts(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_unics/student_report', [
            'toolbar_buttons' => [
                ['url' => '#', 'label' => 'Значки достижений', 'cls' => 'btn btn-outline-warning btn-sm'],
            ],
            'card' => [
                'fio'             => 'Алексеева Полина',
                'class_str'       => '8 «Б» класс',
                'avg_badge_class' => 'warning',
                'avg_text'        => '2.8/5',
                'org_name'        => 'МАОУ СОШ №9',
            ],
            'path' => ['title' => 'Мой маршрут', 'no_path' => true, 'open_url' => '#'],
            'grades'     => ['empty' => true],
            'milestones' => ['empty' => true],
            'retries'    => ['empty' => true],
            'gaps'       => ['empty' => true],
            'courses'    => ['count' => 0, 'empty' => true],
            'notes'      => ['empty' => true],
        ]);

        $this->assertStringContainsString('Мой маршрут', $html);
        $this->assertStringContainsString('Маршрут пока не составлен.', $html);
        $this->assertStringContainsString('Тесты и задания ещё не оценены.', $html);
        $this->assertStringContainsString('Записан на курсы (0)', $html);
        $this->assertStringContainsString('Комментариев ещё нет.', $html);

        // Staff-части и график отсутствуют.
        $this->assertStringNotContainsString('Категория:', $html);
        $this->assertStringNotContainsString('Уровень:', $html);
        $this->assertStringNotContainsString('Email:', $html);
        $this->assertStringNotContainsString('История генерации УМК', $html);
        $this->assertStringNotContainsString('chart-area', $html);
        $this->assertStringNotContainsString('Редактировать маршрут', $html);
        $this->assertStringNotContainsString('<tbody>', $html);
        $this->assertStringNotContainsString('table-responsive', $html);
    }
}
