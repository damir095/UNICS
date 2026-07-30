/**
 * Прогрессивное дополнение страницы курса (course/view.php, формат topics) для ученического
 * вида: карточки активностей с типом и единым статусом, прогресс секции/курса, кнопка
 * «Продолжить», человеческие причины блокировки. Гейт (ребенок, не режим редактирования,
 * формат topics) уже проверен на сервере (local_unics\output\course_view::is_child_view) -
 * модуль только оформляет DOM данными из payload, ничего заново не проверяет. Если разметка
 * страницы не совпадает с ожидаемой или payload неполный - модуль молча ничего не делает
 * (прогрессивное дополнение, никаких исключений наружу).
 *
 * Разметка Moodle 5.0 (Boost, формат topics), снятая живьем на course/view.php?id=21 под
 * ребенком (см. отчет задачи 3 - там же примеры HTML):
 * - Корень содержимого: div.course-content (совпадает с одним из вариантов ROOT_SELECTOR).
 * - Секция: li#section-<num>[data-number="<num>"][data-for="section"]
 *     > div.section-item
 *         > div.course-section-header[data-for="section_title"]  (заголовок, тоглер, h3;
 *               внутри - штатный пустой div[data-region="sectionbadges"], место под бейджи)
 *         > div.content.course-content-item-content                (список активностей секции)
 * - Активность: li#module-<cmid>[data-for="cmitem"][data-id="<cmid>"].activity.<modname>
 *     > div.activity-item[data-region="activity-card"]
 *         > div.activity-grid
 *             > div.activity-icon...                          (штатная иконка типа - не трогаем)
 *             > div.activity-name-area...
 *                 > div.activitytitle...
 *                     > div.activityname
 *                         > a.aalink.stretched-link > span.instancename  (ссылка ЕСТЬ, доступна)
 *                         > span.instancename                             (ссылки НЕТ, заблокирована)
 *             > div.activity-completion...       (штатный чекбокс/дропдаун выполнения - не трогаем)
 *             > div.activity-availability.isrestricted...  (штатное объяснение блокировки, не трогаем)
 *
 * @module     local_unics/course_child
 */
define([], function() {
    'use strict';

    var ROOT_SELECTOR = '[data-region="course-content"], #region-main .course-content, .course-content';

    // Декоративные тайл-глифы по типу активности - только язык-нейтральные символы блока
    // "Geometric Shapes"/музыкальных нот, без эмодзи и без зашитых русских слов. Неизвестный
    // тип - 'other'. quiz был '?' (читалось как "неизвестно/ошибка", а не "тест") - заменен на
    // ◉ (мишень - тест проверяет знания, попадание в цель).
    var TILE_GLYPHS = {
        material: '▤', // ▤ страница текста
        audio: '♪',    // ♪ нота
        video: '▶',    // ▶ треугольник воспроизведения
        quiz: '◉',     // ◉ мишень
        task: '▣',     // ▣
        cert: '◈',     // ◈
        other: '●'     // ●
    };

    /**
     * Мини-хелпер создания элемента.
     * @param {string} tag
     * @param {string} [cls]
     * @param {string} [text]
     * @return {HTMLElement}
     */
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) {
            e.className = cls;
        }
        if (text != null) {
            e.textContent = text;
        }
        return e;
    }

    /** @return {number} 0..100, безопасно к total === 0. */
    function percentOf(done, total) {
        return total > 0 ? Math.round((done / total) * 100) : 0;
    }

    /**
     * Дополняет карточку одной активности: тайл типа, подпись типа под названием (существующая
     * ссылка названия не трогается - переиспользуется как есть), единый статус-чип, причина
     * блокировки. Идемпотентно: если чип уже вставлен или нет ожидаемых узлов - ничего не делает.
     * @param {HTMLElement} li li#module-<cmid>
     * @param {number} cmid
     * @param {Object} info {type, typeLabel, sub, status, lockWhy} - элемент data.cms
     * @param {Object} strings data.strings
     * @param {?number} nextCmid data.next.cmid либо null
     */
    function enhanceActivity(li, cmid, info, strings, nextCmid) {
        if (!li || !info || li.querySelector('.unics-chip')) {
            return;
        }
        var grid = li.querySelector('.activity-grid');
        if (!grid) {
            return;
        }
        var item = li.querySelector('.activity-item') || li;
        var isNext = !!nextCmid && cmid === nextCmid;

        li.classList.add('unics-act');
        li.classList.add('unics-type-' + info.type);
        if (info.status === 'locked') {
            li.classList.add('unics-locked');
        }
        if (isNext) {
            li.classList.add('unics-next');
        }

        // Тайл-иконка типа - декоративная, первым элементом в сетке карточки.
        var tile = el('span', 'unics-tile', TILE_GLYPHS[info.type] || TILE_GLYPHS.other);
        tile.setAttribute('aria-hidden', 'true');
        grid.insertBefore(tile, grid.firstChild);

        // Подпись типа под названием - переиспользуем существующий блок с именем активности.
        var nameArea = li.querySelector('.activity-name-area') || grid;
        var subText = info.sub ? (info.typeLabel + ' - ' + info.sub) : info.typeLabel;
        if (subText) {
            nameArea.appendChild(el('div', 'unics-act-sub', subText));
        }

        // Единый статус-чип справа. 'continue' - частный случай 'todo' у next-step активности.
        var chipKey = (isNext && info.status === 'todo') ? 'continue' : info.status;
        grid.appendChild(el('span', 'unics-chip unics-chip-' + chipKey, strings[chipKey] || ''));

        // Человекочитаемая причина блокировки - обычным текстом под карточкой (для скринридера).
        if (info.status === 'locked' && info.lockWhy) {
            item.appendChild(el('p', 'unics-lock-why', info.lockWhy));
        }
    }

    /**
     * Прогресс секции («темы») в штатном месте под бейджи секции: готовая фраза secData.label
     * («1 из 2») + мягкий бар, либо - если тема пройдена целиком - плашка вместо бара (числа уже
     * не нужны). Фразы (label/aria) приходят готовыми из PHP (course_view::build_payload) -
     * JS не склеивает строки payload с числами в слова, только с числовыми/пунктуационными
     * подписями (см. renderCourseHeader). Идемпотентно.
     * @param {HTMLElement} li li#section-<num>
     * @param {Object} secData {done, total, complete, label, aria} - элемент data.sections
     * @param {Object} strings data.strings
     */
    function renderSectionProgress(li, secData, strings) {
        if (!li || !secData || li.querySelector('.unics-sec-progress')) {
            return;
        }
        var done = secData.done || 0;
        var total = secData.total || 0;
        var wrap = el('div', 'unics-sec-progress');

        if (secData.complete) {
            wrap.classList.add('unics-sec-progress-done');
            if (strings.themeDone) {
                wrap.appendChild(el('span', 'unics-sec-done', strings.themeDone));
            }
        } else {
            wrap.setAttribute('role', 'progressbar');
            wrap.setAttribute('aria-valuenow', String(done));
            wrap.setAttribute('aria-valuemin', '0');
            wrap.setAttribute('aria-valuemax', String(total));
            if (secData.aria) {
                wrap.setAttribute('aria-valuetext', secData.aria);
            }
            wrap.appendChild(el('div', 'unics-sec-progress-bar')).style.width = percentOf(done, total) + '%';
            wrap.appendChild(el('span', 'unics-sec-progress-label', secData.label || (done + '/' + total)));
        }

        var badges = li.querySelector('[data-region="sectionbadges"]');
        if (badges) {
            badges.appendChild(wrap);
            return;
        }
        var header = li.querySelector('.course-section-header');
        if (header && header.parentNode) {
            header.parentNode.insertBefore(wrap, header.nextSibling);
        }
    }

    /**
     * Шапка курса над содержимым: бар «done/total», label, encourage, CTA «Продолжить: ...»
     * (ссылка берется из уже отрисованной ссылки нужной активности - не строится вручную), либо
     * состояние «Курс пройден!» (next === null и все темы пройдены), либо ничего третьего (next
     * === null, но пройдено не все - остальное заблокировано). Идемпотентно.
     * @param {Object} data полный payload
     */
    function renderCourseHeader(data) {
        if (document.querySelector('.unics-course-head')) {
            return;
        }
        var root = document.querySelector(ROOT_SELECTOR);
        if (!root || !root.parentNode) {
            return;
        }
        var course = data.course || {};
        var strings = data.strings || {};
        var done = course.done || 0;
        var total = course.total || 0;

        var head = el('div', 'unics-course-head');

        var bar = el('div', 'unics-course-progress');
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-valuenow', String(done));
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', String(total));
        if (course.label) {
            bar.setAttribute('aria-valuetext', course.label);
        }
        bar.appendChild(el('div', 'unics-course-progress-bar')).style.width = percentOf(done, total) + '%';
        head.appendChild(bar);

        if (course.label) {
            head.appendChild(el('p', 'unics-course-progress-label', course.label));
        }
        if (course.encourage) {
            head.appendChild(el('p', 'unics-course-encourage', course.encourage));
        }

        if (data.next && data.next.cmid) {
            var link = document.querySelector('#module-' + data.next.cmid + ' .aalink') ||
                document.querySelector('#module-' + data.next.cmid + ' .activityname a');
            if (link && link.href) {
                var cta = el('a', 'unics-cta', data.next.label || strings.continue || '');
                cta.href = link.href;
                head.appendChild(cta);
            }
        } else if (total > 0 && done === total && strings.courseDone) {
            head.appendChild(el('p', 'unics-course-done', strings.courseDone));
        }

        root.parentNode.insertBefore(head, root);
    }

    return {
        /**
         * @param {Object} data payload из local_unics\output\course_view::build_payload().
         */
        init: function(data) {
            try {
                data = data || {};
                var root = document.querySelector(ROOT_SELECTOR);
                if (!root) {
                    return;
                }
                document.body.classList.add('unics-child-course');

                renderCourseHeader(data);

                var cms = data.cms || {};
                var nextCmid = (data.next && data.next.cmid) ? data.next.cmid : null;
                Object.keys(cms).forEach(function(cmid) {
                    var li = document.getElementById('module-' + cmid);
                    if (li) {
                        enhanceActivity(li, parseInt(cmid, 10), cms[cmid], data.strings || {}, nextCmid);
                    }
                });

                var sections = data.sections || {};
                Object.keys(sections).forEach(function(num) {
                    var sec = document.getElementById('section-' + num);
                    if (sec) {
                        renderSectionProgress(sec, sections[num], data.strings || {});
                    }
                });
            } catch (e) {
                if (window.console) {
                    console.warn('unics course_child', e);
                }
            }
        }
    };
});
