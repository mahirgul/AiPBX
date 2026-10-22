# Call quality (Plugin)

> **Plugin (`call-quality`)**: Optional plugin from the official catalog. Enable it under **Integrations → Plugins** (see [Plugin system & developer guide](plugin-development.md)).

A call can sound fine to the person who made it and still be losing one packet
in twenty. The PBX already knows: every RTP stream sends RTCP reports, and
`res_hep_rtcp` sends them to the capture daemon exactly like SIP messages. This
package reads those numbers, keeps the evidence, tells a person when a call was
bad, and can move one trunk out of the way — nothing else.

It never touches audio, never listens in, and never invents a MOS score.

## What it needs

| Requirement | Why |
|---|---|
| `pbx_signaling_events` with the `rtcp_*` columns | the counters come from Asterisk's own reports |
| Asterisk's `hep.conf` pointing at **this** server's daemon | a remote HOMER/VoIPmonitor keeps the reports there, not here |
| The portal's **Notices** module | that is how a person is told (`/plugins/call-quality` also shows the evidence) |

The plugin's page shows all three at the top, in one line each, because an
install that "does nothing" is almost always one of them. The same answer is one
command away:

```
aipbx_plugin_cli.php call-quality capture
```

## How a call is scored

Reports for one Call-ID are grouped over the last 15 minutes:

| verdict | rule |
|---|---|
| **poor** | two or more reports at 5 % loss or more |
| **fair** | any report above 1 % |
| **good** | everything below that |

Loss is stored as RFC 3550's 8-bit fraction (255 = 100 %), so 26 is ~10 %. Jitter
is kept in RTP timestamp units and **never converted to milliseconds** — the
sample rate is not part of a report, so a millisecond figure would be a guess.
One spike is not a verdict: a single bad report makes a call _fair_, and only
sustained loss makes it _poor_.

## What it changes, and the one thing it may change on its own

The collector runs every five minutes (`cron[]` in `plugin.json`). It prunes
evidence older than `evidence_days`, scores the last ten minutes, and for each
poor call:

1. **writes the evidence** — the RTCP samples behind the decision, the peers, the
   trunk and the action taken, in `plg_call_quality_evidence`;
2. **raises a notice** — one per problem per six hours, through the portal's
   notice cabinet. Mail follows the administrator's notice settings, not this
   plugin's;
3. **may demote a trunk** — only when *all* of these hold:
   - `auto_fix` is on (it is **off** by default),
   - the lossy peer is a configured trunk,
   - an active outbound route has another trunk behind it,
   - that trunk has not been demoted in the last six hours,
   - no other outbound change is waiting for **Apply**.

A demotion moves the trunk to the end of the route's chain for **new calls**.
Live calls are never touched. The change is written to the audit log
(**Audit → `call_quality`**) with the reason, the old order and the new one, and
it is undone the normal way: **Outbound routes**, reorder, Apply.

Everything else is a notice for a human:

- two trunks losing packets at once — moving either one just swaps the order and
  blames a carrier that is probably not at fault;
- a lossy peer that is not a trunk — a phone, Wi-Fi, the local switch;
- sustained loss with no alternative trunk in the route.

## Settings

| key | default | meaning |
|---|---|---|
| `evidence_days` | `180` | how long evidence is kept (7–3650); older rows are pruned by the collector |
| `auto_fix` | `0` | allow the trunk demotion described above |

Both are edited on **Integrations → Plugins → Call Quality Evidence**.

## Commands

```
aipbx_plugin_cli.php call-quality capture            # where RTCP goes, and why
aipbx_plugin_cli.php call-quality tick               # one pass now, printed as JSON
aipbx_plugin_cli.php call-quality report [--hours=24]
aipbx_plugin_cli.php call-quality evidence <id>      # the full trail of one incident
aipbx_plugin_cron.php --run=call-quality/collect     # the same pass, through the dispatcher
```

`--status` shows the last run, its exit code and the tail of its output, so a
job that keeps failing is a visible row rather than a silent gap.

## Limits

- **No RTCP, no opinion.** When capture is off or remote, the collector records
  `capture_not_local` and says nothing. Silence is honest; "all calls are fine"
  would not be.
- **Loss only.** Jitter is kept and shown, but no threshold acts on it: without
  the clock rate there is no defensible line between "some jitter" and "bad".
- **One change, one direction.** The plugin can move a trunk to the back; it
  never promotes one back, and it never edits a phone.
- **Evidence is local.** Rows stay in this database and follow `evidence_days`.
  The notice mail is the only thing that leaves the server, and only if an
  administrator turned notice mail on.
