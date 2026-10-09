/*
 * AiPBX website call widget (roadmap item 1, #14).
 *
 * Embed with one line (Integrations -> Web widgets shows it):
 *   <script src="https://pbx.example.com/widget.js" data-widget="w_xxxxxxxxxxxx" async></script>
 *
 * Draws a floating button. "Call" talks to the company over WebRTC in the
 * browser (JsSIP, loaded from the same PBX on first use); "Call me back"
 * leaves a number and the PBX calls the visitor. The PBX checks the website,
 * the limits and where the call goes; this script only asks.
 */
(function () {
    'use strict';

    var script = document.currentScript || document.querySelector('script[data-widget]');
    if (!script || window.__aipbxWidgetLoaded) {
        return;
    }
    window.__aipbxWidgetLoaded = true;

    var WIDGET_ID = script.getAttribute('data-widget') || '';
    var BASE = new URL(script.src, document.baseURI).origin;

    var TEXTS = {
        en: {
            button: 'Call us', title: 'Talk to us', call: 'Call now', callback: 'Call me back',
            name: 'Your name (optional)', number: 'Your phone number', send: 'Call me',
            call_hint: 'Free call from your browser. Your microphone will be used.',
            callback_hint: 'Leave your number and we call you right away.',
            connecting: 'Connecting…', ringing: 'Ringing…', in_call: 'In call', ended: 'Call ended',
            hangup: 'Hang up', mute: 'Mute', unmute: 'Unmute', keypad: 'Keypad', close: 'Close',
            calling_you: 'We are calling you now.',
            err_mic: 'Microphone access is needed for the call.',
            err_busy: 'All lines are busy. Please try again later.',
            err_failed: 'The call could not be connected.',
            err_rate_ip: 'Too many attempts. Please try again later.',
            err_limit_daily: 'No more calls can be taken today.',
            err_bad_number: 'This number cannot be called back.',
            err_disabled: 'Calls are not available right now.',
            err_default: 'Something went wrong. Please try again later.'
        },
        tr: {
            button: 'Bizi arayın', title: 'Bizimle konuşun', call: 'Hemen ara', callback: 'Beni arayın',
            name: 'Adınız (isteğe bağlı)', number: 'Telefon numaranız', send: 'Beni ara',
            call_hint: 'Tarayıcınızdan ücretsiz arama. Mikrofonunuz kullanılacak.',
            callback_hint: 'Numaranızı bırakın, sizi hemen arayalım.',
            connecting: 'Bağlanıyor…', ringing: 'Çalıyor…', in_call: 'Görüşmede', ended: 'Görüşme bitti',
            hangup: 'Kapat', mute: 'Sessiz', unmute: 'Sesi aç', keypad: 'Tuşlar', close: 'Kapat',
            calling_you: 'Sizi şimdi arıyoruz.',
            err_mic: 'Arama için mikrofon izni gerekiyor.',
            err_busy: 'Tüm hatlar meşgul. Lütfen daha sonra tekrar deneyin.',
            err_failed: 'Arama bağlanamadı.',
            err_rate_ip: 'Çok fazla deneme. Lütfen daha sonra tekrar deneyin.',
            err_limit_daily: 'Bugün daha fazla arama alınamıyor.',
            err_bad_number: 'Bu numara geri aranamıyor.',
            err_disabled: 'Arama şu an kullanılamıyor.',
            err_default: 'Bir sorun oluştu. Lütfen daha sonra tekrar deneyin.'
        },
        de: {
            button: 'Rufen Sie uns an', title: 'Sprechen Sie mit uns', call: 'Jetzt anrufen', callback: 'Rückruf',
            name: 'Ihr Name (optional)', number: 'Ihre Telefonnummer', send: 'Bitte zurückrufen',
            call_hint: 'Kostenloser Anruf aus dem Browser. Ihr Mikrofon wird verwendet.',
            callback_hint: 'Hinterlassen Sie Ihre Nummer, wir rufen sofort zurück.',
            connecting: 'Verbinde…', ringing: 'Es klingelt…', in_call: 'Im Gespräch', ended: 'Gespräch beendet',
            hangup: 'Auflegen', mute: 'Stumm', unmute: 'Ton an', keypad: 'Tastatur', close: 'Schließen',
            calling_you: 'Wir rufen Sie jetzt an.',
            err_mic: 'Für den Anruf wird das Mikrofon benötigt.',
            err_busy: 'Alle Leitungen sind besetzt. Bitte später erneut versuchen.',
            err_failed: 'Der Anruf konnte nicht verbunden werden.',
            err_rate_ip: 'Zu viele Versuche. Bitte später erneut versuchen.',
            err_limit_daily: 'Heute sind keine Anrufe mehr möglich.',
            err_bad_number: 'Diese Nummer kann nicht zurückgerufen werden.',
            err_disabled: 'Anrufe sind gerade nicht möglich.',
            err_default: 'Etwas ist schiefgelaufen. Bitte später erneut versuchen.'
        },
        fr: {
            button: 'Appelez-nous', title: 'Parlez-nous', call: 'Appeler', callback: 'Être rappelé',
            name: 'Votre nom (facultatif)', number: 'Votre numéro', send: 'Me rappeler',
            call_hint: 'Appel gratuit depuis votre navigateur. Votre micro sera utilisé.',
            callback_hint: 'Laissez votre numéro, nous vous rappelons tout de suite.',
            connecting: 'Connexion…', ringing: 'Ça sonne…', in_call: 'En communication', ended: 'Appel terminé',
            hangup: 'Raccrocher', mute: 'Muet', unmute: 'Son', keypad: 'Clavier', close: 'Fermer',
            calling_you: 'Nous vous appelons maintenant.',
            err_mic: "L'accès au micro est nécessaire pour l'appel.",
            err_busy: 'Toutes les lignes sont occupées. Réessayez plus tard.',
            err_failed: "L'appel n'a pas pu être établi.",
            err_rate_ip: 'Trop de tentatives. Réessayez plus tard.',
            err_limit_daily: "Plus aucun appel possible aujourd'hui.",
            err_bad_number: 'Ce numéro ne peut pas être rappelé.',
            err_disabled: 'Les appels ne sont pas disponibles pour le moment.',
            err_default: 'Une erreur est survenue. Réessayez plus tard.'
        },
        es: {
            button: 'Llámenos', title: 'Hable con nosotros', call: 'Llamar ahora', callback: 'Que me llamen',
            name: 'Su nombre (opcional)', number: 'Su número de teléfono', send: 'Llámenme',
            call_hint: 'Llamada gratuita desde el navegador. Se usará su micrófono.',
            callback_hint: 'Deje su número y le llamamos enseguida.',
            connecting: 'Conectando…', ringing: 'Sonando…', in_call: 'En llamada', ended: 'Llamada finalizada',
            hangup: 'Colgar', mute: 'Silenciar', unmute: 'Activar sonido', keypad: 'Teclado', close: 'Cerrar',
            calling_you: 'Le estamos llamando ahora.',
            err_mic: 'Se necesita acceso al micrófono para la llamada.',
            err_busy: 'Todas las líneas están ocupadas. Inténtelo más tarde.',
            err_failed: 'No se pudo conectar la llamada.',
            err_rate_ip: 'Demasiados intentos. Inténtelo más tarde.',
            err_limit_daily: 'Hoy no se pueden atender más llamadas.',
            err_bad_number: 'No se puede devolver la llamada a este número.',
            err_disabled: 'Las llamadas no están disponibles ahora.',
            err_default: 'Algo salió mal. Inténtelo más tarde.'
        }
    };

    var cfg = null;
    var T = TEXTS.en;
    var ui = {};
    var ua = null;
    var session = null;
    var stream = null;
    var timer = null;
    var muted = false;

    function api(path, params, method) {
        var url = BASE + '/widget-api/' + path;
        var body = new URLSearchParams(params);
        var opts = { method: method || 'POST', mode: 'cors', credentials: 'omit' };
        if (opts.method === 'GET') {
            url += '?' + body.toString();
        } else {
            opts.body = body;
        }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return { success: false, error: 'default' }; });
        });
    }

    function errorText(code) {
        return T['err_' + code] || T.err_default;
    }

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'onclick') { n.addEventListener('click', attrs[k]); } else { n.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { n.appendChild(c); });
        return n;
    }

    function css(color, side) {
        return ':host{all:initial}' +
            '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
            '.btn{position:fixed;bottom:20px;' + side + ':20px;z-index:2147483000;background:' + color + ';color:#fff;border:0;border-radius:999px;padding:14px 20px;font-size:15px;font-weight:600;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:flex;align-items:center;gap:8px}' +
            '.btn svg{width:18px;height:18px;fill:currentColor}' +
            '.panel{position:fixed;bottom:84px;' + side + ':20px;z-index:2147483001;width:min(320px,calc(100vw - 24px));background:#fff;color:#111827;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden;display:none}' +
            '@media (max-width:480px){.panel{' + side + ':12px;bottom:76px}.btn{' + side + ':12px;bottom:12px}}' +
            '.panel.open{display:block}' +
            '.head{background:' + color + ';color:#fff;padding:14px 16px;font-weight:600;display:flex;justify-content:space-between;align-items:center}' +
            '.x{background:none;border:0;color:#fff;font-size:20px;cursor:pointer;line-height:1}' +
            '.tabs{display:flex;border-bottom:1px solid #e5e7eb}' +
            '.tab{flex:1;background:none;border:0;padding:10px;font-size:14px;cursor:pointer;color:#6b7280}' +
            '.tab.on{color:' + color + ';font-weight:600;box-shadow:inset 0 -2px 0 ' + color + '}' +
            '.body{padding:16px;display:flex;flex-direction:column;gap:10px}' +
            '.hint{font-size:13px;color:#6b7280;margin:0}' +
            'input{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;color:#111827;background:#fff}' +
            '.go{background:' + color + ';color:#fff;border:0;border-radius:8px;padding:11px;font-size:15px;font-weight:600;cursor:pointer}' +
            '.go[disabled]{opacity:.6;cursor:default}' +
            '.status{font-size:14px;text-align:center;min-height:20px}' +
            '.err{color:#b91c1c}' +
            '.row{display:flex;gap:8px}.row button{flex:1}' +
            '.sec{background:#f3f4f6;color:#111827;border:0;border-radius:8px;padding:10px;font-size:14px;cursor:pointer}' +
            '.red{background:#dc2626;color:#fff}' +
            '.pad{display:none;grid-template-columns:repeat(3,1fr);gap:6px}.pad.open{display:grid}' +
            '.pad button{background:#f3f4f6;border:0;border-radius:8px;padding:10px;font-size:16px;cursor:pointer}' +
            '.hidden{display:none}';
    }

    var PHONE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg>';

    function build() {
        var host = el('div', { id: 'aipbx-widget' });
        var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        var style = el('style');
        style.textContent = css(cfg.color, cfg.position === 'left' ? 'left' : 'right');
        root.appendChild(style);

        ui.button = el('button', { class: 'btn', type: 'button', 'aria-label': cfg.button_text || T.button });
        ui.button.innerHTML = PHONE_ICON;
        ui.button.appendChild(el('span', { text: cfg.button_text || T.button }));
        ui.button.addEventListener('click', togglePanel);

        ui.panel = el('div', { class: 'panel', role: 'dialog', 'aria-label': T.title });
        ui.panel.appendChild(el('div', { class: 'head' }, [
            el('span', { text: T.title }),
            el('button', { class: 'x', type: 'button', 'aria-label': T.close, text: '×', onclick: togglePanel })
        ]));

        if (cfg.call && cfg.callback) {
            ui.tabCall = el('button', { class: 'tab on', type: 'button', text: T.call, onclick: function () { showTab('call'); } });
            ui.tabCallback = el('button', { class: 'tab', type: 'button', text: T.callback, onclick: function () { showTab('callback'); } });
            ui.panel.appendChild(el('div', { class: 'tabs' }, [ui.tabCall, ui.tabCallback]));
        }

        if (cfg.call) {
            ui.callName = el('input', { type: 'text', maxlength: '60', placeholder: T.name, autocomplete: 'name' });
            ui.callGo = el('button', { class: 'go', type: 'button', text: T.call, onclick: startCall });
            ui.callStatus = el('div', { class: 'status', 'aria-live': 'polite' });
            ui.muteBtn = el('button', { class: 'sec', type: 'button', text: T.mute, onclick: toggleMute });
            ui.padBtn = el('button', { class: 'sec', type: 'button', text: T.keypad, onclick: function () { ui.pad.classList.toggle('open'); } });
            ui.hangBtn = el('button', { class: 'sec red', type: 'button', text: T.hangup, onclick: hangup });
            ui.inCall = el('div', { class: 'row hidden' }, [ui.muteBtn, ui.padBtn, ui.hangBtn]);
            ui.pad = el('div', { class: 'pad' });
            '123456789*0#'.split('').forEach(function (d) {
                ui.pad.appendChild(el('button', { type: 'button', text: d, onclick: function () { if (session) { session.sendDTMF(d); } } }));
            });
            ui.callBody = el('div', { class: 'body' }, [
                el('p', { class: 'hint', text: T.call_hint })
            ].concat(cfg.ask_name ? [ui.callName] : []).concat([ui.callGo, ui.callStatus, ui.inCall, ui.pad]));
            ui.panel.appendChild(ui.callBody);
        }

        if (cfg.callback) {
            ui.cbName = el('input', { type: 'text', maxlength: '60', placeholder: T.name, autocomplete: 'name' });
            ui.cbNumber = el('input', { type: 'tel', maxlength: '20', placeholder: T.number, autocomplete: 'tel', required: 'required' });
            ui.cbGo = el('button', { class: 'go', type: 'button', text: T.send, onclick: requestCallback });
            ui.cbStatus = el('div', { class: 'status', 'aria-live': 'polite' });
            ui.cbBody = el('div', { class: 'body' + (cfg.call ? ' hidden' : '') }, [
                el('p', { class: 'hint', text: T.callback_hint }), ui.cbName, ui.cbNumber, ui.cbGo, ui.cbStatus
            ]);
            ui.panel.appendChild(ui.cbBody);
        }

        root.appendChild(ui.panel);
        root.appendChild(ui.button);
        document.body.appendChild(host);
    }

    function togglePanel() {
        ui.panel.classList.toggle('open');
    }

    function showTab(which) {
        ui.tabCall.classList.toggle('on', which === 'call');
        ui.tabCallback.classList.toggle('on', which === 'callback');
        ui.callBody.classList.toggle('hidden', which !== 'call');
        ui.cbBody.classList.toggle('hidden', which !== 'callback');
    }

    function setStatus(node, text, isError) {
        node.textContent = text || '';
        node.className = 'status' + (isError ? ' err' : '');
    }

    // ------------------------------------------------------------ call

    function loadJsSip() {
        if (window.JsSIP) {
            return Promise.resolve(window.JsSIP);
        }
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = cfg.jssip;
            s.async = true;
            s.onload = function () { window.JsSIP ? resolve(window.JsSIP) : reject(new Error('jssip')); };
            s.onerror = function () { reject(new Error('jssip')); };
            document.head.appendChild(s);
        });
    }

    function startCall() {
        if (session || ui.callGo.disabled) {
            return;
        }
        ui.callGo.disabled = true;
        setStatus(ui.callStatus, T.connecting);

        navigator.mediaDevices.getUserMedia({ audio: true, video: false }).then(function (s) {
            stream = s;
            return api('call', { w: WIDGET_ID, name: ui.callName ? ui.callName.value : '' });
        }, function () {
            throw new Error('mic');
        }).then(function (res) {
            if (!res || !res.success) {
                throw new Error((res && res.error) || 'default');
            }
            return loadJsSip().then(function (JsSIP) { dial(JsSIP, res); });
        }).catch(function (e) {
            finish(e && e.message ? errorText(e.message) : T.err_default, true);
        });
    }

    function dial(JsSIP, res) {
        if (JsSIP.debug && JsSIP.debug.disable) {
            JsSIP.debug.disable();
        }
        ua = new JsSIP.UA({
            sockets: [new JsSIP.WebSocketInterface(res.ws_url)],
            uri: res.uri,
            password: res.password,
            display_name: res.display_name || '',
            register: false,
            session_timers: false
        });
        ua.on('connected', function () {
            if (session) {
                return;
            }
            session = ua.call(res.target, {
                mediaStream: stream,
                pcConfig: { iceServers: res.ice_servers || [] },
                eventHandlers: {
                    peerconnection: function (e) {
                        e.peerconnection.addEventListener('track', function (ev) {
                            if (!ui.audio) {
                                ui.audio = new Audio();
                                ui.audio.autoplay = true;
                            }
                            ui.audio.srcObject = ev.streams[0];
                            ui.audio.play().catch(function () {});
                        });
                    },
                    progress: function () { setStatus(ui.callStatus, T.ringing); },
                    confirmed: onConfirmed,
                    ended: function () { finish(T.ended, false); },
                    failed: function (e) {
                        var busy = e && (e.cause === JsSIP.C.causes.BUSY || e.cause === JsSIP.C.causes.UNAVAILABLE);
                        finish(busy ? T.err_busy : T.err_failed, true);
                    }
                }
            });
            ui.callGo.classList.add('hidden');
            ui.inCall.classList.remove('hidden');
        });
        ua.on('disconnected', function () {
            if (!session) {
                finish(T.err_failed, true);
            }
        });
        ua.start();
    }

    function onConfirmed() {
        var started = Date.now();
        var limit = (cfg.max_seconds || 0) * 1000;
        timer = setInterval(function () {
            var s = Math.floor((Date.now() - started) / 1000);
            setStatus(ui.callStatus, T.in_call + ' ' + Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2));
            if (limit && Date.now() - started > limit + 5000) {
                hangup();
            }
        }, 1000);
        setStatus(ui.callStatus, T.in_call);
    }

    function toggleMute() {
        if (!session) {
            return;
        }
        muted = !muted;
        if (muted) { session.mute({ audio: true }); } else { session.unmute({ audio: true }); }
        ui.muteBtn.textContent = muted ? T.unmute : T.mute;
    }

    function hangup() {
        if (session) {
            try { session.terminate(); } catch (e) { /* already ended */ }
        } else {
            finish(T.ended, false);
        }
    }

    function finish(text, isError) {
        clearInterval(timer);
        timer = null;
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        }
        if (ua) {
            var old = ua;
            ua = null;
            setTimeout(function () { try { old.stop(); } catch (e) { /* stopped */ } }, 0);
        }
        session = null;
        muted = false;
        ui.muteBtn.textContent = T.mute;
        ui.pad.classList.remove('open');
        ui.inCall.classList.add('hidden');
        ui.callGo.classList.remove('hidden');
        ui.callGo.disabled = false;
        setStatus(ui.callStatus, text, isError);
    }

    // ------------------------------------------------------------ call-back

    function requestCallback() {
        var number = ui.cbNumber.value.replace(/[^0-9+]/g, '');
        if (number.length < 6) {
            setStatus(ui.cbStatus, T.err_bad_number, true);
            return;
        }
        ui.cbGo.disabled = true;
        setStatus(ui.cbStatus, T.connecting);
        api('callback', { w: WIDGET_ID, number: number, name: ui.cbName.value }).then(function (res) {
            if (res && res.success) {
                setStatus(ui.cbStatus, T.calling_you);
                ui.cbNumber.value = '';
                setTimeout(function () { ui.cbGo.disabled = false; }, 30000);
            } else {
                setStatus(ui.cbStatus, errorText(res && res.error), true);
                ui.cbGo.disabled = false;
            }
        }).catch(function () {
            setStatus(ui.cbStatus, T.err_default, true);
            ui.cbGo.disabled = false;
        });
    }

    // ------------------------------------------------------------ start

    function start() {
        api('config', { w: WIDGET_ID }, 'GET').then(function (res) {
            if (!res || !res.success || (!res.call && !res.callback)) {
                return;
            }
            cfg = res;
            T = TEXTS[cfg.language] || TEXTS.en;
            if (cfg.call && !(window.RTCPeerConnection && navigator.mediaDevices)) {
                cfg.call = false;
            }
            if (cfg.call || cfg.callback) {
                build();
            }
        }).catch(function () { /* not allowed on this site, or the PBX is unreachable */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
