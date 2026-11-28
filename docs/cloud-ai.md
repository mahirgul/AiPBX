# Cloud AI services (Plugin)

> **Plugin (`aicloud` / `ai-voicemail`)**: Optional plugins from the official catalog. Enable them under **Integration → Plugin Catalog** (see [Plugin system & developer guide](plugin-development.md)).

**AI → Cloud services** (admin only) holds the accounts of cloud AI providers in one place and
chooses, for each job and language, which engine AI features use: a local model of this server
(see [Local AI models](local-ai.md)) or a cloud provider.

> A cloud engine sends audio or text to that provider. Check its terms and your data-protection
> duties (KVKK/GDPR) before choosing one. Local models keep everything on the server.

## Providers

| Provider | Speech | Speech to text | Language models | Embeddings | Free tier |
|----------|:------:|:--------------:|:---------------:|:----------:|:---------:|
| Google AI Studio (Gemini, Gemma) | | ✓ (Gemini) | ✓ | ✓ | ✓ |
| OpenAI | ✓ | ✓ (gpt-4o-mini-transcribe) | ✓ | ✓ | |
| Microsoft Azure AI Speech | ✓ | ✓ | | | ✓ |
| Google Cloud Text-to-Speech | ✓ | | | | ✓ |
| Amazon Polly | ✓ | | | | ✓ |
| ElevenLabs | ✓ (TTS & Music) | ✓ (Scribe) | | | ✓ |
| Deepgram | | ✓ (Nova-3) | | | ✓ |
| Groq | | ✓ (Whisper large v3 turbo) | ✓ (Llama, Gemma, …) | | ✓ |
| OpenRouter | | | ✓ (hundreds of models) | | some models |
| NVIDIA NIM (build.nvidia.com) | | | ✓ (Llama, Nemotron, Qwen, DeepSeek …) | | trial credits |
| Qwen (Alibaba Cloud Model Studio) | | | ✓ (qwen-plus, qwen-turbo, qwen-max …) | | ✓ (new accounts) |
| OpenAI-compatible (own address) | | | ✓ (any service with the OpenAI chat API) | | — |

**Services** lists the accounts that are set up: what each can do, this month's use (minutes of
audio turned into text, characters spoken, language-model tokens; table `ai_cloud_usage`) and
*Test* / *Edit* / *Delete*. **Add** opens the same form for a new account: choose the service,
enter its fields (with a link to get a key) and its monthly limits. *Test* is a cheap authenticated
call that shows the provider's own error when the key is wrong; in the open form it tests what is
typed, before saving.

Keys are stored encrypted in `sys_settings` as `ai_tts.<provider>.<field>` — the names the Cloud
TTS page used before, so existing accounts keep working. Accounts are set up only here; the Cloud
TTS page uses the accounts that can speak.

## Languages

There is no language setting. AI features work in a built-in table of 84 languages (code, name, locale): the name is
what language models are told to answer in ("Turkish"), the locale is what speech recognition and synthesis are asked
for (`tr-TR`, `pt-BR`). An AI agent chooses its language with `{language, tr}` in its instructions (see
[AI applications](ai-apps.md)); voicemail, call analysis and the PBX Assistant find it themselves.

## Features

One table turns the AI features on and chooses the service each one uses:

| Feature | Job |
|---------|-----|
| Voicemail: write new messages down | speech to text |
| Voicemail: one-sentence summary | language model |
| Call recordings: transcript | speech to text |
| Call recordings: summary | language model |
| Call quality review | language model |

*By language* (the default) uses the engine of the caller's language from the table above; or pick
one local model or account for this feature only (`ai_use.<feature>`). A chosen account that is
removed falls back to the engine of the language; one over its monthly limit falls back to a local
model, as above.

## Monthly limits

Each provider can have a monthly limit for **speech to text** (minutes), **speech** (characters)
and the **language model** (tokens); 0 or empty means no limit (`ai_limit.<provider>.stt_min`,
`.tts_chars`, `.llm_tokens`). The provider's row shows this month's use, a **Quota %** badge from
80 % and **Quota exceeded** at 100 %.

Over the limit, that provider gets no more requests of that kind until the next month. A job whose
engine is that provider falls back to a ready local model of the same language (speech to text and
speech; a language model has no local fallback, so the feature works without it — e.g. no call
summary). Without a local model the job simply does not run.

## Call quality review

Criteria for scoring recorded calls, and the automatic review with its daily maximum: see
[Call recordings → Quality review](recordings.md#quality-review).

## Trying

*AI → Local models → Try: speech to text* lists the configured cloud providers next to the local
models: record from the microphone or upload a WAV file, pick the language, and compare the text
and the time each engine needs.

## Language models

Large language models are not run on the PBX itself (most servers cannot carry them); they come
from the providers above. They are used by:

- **AI applications** — routing by speech and voice requests with *Understanding: cloud model*
  ([AI applications](ai-apps.md#understanding)),
- **voicemail as text** — the one-sentence summary (below).

The model of Google AI, OpenAI, Groq and OpenRouter is fixed (a small, fast one: gemini-2.5-flash,
gpt-4.1-mini, llama-3.3-70b-versatile, google/gemini-2.5-flash). For the three providers below the
card has a **Model** field; *Test* sends one tiny chat request, so a wrong model name shows at once.

| Provider | Address | Model (default) |
|----------|---------|-----------------|
| NVIDIA NIM | `https://integrate.api.nvidia.com/v1` | `meta/llama-3.3-70b-instruct`; any model of build.nvidia.com, e.g. `qwen/qwen3-235b-a22b` |
| Qwen (Alibaba Cloud Model Studio) | international `https://dashscope-intl.aliyuncs.com/compatible-mode/v1` (default); mainland China `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen-plus` |
| OpenAI-compatible | your address ending in `/v1`, e.g. `https://api.deepseek.com/v1`, `https://api.mistral.ai/v1`, `http://10.0.0.5:11434/v1` (Ollama on another server) | required; the key is optional |

## Voicemail as text

*AI → Cloud services → Features*: a new voicemail is written down with the
**speech-to-text** engine chosen for it, by default the one of the PBX's prompt language (a small local model such as Vosk or
Whisper, or a cloud provider) and, if *summary* is ticked, summarised in one sentence by the
**language model** chosen for the summary ("John Smith wants to move tomorrow's 10:00 meeting to Friday").

- The **voicemail e-mail** gets both under the list of details: template variable `{ai_text}` of
  the *voicemail* template (*E-mail → Templates*; a changed template needs `{ai_text}` added).
- The **app notification** shows the summary (or the start of the transcript) under the caller
  (Android 1.0.66).
- *My Phone → Voicemail* and the mobile apps' voicemail list show it next to the message.

Each message is processed once (table `voicemail_ai`); the e-mail comes first and the push uses the
result. Voicemail is handled by Asterisk's own user, which cannot read the AI keys, so the work is
done by the portal on the same server (`api/voicemail_ai.php`, only from 127.0.0.1, signed).
Nothing here can hold back the e-mail or the notification: when the engine fails, they go out
without the text. With a cloud engine the recording (speech to text) or its text (summary) is sent
to that provider.
