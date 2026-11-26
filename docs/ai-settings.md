# AI Screens & Architecture (`/ai-agent`, `/ai-local`, `/ai-cloud`, `/ai-tts`, `/ai-voicemail`, `/ai-call-analysis`)

The **AI** and **AI Settings** menus organize voice agents, local models, cloud providers, and per-feature tools into dedicated tabbed pages:

- **AI Voice Agent (`/ai-agent`):** Built-in voice agents bridged via Asterisk ARI or AudioSocket, supporting **Cascade (STT → LLM → TTS)** and **Realtime** modes, structured prompts, Knowledge Base RAG, saved external DB queries, inbound and outbound DTMF (`collect_dtmf`, `send_dtmf`), and outbound IVR navigation (`dial_and_navigate`) with phonebook `sub_extension` lookup. The navigator gives up on an answering machine or a "number cannot be reached" announcement instead of connecting, and on a menu that keeps repeating itself it tries another key first and stops after the fourth time.
- **Local TTS (`/ai-local`):** One table of the voices and small detectors that run on the PBX (EMA, Piper, Kokoro, Silero VAD, Smart Turn); install or remove the local runtime (`aipbx-ai`), download, run and stop them. Speech to text and language models are cloud only.
- **Cloud AI (`/ai-cloud`):** Accounts only, no tabs: one account is one provider with one set of settings (model, voice, language, temperature ...; the rarely changed ones under *Advanced*); *Copy* makes a second account of the same provider for a second model. There is no default account: every AI feature (agent, voicemail, call analysis, PBX Assistant, reminders) picks its own.
- **AI TTS Studio (`/ai-tts`):** Generate speech from text and save directly as PBX announcements, with synthesis history.
- **AI Voicemail (`/ai-voicemail`):** Enable/disable voicemail transcription and summary, choose per-feature engines, and inspect recent `ai_jobs` entries.
- **Call Quality Analysis (`/ai-call-analysis`):** Configure automated Call Quality Review (Auto-QA), review criteria, and inspect recent `ai_jobs` entries.
- **Knowledge Base (`/ai-knowledge`) & PBX Assistant (`/pbx-assistant`):** Assistant model binding, TXT/RAG knowledge base, and chat retention (defaults to 10 days; nightly pruning).

Legacy URLs `/ai-models` and `/ai-settings` permanently redirect (`301`) to `/ai-local` (with `?tab=cloud` → `/ai-cloud`, `?tab=assistant` → `/pbx-assistant`, `?tab=voicemail` → `/ai-voicemail`, `?tab=cdr` → `/ai-call-analysis`).

See also: [Cloud AI](cloud-ai.md) · [Local AI](local-ai.md)

## Realtime voice and the Asterisk media bridge

1. Open **AI Settings → Cloud AI → Connections** and configure a provider account with its API key.
   Set **Realtime model** if you want to override the account's default. OpenAI-compatible accounts also
   offer a **Realtime protocol** selector (automatic, OpenAI Realtime GA or Beta) and a WebSocket URL.
2. Open **AI → AI Agent**, select the account in the speech-to-speech field and choose a voice.
   Save and apply the changes. Leave that account field off to use Cascade.

Realtime is the connection from the AI service to the model provider, such as OpenAI Realtime or Gemini Live.
Asterisk connects to the AI service through ARI when ARI is configured, and through AudioSocket otherwise.

## Architecture Overview

![AI Architecture](img/ai-architecture.svg)

## AI Voice Agent Call & Tool Flow

![AI Voice Agent Call Flow](img/ai-voice-flow.svg)

Chat history defaults to ten days and is pruned nightly, including old messages in active conversations. Choosing **Off** deletes saved chats immediately and prevents subsequent chat persistence. PBX change audit records remain separate.
