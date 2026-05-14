/**
 * Опрашивает /local/unics/ajax/recent_notifications.php и показывает toast'ы
 * для новых записей в unics_notifications.
 *
 * Дедупликация - через sessionStorage по id уведомления.
 *
 * @module     local_unics/toast_poller
 */
define(['core/toast', 'core/str'], function(Toast, Str) {
    'use strict';

    var DEFAULTS = {
        pollInterval: 30000,
        lookbackSec: 90
    };

    function showToast(notif) {
        var key = 'unics_toast_' + notif.id;
        try {
            if (sessionStorage.getItem(key)) {
                return;
            }
            sessionStorage.setItem(key, '1');
        } catch (e) {
            // sessionStorage может быть недоступен (приватный режим, iframe и т.д.) - игнорируем.
        }

        Toast.add(notif.subject, {
            type: notif.type || 'info',
            autohide: true,
            delay: 6000
        });
    }

    function poll(state) {
        var url = M.cfg.wwwroot + '/local/unics/ajax/recent_notifications.php?since=' + state.since;
        fetch(url, {credentials: 'same-origin'})
            .then(function(r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function(data) {
                if (data && Array.isArray(data.notifications)) {
                    data.notifications.forEach(showToast);
                }
                if (data && data.now) {
                    state.since = data.now;
                }
            })
            .catch(function() {
                // Сеть мигнула - продолжим со следующим тиком.
            });
    }

    return {
        init: function(args) {
            args = args || {};
            var pollInterval = args.pollInterval || DEFAULTS.pollInterval;
            var lookbackSec  = args.lookbackSec  || DEFAULTS.lookbackSec;

            var state = {
                since: Math.floor(Date.now() / 1000) - lookbackSec
            };

            // Первый тик чуть позже - чтобы не блокировать рендер.
            setTimeout(function() { poll(state); }, 1500);
            setInterval(function() { poll(state); }, pollInterval);
        }
    };
});
