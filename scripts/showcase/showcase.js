/*
 * AiPBX static showcase (aipbx.bid/demo): the portal's own pages with made-up data, no server.
 * Nothing leaves the browser: API calls are answered from the answers recorded at export
 * (window.DEMO_API) or by the small call simulator below, forms that would save do nothing, and
 * phone/chat connections are never opened.
 */
(function () {
    'use strict';
    const API = window.DEMO_API || {};
    const realFetch = window.fetch.bind(window);

    // --- a small call centre that keeps moving ---
    const agents = [
        { extension: '1002', full_name: 'Mehmet Kaya', queues: ['sales', 'support'] },
        { extension: '1003', full_name: 'John Carter', queues: ['sales', 'vip'] },
        { extension: '1006', full_name: 'Sofia Rossi', queues: ['sales', 'support'] },
        { extension: '1001', full_name: 'Anna Weber', queues: ['billing'] },
        { extension: '1005', full_name: 'Lukas Braun', queues: ['billing'] },
    ];
    const queues = { sales: 'Sales', support: 'Technical support', billing: 'Billing', vip: 'Key accounts' };
    const callers = ['+15550142', '+15550155', '+15550163', '+15550171', '+15550120', '+447700900123', '+447700900456', '+15550188'];
    const pick = (a) => a[Math.floor(Math.random() * a.length)];
    const now = () => Math.floor(Date.now() / 1000);
    let seq = 0;
    let calls = [];       // { id, caller, queue, start, answered, agent }
    const stats = { total: 37, answered: 31, missed: 4, processed: 1240 };
    const paused = { '1005': { reason: 'Lunch', since: now() - 900 } };

    function tick() {
        const t = now();
        // A new caller now and then.
        if (calls.length < 6 && Math.random() < 0.45) {
            calls.push({ id: ++seq, caller: pick(callers), queue: pick(Object.keys(queues)), start: t, answered: 0, agent: null });
            stats.total++;
        }
        for (const c of calls) {
            if (!c.agent && t - c.start > 4 + Math.random() * 20) {
                const free = agents.filter((a) => a.queues.includes(c.queue) && !paused[a.extension] && !calls.some((x) => x.agent === a.extension));
                if (free.length) { c.agent = pick(free).extension; c.answered = t; stats.answered++; }
            }
        }
        // Calls end; one in ten callers gives up waiting.
        calls = calls.filter((c) => {
            if (c.agent && t - c.answered > 20 + Math.random() * 90) { stats.processed++; return false; }
            if (!c.agent && t - c.start > 40 && Math.random() < 0.1) { stats.missed++; return false; }
            return true;
        });
    }
    tick(); tick(); tick();
    setInterval(tick, 3000);

    const fmt = (s) => { s = Math.max(0, s); const m = Math.floor(s / 60); return String(m).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); };
    const waiting = () => calls.filter((c) => !c.agent).map((c) => ({ caller: c.caller, caller_num: c.caller, channel: 'PJSIP/trunk-' + c.id, queue: c.queue, queue_name: c.queue, queue_title: queues[c.queue], wait_time: now() - c.start }));
    const active = () => calls.filter((c) => c.agent).map((c) => ({ caller: c.caller, caller_num: c.caller, channel: 'PJSIP/' + c.agent + '-' + c.id, exten: c.agent, queue: c.queue, queue_name: c.queue, queue_title: queues[c.queue], duration: now() - c.answered }));

    const live = {
        '/api/dashboard_live.php': () => {
            const n = calls.length;
            return { success: true, active_calls: n, active_channels: n * 2 + 1, calls_processed: stats.processed, queue_waiting: waiting().length,
                today_total: stats.total, today_answered: stats.answered, today_missed: stats.missed,
                trunk_usage: [{ title: 'Main carrier (SIP)', in_use: Math.min(n, 30), max: 30 }, { title: 'Backup carrier', in_use: n > 4 ? 1 : 0, max: 10 }, { title: 'SBC over TLS', in_use: 0, max: 0 }] };
        },
        get_live_calls: () => ({ success: true, calls: waiting(), waiting_calls: waiting(), active_calls: active() }),
        get_board_stats: (base) => {
            const w = waiting();
            return Object.assign({}, base, { success: true, waiting_calls: w.length, max_wait: w.reduce((m, c) => Math.max(m, c.wait_time), 0),
                total_calls: stats.total, answered_calls: stats.answered, missed_calls: stats.missed,
                agents_logged_in: agents.length, agents_available: agents.filter((a) => !paused[a.extension] && !calls.some((c) => c.agent === a.extension)).length, agents_total: agents.length });
        },
        get_supervisor_agents: () => ({ success: true, agents: agents.map((a) => {
            const c = calls.find((x) => x.agent === a.extension);
            const p = paused[a.extension];
            return { extension: a.extension, full_name: a.full_name, queues: a.queues, is_logged_in: true, is_paused: !!p, pause_reason: p ? p.reason : '',
                pause_duration: p ? fmt(now() - p.since) : '', is_in_call: !!c, is_ringing: false, call_partner: c ? c.caller : '', connected_number: c ? c.caller : '',
                duration_formatted: c ? fmt(now() - c.answered) : '' };
        }) }),
    };

    // --- answers instead of the server ---
    function answer(method, url) {
        const u = new URL(url, location.href);
        const action = u.searchParams.get('action');
        if (live[u.pathname]) return live[u.pathname]();
        if (action && live[action]) {
            const recorded = API[`GET ${u.pathname}${u.search}`];
            return live[action](recorded ? JSON.parse(recorded) : {});
        }
        if (/sip_credentials|qr_code|passkey/.test(u.pathname)) return { success: false };
        if (u.pathname === '/api/notices.php') return { success: true, count: 0, open: [], recent: [] };
        const key = `${method} ${u.pathname}${u.search}`;
        if (API[key]) return API[key];
        const prefix = `${method} ${u.pathname}`;
        const near = Object.keys(API).find((k) => k === prefix || k.startsWith(prefix + '?'));
        if (near) return API[near];
        return { success: true, data: [], items: [], rows: [] };
    }
    const own = (url, method) => {
        const u = new URL(url, location.href);
        if (u.origin !== location.origin) return false;
        // The demo's own files (pages fetched by the page router, scripts, styles) are real.
        return !(method === 'GET' && u.pathname.startsWith('/demo/') && !u.pathname.includes('/api/'));
    };
    const body = (a) => (typeof a === 'string' ? a : JSON.stringify(a));

    window.fetch = function (input, init) {
        const url = typeof input === 'string' ? input : input.url;
        const method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        if (!own(url, method)) return realFetch(input, init);
        return Promise.resolve(new Response(body(answer(method, url)), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    };

    const xOpen = XMLHttpRequest.prototype.open;
    const xSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        this._demo = own(url, String(method).toUpperCase()) ? { method: String(method).toUpperCase(), url } : null;
        return xOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
        if (!this._demo) return xSend.apply(this, arguments);
        const text = body(answer(this._demo.method, this._demo.url));
        const def = (k, v) => Object.defineProperty(this, k, { value: v, configurable: true });
        def('readyState', 4); def('status', 200); def('responseText', text); def('response', text);
        setTimeout(() => { this.onreadystatechange && this.onreadystatechange(); this.onload && this.onload(); this.dispatchEvent(new Event('load')); this.dispatchEvent(new Event('loadend')); }, 30);
    };

    // No phone, chat or event connections.
    class Quiet {
        constructor() { this.readyState = 0; }
        send() {} close() {} addEventListener() {} removeEventListener() {}
    }
    window.WebSocket = Quiet;
    window.EventSource = Quiet;

    // Forms that would save do nothing; a search form (GET) still works on the page it is on.
    document.addEventListener('submit', function (e) {
        const f = e.target;
        if ((f.method || 'get').toLowerCase() !== 'get') { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);
    // Sign-out and downloads stay where they are.
    document.addEventListener('click', function (e) {
        const a = e.target.closest && e.target.closest('a[href]');
        if (a && /\/(logout|api\/)/.test(a.getAttribute('href'))) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);
})();
