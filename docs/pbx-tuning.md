# PBX fine tuning & maintenance

Asterisk core tuning, SIP protocol timers, adaptive jitter buffering, night maintenance restart
windows, log diagnostics, and spoken status announcements.

- Core tuning & diagnostics: **PBX settings → Tuning & Ops** (tab in `/asterisk-settings`).
- Spoken status messages: **PBX settings → Ringtone** (tab in `/asterisk-settings`).

## Adaptive jitter buffer

Jitter buffering smooths out network packet arrival timing variations on VoIP calls.

| Setting | Default | Range / Options | Notes |
|---|---|---|---|
| **Jitter Buffer Mode** | Adaptive (Active) | Active / Disabled (Off) | When disabled, audio packets pass without buffering |
| **Maximum Buffer Size** | 200 ms | 50–2000 ms | Upper ceiling for buffered audio frames |
| **Initial Buffer Size** | 100 ms | 20–1000 ms | Starting buffer depth when a call establishes |

- **Fax exemption:** Jitter buffering is automatically bypassed for faxes. Reshaping audio packet timing
  disrupts T.30 / T.38 modem handshakes and causes faxes to drop.

## SIP protocol timers & Max-Forwards

Fine-tune SIP signaling behavior for high-latency trunks or deep routing chains:

| Setting | Default | Range | Description |
|---|---|---|---|
| **Max-Forwards Limit** | 70 | 1–255 | Maximum SIP proxy hops allowed before a request is rejected; prevents infinite forwarding loops |
| **Timer T1** | 500 ms | 50–10000 ms | Base round-trip time (RTT) estimate for SIP transactions; increase for high-latency links (satellite, cellular, or VPN trunks) |
| **Timer B** | 32000 ms | 500–64000 ms | INVITE transaction timeout (standard 64 × T1) before failing an unacknowledged invite |

> Changes to Timer T1 and Timer B are written to PJSIP system configuration and take effect after the
> next Asterisk restart.

## Live diagnostics & logs

### 10-minute SIP debug

Click **Enable SIP Debug (10 Minutes)** to turn on verbose PJSIP packet dumping in Asterisk.
- Displays a live countdown badge showing remaining minutes.
- Writes every SIP message sent and received to the Asterisk log (`pjsip set logger on`).
- Automatically switches itself off after 10 minutes to protect disk space and CPU performance.
- Can be stopped at any time with **Stop SIP Debug**.

### On-demand log rotation

Click **Rotate Asterisk Logs Now** to rotate all Asterisk log files at once (`logger rotate`), for
example before reproducing a problem. Calls are not affected.

## Safe night maintenance restart window

Ubuntu automatic security updates (`unattended-upgrades`) periodically install updated system libraries
(such as OpenSSL, glibc, or SQLite). Since version 1.50.7, `needrestart` leaves Asterisk alone to
prevent interrupting calls during the day.

The **Night Maintenance Restart Window** lets Asterisk safely restart to load updated libraries during
quiet hours.

| Setting | Default | Description |
|---|---|---|
| **Night Restart Status** | Passive (Off) | Enable or disable safe night restarts |
| **Window Start** | `03:00` | Start of the maintenance window in server local time |
| **Window End** | `05:00` | End of the maintenance window in server local time |

The window can cross midnight (e.g. `23:00` to `04:00`).

### Execution conditions

The task dispatcher evaluates the restart window every minute. A restart is triggered **only when all**
of the following conditions are met:

1. **Feature is enabled:** Night restart is switched on.
2. **Current time is in window:** Local server time is between the configured start and end times.
3. **At most once a day:** A restart has not already occurred today.
4. **A security update is waiting:** Asterisk still uses a security-relevant library that an update
   replaced (OpenSSL, GnuTLS, the C library, SRTP, curl, libxml2 …). Updates of other libraries
   (libsystemd, libudev …) change nothing for calls and never cause a restart.
5. **Zero active calls:** The active call count on Asterisk is strictly 0.
6. **Call count is verifiable:** If Asterisk cannot be queried or the call count cannot be determined,
   the restart is aborted.

Every automated night restart is recorded in the system audit log.

## Failed registration alarm

Under **PBX settings → Tuning & Ops**, the **Failed Registrations Threshold (per 15 min)** setting
(default `10`, range 1–1000) controls the security threshold for SIP login attacks. When failed
registration and authentication attempts in `/var/log/asterisk/security.log` exceed this number within
15 minutes, the [Health check](health.md#checks) *Failed registrations* triggers an alarm in the alert
bell and e-mails administrators.

## Spoken status instead of tones

**PBX settings → Ringtone** (applied with *Apply*).

When an internal call to an extension ends because the extension is busy, does not answer, or is
unreachable (and no voicemail or call forwarding takes the call), callers can hear a spoken explanation
in their own language instead of only a busy or congestion tone:

| Status | Message text |
|---|---|
| **Busy** | *"The person at extension 3001 is on the phone. Please try again."* |
| **No answer** | *"The number you are calling is not answering. Please try again."* |
| **Unreachable** | *"The person at extension 3001 is unavailable. Please try again."* |

- **Billing safety:** For calls originating from external trunks, the spoken explanation is played as
  **early media** before the call is answered. The caller hears the explanation, but the trunk call is
  never answered, preventing call charges.
- **Default:** Off (Tone only) for all three conditions. Each can be set independently to *Tone only* or
  *Spoken message*.
