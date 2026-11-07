// Static showcase export: node export.mjs <base https url> <admin password> <out dir> [langs]
// For each language: signs in, collects the pages from the menu, saves the server's HTML and the
// API answers each page asks for while it loads (headless Chrome), and rewrites every portal path
// under /demo/<lang>/. The result needs no PHP, database or Asterisk: showcase.js answers the API
// calls from the recorded answers and makes the live views move.
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';

// The showcase container has a self-signed certificate; this tool talks only to it.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
const [base, password, out, langArg = 'en,tr,de'] = process.argv.slice(2);
const langs = langArg.split(',');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// --- a signed-in session ---
async function login() {
    let cookie = '';
    const keep = (res) => { const c = res.headers.getSetCookie?.() || []; for (const s of c) { const kv = s.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; } };
    let res = await fetch(base + '/login', { redirect: 'manual' }); keep(res);
    const page = await res.text();
    const csrf = (page.match(/name="csrf_token" value="([^"]*)"/) || [])[1] || '';
    const cap = page.match(/(\d+) \+ (\d+) = \?/);
    const body = new URLSearchParams({ csrf_token: csrf, username: 'admin', password, captcha_answer: cap ? String(+cap[1] + +cap[2]) : '' });
    res = await fetch(base + '/login', { method: 'POST', body, headers: { cookie }, redirect: 'manual' }); keep(res);
    return cookie;
}
const get = async (path, cookie) => (await fetch(base + path, { headers: { cookie }, redirect: 'follow' })).text();

// --- headless Chrome over CDP ---
async function chrome() {
    const bin = ['google-chrome', 'chromium'].find((c) => spawnSync('which', [c]).status === 0);
    const port = 9400 + Math.floor(Math.random() * 400);
    const proc = spawn(bin, ['--headless=new', '--no-sandbox', '--ignore-certificate-errors', `--remote-debugging-port=${port}`,
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'exp-'))}`, '--window-size=1400,900', 'about:blank'], { stdio: 'ignore' });
    let t;
    for (let i = 0; i < 50 && !t; i++) { await sleep(200); try { t = (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).find((x) => x.type === 'page'); } catch {} }
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener('open', r));
    let id = 0; const wait = new Map(); const handlers = [];
    ws.addEventListener('message', (e) => {
        const m = JSON.parse(e.data);
        if (m.id && wait.has(m.id)) { wait.get(m.id)(m.result || {}); wait.delete(m.id); } else if (m.method) handlers.forEach((h) => h(m));
    });
    const call = (method, params = {}) => new Promise((r) => { wait.set(++id, r); ws.send(JSON.stringify({ id, method, params })); });
    await call('Network.enable');
    return { call, on: (h) => handlers.push(h), close: () => proc.kill() };
}

// Every API answer a page asks for while it loads: key "METHOD path?query".
async function capture(browser, path) {
    const seen = new Map();
    const done = [];
    browser.on((m) => {
        if (m.method === 'Network.requestWillBeSent') {
            const u = new URL(m.params.request.url);
            if (u.origin === new URL(base).origin && (m.params.type === 'XHR' || m.params.type === 'Fetch')) {
                seen.set(m.params.requestId, `${m.params.request.method} ${u.pathname}${u.search}`);
            }
        } else if (m.method === 'Network.loadingFinished' && seen.has(m.params.requestId)) {
            done.push(m.params.requestId);
        }
    });
    await browser.call('Page.navigate', { url: base + path });
    await sleep(4000);
    const api = {};
    for (const rid of done) {
        const r = await browser.call('Network.getResponseBody', { requestId: rid });
        if (r && typeof r.body === 'string' && !r.base64Encoded) api[seen.get(rid)] = r.body;
    }
    return api;
}

// --- rewriting portal paths under /demo/<lang> ---
let segments = [];
function rewrite(text, lang) {
    const pre = `/demo/${lang}`;
    const re = new RegExp(`([\"'\`(=,]\\s*)/(${segments.join('|')})(?=[/\"'\`?#)\\s,]|$)`, 'g');
    return text.replace(re, `$1${pre}/$2`).replace(/href="\/"/g, `href="${pre}/dashboard"`);
}

const pathOf = (p) => p.split('?')[0].replace(/\/$/, '') || '/dashboard';
const save = (lang, p, html) => {
    const file = join(out, lang, pathOf(p), 'index.html');
    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, html);
};

for (const lang of langs) {
    const cookie = await login();
    await get(`/set-language?lang=${lang}`, cookie);
    const dash = await get('/dashboard', cookie);
    const fromMenu = [...dash.matchAll(/href="(\/[a-z0-9][a-z0-9/_-]*(\?[^"]*)?)"/g)].map((m) => m[1])
        .filter((p) => !/^\/(api|logout|set-language|assets|auth|integrations\/google|uploads)/.test(p));
    const pages = [...new Set(['/dashboard', ...fromMenu, '/my-phone', '/chat', '/apps/sms?n=1&peer=%2B15550142', '/plugins/sms', '/plugins/provider-templates'])];
    segments = [...new Set([...pages.map((p) => p.split(/[/?]/)[1]), 'assets', 'apps', 'plugins', 'dashboard'])].filter(Boolean).sort((a, b) => b.length - a.length);
    console.log(lang, pages.length, 'pages');
    const browser = await chrome();
    await browser.call('Network.setCookie', { name: 'PHPSESSID', value: cookie.split('=')[1], url: base });
    const apiAll = {};
    for (const p of pages) {
        const html = await get(p, cookie);
        if (!html.includes('</html>')) { console.log('skip', p); continue; }
        Object.assign(apiAll, await capture(browser, p));
        const head = `<script src="/demo/${lang}/demo-api.js"></script><script src="/demo/${lang}/showcase.js"></script>`;
        save(lang, p, rewrite(html, lang).replace(/<head>/i, `<head>${head}`));
        process.stdout.write('.');
    }
    browser.close();
    writeFileSync(join(out, lang, 'demo-api.js'), 'window.DEMO_API = ' + JSON.stringify(apiAll) + ';\n');
    writeFileSync(join(out, lang, 'segments.json'), JSON.stringify(segments));
    console.log(`\n${lang}: ${Object.keys(apiAll).length} API answers`);
}
