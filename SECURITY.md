# Security & Privacy Policy

## Supported Versions

Security updates are published as new releases on the `main` branch and can be applied on any installation via `sudo aipbx-update` or **System → System Update**.

| Version | Supported |
| ------- | --------- |
| `1.34.x` (latest) | :white_check_mark: |
| `< 1.34.0` | :x: (upgrade via `sudo aipbx-update`) |

---

## Reporting a Vulnerability

If you discover a security vulnerability in **AiPBX** (web portal, Asterisk dialplan generator, REST API v1, Go chat service, Python AI/signaling services, system helpers, or mobile applications):

1. **Please do not open a public GitHub issue** for unpatched vulnerabilities.
2. Report it privately via [GitHub Security Advisories](https://github.com/mahirgul/AiPBX/security/advisories/new) or contact the project lead directly via [mhrgl.com](https://mhrgl.com).
3. Include a clear description of the issue, affected component/file, and reproduction steps.

---

## Security Architecture Highlights

- **Zero Default Credentials:** `install.sh` generates cryptographically random secrets for MariaDB, AMI, TURN, JWT signing (`CHAT_JWT_SECRET`), and encryption (`settings.key`).
- **Least-Privilege Web Server Sandbox:** Apache runs as `www-data` inside an Ubuntu systemd sandbox (`ProtectSystem=strict`) with write access limited to `/etc/asterisk/pbx`, `/var/lib/aipbx`, `/var/log/aipbx`, and `/var/spool/asterisk`.
- **Single Argument-Validated Root Helper (`aipbx-priv`):** Privileged operations (firewalld, fail2ban, Postfix SASL, network services) go through `/usr/local/sbin/aipbx-priv` with strict regex validation — no arbitrary shell commands can be executed by the web portal.
- **RBAC Symmetric Circuit Breaker:** Security-critical modules (`roles`, `system_users`, `firewall`, `fail2ban`, `certificates`, `privacy`, `system_update`, `plugins`, `api_keys`, `webhooks`, `phones`, `web_widgets`, `file_storage`, `ai_models`, `ai_cloud`, `ai_apps`, `assistant`, `health`) are hard-locked to the `admin` role in [`web/auth.php`](web/auth.php), regardless of database permission rows.
- **Scoped REST API v1 & SSRF-Protected Webhooks:** `/api/v1/*` tokens (`apbx_...`) store only SHA-256 hashes, enforce per-token scopes, CIDR allowlists, and rate limits, and redact secret columns (`sip_password`, `auth_password`, `voicemail_pin`). Outbound webhooks verify public IP resolution and pin DNS via `CURLOPT_RESOLVE`.
- **Plugin System Safe Mode:** Any plugin that throws 5 consecutive runtime errors is automatically disabled by `PluginManager` so a faulty third-party plugin cannot take down the portal.

---

## Optional Global Privacy & Telecom Compliance (`/privacy`)

Under **Security → GDPR & Data Privacy** (shown as **KVKK ve Veri Gizliliği** when the interface is in Turkish), administrators can optionally enable or disable individual privacy controls (all **Off by default**):

- **Jurisdiction Presets:** `Off / Custom`, `KVKK (TR — 6698)`, `EU / UK GDPR (2016/679)`, `US CCPA / Two-Party Consent & FCC`, `HIPAA (Healthcare PHI)`, `PCI-DSS v4.0 (Call Center)`, and `LGPD / PIPEDA / POPIA / PDPA`.
- **Pre-Call Disclosure & Consent (`[sub-privacy-consent]`):** Announcement-only, Opt-Out (`Press 9 for unrecorded call`), or Opt-In (`Press 1 to allow recording`), plus optional FCC periodic recording beep (`MixMonitor B(15)`).
- **Automated Data Retention Purge (`privacy_purge.php`):** Configurable retention windows for audio recordings, voicemails/AI transcripts, faxes, chat messages, and CDR PII anonymization (`REDACTED`).
- **Subject Access Request (SAR) & Right to Erasure:** One-click JSON data export or permanent erasure/anonymization with an immutable `PRIVACY_ERASURE` audit record.
- **Cross-Border Cloud AI Lock:** One-click toggle to block outbound audio/text requests to external Cloud AI services and keep all speech/AI processing strictly local.
