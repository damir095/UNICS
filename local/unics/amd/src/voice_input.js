/**
 * Голосовой ввод ответов через Web Speech API (SpeechRecognition).
 *
 * Навешивает кнопку «🎤 Диктовать» рядом с текстовыми полями ответа на страницах
 * задания (mod_assign) и попытки теста (mod_quiz). Распознавание идёт на стороне
 * браузера (lang=ru-RU); без сервера и затрат. Если API недоступен (Firefox/часть
 * Safari) — кнопка не добавляется.
 *
 * Приватность (152-ФЗ): в Chrome аудио уходит во внешний движок. Поэтому фича
 * включается админом (local_unics/voice_input_enabled) и при первом запуске
 * показывает предупреждение (через confirm).
 *
 * @module     local_unics/voice_input
 */
define([], function() {
    'use strict';

    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var WARN_KEY = 'unics_voice_warned';

    /**
     * Вставляет распознанный текст в целевое поле (textarea или contenteditable).
     * @param {HTMLElement} field
     * @param {string} text
     */
    function appendText(field, text) {
        if (!text) {
            return;
        }
        if (field.isContentEditable) {
            field.innerHTML += (field.innerHTML ? ' ' : '') + text;
        } else {
            var sep = field.value && !/\s$/.test(field.value) ? ' ' : '';
            field.value += sep + text;
        }
        // Уведомляем редакторы (Atto/TinyMCE/валидаторы) об изменении.
        field.dispatchEvent(new Event('input', {bubbles: true}));
        field.dispatchEvent(new Event('change', {bubbles: true}));
    }

    /**
     * Создаёт кнопку диктовки для одного поля.
     * @param {HTMLElement} field
     * @return {HTMLElement}
     */
    function makeButton(field) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-secondary btn-sm unics-voice-btn';
        btn.innerHTML = '<span aria-hidden="true">🎤</span> Диктовать';
        btn.setAttribute('aria-label', 'Диктовать ответ голосом');

        var recognition = null;
        var listening = false;

        btn.addEventListener('click', function() {
            // Предупреждение о приватности — один раз за сессию.
            try {
                if (!sessionStorage.getItem(WARN_KEY)) {
                    var ok = window.confirm(
                        'Голосовой ввод использует браузерное распознавание речи. ' +
                        'В некоторых браузерах аудио обрабатывается на внешнем сервере. ' +
                        'Продолжить?');
                    if (!ok) {
                        return;
                    }
                    sessionStorage.setItem(WARN_KEY, '1');
                }
            } catch (e) {
                // sessionStorage недоступен — продолжаем без памяти о предупреждении.
            }

            if (listening && recognition) {
                recognition.stop();
                return;
            }

            recognition = new SR();
            recognition.lang = 'ru-RU';
            recognition.interimResults = false;
            recognition.continuous = false;

            recognition.onstart = function() {
                listening = true;
                btn.classList.add('btn-danger');
                btn.classList.remove('btn-outline-secondary');
                btn.innerHTML = '<span aria-hidden="true">⏺</span> Слушаю...';
            };
            recognition.onresult = function(ev) {
                var text = '';
                for (var i = ev.resultIndex; i < ev.results.length; i++) {
                    if (ev.results[i].isFinal) {
                        text += ev.results[i][0].transcript;
                    }
                }
                appendText(field, text.trim());
            };
            recognition.onerror = function() {
                // Тихо завершаем — onend вернёт кнопку в исходное состояние.
            };
            recognition.onend = function() {
                listening = false;
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-outline-secondary');
                btn.innerHTML = '<span aria-hidden="true">🎤</span> Диктовать';
            };

            try {
                recognition.start();
            } catch (e) {
                listening = false;
            }
        });

        return btn;
    }

    /**
     * Находит текстовые поля ответа и навешивает на каждое кнопку (один раз).
     */
    function attach() {
        var fields = document.querySelectorAll(
            '#region-main textarea, ' +
            '#region-main .editor_atto_content[contenteditable="true"]');
        fields.forEach(function(field) {
            if (field.dataset.unicsVoice) {
                return;
            }
            // Пропускаем скрытые служебные textarea редакторов.
            if (field.tagName === 'TEXTAREA' &&
                (field.classList.contains('d-none') || field.style.display === 'none')) {
                return;
            }
            field.dataset.unicsVoice = '1';
            var wrap = document.createElement('div');
            wrap.className = 'unics-voice-wrap mt-1 mb-2';
            wrap.appendChild(makeButton(field));
            field.parentNode.insertBefore(wrap, field.nextSibling);
        });
    }

    return {
        init: function() {
            if (!SR) {
                return; // Браузер не поддерживает — грейсфул-фолбэк.
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    // Небольшая задержка — дать редакторам (Atto/TinyMCE) проинициализироваться.
                    setTimeout(attach, 800);
                });
            } else {
                setTimeout(attach, 800);
            }
        }
    };
});
