# PBX Settings

**System → PBX Settings** (`/asterisk-settings`, administrators) holds the settings that apply to the
whole PBX, one tab per subject. Saving marks the change for **Apply** ([Portal basics](portal.md)),
unless a tab says otherwise.

| Tab | What it sets | More |
|-----|--------------|------|
| **PJSIP & Net** | TLS method and SIP domain, audio codecs for web and for desk phones, SIP user agent, direct media, symmetric RTP, how often extensions are checked (qualify), ring timeouts for internal and outbound calls, and **Addresses the PBX uses**: *External IP Address*, *Public media address*, *Local Network & VPN Subnets* and, while it is set, *This server's address on the local network*. The ports of UDP, TCP and TLS are on [Transports](transports.md). | [WebRTC & NAT](webrtc-nat.md), [Network settings](network-settings.md) |
| **RTP** | The audio and video port range (a change needs an Asterisk restart) and Strict RTP. | [Network ports](network-ports.md) |
| **T.38 Fax** | The UDPTL ports for fax media, checksums and error correction (FEC). | [Trunks](trunks.md) |
| **Ringtone** | The web phone's ring and dial tones, and **Spoken status instead of tones**. | [PBX tuning](pbx-tuning.md#spoken-status-instead-of-tones) |
| **Video** | Video calls on or off, the codec, the largest resolution and frame rate. | [Video calls](video-calls.md) |
| **Language** | The system's default language, and **Time zone and tones**: one time zone for the server, the portal, call records and time conditions, and the ring and busy tones by country. | [Network settings](network-settings.md#time-zone-and-tones) |
| **AMD** | Answering machine detection, used by the auto dialer. | [Auto dialer](auto-dialer.md) |
| **Voicemail** | Message limits and PIN rules. | [Voicemail](voicemail.md#limits-and-pin-rules) |
| **Signaling** | Signaling capture (SIP and call quality) and how many days it is kept. | [Signaling](signaling.md), [Call quality](call-quality.md) |
| **Tuning & Ops** | Max-Forwards, Timer B, the jitter buffer, the night restart window, failed registration alarms. | [PBX tuning](pbx-tuning.md) |

Settings an option list does not reach go into [Advanced Configuration](advanced-config.md).
