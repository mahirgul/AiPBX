# AI Voicemail Summaries & Transcripts

## The Voicemail AI page

**AI Apps → Voicemail AI** (`/ai-voicemail`) has two tabs. **Settings** turns transcription and summaries on, chooses the
speech-to-text account and the language model of the summary (**AI → Cloud AI** accounts, one per task, off by
default) and the language of the summary (the language of the message, or one fixed language). **History** is one table
of everything Voicemail AI did, one row per voicemail: queued, running and failed jobs (with the reason, and a button to
cancel a waiting one) and the finished transcripts and summaries.


Voicemail AI is one page, **AI Apps → Voicemail AI** (`/ai-voicemail`), described above: transcription and summaries
use the speech-to-text and language-model accounts of [Cloud AI](cloud-ai.md); there is no separate LLM address, key or
model to enter. Since the version that introduced it, the community plugin of the same name is no longer needed: an upgrade
keeps the plugin enabled as the bundled one and removes the community copy, its settings and its stored summaries.

## Enable transcription

1. Add a speech-to-text account (and, for summaries, a language-model account) under **AI → Cloud AI**.
2. On **AI Apps → Voicemail AI → Settings** tick *Transcribe new voicemails* (and *Summarize*), and choose the accounts.
3. The **History** tab shows each voicemail's job and result.

The core transcribes the recording itself and emits `voicemail.transcribed` (extension, caller, duration, summary preview and
full transcript) for plugins that want to react to it. See the [plugin developer guide](plugin-development.md).

## Notifications and privacy

The voicemail player, e-mail and mobile notifications use the result stored by Voicemail AI. See
[voicemail](voicemail.md) and [mail settings](mail.md).

Transcript redaction runs when the privacy settings enable it, and the *block cloud AI* privacy lock stops every
request to a cloud account, so a voicemail is then not transcribed. Speech to text and summaries are cloud only (there are
no local speech or language models); Local TTS is for voices.
