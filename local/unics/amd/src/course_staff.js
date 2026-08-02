/**
 * Прогрессивное дополнение страницы курса (course/view.php, формат topics) для ПЕДАГОГСКОГО вида:
 * карточка «Требует внимания» над содержимым, прогресс класса у секций, чипы с числами у активностей.
 * Гейт (не ребенок, не родитель, не режим редактирования, есть право видеть участников) уже проверен
 * на сервере (local_unics\output\course_staff_view::is_staff_view) - модуль только оформляет DOM
 * данными из payload и ничего заново не проверяет. Разметка не совпала или payload неполный - модуль
 * молча ничего не делает (прогрессивное дополнение, исключений наружу нет).
 *
 * Все тексты приходят готовыми строками из PHP: в этом модуле нет ни одной русской строки и ни одной
 * склейки слова с числом (правило фичи - i18n на сервере).
 *
 * Разметка Moodle 5.0 (Boost, формат topics) под ПЕДАГОГОМ сверена живьем на course/view.php?id=21
 * (см. отчет задачи 3) - совпадает с уже задокументированной в course_child.js для ребенка, с
 * дополнительными ядровыми элементами внутри .activity-grid (.activity-groupmode-info,
 * .activity-badges, чекбоксы массового выбора) - они не мешают, чипы добавляются последним
 * элементом .activity-grid, как и тайл/чип в course_child.js.
 *
 * @module     local_unics/course_staff
 */
define([], function() {
    'use strict';

    var ROOT_SELECTOR = '[data-region="course-content"], #region-main .course-content, .course-content';

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
     * Строка карточки «Требует внимания»: ссылка, если url есть, иначе просто текст.
     * @param {Object} item {count, label, url}
     * @param {string} cls модификатор класса
     * @return {HTMLElement}
     */
    function attentionRow(item, cls) {
        if (item.url) {
            var a = el('a', 'unics-staff-attention ' + cls, item.label);
            a.href = item.url;
            return a;
        }
        return el('p', 'unics-staff-attention ' + cls, item.label);
    }

    /**
     * Карточка «Требует внимания» над содержимым курса. Обе строки показываем только при
     * ненулевом счетчике; если оба нуля - спокойное состояние «Все работы проверены».
     * Идемпотентно.
     * @param {Object} data полный payload
     */
    function renderAttention(data) {
        if (document.querySelector('.unics-staff-head')) {
            return;
        }
        var root = document.querySelector(ROOT_SELECTOR);
        if (!root || !root.parentNode) {
            return;
        }
        var attention = data.attention || {};
        var head = el('div', 'unics-staff-head');

        if (attention.grading) {
            head.appendChild(attentionRow(attention.grading, 'unics-staff-attention-grading'));
        }
        if (attention.stuck) {
            head.appendChild(attentionRow(attention.stuck, 'unics-staff-attention-stuck'));
        }
        if (attention.orphans) {
            head.appendChild(attentionRow(attention.orphans, 'unics-staff-attention-orphans'));
        }
        if (!attention.grading && !attention.stuck && !attention.orphans) {
            if (!data.strings || !data.strings.allClear) {
                return;
            }
            head.appendChild(el('p', 'unics-staff-clear', data.strings.allClear));
        }

        root.parentNode.insertBefore(head, root);
    }

    /**
     * Прогресс класса по теме в штатном месте под бейджи секции: бар + готовая фраза
     * «прошли тему: 5 из 12». Идемпотентно.
     * @param {HTMLElement} li li#section-<num>
     * @param {Object} secData {done, total, label, aria}
     * @param {Object} strings data.strings
     */
    function renderSectionProgress(li, secData, strings) {
        if (!li || !secData || li.querySelector('.unics-staff-sec')) {
            return;
        }
        var done = secData.done || 0;
        var total = secData.total || 0;
        if (total <= 0) {
            return;
        }
        var wrap = el('div', 'unics-staff-sec');
        wrap.setAttribute('role', 'progressbar');
        // role=progressbar обязан иметь ОТДЕЛЬНОЕ доступное имя (WCAG 4.1.2, axe
        // aria-progressbar-name); aria-valuetext описывает значение, а не имя виджета.
        if (strings.sectionName) {
            wrap.setAttribute('aria-label', strings.sectionName);
        }
        wrap.setAttribute('aria-valuenow', String(done));
        wrap.setAttribute('aria-valuemin', '0');
        wrap.setAttribute('aria-valuemax', String(total));
        if (secData.aria) {
            wrap.setAttribute('aria-valuetext', secData.aria);
        }
        wrap.appendChild(el('div', 'unics-staff-sec-bar')).style.width = percentOf(done, total) + '%';
        wrap.appendChild(el('span', 'unics-staff-sec-label', secData.label || ''));

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
     * Чипы с числами у одной активности: «сделали 8 из 12», «на проверке: 3», «застряли: 2».
     * Чипы со ссылкой - настоящие ссылки. Идемпотентно.
     * @param {HTMLElement} li li#module-<cmid>
     * @param {Object} info элемент data.cms
     */
    function enhanceActivity(li, info) {
        if (!li || !info || li.querySelector('.unics-staff-chips')) {
            return;
        }
        var grid = li.querySelector('.activity-grid');
        if (!grid) {
            return;
        }
        var box = el('div', 'unics-staff-chips');

        if (info.doneLabel) {
            box.appendChild(el('span', 'unics-staff-chip unics-staff-chip-done', info.doneLabel));
        }
        if (info.gradingLabel) {
            if (info.gradingUrl) {
                var g = el('a', 'unics-staff-chip unics-staff-chip-grading', info.gradingLabel);
                g.href = info.gradingUrl;
                box.appendChild(g);
            } else {
                box.appendChild(el('span', 'unics-staff-chip unics-staff-chip-grading', info.gradingLabel));
            }
        }
        if (info.stuckLabel) {
            if (info.stuckUrl) {
                var s = el('a', 'unics-staff-chip unics-staff-chip-stuck', info.stuckLabel);
                s.href = info.stuckUrl;
                box.appendChild(s);
            } else {
                box.appendChild(el('span', 'unics-staff-chip unics-staff-chip-stuck', info.stuckLabel));
            }
        }
        if (!box.firstChild) {
            return;
        }
        grid.appendChild(box);
    }

    /**
     * Пометка аудитории варианта у одной активности: «Стандартный · 5 учеников»,
     * «Базовый · не видит никто», «для группы 7А класс · 1 ученик». Текст приходит готовым из PHP.
     * Набор активностей здесь ШИРЕ, чем у чипов: пометка есть и на скрытых от учеников строках -
     * именно там она нужнее всего. Идемпотентно.
     * @param {HTMLElement} li li#module-<cmid>
     * @param {Object} info элемент data.variants: {label, orphan}
     */
    function renderVariant(li, info) {
        if (!li || !info || !info.label || li.querySelector('.unics-staff-audience')) {
            return;
        }
        var grid = li.querySelector('.activity-grid');
        if (!grid) {
            return;
        }
        if (info.orphan) {
            li.classList.add('unics-staff-orphan');
        }
        grid.appendChild(el('span', 'unics-staff-audience', info.label));
    }

    return {
        /**
         * @param {Object} data payload из local_unics\output\course_staff_view::build_payload().
         */
        init: function(data) {
            try {
                data = data || {};
                var root = document.querySelector(ROOT_SELECTOR);
                if (!root || !data.classSize) {
                    return;
                }
                document.body.classList.add('unics-staff-course');

                renderAttention(data);

                var cms = data.cms || {};
                Object.keys(cms).forEach(function(cmid) {
                    var li = document.getElementById('module-' + cmid);
                    if (li) {
                        enhanceActivity(li, cms[cmid]);
                    }
                });

                var sections = data.sections || {};
                Object.keys(sections).forEach(function(num) {
                    var sec = document.getElementById('section-' + num);
                    if (sec) {
                        renderSectionProgress(sec, sections[num], data.strings || {});
                    }
                });

                var variants = data.variants || {};
                Object.keys(variants).forEach(function(cmid) {
                    var vli = document.getElementById('module-' + cmid);
                    if (vli) {
                        renderVariant(vli, variants[cmid]);
                    }
                });
            } catch (e) {
                if (window.console) {
                    console.warn('unics course_staff', e);
                }
            }
        }
    };
});
