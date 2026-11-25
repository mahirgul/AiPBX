// Static showcase export: node export.mjs <base https url> <admin password> <out dir> [langs]
// For each language: signs in, collects menu pages, saves their HTML and selected read-only API
// answers, and rewrites each portal path
// under /demo/<lang>/. The result needs no PHP, database or Asterisk: showcase.js answers the API
// calls from the recorded answers and makes the live views move.
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';

// The showcase container has a self-signed certificate; this tool talks only to it.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
const [base, passwordArg, out, langArg = 'en,tr,de'] = process.argv.slice(2);
const password = passwordArg === '-' ? process.env.SHOWCASE_PASS : passwordArg;
if (!password) throw new Error('Missing showcase fixture password');
const langs = langArg.split(',');
const source = new URL(base);
const revision = Date.now().toString(36);
const policy = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; media-src 'self' blob:; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; worker-src 'none'";
const readApis = [
    '/api/cc.php?action=get_status',
    '/api/cc.php?action=get_board_stats&queue=ALL&range=today',
    '/sip-live?poll=1&file=sip-20261114-120000.pcap&offset=0',
];

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
async function get(path, cookie) {
    const res = await fetch(base + path, { headers: { cookie }, redirect: 'follow', signal: AbortSignal.timeout(15000) });
    return res.ok && new URL(res.url).origin === source.origin && !/^\/(login|force-reset)(\/|$)/.test(new URL(res.url).pathname)
        ? res.text()
        : '';
}

// --- rewriting portal paths under /demo/<lang> ---
let segments = [];
function rewrite(text, lang) {
    const pre = `/demo/${lang}`;
    const re = new RegExp(`([\"'\`(=,]\\s*)/(${segments.join('|')})(?=[/\"'\`?#)\\s,]|$)`, 'g');
    return text.replace(re, `$1${pre}/$2`)
        .replace(/href="\/"/g, `href="${pre}/dashboard"`)
        .replace(/\/ai-agent\?(new=1|edit=\d+)(?=")/g, (_, query) => pathOf('/ai-agent?' + query) + '/');
}

const pathOf = (p) => {
    const agent = p.match(/^\/ai-agent\?(new=1|edit=\d+)$/);
    if (agent) return '/ai-agent/' + (agent[1] === 'new=1' ? 'new' : agent[1].replace('=', '/'));
    return p.split('?')[0].replace(/\/$/, '') || '/dashboard';
};
const clean = (html, csrf = '') => {
    let value = html.replaceAll(source.hostname, '192.0.2.10')
        .replaceAll('demo.aipbx.local', 'pbx.northwind.example')
        .replaceAll('aipbx-showcase.local', 'pbx.northwind.example')
        .replace(/\b10\.218\.49\.\d+\b/g, '192.0.2.10')
        .replace(/\bDemo-[0-9a-f]{12}\b/g, 'demo-only')
        .replace(/\bdata-pw="[^"]*"/g, 'data-pw="demo-only"')
        .replace(/("sip_password"\s*:\s*")[^"]*(")/g, '$1demo-only$2')
        .replace(/(&quot;sip_password&quot;\s*:\s*&quot;).*?(&quot;)/g, '$1demo-only$2')
        .replace(/<link\b[^>]*rel="manifest"[^>]*>/gi, '')
        .replace(/\ssrc="\/api\/[^"]*"/g, '');
    if (csrf) value = value.replaceAll(csrf, 'demo-only');
    return value;
};
const save = (lang, p, html) => {
    const file = join(out, lang, pathOf(p), 'index.html');
    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, html);
};

for (const lang of langs) {
    rmSync(join(out, lang), { recursive: true, force: true });
    const cookie = await login();
    await get(`/set-language?lang=${lang}`, cookie);
    const dash = await get('/dashboard', cookie);
    if (!dash.includes('class="nav-menu"')) throw new Error(`Demo sign-in failed for ${lang}`);
    const csrf = dash.match(/name="csrf-token" content="([^"]*)"/)?.[1] || '';
    const agent = await get('/ai-agent', cookie);
    const agentForms = [...agent.matchAll(/href="(\/ai-agent\?(?:new=1|edit=\d+))"/g)].map(match => match[1]);
    const fromMenu = [...dash.matchAll(/href="(\/[a-z0-9][a-z0-9/_-]*(\?[^"]*)?)"/g)].map((m) => m[1])
        .filter((p) => !/^\/(api\/|logout|set-language|assets|auth|integrations\/google|uploads)/.test(p));
    const pages = [...new Set([
        '/dashboard',
        ...fromMenu,
        ...agentForms,
        '/my-phone',
        '/chat',
        '/apps/sms?n=1&peer=%2B15550142',
        '/plugins/sms',
        '/plugins/provider-templates',
        '/mail-templates',
        '/phone-keys?user=2',
        '/ivr-data-steps',
        '/sip-calls?file=sip-20261114-120000.pcap',
        '/sip-call?file=sip-20261114-120000.pcap&id=call-1002-01%40northwind.example',
    ])];
    segments = [...new Set([...pages.map((p) => p.split(/[/?]/)[1]), 'assets', 'apps', 'plugins', 'dashboard'])].filter(Boolean).sort((a, b) => b.length - a.length);
    console.log(lang, pages.length, 'pages');
    const apiAll = {};
    for (const path of readApis) {
        const body = await get(path, cookie);
        if (body) {
            try { JSON.parse(body); apiAll[`GET ${path}`] = clean(body, csrf); } catch {}
        }
    }
    for (const p of pages) {
        const html = await get(p, cookie);
        if (!html.includes('</html>') || !html.includes('class="nav-menu"')) { console.log('skip', p); continue; }
        const head = `<meta http-equiv="Content-Security-Policy" content="${policy}"><meta name="robots" content="noindex"><script src="/demo/${lang}/demo-api.js?v=${revision}"></script><script src="/demo/${lang}/showcase.js?v=${revision}"></script>`;
        save(lang, p, rewrite(clean(html, csrf), lang).replace(/<head>/i, `<head>${head}`));
        process.stdout.write('.');
    }
    writeFileSync(join(out, lang, 'demo-api.js'), 'window.DEMO_API = ' + JSON.stringify(apiAll) + ';\n');
    writeFileSync(join(out, lang, 'segments.json'), JSON.stringify(segments));
    console.log(`\n${lang}: ${Object.keys(apiAll).length} API answers`);
}
