# Call quality

**Reports → Call quality** (administrators) shows how the audio of the calls was: packet loss in both
directions and jitter, per trunk and per extension, from the RTCP reports Asterisk already sends. Nothing
on the PBX is changed by it.

Idea and first version: Gregory Merriweather ([@shido6](https://github.com/shido6)).

## Turning it on

It reads [signaling capture](signaling.md): **PBX settings → Signaling → Capture: On**. From then on the
measurements appear a minute after each call. Without capture the page says so and the health check
*Call quality* is "not used".

## Reading the page

- **Last 24 hours by trunk and extension**: calls, poor and fair calls, average and highest loss
  **in** and **out**, highest jitter.
- **Poor and fair calls**: newest first, with the address the media came from and the Call-ID.

**In** is what this PBX received, **out** what the other side received. That tells where to look:

| | in | out |
|---|---|---|
| trunk | the carrier's side | ours: uplink, firewall, bandwidth |
| extension | the phone's uplink | the way to the phone, often Wi-Fi |

A leg is **poor** when two of its reports in one direction reach the configured loss (default 5 %), and
**fair** from 1 %. Jitter stays in RTP timestamp units as Asterisk reports it (8 units = 1 ms for G.711;
the sample rate is not in the report).

## Alarms

A trunk or extension with at least *N* poor calls in the last 15 minutes (default 3, and at least a fifth
of its measured calls) is a problem of the [health check](health.md) **Call quality** (every 5 minutes):
it shows in the alert bell and is e-mailed to the health recipients like every other alarm, with a "back
to normal" mail when the audio is good again. Example: *"Trunk VOIP is losing audio: 4 of 9 calls in 15
minutes had poor audio, up to 12 % packet loss, mostly on the way from the carrier."*

## How it works

Every minute `aipbx-webhook-worker` reads the RTCP rows that arrived since the last pass from
`pbx_signaling_events` and adds them to one row per call leg in `pbx_call_quality_legs` (one SIP Call-ID:
a call from a trunk to a phone is two legs). A leg is named from its SIP INVITE: when the other side of the
INVITE is a trunk's address or matches its *match* list (host names are resolved), it is that trunk;
otherwise the From/To user part names the extension. The media may come from other addresses than the
signaling, so the INVITE, not the RTP address, decides. Settings (loss threshold, poor calls for an alarm,
days kept) are at the bottom of the page.
