# AiPBX Official Plugin Catalog (`plugins/`)

This directory contains the **Official Plugin Catalog** for AiPBX. Starting with the modular plugin architecture, optional external integrations, telemetry collectors, and AI modules live here as self-contained plugin manifests (`<slug>/plugin.json`) rather than being force-enabled on every PBX installation.

- **Lean Core by Default**: Fresh installations start with a clean, fast core PBX. Administrators can browse, install, enable, configure, or disable any plugin with a single click under **Integrations → Plugins** (`/plugins`) or via the **REST API v1** (`POST /api/v1/plugins/{slug}/enable`).
- **Zero-Disruption Upgrades**: Existing servers that already have Google Sign-In, Microsoft Teams, Website Call Widgets, or AI settings configured automatically keep those plugins enabled during database migration.
- **Custom `.zip` Plugins**: Third-party developers can also package custom plugins into a `.zip` archive and upload them directly through **Integrations → Plugins → Upload Custom Plugin (`.zip`)** (extracted into `/var/lib/aipbx/plugins/<slug>/`).

---

## Official Catalog Plugins

| Slug | Category | Description |
|---|---|---|
| [`google-auth`](./google-auth/plugin.json) | `integration` | Google Sign-In (OAuth 2.0) for web portal and mobile apps |
| [`ms-teams`](./ms-teams/plugin.json) | `integration` | Microsoft Teams Direct Routing SBC trunking, PowerShell generator, and call webhooks |
| [`web-widgets`](./web-widgets/plugin.json) | `integration` | Embeddable WebRTC click-to-call button, callback form, and downloadable WordPress plugin (`.zip`) |
| [`ai-cloud`](./ai-cloud/plugin.json) | `ai` | Cloud & OpenAI-compatible AI provider connection manager |
| [`ai-tts`](./ai-tts/plugin.json) | `ai` | AI Text-to-Speech studio for generating IVR and system announcements |
| [`ai-apps`](./ai-apps/plugin.json) | `ai` | Conversational AI Voice Bots (`AudioSocket` / `ARI`) for inbound calls |
| [`ai-models`](./ai-models/plugin.json) | `ai` | Lightweight local TTS & speech runtime manager |
| [`ai-voicemail`](./ai-voicemail/plugin.json) | `ai` | Automatic voicemail transcription & executive summary via OpenAI-compatible Whisper/LLM APIs |
| [`ai-assistant`](./ai-assistant/plugin.json) | `ai` | Natural-language PBX assistant (TR/EN) with safe `/api/v1/*` preview & confirmation |
| [`signaling-capture`](./signaling-capture/plugin.json) | `telemetry` | Asterisk HEPv3 (`res_hep_pjsip` / `res_hep_rtcp`) SIP signaling & webhook telemetry forwarder |

---

## Custom Plugin Structure

Every plugin (official or custom `.zip`) must contain a `plugin.json` manifest at its root:

```
my-custom-plugin/
├── plugin.json          # Required: metadata, scopes, hooks, settings_schema
├── events/
│   └── handler.php      # Optional: returns function(string $event, array $envelope, array $settings): void
├── sync/
│   └── Sync.php         # Optional: returns function(string $slug, array $settings): void
└── admin/
    └── page.php         # Optional: custom PHP view rendered inside /plugins/<slug>
```
