#!/usr/bin/env node
// A conference call started from a group chat, between two signed-in headless browsers with fake
// cameras: the first presses "video call" in the group, the second joins from the chat card. Held for
// longer than any timer that has dropped video before (v1.60.4 dropped everyone at 30 s).
//
//   node chat_call.mjs https://<host> <user A> <password A> <user B> <password B> <group title>
//
// Both users need an extension with the web phone and video allowed, and must be members of the group.
//
// Needs Node 22 (built-in WebSocket) and Chrome (CHROME_BIN, or google-chrome/chromium on the PATH).
// No packages. Exit code 0 = both hear each other, both send video, the picture of the speaker arrives, and the
// chat says the call ended after both have left.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [base, userA, passA, userB, passB, group] = process.argv.slice(2);
const HOLD_SECONDS = Number(process.env.MEETING_HOLD_SECONDS || 70);
if (!base || !group) {
    console.error('usage: chat_call.mjs https://<host> <user A> <password A> <user B> <password B> <group title>');
    process.exit(2);
}
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const chrome = process.env.CHROME_BIN
    || ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'].find((c) => spawnSync('which', [c]).status === 0);
if (!chrome) {
    console.error('FAIL  no Chrome found (set CHROME_BIN)');
    process.exit(2);
}

// Runs in the page before its own scripts, to reach the page's peer connection.
const WATCH = `(() => {
    const Original = window.RTCPeerConnection;
    window.RTCPeerConnection = function (...args) { const pc = new Original(...args); window.__pc = pc; return pc; };
    window.RTCPeerConnection.prototype = Original.prototype;
})()`;
const STATS = `(async () => {
    const pc = window.__pc;
    if (!pc) return null;
    const out = { state: pc.connectionState, ice: pc.iceConnectionState, videoIn: 0, videoOut: 0, audioIn: 0, audioOut: 0 };
    (await pc.getStats()).forEach((r) => {
        if (r.type === 'inbound-rtp') out[r.kind + 'In'] += r.bytesReceived || 0;
        if (r.type === 'outbound-rtp') out[r.kind + 'Out'] += r.bytesSent || 0;
    });
    return out;
})()`;

class Member {
    constructor(user, pass, port) {
        this.name = user;
        this.pass = pass;
        this.port = port;
        this.dir = mkdtempSync(join(tmpdir(), 'aipbx-chatcall-'));
        this.nextId = 1;
        this.pending = new Map();
    }

    async start() {
        this.proc = spawn(chrome, ['--headless=new', '--no-sandbox', '--ignore-certificate-errors',
            '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required',
            `--remote-debugging-port=${this.port}`, `--user-data-dir=${this.dir}`, 'about:blank'], { stdio: 'ignore' });
        let target = null;
        for (let i = 0; i < 60 && !target; i++) {
            await sleep(500);
            try {
                const list = await (await fetch(`http://127.0.0.1:${this.port}/json`)).json();
                target = list.find((t) => t.type === 'page');
            } catch { /* not up yet */ }
        }
        if (!target) throw new Error(`${this.name}: Chrome did not start`);
        this.ws = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { this.ws.onopen = resolve; this.ws.onerror = reject; });
        this.ws.onmessage = (event) => {
            const msg = JSON.parse(event.data);
            if (msg.id && this.pending.has(msg.id)) {
                this.pending.get(msg.id)(msg);
                this.pending.delete(msg.id);
            }
        };
        await this.send('Page.enable');
        await this.send('Page.addScriptToEvaluateOnNewDocument', { source: WATCH });
    }

    send(method, params = {}) {
        const id = this.nextId++;
        return new Promise((resolve) => {
            this.pending.set(id, resolve);
            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    async run(expression) {
        const res = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        return res.result?.result?.value;
    }

    async until(expression, seconds) {
        for (let i = 0; i < seconds * 2; i++) {
            if (await this.run(expression)) return true;
            await sleep(500);
        }
        return false;
    }

    // Signs in, opens the chat page and the group, with the web phone registered.
    async openGroup() {
        await this.send('Page.navigate', { url: `${base}/login` });
        if (!await this.until(`!!document.querySelector('input[name="password"]')`, 20)) return 'no sign-in page';
        await this.run(`(() => { document.querySelector('input[name="username"]').value = ${JSON.stringify(this.name)};
            document.querySelector('input[name="password"]').value = ${JSON.stringify(this.pass)};
            const form = document.querySelector('input[name="password"]').form;
            // The sign-in page may ask a sum ("3 + 4 = ?").
            const sum = form.innerText.match(/(\\d+)\\s*\\+\\s*(\\d+)/);
            const answer = document.querySelector('#captcha_answer');
            if (sum && answer) answer.value = String(Number(sum[1]) + Number(sum[2]));
            form.submit(); })()`);
        await sleep(3000);
        await this.send('Page.navigate', { url: `${base}/chat` });
        if (!await this.until(`typeof window.CHAT_TOKEN === 'string' && window.CHAT_TOKEN !== ''`, 20)) return 'chat page did not open (sign-in refused?)';
        if (!await this.until(`typeof headerSipRegistered !== 'undefined' && headerSipRegistered === true`, 30)) return 'web phone did not register';
        const opened = await this.run(`(async () => {
            const res = await fetch('/chat/api/conversations', { headers: { Authorization: 'Bearer ' + window.CHAT_TOKEN } });
            const conv = ((await res.json()).conversations || []).find((c) => c.type === 'group' && c.title === ${JSON.stringify(group)});
            if (!conv) return false;
            await openConversation(conv);
            return true;
        })()`);
        return opened ? '' : 'group not found';
    }

    connected() {
        return this.until(`(async () => { const s = await ${STATS}; return !!s && s.state === 'connected'; })()`, 45);
    }

    stop() {
        try { this.ws?.close(); } catch { /* closing anyway */ }
        this.proc?.kill('SIGKILL');
        try { rmSync(this.dir, { recursive: true, force: true }); } catch { /* temp folder */ }
    }
}

const [a, b] = [new Member(userA, passA, 9341), new Member(userB, passB, 9342)];
let failed = 0;
const check = (ok, what) => {
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${what}`);
    if (!ok) failed++;
};
try {
    for (const m of [a, b]) {
        await m.start();
        const problem = await m.openGroup();
        check(problem === '', `${m.name} signed in with the group open${problem ? ': ' + problem : ''}`);
    }
    if (failed === 0) {
        check(await a.run(`document.getElementById('active-group-video-btn').style.display !== 'none'`), 'the group shows the video call button');
        await a.run(`startChatCall(true)`);
        check(await a.connected(), `${a.name} is in the call`);
        check(await b.until(`!!document.querySelector('[data-call-join]')`, 15), `${b.name} got the "call started" card with Join`);
        await b.run(`document.querySelector('[data-call-join]').click()`);
        check(await b.connected(), `${b.name} joined from the card`);
    }
    if (failed === 0) {
        await sleep(HOLD_SECONDS * 1000);
        const before = await Promise.all([a, b].map((m) => m.run(STATS)));
        await sleep(5000);
        const after = await Promise.all([a, b].map((m) => m.run(STATS)));
        const moved = (i, key) => (after[i]?.[key] ?? 0) - (before[i]?.[key] ?? 0);
        [a, b].forEach((m, i) => {
            check(after[i]?.state === 'connected', `${m.name} still connected after ${HOLD_SECONDS} s (${after[i]?.state})`);
            for (const key of ['audioIn', 'audioOut', 'videoOut']) {
                check(moved(i, key) > 0, `${m.name} ${key}: ${moved(i, key)} bytes in 5 s`);
            }
        });
        // "Follow the talker": the speaker's picture goes to the others, so at least one side receives video.
        check(moved(0, 'videoIn') + moved(1, 'videoIn') > 0, `the speaker's picture arrives: ${moved(0, 'videoIn')} + ${moved(1, 'videoIn')} bytes in 5 s`);
        await a.run(`headerPhoneHangup()`);
        await sleep(1500);
        await b.run(`headerPhoneHangup()`);
        check(await b.until(`!!document.querySelector('[data-call-ended]') && !document.querySelector('[data-call-join]')`, 15),
            'the chat says the call ended and Join is gone');
    }
} catch (e) {
    check(false, e.message);
} finally {
    [a, b].forEach((m) => m.stop());
}
console.log(`== ${failed === 0 ? 'chat call passed' : failed + ' failed'}`);
process.exit(failed === 0 ? 0 : 1);
