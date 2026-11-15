# External AI agents

**AI → External agents** (admin only) connect callers to a voice agent of another service — for
example talku.ai, Ultravox, OpenAI Realtime over SIP, or your own LiveKit or Pipecat server — that
answers at a SIP address. An agent is a destination like any other: choose **External AI agent** in
an inbound route, an IVR key, a time condition or wherever destinations are offered.

The caller stays on this PBX the whole time: the call record (CDR), the recording, the queue
statistics and the limits here keep working, and the agent can give the call back to a person.
Changes take effect with **Apply**, like other PBX settings.

## Settings

| Field | What |
|---|---|
| **SIP address** | `sip:user@host:port` as the service gives it; `sips:` means TLS |
| **Transport** | UDP, TCP or TLS (`sips:` is always TLS) |
| **Sign-in** | user name and password, only when the service asks for digest authentication; the password is stored encrypted and never shown again |
| **Calls at the same time** | above this number new calls go to the fallback destination |
| **Longest call** | the call is ended after this many minutes |
| **Wait for an answer** | seconds to wait for the agent to answer |
| **Hand-over destination** | where the call goes when the agent transfers it (a queue, a ring group, an extension…); without one, a transfer ends the call |
| **Fallback destination** | where the call goes when the agent cannot be reached: busy, no answer, an error, or too many calls |

## What the agent receives

The INVITE to the agent carries the caller in the usual `From`, plus three headers:

| Header | Value |
|---|---|
| `X-AiPBX-Caller` | the caller's number |
| `X-AiPBX-DID` | the number that was dialled (inbound route) |
| `X-AiPBX-Call-ID` | the Asterisk call id, the same as `uniqueid` in the call records |

Only audio codecs are offered (the PBX's codec list without video).

## Handing the call to a person

The agent hands a call over with a SIP **REFER** (blind transfer), which most voice-agent services
support as "transfer call". Whatever number or address the agent names, the call goes to the
agent's **hand-over destination**, so an agent can never make an outside call or reach an
extension it was not meant to. The number it asked for is written to the Asterisk log
(`AI agent <id>: hands the call over (asked for …)`).

## Security

- Each agent is a PJSIP endpoint `aiagent-<id>` used only for calls **to** the agent. It has no
  `identify` section and `identify_by=ip`, so no request from outside matches it: nobody can call
  in "as the agent" (the INVITE gets *No matching endpoint found*).
- Its context holds nothing but the hand-over: a REFER can only reach the hand-over destination.
- The SIP address is checked against a strict pattern before it is written to the dialplan.
- With a cloud agent, the caller's voice goes to that service: mention it in your call
  announcement where the law asks for it ([Privacy](privacy.md)).

## Files

| File | What |
|---|---|
| `/etc/asterisk/pbx/pjsip_ai_agents.conf` | the endpoint (and auth) of every active agent |
| `/etc/asterisk/pbx/extensions_ai_agents.conf` | `[aipbx-ai-agent-<id>]` (limit, headers, Dial, fallback) and `[aipbx-ai-agent-xfer-<id>]` (hand-over) |
| `pbx_ai_agents` | the settings |

## Troubleshooting

- **The call goes straight to the fallback:** look for `AI agent <id>: not reached (<DIALSTATUS>)`
  in the log, or record the problem (**Troubleshooting → Problem recording**): its zip has the SIP
  messages of the call, with the agent's answer to the INVITE (for example `401`/`403` for the
  sign-in, `488` for codecs, no answer at all for a wrong address, port, transport or a firewall).
- **The transfer does nothing:** the agent's REFER is answered `202 Accepted` when it reached the
  PBX; `400 Bad Request` means the agent sent no valid SIP address in `Refer-To`.
