/**
 * Боковой рельс оболочки (G3 per-role-shell): свернуть/развернуть (десктоп) +
 * мобильный off-canvas (выезд по гамбургеру).
 *
 * ДЕСКТОП (>=1024): рельс постоянный; кнопка-тоггл сворачивает его в столбец иконок.
 * Состояние - user-pref `local_unics_shell_rail_collapsed` + localStorage (мгновенность);
 * серверный рендер = источник истины (без мигания).
 *
 * МОБИЛЬНЫЙ/ПЛАНШЕТ (<1024): рельс спрятан за левым краем; гамбургер в навбаре
 * (`.unics-shell-rail__burger`) выдвигает его (`.is-open`) с подложкой
 * (`.unics-shell-rail__backdrop`). Закрытие: повторный тап гамбургера, тап по
 * подложке, Esc, выбор пункта. Фокус уводится в рельс и ловится (Tab-цикл),
 * по закрытию возвращается на гамбургер.
 *
 * @module     local_unics/shell_rail
 */
define(['core_user/repository'], function(UserRepository) {
    'use strict';

    var PREF = 'local_unics_shell_rail_collapsed';
    var LS_KEY = 'unics_shell_rail_collapsed';
    var COLLAPSED = 'unics-shell-rail--collapsed';
    var OPEN = 'is-open';

    /**
     * Применяет свёрнутое состояние к рельсу и кнопке (десктоп).
     * @param {HTMLElement} rail
     * @param {HTMLElement} toggle
     * @param {boolean} collapsed
     */
    function setCollapsed(rail, toggle, collapsed) {
        rail.classList.toggle(COLLAPSED, collapsed);
        var label = collapsed ? 'Развернуть меню' : 'Свернуть меню';
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
    }

    return {
        init: function() {
            var rail = document.querySelector('.unics-shell-rail');
            if (!rail) {
                return;
            }

            // --- Десктоп: сворачивание ---
            var toggle = rail.querySelector('.unics-shell-rail__toggle');
            if (toggle) {
                try {
                    var ls = window.localStorage.getItem(LS_KEY);
                    if (ls !== null) {
                        var lsCollapsed = ls === '1';
                        if (lsCollapsed !== rail.classList.contains(COLLAPSED)) {
                            setCollapsed(rail, toggle, lsCollapsed);
                        }
                    }
                } catch (e) {
                    // localStorage недоступен - работаем по серверному pref.
                }

                toggle.addEventListener('click', function() {
                    var collapsed = !rail.classList.contains(COLLAPSED);
                    setCollapsed(rail, toggle, collapsed);
                    try {
                        window.localStorage.setItem(LS_KEY, collapsed ? '1' : '0');
                    } catch (e) {
                        // ignore
                    }
                    UserRepository.setUserPreference(PREF, collapsed ? '1' : '0')
                        .catch(function() {
                            // Сохранение не удалось - вернётся к серверному значению.
                        });
                });
            }

            // --- Мобильный: off-canvas ---
            var burger = document.querySelector('.unics-shell-rail__burger');
            var backdrop = document.querySelector('.unics-shell-rail__backdrop');

            function focusable() {
                // Только видимые (исключаем скрытую на мобильном кнопку-сворачивания).
                return Array.prototype.filter.call(
                    rail.querySelectorAll('a[href], button:not([disabled])'),
                    function(el) { return el.offsetParent !== null; }
                );
            }

            function onKey(e) {
                if (e.key === 'Escape') {
                    closeRail();
                    return;
                }
                if (e.key === 'Tab') {
                    var f = focusable();
                    if (!f.length) {
                        return;
                    }
                    var first = f[0];
                    var last = f[f.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }

            function openRail() {
                rail.classList.add(OPEN);
                if (burger) {
                    burger.setAttribute('aria-expanded', 'true');
                }
                if (backdrop) {
                    backdrop.hidden = false;
                    // rAF, чтобы сработал transition прозрачности.
                    window.requestAnimationFrame(function() {
                        backdrop.classList.add('is-visible');
                    });
                }
                document.body.style.overflow = 'hidden';
                document.addEventListener('keydown', onKey);
                var f = focusable();
                if (f.length) {
                    f[0].focus();
                }
            }

            function closeRail() {
                if (!rail.classList.contains(OPEN)) {
                    return;
                }
                rail.classList.remove(OPEN);
                if (burger) {
                    burger.setAttribute('aria-expanded', 'false');
                }
                if (backdrop) {
                    backdrop.classList.remove('is-visible');
                    backdrop.hidden = true;
                }
                document.body.style.overflow = '';
                document.removeEventListener('keydown', onKey);
                if (burger) {
                    burger.focus();
                }
            }

            if (burger) {
                burger.addEventListener('click', function() {
                    if (rail.classList.contains(OPEN)) {
                        closeRail();
                    } else {
                        openRail();
                    }
                });
            }
            if (backdrop) {
                backdrop.addEventListener('click', closeRail);
            }
            // Выбор пункта закрывает off-canvas (переход на страницу).
            rail.addEventListener('click', function(e) {
                if (e.target.closest('.unics-shell-rail__item')) {
                    closeRail();
                }
            });
        }
    };
});
