# Cloud TTS

**AI → Cloud TTS** (admin by default) turns text into speech with a cloud text-to-speech service. The
result can be played on the page, downloaded as MP3, or saved straight into **Sounds**
for IVRs, queues and time conditions — no recording studio, and every announcement in the same voice.

## Providers

Set up one or more providers on the **Providers** tab:

| Provider | Credentials |
|----------|-------------|
| Google Cloud Text-to-Speech | API key, **or** a service account JSON key (for organisations that forbid API keys) |
| Amazon Polly | access key ID + secret and region; give the IAM user only `polly:SynthesizeSpeech` and `polly:DescribeVoices` |
| Microsoft Azure AI Speech | key and region of a *Speech* resource |
| ElevenLabs | API key; voices come from your account's voice library |
| OpenAI | API key; fixed voices that speak every language |

*Test connection* checks the credentials. The voices are listed live from each provider (cached for 12 hours). When a request fails, the
provider's own error message is shown.

Credentials are **encrypted** (libsodium) with a key kept outside the database — `AIPBX_SETTINGS_KEY`
in the environment or `/var/lib/aipbx/settings.key`, created by `install.sh` — and are never sent back
to the browser; the page only shows that a key is set (for a Google service account: its e-mail).
AiPBX calls the providers' REST APIs directly; no vendor SDK is installed.

## Synthesize

On the **Synthesize** tab choose provider, language, voice and speed and enter the text (up to 20,000
characters; long texts are split at sentence ends to fit each provider's limit). Then:

- **Listen** on the page,
- **Download** the MP3,
- **Save as announcement** — the audio is converted to 8 kHz mono WAV in the custom sounds folder and
  appears in **Sounds**, marked for *Apply* like an uploaded file.

The **History** lists every synthesis with its character count (the services bill per character),
duration, who made it and the announcement it became. Syntheses and settings changes are written to
the audit log.

## Turkish system prompts

AiPBX's complete Turkish prompt set was generated with the Google provider configured here (voice
`tr-TR-Wavenet-C`, 16 kHz masters) and ships as the `asterisk-core-sounds-tr` packages. To change or
add a prompt, see [Sounds & languages](sounds.md#turkish-prompts).
