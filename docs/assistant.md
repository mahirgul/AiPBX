# PBX Assistant (Plugin, experimental)

> **Official plugin (`ai-assistant`), experimental.** Enable it under **Integration → Plugin Catalog**; its settings are on **AI Settings → PBX Assistant Settings** (`/ai-assistant`). The chat is **Panel → PBX Assistant** (`/assistant`). Its answers and behaviour may still change: check what it proposes before you apply it.

The page at **AI → Dialplan editor** turns a plain-language sentence into a
PBX change — with three hard rules that are not excuses, they are the design:

1. **You always see the change before it happens.** "Forward 101 to 203"
   shows *Forward an extension: 101 → 203* and waits for your Apply. A
   preview nobody confirmed does nothing.
2. **It applies nothing on its own.** A confirmed change goes through the
   exact same services as the portal's own forms (extensions, ring groups,
   queues, time conditions) — the same validation, the same pending sync,
   the same alarms. The assistant is one more way to fill a form, not a
   different way to touch Asterisk.
3. **A language model may only NAME a change.** Every value it names is
   checked against what exists on this server, and anything outside a fixed
   list of actions is refused outright. A wrong model produces a refusal,
   never a broken phone system.

## What it understands

Without any AI configured (English and Turkish, rule-based, offline):

| You type | Change |
|----------|--------|
| forward 101 to 203 · 101'i 203'e yönlendir | Forward extension 101 → 203 |
| turn off voicemail for 102 | Voicemail off for 102 |
| enable dnd for 101 · 101 rahatsız etmeyin aç | Do not disturb on for 101 |
| add 101 and 102 to the sales ring group | Ring group members |
| ring group sales timeout 45 | Ring group ring time |
| queue support timeout 30 | Queue ring time |
| announcement on 101 saying "we are closed" | Voicemail greeting (quoted text) |

When the rules are not confident, a language model is asked — first the
local runtime (`aipbx-ai`, offline), then a cloud provider from **AI → Cloud
AI services** (Gemini, Groq, NVIDIA, OpenRouter, Qwen, or any
OpenAI-compatible address, which is how a local **Ollama** on
`127.0.0.1:11434/v1` plugs in with zero extra code). No model is a single
point of failure: with none configured, the rules still resolve the everyday
phrasings and everything else is refused with an explanation.

## The audit trail and undo

Every applied change lands in the table at the bottom of the page: when,
who, the sentence you typed, what was changed, and whether it was resolved
by the rules or a model. Forwards and voicemail switches record the previous
value and can be undone with one click from the same table. Changes made by
a colleague stay visible — this page is also "who did the phones change".

## The Echo box

"Ask about the phone system" on the same page answers *questions about
calls* — read-only, from this server's own data: calls today, the last
hour, answered vs. unanswered, busiest callers, and (with signaling capture
on) captured SIP messages and error responses. The numbers come from fixed
queries; a configured model only narrates them. When it cannot answer a
question it lists what it can answer — it does not improvise.

See [Signaling capture](signaling.md) for the capture side of the Echo box.

## API

The page uses `api/assistant.php` (admin session + CSRF token):
`action=plan` (preview only), `action=apply` (one plan, re-validated
server-side — the browser's copy is a suggestion, the server's validation
is what lands), `action=changes`, `action=rollback`, `action=echo`.

## Knowledge base and shortcuts

- **"How do I …" questions** are answered from the Knowledge Base collections chosen for the user's
  language on **AI Settings → PBX Assistant Settings** (English ones when the language has none).
  Two short guides ship with AiPBX, *AiPBX Help (English)* and *AiPBX Yardım (Türkçe)*; they can be
  edited, switched off or replaced. A clear match in a guide is answered at once, without a model; the
  answer lists its source.
- **A page or setting name** (`pjsip`, `codec`, `fail2ban`) lists the pages where it is, with links that
  open the page and highlight the setting. Only pages the user's role may see are listed.
- **Greetings and general questions** go to the language model chosen for the Assistant, which may only
  answer in a sentence or two and never claims to have changed anything.

The former community `ai-assistant` plugin (an API console at `/plugins/ai-assistant`) has been replaced
by this plugin: an upgrade removes an installed copy, its settings and its API token, and keeps the
plugin on or off as it was.
