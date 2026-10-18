# GDPR, KVKK & Global Data Privacy (`/privacy`)

**Security → GDPR & Data Privacy** (displayed as **KVKK ve Veri Gizliliği** when the portal language is set to Turkish) provides built-in, **100% optional** telecommunications privacy and compliance tools.

All rules are **Off by default** on fresh installations. Administrators can keep everything disabled, enable only specific features, or apply a pre-configured regulatory preset in one click.

---

## 1. Global Regulatory Presets

| Preset | Target Standard | Default Behaviour when Applied |
|--------|-----------------|--------------------------------|
| **Off / Custom** | Default (no rules enforced) | All disclosures, beeps, auto-purge, and AI locks disabled |
| **KVKK (TR — 6698)** | Turkey Law No. 6698 & BTK | Pre-call Opt-Out (`9`), 180d recordings, 730d CDR anonymization |
| **EU / UK GDPR** | EU 2016/679, UK GDPR & ePrivacy | Explicit Opt-In (`1`), 90d recordings, 365d CDR anonymization |
| **US CCPA / Two-Party & FCC** | CIPA, CCPA/CPRA, 47 CFR § 64.1200 | Explicit Opt-In (`1`) + 15s periodic recording beep (`MixMonitor B(15)`) |
| **HIPAA (Healthcare)** | Protected Health Information (PHI) | Disclosure notice, 15s beep, 90d retention, **Cloud AI Lock enabled** |
| **PCI-DSS v4.0** | Call center payment card safety | Pre-call Opt-Out (`9`), agent pause/resume, **Cloud AI Lock enabled** |
| **LGPD / PIPEDA / POPIA / PDPA** | Brazil, Canada, South Africa, Singapore, Australia | Explicit Opt-In (`1`) + 15s periodic beep, 180d recordings |

---

## 2. Pre-Call Disclosure, Consent & Periodic Beep

When enabled on inbound DID routes (`[sub-privacy-consent]` in `extensions_inbound.conf`):

- **Disclosure Announcement Only (`notice`):** Plays the selected announcement sound before routing the call.
- **Opt-Out Key (`optout`):** Plays the announcement and listens for the opt-out digit (default `9`). If pressed, Asterisk sets `__PRIVACY_NO_RECORD=1` across all child channels, skips `MixMonitor` in the DID route, Queue, and Ring Group, and tags `CDR(accountcode)=PRIVACY_OPTOUT`.
- **Explicit Consent Opt-In (`optin`):** Sets `__PRIVACY_NO_RECORD=1` by default and only enables recording (`__PRIVACY_NO_RECORD=0`) if the caller presses the consent key (default `1`).
- **FCC Periodic Recording Beep (`recording_beep_sec`):** Passes `,B(15)` (or `,B(30)`) to `MixMonitor` so a subtle periodic beep is played every N seconds while a call is being recorded.

---

## 3. Data Retention & Automated Nightly Purge

When **Enable automated nightly data retention purge** is checked, `/etc/cron.d/aipbx` runs `php /usr/local/bin/privacy_purge.php` every night at 03:45 (or on demand via **Run Purge Policy Now**):

- Deletes call recording audio files (`/var/spool/asterisk/monitor/*`) older than N days
- Deletes voicemail archives and AI transcripts older than N days
- Deletes fax logs and spool files older than N days
- Deletes chat messages older than N days
- Anonymizes expired CDR rows across both `asteriskcdr` (Asterisk ODBC runtime) and `cdrs` (`src = 'REDACTED'`, `dst = 'REDACTED'`, `clid = 'REDACTED'`) so call volume statistics remain intact without retaining personal phone numbers (or deletes expired CDR rows if configured)

---

## 4. Subject Access Request (SAR) & Right to Erasure

Searching a phone number or extension lists all matching CDRs (across `asteriskcdr` and `cdrs`), audio recordings, voicemails, faxes, and SIP signaling rows:

- **Export SAR Data Package (JSON):** Downloads a structured JSON archive for KVKK Art. 11 / GDPR Art. 15 & 20 requests and logs `PRIVACY_SAR_EXPORT` in the Audit Log.
- **Execute Right to Erasure:** Permanently deletes matching audio recordings, voicemails, faxes, and signaling events, anonymizes matching CDR entries as `REDACTED`, and records an immutable `PRIVACY_ERASURE` certificate in the Audit Log.

---

## 5. Live AGI/AI PII Redaction & Cross-Border Cloud AI Lock

- **Live Transcript PII / Payment Card Masking:** When PCI-DSS pause/resume or a strict privacy preset (`pci_dss`, `gdpr`, `kvkk`, `hipaa`) is active, `PrivacyService::redactTranscriptPii()` automatically masks Luhn-validated payment card numbers (13–19 digits → `[REDACTED-CARD]`), CVV/CVC sequences (`[REDACTED-CVV]`), and 11-digit National IDs (`[REDACTED-ID]`) in live AGI voice transcripts and voicemail transcriptions before database storage or external LLM synthesis.
- **Cross-Border Cloud AI Lock:** Checking **Lock outbound Cloud AI services** immediately disables all external Cloud AI providers (`CloudAiService::configured() === false`), ensuring call audio and transcripts never leave the local PBX server.

