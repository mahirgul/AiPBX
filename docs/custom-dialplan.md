# Custom Asterisk Dialplan & Context Injection

AiPBX provides a dedicated management engine and user interface (`/custom-dialplan`) for creating, validating, and injecting custom Asterisk dialplan contexts, extension patterns, and operational execution hooks directly into the running telephony system.

## Architectural Overview

The custom dialplan subsystem bridges low-level Asterisk dialplan capabilities (`extensions.conf`) with modern web-based PBX administration:

```
[Web Admin Portal]
       │
       ▼ (/custom-dialplan)
[CustomDialplanService] ────► [Syntax & Safety Validation]
       │
       ▼ (markPendingSync / SyncCustomDialplan)
[/etc/asterisk/pbx/extensions_custom.conf]
       ▲
       ├── [custom-dialplan-pbx] (Aggregator context for internal calling)
       │         └── include => custom-context-1
       │         └── include => custom-context-2
       │
       └── [custom-context-1], [custom-context-2], ...
                 ▲
                 └── Included in [from-internal-pbx-ortak]
```

### Context Isolation & Internal Aggregation

1. **Target Generation File:**
   - Saved custom dialplan entries are automatically compiled into `/etc/asterisk/pbx/extensions_custom.conf`.
   - The file is included automatically in Asterisk via `#include "pbx/extensions_*.conf"`.

2. **Internal Calling Aggregation (`include_in_internal = 1`):**
   - When a custom context is marked with **"Include in internal dialplan"**, its context name is automatically added as an `include => <context_name>` directive inside `[custom-dialplan-pbx]`.
   - The `[from-internal-pbx-ortak]` shared dialplan includes `[custom-dialplan-pbx]`.
   - This makes custom star codes, feature codes, and extension patterns (*80, *55, *700, etc.) immediately dialable from all registered desktop phones, softphones, mobile apps, and WebRTC clients.

3. **Standalone Contexts (`include_in_internal = 0`):**
   - Contexts not included in the internal plan operate as isolated routing targets.
   - Ideal for custom inbound trunk filters (`context = custom-inbound-filter`), IVR subroutines (`Gosub(custom-context,s,1)`), CRM webhooks, and AGI processing.

---

## Live Syntax Validation & Safety Engine

Before any custom dialplan snippet is persisted or applied to Asterisk, `CustomDialplanService` validates the code structure:
- **Parentheses Balance:** Verifies matching `(` and `)` on every executable line.
- **Bracket Balance:** Verifies matching `[` and `]`.
- **Variable Expansions:** Ensures Asterisk channel variable references (`${VAR}`) are properly closed.
- **Directive Conformance:** Confirms lines start with valid Asterisk dialplan directives (`exten =>`, `same =>`, `include =>`, `ignorepat =>`, `switch =>`).
- **Context Name Reservations:** Protects system-critical context names (`general`, `default`, `from-internal-pbx`, `from-trunk`, `sub-callee-jb`, etc.) from being overwritten.
- **Rollback Protection:** Sync operations use `writeConfWithRollback()`. If Asterisk rejects the generated dialplan configuration on reload, the system automatically rolls back to the previous working configuration without downtime.

---

## Built-In Recipe Templates

The web portal includes ready-to-use dialplan templates that administrators can insert and customize with one click:

### 1. 1-to-1 Intercom / Auto-Answer (`*80<extension>`)
Dials an internal extension with SIP `Alert-Info` and `Call-Info` auto-answer headers, causing supported desk phones to automatically pick up via hands-free speakerphone:
```asterisk
[custom-intercom]
exten => _*80.,1,NoOp(Intercom Auto-Answer to ${EXTEN:3} by ${CALLERID(num)})
 same => n,Set(TARGET_EXT=${FILTER(0123456789,${EXTEN:3})})
 same => n,GotoIf($["${TARGET_EXT}" = ""]?empty)
 same => n,Set(PJSIP_HEADER(add,Alert-Info)=<http://www.google.com>;info=alert-autoanswer;delay=0)
 same => n,Set(PJSIP_HEADER(add,Call-Info)=<http://www.google.com>;answer-after=0)
 same => n,Dial(PJSIP/${TARGET_EXT}-sip&PJSIP/${TARGET_EXT}-webrtc,20)
 same => n,Hangup()
 same => n(empty),Playback(beep)
 same => n,Hangup()
```

### 2. Supervisor Direct Spy (`*55<ext>`) & Whisper Coaching (`*56<ext>`)
Enables authorized supervisors to dial `*55<ext>` to silently monitor an ongoing call or `*56<ext>` to whisper guidance to the agent without the caller hearing:
```asterisk
[custom-spy-coaching]
exten => _*55.,1,NoOp(Supervisor Direct Listen on Extension ${EXTEN:3})
 same => n,Answer()
 same => n,ChanSpy(PJSIP/${FILTER(0123456789,${EXTEN:3})}-,b)
 same => n,Hangup()

exten => _*56.,1,NoOp(Supervisor Direct Whisper on Extension ${EXTEN:3})
 same => n,Answer()
 same => n,ChanSpy(PJSIP/${FILTER(0123456789,${EXTEN:3})}-,qwb)
 same => n,Hangup()
```

### 3. Broadcast Audio Paging Group (`*700`)
Broadcasts a one-way announcement to multiple phones simultaneously using the Asterisk `Page()` application:
```asterisk
[custom-paging-broadcast]
exten => *700,1,NoOp(Company-Wide Audio Announcement Paging)
 same => n,Answer()
 same => n,Playback(beep)
 same => n,Page(PJSIP/1001-sip&PJSIP/1002-sip&PJSIP/1003-sip,d,30)
 same => n,Hangup()
```

### 4. Non-Blocking CRM Webhook Trigger
Dispatches an asynchronous, non-blocking HTTP POST request to an external CRM system upon call arrival:
```asterisk
[custom-crm-webhook]
exten => s,1,NoOp(CRM Webhook Trigger for Caller ${CALLERID(num)})
 same => n,System(curl -s -X POST -d "caller=${CALLERID(num)}&did=${CDR(did)}" http://127.0.0.1:8080/api/call-notify &)
 same => n,Return()
```

### 5. Inbound VIP Caller Priority Tagging
Checks if a caller belongs to a designated VIP list and prefixes their Caller ID with `VIP-`:
```asterisk
[custom-vip-routing]
exten => s,1,NoOp(Checking VIP Status for ${CALLERID(num)})
 same => n,GotoIf($["${CALLERID(num):-4}" = "9999"]?vip_user)
 same => n,Return()
 same => n(vip_user),Set(CALLERID(name)=VIP-${CALLERID(name)})
 same => n,Set(__VIP_CALL=1)
 same => n,Return()
```

### 6. PIN-Protected DISA (Direct Inward System Access)
Provides secure remote dial tone to place outbound trunk calls through the PBX after entering a valid PIN:
```asterisk
[custom-disa]
exten => 7777,1,NoOp(Incoming DISA access request)
 same => n,Answer()
 same => n,Authenticate(1234)
 same => n,DISA(no-password,from-internal-pbx)
 same => n,Hangup()
```

---

## Role-Based Access Control (RBAC) & Security

Because custom dialplan snippets execute arbitrary Asterisk applications, access to the `/custom-dialplan` module is restricted by an **administrative circuit breaker**:
- Only authenticated users with the `admin` role may view, create, edit, or delete custom dialplan contexts.
- Non-admin roles (even if configured with permissions) are unconditionally blocked by `hasModulePermission('custom_dialplan')`.
- All modifications are permanently recorded in the system audit log (`sys_audit_log`).
