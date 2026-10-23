# AiPBX Plugin Catalog (Official & Community)

This directory contains the plugins bundled with AiPBX: **official plugins** and **community plugins** (listed separately below). Starting with the modular plugin architecture, optional external integrations, telemetry collectors, and AI modules live here as self-contained plugin manifests (`<slug>/plugin.json`) rather than being force-enabled on every PBX installation.

- **Lean Core by Default**: Fresh installations start with a clean, fast core PBX. Administrators can browse, install, enable, configure, or disable any plugin with a single click under **Integrations → Plugins** (`/plugins`) or via the **REST API v1** (`POST /api/v1/plugins/{slug}/enable`).
- **Zero-Disruption Upgrades**: Existing servers that already have Google Sign-In, Microsoft Teams, Website Call Widgets, or AI settings configured automatically keep those plugins enabled during database migration.
- **Custom `.zip` Plugins**: Third-party developers can also package custom plugins into a `.zip` archive and upload them directly through **Integrations → Plugins → Upload Custom Plugin (`.zip`)** (extracted into `/var/lib/aipbx/plugins/<slug>/`).

---

## Official Plugins (`type: "official"`)

| Slug | Category | Author | Description |
|---|---|---|---|
| [`google-auth`](./google-auth/plugin.json) | `integration` | AiPBX Official Team | Google Sign-In (OAuth 2.0) for web portal and mobile apps |
| [`ms-teams`](./ms-teams/plugin.json) | `integration` | AiPBX Official Team | Microsoft Teams Direct Routing SBC trunking, PowerShell generator, and call webhooks |
| [`web-widgets`](./web-widgets/plugin.json) | `integration` | AiPBX Official Team | Embeddable WebRTC click-to-call button, callback form, and downloadable WordPress plugin (`.zip`) |
| [`auto-dialer`](./auto-dialer/plugin.json) | `integration` | AiPBX Official Team | Automated outbound calling campaigns with Asterisk AMD, queue free-agent pacing, voicemail drop, and `plg_autodial_*` tables |
| [`ai-cloud`](./ai-cloud/plugin.json) | `ai` | AiPBX Official Team | Cloud & OpenAI-compatible AI provider connection manager |
| [`ai-tts`](./ai-tts/plugin.json) | `ai` | AiPBX Official Team | AI Text-to-Speech studio for generating IVR and system announcements |
| [`ai-apps`](./ai-apps/plugin.json) | `ai` | AiPBX Official Team | Conversational AI Voice Bots (`AudioSocket` / `ARI`) for inbound calls |
| [`ai-models`](./ai-models/plugin.json) | `ai` | AiPBX Official Team | Lightweight local TTS & speech runtime manager |
| [`ai-voicemail`](./ai-voicemail/plugin.json) | `ai` | AiPBX Official Team | Automatic voicemail transcription & executive summary via OpenAI-compatible Whisper/LLM APIs |

## Community Plugins (`type: "community"`)

| Slug | Category | Author | Description |
|---|---|---|---|
| [`ai-assistant`](./ai-assistant/plugin.json) | `ai` | Gregory Merriweather (shido6) | Natural-language PBX assistant (TR/EN) with safe `/api/v1/*` preview & confirmation |

---

## Custom Plugin Structure

Every plugin (official or custom `.zip`) must contain a `plugin.json` manifest at its root:

```
my-custom-plugin/
├── plugin.json          # Required: metadata, tables (plg_*), scopes, hooks, settings_schema
├── schema.sql           # Optional: CREATE TABLE IF NOT EXISTS plg_<name> (...) statements
├── events/
│   └── handler.php      # Optional: returns function(string $event, array $envelope, array $settings): void
├── worker/
│   └── tick.php         # Optional: periodic background worker function(string $slug, array $settings): void
├── public/
│   └── handler.php      # Optional: serves /api/plugins/<slug>/<subPath>
├── sync/
│   └── Sync.php         # Optional: returns function(string $slug, array $settings): void
└── admin/
    ├── action.php       # Optional: pre-render POST/export handler returning ['message' => ..., 'error' => ...]
    └── page.php         # Optional: custom PHP view rendered inside /plugins/<slug>
```
