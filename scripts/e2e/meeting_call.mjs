#!/usr/bin/env node
// A real video meeting between two headless browsers with fake cameras, held for longer than any
// timer that has dropped guests before (v1.60.4 dropped everyone at 30 s and passed every other test).
//
//   node meeting_call.mjs https://<host> <meeting token>
//
// Needs Node 22 (built-in WebSocket) and Chrome (CHROME_BIN, or google-chrome/chromium on the PATH).
// No packages. Exit code 0 = both guests send and receive video and audio at the end.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [base, token] = process.argv.slice(2);
const HOLD_SECONDS = Number(process.env.MEETING_HOLD_SECONDS || 70);
if (!base || !token) {
    console.error('usage: meeting_call.mjs https://<host> <token>');
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

class Guest {
    constructor(name, port) {
        this.name = name;
        this.port = port;
        this.dir = mkdtempSync(join(tmpdir(), 'aipbx-meeting-'));
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

    async join() {
        await this.send('Page.navigate', { url: `${base}/meet/${token}` });
        for (let i = 0; i < 40; i++) {
            await sleep(500);
            if (await this.run(`!!document.querySelector('#btnJoinRoom')`)) break;
        }
        await sleep(1500);
        await this.run(`(() => { const n = document.querySelector('#userNameInput'); if (n) n.value = ${JSON.stringify(this.name)}; document.querySelector('#btnJoinRoom').click(); })()`);
        for (let i = 0; i < 90; i++) {
            await sleep(500);
            const s = await this.run(STATS);
            if (s && s.state === 'connected') return true;
        }
        return false;
    }

    stop() {
        try { this.ws?.close(); } catch { /* closing anyway */ }
        this.proc?.kill('SIGKILL');
        try { rmSync(this.dir, { recursive: true, force: true }); } catch { /* temp folder */ }
    }
}

const guests = [new Guest('Alice', 9331), new Guest('Bob', 9332)];
let failed = 0;
const check = (ok, what) => {
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${what}`);
    if (!ok) failed++;
};
try {
    for (const g of guests) {
        await g.start();
        check(await g.join(), `${g.name} connected`);
    }
    if (failed === 0) {
        await sleep(HOLD_SECONDS * 1000);
        const before = await Promise.all(guests.map((g) => g.run(STATS)));
        await sleep(5000);
        const after = await Promise.all(guests.map((g) => g.run(STATS)));
        guests.forEach((g, i) => {
            const [b, a] = [before[i], after[i]];
            check(a?.state === 'connected', `${g.name} still connected after ${HOLD_SECONDS} s (${a?.state})`);
            for (const key of ['videoIn', 'videoOut', 'audioIn', 'audioOut']) {
                const bytes = (a?.[key] ?? 0) - (b?.[key] ?? 0);
                check(bytes > 0, `${g.name} ${key}: ${bytes} bytes in 5 s`);
            }
        });
    }
} catch (e) {
    check(false, e.message);
} finally {
    guests.forEach((g) => g.stop());
}
console.log(`== ${failed === 0 ? 'meeting passed' : failed + ' failed'}`);
process.exit(failed === 0 ? 0 : 1);
