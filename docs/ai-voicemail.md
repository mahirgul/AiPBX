# AI Voicemail Transcription & Executive Summary (Plugin)

> 🔌 **Plugin (`ai-voicemail`)**: Official bundled plugin. Enable it under **Integration → Plugin Catalog** (requires AiPBX 1.70.0 or newer). See [Plugin system & developer guide](plugin-development.md).

**Integration → AI Voicemail Transcription & Executive Summary** (`/plugins/ai-voicemail`) automatically transcribes voicemail recordings into text using high-accuracy speech-to-text (STT) models, then utilizes Large Language Models (LLMs) to generate structured executive summaries, action items, and urgency ratings.

---

## 🏗️ Architecture & Event Flow

```
  Caller Leaves Voicemail
        │
        ▼
  Asterisk `VoiceMail()` Application
        │ (Audio File Saved: `.wav`)
        ▼
  AiPBX Speech-to-Text Pipeline (Whisper / Deepgram / Groq)
        │
        ▼
  Core Dispatches Event: `voicemail.transcribed`
        │
        ▼
  Plugin Event Handler (`plugins/ai-voicemail/events/handler.php`)
        │
        ├── 1. PCI-DSS & PII Redaction Filter (Card numbers, CVV, National IDs)
        │
        ├── 2. OpenAI-Compatible LLM Prompting (OpenAI / Groq / Ollama / vLLM)
        │         ├── Executive Summary (1-2 sentences)
        │         ├── Urgency Assessment (Normal, Urgent, Callback Needed)
        │         └── Action Items & Contact Information
        │
        ▼
  Store Structured Summary in `mailbox_messages` Database
        │
        ├──► Portal Voicemail Player (`/voicemail`)
        ├──► Email Notification with Audio & Text Summary ([Mail Settings](mail.md))
        └──► Mobile Push Notification (Android / iOS)
```

---

## 🚀 Key Features

- **🎙️ Automatic Transcription**: Supports speech recognition engines configured under **AI → Cloud Services** ([Cloud AI](cloud-ai.md)) or local offline models ([Local AI Models](local-ai.md)).
- **📝 Executive Briefing**: Instead of reading long rambling audio transcripts, staff members receive an immediate 1–2 sentence summary of why the caller reached out.
- **⚡ Action Item Extraction**: Automatically extracts caller contact numbers, callback windows, account references, and core requests.
- **🌐 Multilingual Output**: Produces summaries in **Turkish**, **English**, or **Auto-Detect** (matches the caller's spoken language).
- **🔒 Automated PII & PCI-DSS Masking**: All transcripts pass through AiPBX's redaction engine prior to LLM dispatch:
  - Payment card numbers (13–19 digits validated with the Luhn algorithm) are replaced with `[REDACTED-CARD]`.
  - Sensitive personal IDs are replaced with `[REDACTED-ID]`.

---

## ⚙️ Configuration

1. Navigate to **Integration → Plugin Catalog** (`/plugins`) and enable **AI Voicemail Transcription & Executive Summary**.
2. Go to **Settings → Plugins → AI Voicemail** (`/plugins/ai-voicemail`).
3. Configure the OpenAI-compatible LLM parameters:

| Field | Description | Example Values |
|---|---|---|
| **API Base URL** | Endpoint URL of any OpenAI-compatible provider | `https://api.openai.com/v1`<br>`https://api.groq.com/openai/v1`<br>`http://127.0.0.1:11434/v1` (Local Ollama) |
| **API Key (Bearer Token)** | Authentication secret for the provider | `sk-proj-...` or `gsk_...` (Leave blank for local Ollama) |
| **Summarization LLM Model** | Model slug to execute prompt | `gpt-4o-mini`, `gpt-4o`, `llama-3.3-70b-versatile`, `mistral-small` |
| **Summary Output Language** | Language of the resulting summary | `tr` (Turkish), `en` (English), or `auto` (Caller Language) |

4. Click **Save Settings**.

---

## 📬 User Experience & Notification Channels

### 1. Web Portal (`/voicemail`)
When an extension owner logs into the web portal:
- Voicemail cards display the audio playback waveform.
- A purple badge indicates **AI Summary Ready**.
- The full transcript is displayed beneath the player with synchronized timestamp anchors.
- Executive summary points and action items are highlighted at the top.

### 2. Email Notifications
If **Email Voicemail** is enabled on the mailbox ([Voicemail Configuration](voicemail.md)):
- The outgoing email includes the `.wav` audio attachment.
- The email body contains the formatted executive summary card followed by the full transcript.

### 3. Mobile Apps (iOS & Android)
The AiPBX mobile app delivers push notifications for new voicemails containing the caller's name, caller ID, and a short snippet of the AI executive summary.

---

## 🛡️ Privacy & Offline Operation

- **Cross-Border Cloud AI Lock**: If your organization enforces strict data sovereignty under GDPR or KVKK ([Privacy & Compliance](privacy.md)), enabling the Cloud AI Lock blocks external AI APIs.
- **Local Private LLM**: You can point the **API Base URL** to a private on-premise Ollama or vLLM container (`http://192.168.1.50:11434/v1` running `llama3:8b`). Transcripts and audio never leave your local infrastructure.
