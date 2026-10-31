# Inbound Caller ID Blacklist & Spam Blocker

AiPBX provides high-performance inbound spam filtering and caller number blocking designed to intercept nuisance callers, robocalls, and fraud attempts at trunk entry before they tie up extensions, queues, or IVR channels.

## Architecture & Sub-Microsecond AstDB Lookup

When an incoming call arrives on any configured trunk (`[from-trunk-<name>]`), it immediately passes through the `[sub-inbound-blacklist]` dialplan subroutine:

1. **Exact Number Match via AstDB:**
   - Active blocked numbers are stored in Asterisk's in-memory key-value database (`AstDB`) under `blacklist/<number>` and `blacklist_action/<number>`.
   - Lookups execute with zero SQL overhead and complete in sub-microseconds without database queries.
2. **Wildcard & Dialplan Pattern Matching:**
   - Prefix blocks (e.g., `_0850.`, `_+1800.`) are dynamically compiled into the `[sub-inbound-blacklist-patterns]` context.
   - Asterisk pattern matching is evaluated when an exact match is not found.
3. **Early Rejection Before Queue/IVR Execution:**
   - Rejected calls never consume trunk channel limits, never trigger IVR speech synthesis, and never ring internal extensions.
   - The CDR record is tagged with `CDR(accountcode) = SPAM_BLOCKED` and `CDR(userfield) = Blacklist: <action>`.

## Block Actions

Administrators can choose how blacklisted calls are handled:

| Action | Technical Mechanism | Description |
|--------|---------------------|-------------|
| **Busy** (`busy`) | `Playtones(busy)` & `Hangup(17)` | Returns ISDN 17 / SIP 486 Busy Here. The carrier informs the caller that the line is busy. |
| **Congestion** (`congestion`) | `Playtones(congestion)` & `Hangup(34)` | Returns ISDN 34 / SIP 503 Service Unavailable / Circuit Busy. Simulates a network failure. |
| **Drop** (`drop`) | `Hangup(16)` | Terminates the call immediately with normal clearing (ISDN 16) without playing audio or answer supervision. |
| **Announcement** (`announcement`) | `Answer()` + `Playback(ss-noservice)` + `Hangup(1)` | Answers the call and plays the standard telecom "number is not in service" announcement before disconnecting. |

## Quick Blocking from Extension CDR

The web interface (`/blacklist`) includes a quick lookup tool:
- Enter an extension number (e.g. `101`) to automatically fetch the last inbound caller ID from call logs and populate the blocking form in one click.

## CSV Bulk Import

Numbers can be bulk-imported using CSV files (`.csv`):
```csv
number,title,action
08501234567,Telemarketing Spam,busy
08507654321,Robocall,congestion
_0850.,Entire 0850 Prefix Block,drop
```
If the action column is omitted, the user-selected default action is applied.

## CLI & Direct AstDB Management

Blacklist records can also be viewed or managed directly via the Asterisk CLI:
```bash
# View active blacklisted numbers
asterisk -rx "database show blacklist"

# Add a number manually
asterisk -rx "database put blacklist 08501234567 1"
asterisk -rx "database put blacklist_action 08501234567 busy"

# Delete a number manually
asterisk -rx "database del blacklist 08501234567"
asterisk -rx "database del blacklist_action 08501234567"
```
