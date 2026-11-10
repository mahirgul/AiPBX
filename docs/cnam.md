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

AiPBX core automatically includes a dialplan sub-routine hook in `extensions_inbound.conf`:

```asterisk
[sub-cnam-lookup]
exten => s,1,NoOp(CNAM lookup for ${CALLERID(num)})
 same => n,Set(CURLOPT(conntimeout)=1)
 same => n,Set(CURLOPT(timeout)=2)
 same => n,Set(CURLOPT(httpheader)=X-CNAM-Token: <pbx_internal_secret>)
 same => n,Set(CNAM_RESULT=${CURL(http://127.0.0.1/api/plugins/cnam/lookup?number=${URIENCODE(${CALLERID(num)})})})
 same => n,GotoIf($["${CNAM_RESULT}" = ""]?skip)
 same => n,Set(CALLERID(name)=${CNAM_RESULT})
 same => n(skip),Return()
```

- **🛡️ Fail-Open Design**: The HTTP request uses a strict **2-second timeout**. If the provider API is down, rate-limited, or slow, Asterisk immediately proceeds with call routing. Inbound calls are **never dropped or blocked** due to a CNAM lookup failure.
- **🔒 Security Token (`X-CNAM-Token`)**: Asterisk communicates with the local AiPBX web server over loopback (`127.0.0.1`). All requests must present a matching PBX internal authentication token, preventing unauthorized local or network queries.

---

## 🗄️ Database Cache (`plg_cnam_cache`)

To minimize paid provider API costs and optimize lookup latency, results are cached in the isolated `plg_cnam_cache` table:

| Column | Type | Description |
|---|---|---|
| `phone_number` | `VARCHAR(32)` | E.164 normalized phone number (Primary Key) |
| `caller_name` | `VARCHAR(80)` | Cleaned caller name |
| `provider` | `VARCHAR(32)` | Provider used (`bulkvs`, `telnyx`, `cache`, etc.) |
| `created_at` | `DATETIME` | Timestamp of initial resolution |
| `expires_at` | `DATETIME` | Cache expiration timestamp based on configured retention |

- **Default TTL**: 30 days (configurable from `0` to `365` days). Setting to `0` disables caching.
- **Cache Hit Latency**: < 5 ms directly from the local database.

---

## 🌐 Supported Providers

The CNAM plugin supports 10 resolution methods:

| Provider | Method | Required Credentials | Typical Latency | Notes |
|---|---|---|---|---|
| **BulkVS** | REST GET | API Key / ID | ~120 ms | Cost-effective North American CNAM database |
| **Telnyx** | REST GET | API Key (Bearer Token) | ~90 ms | Global coverage via Number Lookup API |
| **Twilio** | REST Basic Auth | Account SID & Auth Token | ~110 ms | Twilio Lookup v2 Caller Name addon |
| **Vonage** | REST GET | API Key & API Secret | ~150 ms | Vonage Number Insight Advanced |
| **OpenCNAM** | REST Basic Auth | Account SID & Auth Token | ~140 ms | Hobbyist and professional US/CA pools |
| **SignalWire** | REST Basic Auth | Space URL, Project ID, API Token | ~100 ms | High-throughput telecom API |
| **NumVerify** | REST GET | API Key | ~180 ms | Global APILayer carrier and caller lookup |
| **AbstractAPI** | REST GET | API Key | ~160 ms | Phone validation & caller reputation API |
| **Custom URL** | REST GET/JSON | Endpoint URL (with `{number}`) | Variable | Query your proprietary CRM, ERP, or internal directory |
| **Phonebook Only** | Local DB | None (Free) | < 2 ms | Matches internal extensions and SMS contact names |

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
   - Raw JSON response payload for debugging.

---

## 🛡️ Security & SSRF Protection

- **Loopback Validation**: The dialplan lookup endpoint only accepts connections from `127.0.0.1` and `::1`.
- **SSRF Shielding on Custom URLs**: When using a **Custom URL** provider, URLs targeting local hostnames (`localhost`, `127.*`, `10.*`, `192.168.*`, `172.16-31.*`, cloud metadata `169.254.169.254`) are strictly rejected.
- **Privacy Compliance**: When call privacy regulations (KVKK / GDPR) are active, caller names stored in cache respect automated data retention purges ([Privacy & Compliance](privacy.md)).
