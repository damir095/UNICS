/**
 * Собственный UI мессенджера УНИКС (M2.1 + M2.2 + M2.3).
 *
 * Читает и отправляет сообщения через штатные внешние функции core_message_*
 * (все помечены ajax => true в lib/db/services.php) с помощью core/ajax - без
 * iframe и без web-service токена. Ядро само проверяет права (членство в беседе,
 * moodle/site:sendmessage) и форматирует текст. Наш слой - только рендер пузырей,
 * композер, живой поллинг и бейджи. См. wiki/concepts/messenger-ui-design.md.
 *
 * M2.1: чтение/отправка/ленивое создание личной беседы/mark-as-read.
 * M2.2: живой поллинг активной беседы (пауза по document.hidden), плашка
 *   «новые сообщения», бейджи непрочитанного в левом списке (get_conversations).
 * M2.3: разделители дат, подписи авторов для скринридера, пагинация
 *   «показать ранние», панель быстрого ввода фраз (ученику с ОВЗ).
 *
 * Решение Q2: отправка только кнопкой (Enter = новая строка - безопасно для ОВЗ).
 *
 * @module     local_unics/messenger_app
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    var FORMAT_PLAIN = 2;       // Экранирование + nl2br на стороне ядра: безопасно для детского ввода.
    var LOAD_LIMIT = 50;        // Сколько сообщений грузим за раз (начальная загрузка / страница «ранние»).
    var THREAD_POLL_MS = 12000; // Период поллинга активной беседы.
    var BADGE_POLL_MS = 25000;  // Период обновления бейджей непрочитанного.

    var TYPE_INDIVIDUAL = 1;    // \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL
    var TYPE_GROUP = 2;         // ...TYPE_GROUP

    // Месяцы в родительном падеже для разделителей дат («8 июня»).
    var MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    var cfg = {
        currentuserid: 0,
        convid: 0,
        userid: 0,
        isgroup: false
    };

    var els = {};
    var members = {};      // id участника -> ФИО (для подписи автора).
    var seenIds = {};      // id сообщения -> true (дедуп при поллинге/пагинации).
    var lastTs = 0;        // максимальный timecreated отрисованного сообщения (для timefrom).
    var loadedCount = 0;   // всего загружено сообщений (limitfrom для пагинации «ранние»).
    var moreEarlier = false; // последняя загрузка вернула полную страницу -> есть что грузить выше.
    var earlierBusy = false; // защита от двойного клика по «показать ранние».

    function escapeHtml(value) {
        var d = document.createElement('div');
        d.textContent = (value === null || value === undefined) ? '' : String(value);
        return d.innerHTML;
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function fmtTime(ts) {
        var d = new Date(ts * 1000);
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function dayKey(ts) {
        var d = new Date(ts * 1000);
        return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate();
    }

    /**
     * Подпись разделителя даты: «Сегодня» / «Вчера» / «8 июня» (+ год, если не текущий).
     */
    function dayLabel(ts) {
        var d = new Date(ts * 1000);
        var now = new Date();
        if (dayKey(ts) === dayKey(Math.floor(now.getTime() / 1000))) {
            return 'Сегодня';
        }
        if (dayKey(ts) === dayKey(Math.floor((now.getTime() - 86400000) / 1000))) {
            return 'Вчера';
        }
        var label = d.getDate() + ' ' + MONTHS[d.getMonth()];
        if (d.getFullYear() !== now.getFullYear()) {
            label += ' ' + d.getFullYear();
        }
        return label;
    }

    function setState(text) {
        els.thread.innerHTML = '<div class="unics-messenger__state">' + escapeHtml(text) + '</div>';
    }

    function clearStatePlaceholder() {
        var s = els.thread.querySelector('.unics-messenger__state');
        if (s) {
            els.thread.innerHTML = '';
        }
    }

    function atBottom() {
        var t = els.thread;
        return (t.scrollHeight - t.scrollTop - t.clientHeight) < 60;
    }

    function scrollToBottom() {
        els.thread.scrollTop = els.thread.scrollHeight;
    }

    function showJump() {
        if (els.jump) {
            els.jump.hidden = false;
        }
    }

    function hideJump() {
        if (els.jump) {
            els.jump.hidden = true;
        }
    }

    /**
     * Запомнить сообщение как отрисованное: id (дедуп) + сдвинуть lastTs вперёд.
     */
    function noteSeen(m) {
        seenIds[parseInt(m.id, 10)] = true;
        var ts = parseInt(m.timecreated, 10) || 0;
        if (ts > lastTs) {
            lastTs = ts;
        }
    }

    function mergeMembers(list) {
        (list || []).forEach(function(mem) {
            members[parseInt(mem.id, 10)] = mem.fullname;
        });
    }

    /**
     * Хронологический порядок: по времени, при равном timecreated - по id (порядок
     * отправки). Без tiebreaker сообщения одной секунды шли бы в произвольном порядке.
     */
    function byChrono(a, b) {
        return (a.timecreated - b.timecreated) || (parseInt(a.id, 10) - parseInt(b.id, 10));
    }

    /**
     * Перестроить разделители дат (M2.3): удалить старые и вставить по одному перед
     * каждым сообщением, чей день отличается от предыдущего. Вызывается после любой
     * мутации ленты (полный рендер / аппенд новых / препенд ранних) - дёшево (O(n)).
     */
    function normalizeSeparators() {
        var seps = els.thread.querySelectorAll('.unics-messenger__date-sep');
        Array.prototype.forEach.call(seps, function(s) {
            s.parentNode.removeChild(s);
        });
        var msgs = els.thread.querySelectorAll('.unics-msg');
        var prevKey = null;
        Array.prototype.forEach.call(msgs, function(el) {
            var ts = parseInt(el.getAttribute('data-ts'), 10) || 0;
            var key = dayKey(ts);
            if (key !== prevKey) {
                var sep = document.createElement('div');
                sep.className = 'unics-messenger__date-sep';
                sep.setAttribute('role', 'separator');
                sep.innerHTML = '<span>' + escapeHtml(dayLabel(ts)) + '</span>';
                els.thread.insertBefore(sep, el);
                prevKey = key;
            }
        });
    }

    /**
     * HTML одного пузыря. m.text приходит уже отформатированным ядром (для нашего
     * FORMAT_PLAIN - экранированный текст + nl2br), поэтому вставляем как HTML.
     * data-ts нужен разделителям дат. Подпись автора: в группе видимая, иначе (и для
     * своих) - только для скринридера (visually-hidden), чтобы визуал был чистым, но
     * различие «свой/чужой» было доступно не только цветом (WCAG 1.4.1).
     */
    function bubbleHtml(m) {
        var fromId = parseInt(m.useridfrom, 10);
        var own = (fromId === cfg.currentuserid);
        var author = own ? 'Вы' : (members[fromId] || 'Участник');
        var ts = parseInt(m.timecreated, 10) || 0;
        var html = '<div class="unics-msg ' + (own ? 'unics-msg--own' : 'unics-msg--other')
            + '" data-ts="' + ts + '">';
        html += '<div class="unics-msg__bubble">';
        if (!own && cfg.isgroup) {
            html += '<div class="unics-msg__author">' + escapeHtml(author) + '</div>';
        } else {
            html += '<div class="unics-msg__author visually-hidden">' + escapeHtml(author) + '</div>';
        }
        html += '<div class="unics-msg__text">' + m.text + '</div>';
        html += '<div class="unics-msg__time">' + escapeHtml(fmtTime(ts)) + '</div>';
        html += '</div></div>';
        return html;
    }

    /**
     * Показать/скрыть кнопку «показать ранние»: видна, когда есть что грузить выше и
     * беседа выбрана (и не идёт подгрузка).
     */
    function updateEarlierBtn() {
        if (!els.earlier) {
            return;
        }
        els.earlier.hidden = !(moreEarlier && cfg.convid && !earlierBusy);
    }

    function renderAll(data) {
        members = {};
        seenIds = {};
        mergeMembers(data.members);
        var msgs = (data.messages || []).slice();
        // newest=true отдаёт новые сверху - приводим к хронологии (старые сверху).
        msgs.sort(byChrono);
        loadedCount = msgs.length;
        moreEarlier = (msgs.length >= LOAD_LIMIT);
        if (!msgs.length) {
            setState('Пока нет сообщений. Напишите первым!');
            updateEarlierBtn();
            return;
        }
        var html = '';
        msgs.forEach(function(m) {
            noteSeen(m);
            html += bubbleHtml(m);
        });
        els.thread.innerHTML = html;
        normalizeSeparators();
        scrollToBottom();
        updateEarlierBtn();
    }

    /**
     * Дорисовать собственное только что отправленное сообщение (оптимистичное
     * подтверждение по ответу ядра).
     */
    function appendOwn(m) {
        if (!m) {
            return;
        }
        noteSeen(m);
        clearStatePlaceholder();
        els.thread.insertAdjacentHTML('beforeend', bubbleHtml(m));
        normalizeSeparators();
        loadedCount += 1;
        scrollToBottom();
        hideJump();
    }

    /**
     * Обработать пачку сообщений от поллинга: дедуп по id, аппенд новых, авто-скролл
     * если были внизу (иначе плашка «новые сообщения»).
     */
    function handlePolled(data) {
        mergeMembers(data.members);
        var fresh = (data.messages || []).filter(function(m) {
            return !seenIds[parseInt(m.id, 10)];
        });
        if (!fresh.length) {
            return;
        }
        fresh.sort(byChrono);
        var wasBottom = atBottom();
        clearStatePlaceholder();
        var html = '';
        fresh.forEach(function(m) {
            noteSeen(m);
            html += bubbleHtml(m);
        });
        els.thread.insertAdjacentHTML('beforeend', html);
        normalizeSeparators();
        loadedCount += fresh.length;
        if (wasBottom) {
            scrollToBottom();
            hideJump();
            if (!document.hidden) {
                markRead();
            }
        } else {
            showJump();
        }
    }

    /**
     * Подгрузить более ранние сообщения (M2.3): следующая страница (limitfrom =
     * loadedCount) препендится в начало ленты с сохранением позиции прокрутки.
     */
    function loadEarlier() {
        if (!cfg.convid || earlierBusy || !moreEarlier) {
            return;
        }
        earlierBusy = true;
        els.earlier.disabled = true;
        els.earlier.textContent = 'Загрузка...';
        Ajax.call([{
            methodname: 'core_message_get_conversation_messages',
            args: {
                currentuserid: cfg.currentuserid,
                convid: cfg.convid,
                limitfrom: loadedCount,
                limitnum: LOAD_LIMIT,
                newest: true
            }
        }])[0].then(function(data) {
            mergeMembers(data.members);
            moreEarlier = ((data.messages || []).length >= LOAD_LIMIT);
            var older = (data.messages || []).filter(function(m) {
                return !seenIds[parseInt(m.id, 10)];
            });
            older.sort(function(a, b) {
                return a.timecreated - b.timecreated;
            });
            if (older.length) {
                var prevH = els.thread.scrollHeight;
                var prevTop = els.thread.scrollTop;
                var html = '';
                older.forEach(function(m) {
                    noteSeen(m);
                    html += bubbleHtml(m);
                });
                els.thread.insertAdjacentHTML('afterbegin', html);
                normalizeSeparators();
                loadedCount += older.length;
                // Не прыгать к началу: сместить прокрутку на прибавившуюся высоту.
                els.thread.scrollTop = prevTop + (els.thread.scrollHeight - prevH);
            }
            earlierBusy = false;
            els.earlier.disabled = false;
            els.earlier.textContent = 'Показать ранние сообщения';
            updateEarlierBtn();
            return data;
        }).catch(function(err) {
            earlierBusy = false;
            els.earlier.disabled = false;
            els.earlier.textContent = 'Показать ранние сообщения';
            updateEarlierBtn();
            Notification.exception(err);
        });
    }

    function markRead() {
        if (!cfg.convid) {
            return;
        }
        Ajax.call([{
            methodname: 'core_message_mark_all_conversation_messages_as_read',
            args: {userid: cfg.currentuserid, conversationid: cfg.convid}
        }])[0].catch(function() {
            // Нефатально - пометка прочтения не критична для UI.
        });
    }

    function loadMessages() {
        if (!cfg.convid) {
            // Личной беседы ещё нет (первое сообщение её создаст).
            setState('Пока нет сообщений. Напишите первым!');
            return;
        }
        setState('Загрузка сообщений...');
        Ajax.call([{
            methodname: 'core_message_get_conversation_messages',
            args: {
                currentuserid: cfg.currentuserid,
                convid: cfg.convid,
                limitfrom: 0,
                limitnum: LOAD_LIMIT,
                newest: true
            }
        }])[0].then(function(data) {
            renderAll(data);
            markRead();
            return data;
        }).catch(function(err) {
            setState('Не удалось загрузить сообщения. Обновите страницу.');
            Notification.exception(err);
        });
    }

    /**
     * Поллинг активной беседы: только новые сообщения (timefrom = lastTs, включающий
     * -> граничные дедуплицируются по id). Пауза при скрытой вкладке.
     */
    function pollThread() {
        if (document.hidden || !cfg.convid) {
            return;
        }
        Ajax.call([{
            methodname: 'core_message_get_conversation_messages',
            args: {
                currentuserid: cfg.currentuserid,
                convid: cfg.convid,
                limitfrom: 0,
                limitnum: LOAD_LIMIT,
                newest: true,
                timefrom: lastTs
            }
        }])[0].then(function(data) {
            handlePolled(data);
            return data;
        }).catch(function() {
            // Сеть мигнула - продолжим со следующего тика.
        });
    }

    function setBadge(el, count) {
        var badge = el.querySelector('.unics-contact__badge');
        if (!badge) {
            return;
        }
        if (count > 0) {
            badge.innerHTML = (count > 99 ? '99+' : count)
                + '<span class="visually-hidden"> непрочитанных</span>';
            badge.hidden = false;
        } else {
            badge.textContent = '';
            badge.hidden = true;
        }
    }

    /**
     * Разложить непрочитанное по левому списку. Групповые беседы матчим по convid,
     * личные - по id собеседника (members личной беседы исключают самого пользователя).
     */
    function applyBadges(conversations) {
        var byConv = {};
        var byUser = {};
        (conversations || []).forEach(function(c) {
            var type = parseInt(c.type, 10);
            var unread = parseInt(c.unreadcount, 10) || 0;
            if (type === TYPE_GROUP) {
                byConv[parseInt(c.id, 10)] = unread;
            } else if (type === TYPE_INDIVIDUAL) {
                (c.members || []).forEach(function(mem) {
                    byUser[parseInt(mem.id, 10)] = unread;
                });
            }
        });
        var groupEls = document.querySelectorAll('.unics-contact[data-convid]');
        Array.prototype.forEach.call(groupEls, function(el) {
            setBadge(el, byConv[parseInt(el.getAttribute('data-convid'), 10)] || 0);
        });
        var userEls = document.querySelectorAll('.unics-contact[data-userid]');
        Array.prototype.forEach.call(userEls, function(el) {
            setBadge(el, byUser[parseInt(el.getAttribute('data-userid'), 10)] || 0);
        });
    }

    function pollBadges() {
        if (document.hidden) {
            return;
        }
        Ajax.call([{
            methodname: 'core_message_get_conversations',
            args: {userid: cfg.currentuserid}
        }])[0].then(function(data) {
            applyBadges(data && data.conversations);
            return data;
        }).catch(function() {
            // Нефатально - бейджи обновятся на следующем тике.
        });
    }

    function lockComposer(lock) {
        els.input.disabled = lock;
        els.send.disabled = lock;
    }

    function sendMessage() {
        var text = els.input.value.replace(/\s+$/, '');
        if (!text) {
            return;
        }
        lockComposer(true);

        var fail = function(err) {
            lockComposer(false);
            Notification.exception(err);
        };

        if (cfg.convid) {
            // Существующая беседа (группа или личная с историей).
            Ajax.call([{
                methodname: 'core_message_send_messages_to_conversation',
                args: {
                    conversationid: cfg.convid,
                    messages: [{text: text, textformat: FORMAT_PLAIN}]
                }
            }])[0].then(function(res) {
                els.input.value = '';
                appendOwn((res && res.length) ? res[0] : null);
                lockComposer(false);
                els.input.focus();
                return res;
            }).catch(fail);
        } else {
            // Первое сообщение личной беседе - ленивое создание через send_instant_messages.
            Ajax.call([{
                methodname: 'core_message_send_instant_messages',
                args: {
                    messages: [{touserid: cfg.userid, text: text, textformat: FORMAT_PLAIN}]
                }
            }])[0].then(function(res) {
                var r = (res && res.length) ? res[0] : null;
                if (r && r.conversationid) {
                    cfg.convid = parseInt(r.conversationid, 10);
                }
                els.input.value = '';
                // Беседа только что создана - перезагружаем нить целиком (единый путь рендера).
                // Дальше поллинг подхватит её по cfg.convid.
                loadMessages();
                lockComposer(false);
                els.input.focus();
                return res;
            }).catch(fail);
        }
    }

    /**
     * Вставить готовую фразу из панели быстрого ввода (M2.3) в позицию курсора поля,
     * с пробелом-разделителем при необходимости. Фокус возвращается в поле.
     */
    function insertPhrase(text) {
        var input = els.input;
        if (!input || !text) {
            return;
        }
        var start = input.selectionStart;
        var end = input.selectionEnd;
        if (typeof start === 'number' && typeof end === 'number') {
            var before = input.value.slice(0, start);
            var after = input.value.slice(end);
            var sep = (before && !/\s$/.test(before)) ? ' ' : '';
            var insert = sep + text;
            input.value = before + insert + after;
            var pos = start + insert.length;
            input.setSelectionRange(pos, pos);
        } else {
            input.value += (input.value && !/\s$/.test(input.value) ? ' ' : '') + text;
        }
        input.focus();
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }

    function onVisible() {
        if (!document.hidden) {
            // Вкладка вернулась - догоняем активную беседу и бейджи немедленно.
            pollThread();
            pollBadges();
        }
    }

    function onThreadScroll() {
        if (atBottom() && els.jump && !els.jump.hidden) {
            hideJump();
            if (!document.hidden) {
                markRead();
            }
        }
    }

    function onJumpClick() {
        scrollToBottom();
        hideJump();
        if (!document.hidden) {
            markRead();
        }
    }

    return {
        init: function(args) {
            args = args || {};
            cfg.currentuserid = parseInt(args.currentuserid, 10) || 0;
            cfg.convid = parseInt(args.convid, 10) || 0;
            cfg.userid = parseInt(args.userid, 10) || 0;
            cfg.isgroup = !!parseInt(args.isgroup, 10);

            if (!cfg.currentuserid) {
                return;
            }

            // Бейджи непрочитанного поллим всегда, пока показан мессенджер (даже без
            // открытой беседы). Возврат вкладки - мгновенный догон (onVisible).
            document.addEventListener('visibilitychange', onVisible);
            setTimeout(pollBadges, 1500);
            setInterval(pollBadges, BADGE_POLL_MS);

            els.thread = document.getElementById('unics-thread');
            els.form = document.getElementById('unics-composer');
            els.input = document.getElementById('unics-composer-input');
            els.send = document.getElementById('unics-composer-send');
            els.jump = document.getElementById('unics-thread-jump');
            els.earlier = document.getElementById('unics-thread-earlier');
            els.quick = document.querySelector('.unics-messenger__quick');

            // Тред/композер существуют только когда беседа выбрана.
            if (!els.thread || !els.form) {
                return;
            }

            // Базовый timefrom - момент открытия страницы (renderAll сдвинет вперёд
            // по последнему сообщению; для пустой беседы останется этот момент).
            lastTs = Math.floor(Date.now() / 1000);

            // Отправка только по кнопке (submit). Enter в textarea = новая строка
            // по умолчанию - намеренно НЕ перехватываем (решение Q2).
            els.form.addEventListener('submit', function(e) {
                e.preventDefault();
                sendMessage();
            });
            els.thread.addEventListener('scroll', onThreadScroll);
            if (els.jump) {
                els.jump.addEventListener('click', onJumpClick);
            }
            if (els.earlier) {
                els.earlier.addEventListener('click', loadEarlier);
            }
            if (els.quick) {
                els.quick.addEventListener('click', function(e) {
                    var btn = e.target.closest('.unics-quick-btn');
                    if (btn) {
                        insertPhrase(btn.getAttribute('data-insert') || '');
                    }
                });
            }

            loadMessages();
            setInterval(pollThread, THREAD_POLL_MS);
            els.input.focus();
        }
    };
});
