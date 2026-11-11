# SMS & MMS Messaging (Plugin)

> 🔌 **Plugin (`sms`)**: Community plugin from the official catalog (`mahirgul/AiPBX-plugins`). Enable it under **Integration → Plugin Catalog** (requires AiPBX 1.71.0 or newer). See [Plugin system & developer guide](plugin-development.md).

**Apps → Messages** (`/apps/sms`) adds native, two-way SMS and multimedia MMS messaging to AiPBX. Staff members can send and receive text messages, images, documents, and business cards directly from the web portal and mobile apps using your existing telecom DIDs.

---

## 🏗️ Architecture & Inbound Webhooks

```
  Customer Smartphone
        │  (SMS / MMS)
        ▼
  Telecom Carrier (Twilio, Telnyx, Vonage, etc.)
        │
        ▼  HTTP POST / Inbound Webhook (Cryptographically Signed)
  AiPBX Webhook Endpoint (`/api/plugins/sms/webhook/{provider}`)
        │
        ├── 1. Cryptographic Signature Verification (Twilio HMAC, Vonage SHA256, etc.)
        ├── 2. E.164 Number Normalization & Routing Lookup
        ├── 3. Concurrency Lock (`FOR UPDATE`) & Message Ingestion (`plg_sms_messages`)
        ├── 4. MMS Media Download (SSRF-Protected) ──► File Storage (Local / S3)
        ├── 5. Browser Push / WebSocket Live Event
        └── 6. Optional E-mail Alert (Throttled: max 1 per 10 min per conversation)
        │
        ▼
  User Portal Inbox (`/apps/sms`) & Mobile Apps
```

### 📡 Public Webhook Endpoints

Configure your provider's messaging portal with the matching webhook URL from AiPBX:

| Provider | Webhook URL Format | Signature / Authentication Method |
|---|---|---|
| **Twilio** | `https://<pbx-domain>/api/plugins/sms/webhook/twilio` | `X-Twilio-Signature` HMAC-SHA1 using Auth Token |
| **Vonage** | `https://<pbx-domain>/api/plugins/sms/webhook/vonage` | Vonage Signature Secret (SHA-256 HMAC) |
| **Plivo** | `https://<pbx-domain>/api/plugins/sms/webhook/plivo` | `X-Plivo-Signature-V2` HMAC-SHA256 |
| **Telnyx** | `https://<pbx-domain>/api/plugins/sms/webhook/telnyx` | ED25519 public key webhook verification |
| **Bandwidth** | `https://<pbx-domain>/api/plugins/sms/webhook/bandwidth` | Basic Auth / Account Secret |
| **Sinch** | `https://<pbx-domain>/api/plugins/sms/webhook/sinch` | Sinch App Token & Secret |
| **VoIP.ms** | `https://<pbx-domain>/api/plugins/sms/webhook/voipms` | Callback URL with DID parameter mapping |
| **BulkVS** | `https://<pbx-domain>/api/plugins/sms/webhook/bulkvs` | JSON POST callback with API Key |

---

## 🗄️ Database Tables (`plg_` Sandbox)

The SMS plugin operates in dedicated, isolated `plg_*` database tables:

| Table | Purpose |
|---|---|
| 📱 `plg_sms_numbers` | Configured DIDs, assigned carrier/provider, and default outbound caller ID name |
| 🔑 `plg_sms_access` | Access control mappings: which users and extensions have permission to read/send from which number |
| 💬 `plg_sms_messages` | Inbound and outbound message records (E.164 numbers, direction, body, status, carrier message ID, timestamps) |
| 📎 `plg_sms_media` | MMS attachments (MIME type, file size, storage URL/path, SHA256 checksum) |
| 📇 `plg_sms_contacts` | Contact directory: name, business organization, notes, linked to phone number |

---

## ⚙️ Configuration & Setup

### 1. Configure Provider Credentials
Navigate to **Settings → Plugins → SMS / MMS** (`/plugins/sms`):
- Enter the API Key, Secret, or Auth Token for your provider.
- If using **Twilio**, **Vonage**, or **Plivo**, provide the webhook signing secret to enforce cryptographic sender verification.
- **E-mail Notification**: Check this box to alert users via email when a new message arrives. To avoid inbox flooding during active chats, alerts are automatically throttled to **at most 1 email every 10 minutes** per conversation.

### 2. Add DID Numbers & Assign Staff Access
Under the **Numbers** tab:
1. Click **+ Add Number**.
2. Enter the DID in standard E.164 format (e.g. `+12025550150` or `+902125550190`).
3. Select the carrier provider that hosts this number.
4. Select which **Users / Extensions** are allowed to manage this number:
   - **Shared Team Inbox**: Assign multiple users or a department to a single number. Everyone assigned sees incoming messages and replies in real time.
   - **Dedicated DID**: Assign a direct line to an individual extension owner.

---

## 💬 User Experience (`/apps/sms`)

Staff members access their messaging interface under **Apps → Messages**:

- **Conversations List**: Threads sorted by recent activity with unread message badges.
- **Message Composer**:
  - Live character and segment counter (160 characters for standard GSM-7; 70 characters for Unicode/emojis).
  - Automatic multi-part message concatenation.
  - Attachment button for uploading pictures, documents, or audio files.
- **Delivery Status Indicators**:
  - 🕒 `Pending`: Queued for dispatch.
  - 📤 `Sent`: Accepted by provider network.
  - ✅ `Delivered`: Carrier delivery receipt confirmed.
  - ❌ `Failed`: Delivery error with actionable failure code.
- **Integrated Contact Directory**: Save customer names and notes directly from the chat view. Contact names automatically synchronize with the [Caller Name (CNAM)](cnam.md) plugin.

---

## 📎 Multimedia Messaging (MMS)

The SMS plugin supports sending and receiving multimedia attachments:

- **Supported Formats**: JPEG, PNG, GIF, WebP, PDF documents, Audio clips (MP3/WAV/OGG), and vCard contacts (`.vcf`).
- **File Storage**: Attachments are managed through AiPBX's unified storage backend: either on the local server disk or an S3-compatible cloud bucket (AWS S3, MinIO, Wasabi, Cloudflare R2) — see [File Storage](file-storage.md).
- **🛡️ SSRF Security Shield**: When receiving MMS media from external carrier webhooks, AiPBX validates all media URLs through an SSRF-safe HTTP client. Downloads to internal, private (`10.*`, `192.168.*`, `127.*`), or cloud metadata IPs are strictly forbidden.

---

## 🛡️ Concurrency & Security

- **Row-Level Concurrency Locking**: Inbound webhook processing uses database row-level locking (`SELECT ... FOR UPDATE`) during conversation insertion. This ensures messages arriving in rapid succession are written in exact chronological order without duplicate thread creation.
- **Webhook Replay & Tamper Protection**: All inbound requests from Twilio, Vonage, and Plivo are verified against their cryptographic request signatures before any database transaction is initiated.
- **Privacy & Purge**: Message logs and MMS media honor the automated privacy retention policies configured in [Privacy & Compliance](privacy.md).

## Before you send: registration and reputation

Whether a message arrives is decided by the carrier, not by this plugin. In the United States and
Canada application-to-person messages from local numbers need a registered brand and campaign
(10DLC), toll-free numbers need verification, and unregistered traffic is filtered or surcharged;
other countries have their own sender registration. Register with your provider before going live,
keep the opt-in of every recipient and honour STOP. AiPBX stores and relays what you send; the
registration, the consent records and the content are the operator's responsibility
([Privacy & Compliance](privacy.md)).
