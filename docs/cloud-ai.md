# Cloud AI services

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
| ElevenLabs | ✓ | ✓ (Scribe) | | | ✓ |
| Deepgram | | ✓ (Nova-3) | | | ✓ |
| Groq | | ✓ (Whisper large v3 turbo) | ✓ (Llama, Gemma, …) | | ✓ |
| OpenRouter | | | ✓ (hundreds of models) | | some models |

Each card has the key fields, a link to get a key, a **Test** button (a cheap authenticated call
that shows the provider's own error when the key is wrong) and this month's use: minutes of audio
turned into text, characters spoken and language-model tokens (table `ai_cloud_usage`).

Keys are stored encrypted in `sys_settings` as `ai_tts.<provider>.<field>` — the names the Cloud
TTS page used before, so existing accounts keep working. The Cloud TTS page now only shows the
status of its providers and links here; changing keys needs the admin role.

## Engine for each job

For speech and speech to text in Turkish, German and English, choose a running or downloaded
local model or a configured cloud provider (`ai_engine.<job>.<lang>` in `sys_settings`, values
`local:<model id>` or `cloud:<provider>`). AI features that come later (AI applications, voice
requests) use these choices, so switching between local and cloud needs no other change.

## Trying

*AI → Local models → Try: speech to text* lists the configured cloud providers next to the local
models: record from the microphone or upload a WAV file, pick the language, and compare the text
and the time each engine needs.

## Language models

Gemini, Gemma (Google AI Studio, Groq, OpenRouter), GPT and others are configured and tested here
already; they are used by the AI applications that follow (roadmap 5).
