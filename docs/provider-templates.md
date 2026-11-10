# SIP Provider Templates (Plugin)

> 🔌 **Plugin (`provider-templates`)**: Community plugin from the official catalog (`mahirgul/AiPBX-plugins`). Enable it under **Integration → Plugin Catalog** (requires AiPBX 1.60.6 or newer). See [Plugin system & developer guide](plugin-development.md).

**Integration → SIP Provider Templates** (`/plugins/provider-templates`) allows administrators to create pre-configured SIP trunks using community-tested carrier profiles. Instead of researching carrier-specific PJSIP NAT flags, registration expiration, RTP options, codecs, and outbound proxies, you simply select your provider and enter your account credentials.

---

## 🚀 Key Advantages

- **⚡ 1-Click Trunk Setup**: Pre-populates all technical Asterisk PJSIP parameters (UDP/TLS transport, DTMF mode, SIP qualify timers, direct media, PAI/RPID caller ID headers, and carrier match hosts).
- **🌍 Community Curated**: Templates are maintained as simple JSON files in the community repository. The catalog grows directly with user contributions.
- **🛡️ Standard AiPBX Trunks**: Trunks created via templates become standard records in your AiPBX trunk table ([Trunks Guide](trunks.md)). You can fine-tune any parameter after creation, and trunks continue functioning normally even if the plugin is uninstalled.
- **🏷️ Real-World Testing Flags**: Each template clearly indicates whether it has been verified with live incoming/outgoing calls or compiled from carrier technical documentation.

---

## 📋 Available & Tested Provider Profiles

The template library covers international, regional, and national carriers:

| Provider | Region | Type | Verified / Tested | Highlights |
|---|---|---|---|---|
| **Twilio Elastic SIP Trunking** | Global | IP / Registration | ✅ Tested | SRTP / TLS option, localized edge endpoints, PAI headers |
| **Telnyx** | Global | Credential / IP | ✅ Tested | Low-latency multi-region POPs, RFC2833 DTMF, G.711u/A + Opus |
| **A1 Telekom Austria** | Austria (AT) | Registration | ✅ Tested | Specific `from_domain`, Digest Auth, G.711a |
| **Drei Austria (Hutchison Drei)** | Austria (AT) | Registration | ✅ Tested | Custom SIP Contact rules, E.164 normalization |
| *Community additions* | Various | Various | Verified in PRs | Additional profiles added continuously via community catalog |

---

## ⚙️ How to Create a Trunk from a Template

1. Navigate to **Integration → Plugin Catalog** (`/plugins`) and enable **SIP Provider Templates**.
2. Go to **Integration → SIP Provider Templates** (`/plugins/provider-templates`).
3. Select your provider from the list.
4. Review the carrier setup guidelines and prerequisite notes shown in the helper card.
5. Fill in your specific account information:
   - **SIP Host / Domain**: Populated automatically, or enter your assigned tenant server.
   - **Trunk Name**: A friendly label for this trunk (e.g. `twilio-outbound`).
   - **Username / Auth ID**: Your SIP username or main telephone number.
   - **Password**: Your SIP secret or carrier token.
   - **Outbound Caller ID**: The verified DID number to present on outbound calls.
6. Click **Create Trunk**.
7. Go to **Telephony → Trunks** (`/trunks`) to verify the new trunk, then configure your [Inbound Routes](inbound-routes.md) and [Outbound Routes](outbound-routes.md).
8. Click **Apply Changes** in the top navigation bar to write the new configuration to Asterisk.

---

## 🤝 Contributing a New Carrier Template

Adding a new provider does not require any PHP or database coding. Templates are declarative JSON definitions in `templates/<carrier-slug>.json`:

```json
{
  "schema": 1,
  "name": "Example Telecom",
  "country": "US",
  "docs_url": "https://example.com/sip-trunk-setup",
  "source": "working installation",
  "host": "sip.example.com",
  "needs_login": true,
  "login_is_number": false,
  "send_e164": true,
  "notes": "Enable IP authentication or create SIP credentials in the provider portal first.",
  "tested": {
    "by": "github-username",
    "date": "2026-11",
    "aipbx": "1.72.0"
  },
  "trunk": {
    "transport": "transport-udp",
    "port": "5060",
    "codecs": "alaw,ulaw,g729",
    "dtmf_mode": "rfc4733",
    "direct_media": "no",
    "rtp_symmetric": "yes",
    "force_rport": "yes",
    "rewrite_contact": "yes",
    "send_pai": "yes",
    "qualify_frequency": "60"
  }
}
```

### Validation & Submission Guidelines:
- **No private secrets**: Never include personal passwords, account IDs, or proprietary IP addresses.
- **Official documentation link**: Always provide the public carrier configuration URL in `docs_url`.
- Run validation locally: `php tests/validate.php`.
- Submit a Pull Request to [mahirgul/AiPBX-plugins](https://github.com/mahirgul/AiPBX-plugins).
