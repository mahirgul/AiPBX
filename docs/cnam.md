# Caller Name (CNAM) Lookup (Plugin)

> 🔌 **Plugin (`cnam`)**: Community plugin from the official catalog (`mahirgul/AiPBX-plugins`). Enable it under **Integration → Plugin Catalog** (requires AiPBX 1.72.12 or newer). See [Plugin system & developer guide](plugin-development.md).

**Integration → Caller Name (CNAM)** (`/plugins/cnam`) provides automated caller name identification for incoming calls. When an external call reaches AiPBX, the dialplan intercepts the caller ID number, queries an internal directory or third-party CNAM API, and injects the resolved caller name into Asterisk's `CALLERID(name)` before the call rings desk phones, ring groups, queues, or mobile apps.

---

## 🏗️ Architecture & Fail-Open Guarantee

```
  Inbound SIP Call
        │
        ▼
  [sub-cnam-lookup]  ───(GosubIf in trunk / catch-all dialplan)
        │
        ├── 1. Already has name & "overwrite" is OFF? ──► Continue call immediately
        │
        ├── 2. Internal Phonebook Lookup (Extensions & SMS Contacts)
        │         └── Found? ──► Set CALLERID(name) & Continue
        │
        ├── 3. Local MySQL Cache (`plg_cnam_cache`)
        │         └── Found & fresh? ──► Set CALLERID(name) & Continue
        │
        ├── 4. External Provider Query (BulkVS, Telnyx, Twilio, etc.)
        │         ├── Success ──► Save to Cache & Set CALLERID(name)
        │         └── Timeout (2s) / Error ──► Fail-open (Leave original caller name)
        │
        ▼
  Dial Inbound Destination (Queue / Ring Group / Extension / IVR)
```

### ⚡ Dialplan Hook (`sub-cnam-lookup`)

AiPBX core calls the hook when it exists; the enabled plugin generates the subroutine in
`extensions_plugin_cnam.conf`. Its lookup uses `?num=`, HTTP Basic credentials (`cnam` and a per-installation token), and
`CURLOPT(conntimeout)` / `CURLOPT(httptimeout)` set to two seconds. The `CURLOPT(userpwd)` credentials are cleared after
lookup. Save settings and apply the PBX configuration to generate it.

The external provider timeout is one second. The synchronous local request can delay routing up to
its two-second HTTP timeout. An empty result preserves the caller name and continues routing.

---

## 🗄️ Database Cache (`plg_cnam_cache`)

To minimize paid provider API costs and optimize lookup latency, results are cached in the isolated `plg_cnam_cache` table:

| Column | Type | Description |
|---|---|---|
| `caller_number` | `VARCHAR(32)` | Lookup number as supplied (Primary Key) |
| `caller_name` | `VARCHAR(80)` | Cleaned caller name |
| `source` | `VARCHAR(32)` | Provider used (`bulkvs`, `telnyx`, `cache`, etc.) |
| `lookups_count` | `INT UNSIGNED` | Lookup counter |
| `created_at` | `DATETIME` | Timestamp of initial resolution |
| `expires_at` | `DATETIME` | Cache expiration timestamp based on configured retention |

- **Default TTL**: 30 days (configurable from `0` to `365` days). Setting to `0` disables caching.
- Expired entries are ignored during lookup. Use **Purge expired** or **Clear cache** on the plugin page to remove them; expiry alone does not delete stored rows.

---

## 🌐 Supported Providers

The plugin offers BulkVS, Telnyx, Twilio, Vonage, OpenCNAM and SignalWire caller-name lookups.
Coverage and billing depend on the provider and account. SignalWire uses the `cnam.caller_id` field
of its lookup response.

NumVerify and AbstractAPI provide carrier/location labels, rather than the subscriber's name.
Custom URL accepts a configured endpoint with a `{number}` placeholder; Phonebook Only uses active
extensions/external caller IDs and SMS contacts without a paid lookup. Provider timings vary; the
plugin does not guarantee the latency or coverage of an external service.

---

## ⚙️ Configuration Guide

1. Navigate to **Integration → Plugin Catalog** (`/plugins`) and install/enable **Caller Name (CNAM)**.
2. Go to **Settings → Plugins → Caller Name** (`/plugins/cnam`).
3. Select your desired **CNAM Provider** from the dropdown menu.
4. Enter the required API credentials for your chosen provider.
5. Configure operational toggles:
   - **Check local extensions & contacts before paid provider**: *(Recommended: ON)*. Checks local PBX extensions and the SMS contact directory first. If a match is found, no external API call is made, saving credits.
   - **Overwrite name even if already provided by carrier / trunk**: *(Default: OFF)*. When disabled, if your SIP carrier already supplies a valid caller name in `CALLERID(name)`, the plugin preserves it and skips lookup.
   - **Cache Duration (Days)**: Enter the number of days to retain cached lookups (default: `30`).
6. Click **Save Settings**.

---

## 🧪 Test Lookup Tool

The plugin settings page includes an interactive **Test Lookup** diagnostic panel:

1. In the **Test Lookup** field, enter any phone number in standard international format (e.g. `+12025550199` or `+902125550123`).
2. Click **Run Test**.
3. The panel displays:
   - The resolved caller name.
   - Source indicator (`Local Phonebook`, `Cache Hit`, or `Live Provider Query`).
   - Round-trip execution latency in milliseconds.
   The test displays the resolved name, source and measured latency; it does not expose the raw provider response.

---

## Security and retention

The dialplan endpoint accepts only exact loopback addresses (`127.0.0.1` and `::1`) with the correct
per-installation credentials. Administrator test requests also require a valid CSRF token.

Custom URL is an administrator-controlled integration setting. It does not reject private/internal
addresses, and its HTTP transport does not enforce HTTPS. Configure only trusted endpoints; internal
CRM services are supported. The SMS plugin's public-address download policy does not apply to CNAM.

Cached caller names are not connected to the core privacy retention purge. Cache expiry prevents
reuse but leaves the row stored until manually purged or cleared. Disabling caching does not erase
previous entries. External providers receive the lookup number, so select a provider and retention
period appropriate to your privacy policy.
