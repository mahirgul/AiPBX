# AiPBX Plugin Catalog (Official & Community)

This directory contains the **official plugins** bundled with AiPBX. Community plugins are maintained in the separate plugin repository. Starting with the modular plugin architecture, optional external integrations, telemetry collectors, and AI modules live here as self-contained plugin manifests (`<slug>/plugin.json`) rather than being force-enabled on every PBX installation.

- **Lean Core by Default**: Fresh installations start with a clean, fast core PBX. Administrators can browse, install, enable, configure, or disable any plugin with a single click under **Integrations → Plugins** (`/plugins`) or via the **REST API v1** (`POST /api/v1/plugins/{slug}/enable`).
- **Zero-Disruption Upgrades**: Existing servers that already have Google Sign-In, Microsoft Teams, Website Call Widgets, or AI settings configured automatically keep those plugins enabled during database migration.
- **Custom `.zip` Plugins**: Third-party developers can also package custom plugins into a `.zip` archive and upload them directly through **Integrations → Plugins → Upload Custom Plugin (`.zip`)** (extracted into `/var/lib/aipbx/plugins/<slug>/`).

---

## Official Plugins (`type: "official"`)

| Slug | Category | Author | Description |
|---|---|---|---|
| [`google-auth`](./google-auth/plugin.json) | `integration` | AiPBX Official Team | Allow portal and mobile users to sign in with their corporate Google Workspace or Gmail accounts via OAuth 2.0 |
| [`oidc-auth`](./oidc-auth/plugin.json) | `integration` | AiPBX Official Team | Enterprise Single Sign-On (SSO) via OpenID Connect (OIDC) with auto-provisioning for Keycloak, Microsoft Entra ID, Authentik, Okta, and Google Workspace |
| [`saml-auth`](./saml-auth/plugin.json) | `integration` | AiPBX Official Team | Enterprise Single Sign-On (SSO) via SAML 2.0 with JIT auto-provisioning for Microsoft Entra ID (Azure AD), Okta, Keycloak, Authentik, and ADFS |
| [`ms-teams`](./ms-teams/plugin.json) | `integration` | AiPBX Official Team | Connect AiPBX as a Session Border Controller (SBC) for Microsoft Teams Direct Routing and send real-time call/fax/voicemail Adaptive Cards to Teams channels |
| [`web-widgets`](./web-widgets/plugin.json) | `integration` | AiPBX Official Team | Embed click-to-call WebRTC audio buttons and callback forms on any website or WordPress site with origin allowlists and daily rate limits |
| [`ailocal`](./ailocal/plugin.json) | `ai` | AiPBX Official Team | Local AI models, Ollama / local runtime connections, and local inference testing |
| [`aicloud`](./aicloud/plugin.json) | `ai` | AiPBX Official Team | Cloud AI provider accounts, models, usage limits, and cloud inference testing |
| [`ai-apps`](./ai-apps/plugin.json) | `ai` | AiPBX Official Team | Build voice-driven AI IVR applications that greet callers, transcribe speech via Cloud/OpenAI-compatible STT, query external HTTP webhooks, and route calls dynamically |
| [`ai-tts`](./ai-tts/plugin.json) | `ai` | AiPBX Official Team | Synthesize natural IVR and queue announcements from text using Cloud TTS or lightweight local TTS engines and save directly to Asterisk sounds |
| [`ai-voicemail`](./ai-voicemail/plugin.json) | `ai` | AiPBX Official Team | Automatic speech-to-text transcription and one-sentence summaries for voicemail messages |
| [`ai-call-analysis`](./ai-call-analysis/plugin.json) | `ai` | AiPBX Official Team | Call recording transcription, AI summaries, and automatic quality review (Auto-QA) |
| [`ai-knowledge`](./ai-knowledge/plugin.json) | `ai` | AiPBX Official Team | Upload TXT documents, index chunks via AI Gateway embeddings, and ground Assistant & Voice Apps answers with role-based access control |
| [`pbx-assistant`](./pbx-assistant/plugin.json) | `ai` | AiPBX Official Team | Experimental. Chat with the PBX: plain-language changes with a preview and undo |

## Community Plugins (`type: "community"`)

See the [community plugin repository](https://github.com/mahirgul/aipbx-plugins) for independently installed plugins.

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

## Developing and reviewing plugins

Read the [Plugin Developer Guide](../docs/plugin-development.md), including its
[development requirements and release checklist](../docs/plugin-development.md#6-development-requirements-and-release-checklist),
before adding or updating a plugin. It covers authentication, outbound HTTP, database and event contracts,
call-path timeouts, compatibility, lifecycle and required test evidence.

Auto-dialer is maintained in the [community plugin repository](https://github.com/mahirgul/aipbx-plugins/tree/main/plugins/community/auto-dialer); existing installations migrate on upgrade with their campaign data and enabled state preserved.

The PBX Assistant (`pbx-assistant/`, id `ai-assistant` until 1.80) is an official, experimental plugin: the chat at `/assistant`, its settings at `/pbx-assistant`. An upgrade removes an older community copy of `ai-assistant`.
