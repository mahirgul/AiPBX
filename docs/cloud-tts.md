# Cloud TTS (Plugin)

> **Plugin (`ai-tts`)**: Optional plugin from the official catalog. Enable it under **Integration → Plugin Catalog** (see [Plugin system & developer guide](plugin-development.md)).

**AI → Cloud TTS** (admin by default) turns text into speech with a cloud text-to-speech
service and generates instrumental **Hold Music (MOH)** with ElevenLabs. Results can be played on
the page, downloaded as MP3, or saved straight into **Sounds** (as announcements for IVRs, queues
and time conditions) or **Music on Hold** classes — no recording studio needed.

## Providers

Set up one or more providers under **AI → Cloud services → Services → Add** ([Cloud AI services](cloud-ai.md)):

| Provider | Credentials |
|----------|-------------|
| Google Cloud Text-to-Speech | API key, **or** a service account JSON key (for organisations that forbid API keys) |
| Amazon Polly | access key ID + secret and region; give the IAM user only `polly:SynthesizeSpeech` and `polly:DescribeVoices` |
| Microsoft Azure AI Speech | key and region of a *Speech* resource |
| ElevenLabs | API key; voices come from your account's voice library (also enables the **Hold Music (MOH)** tab via ElevenLabs Music API) |
| OpenAI | API key; fixed voices that speak every language |

*Test connection* checks the credentials. The voices are listed live from each provider (cached for 12 hours). When a request fails, the
provider's own error message is shown.

Credentials are **encrypted** (libsodium) with a key kept outside the database — `AIPBX_SETTINGS_KEY`
in the environment or `/var/lib/aipbx/settings.key`, created by `install.sh` — and are never sent back
to the browser; the page only shows that a key is set (for a Google service account: its e-mail).
AiPBX calls the providers' REST APIs directly; no vendor SDK is installed.

## Speech & Prompts

On the **Speech & Prompts** tab (`/ai-tts`), choose provider, language, voice and speed. You can
either pick one of the **8 built-in Asterisk / PBX prompt templates** (which fills editable text in
the portal language) or enter custom text up to 20,000 characters (long texts are split at sentence
ends to fit each provider's limit):

- Welcome IVR greeting (`welcome_ivr`)
- After-hours greeting (`after_hours`)
- Queue waiting announcement (`queue_wait`)
- Voicemail unavailable greeting (`voicemail_greeting`)
- Holiday closure notice (`holiday_closed`)
- Call recording notice (`recording_notice`)
- Invalid key option (`invalid_option`)
- Goodbye prompt (`goodbye`)

After synthesizing, you can:

- **Listen** on the page,
- **Download** the MP3,
- **Save as announcement** — the audio is converted to 8 kHz mono WAV in the custom sounds folder and
  appears in **Sounds**, marked for *Apply* like an uploaded file.

**Sounds → Announcements** also includes a direct button to open `/ai-tts`.

## Hold Music (MOH)

On the **Hold Music (MOH)** tab (`/ai-tts?tab=music`), any configured **ElevenLabs** account from
**AI → Cloud services** can generate custom **Music on Hold** tracks via the ElevenLabs Music API
(`POST /v1/music`):

- **5 style presets** tuned for 8 kHz telephony playback — *Warm Acoustic Lounge*, *Uplifting Corporate Piano*,
  *Calm Ambient Lo-Fi*, *Smooth Jazz Trio*, and *Classical Chamber Strings* — or a custom description
  up to 2,000 characters.
- **Duration, model & vocals:** choose `30 s`, `60 s` (default), `120 s` or `180 s`, select a model
  (`music_v1`, `music_v2`, `music_v2_5`), and keep **Instrumental only (no vocals)** enabled (default)
  for loopable background tracks.
- **Listen, Download or Save to Hold Music:** the audio can be played, downloaded as MP3, or saved
  directly into any existing MOH class (default `default`), converted to 8 kHz 16-bit mono WAV under
  `/var/lib/asterisk/moh/<class>/` and queued for *Apply*.

**Sounds → Music on Hold** includes a direct shortcut to `/ai-tts?tab=music`.

## History

The **History** tab (`/ai-tts?tab=history`) lists every speech synthesis and hold music generation
with its character count (the services bill per character), duration, who created it, and the
announcement or MOH track (`moh:<class>/<name>`) it became. Generations, saves and settings changes
are written to the audit log.

## Turkish system prompts

AiPBX's complete Turkish prompt set was generated with the Google provider configured here (voice
`tr-TR-Wavenet-C`, 16 kHz masters) and ships as the `asterisk-core-sounds-tr` packages. To change or
add a prompt, see [Sounds & languages](sounds.md#turkish-prompts).
