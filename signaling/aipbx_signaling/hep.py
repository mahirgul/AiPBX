"""HEPv3 (Homer Encapsulation Protocol) parsing for captured phone signaling.

Asterisk's res_hep / res_hep_pjsip / res_hep_rtcp copy every SIP and RTCP
message the PBX handles and send it as a HEPv3 datagram to a capture address
(PBX settings -> Signaling, default 127.0.0.1:9061). This module turns one
datagram into the row the portal stores.

It is pure: no sockets, no database, no clock. Every function can therefore be
unit-tested against fixtures (see ../tests), including the datagrams with the
quirks below.

Wire format ("HEPv3", https://github.com/sipcapture/HEP):

    "HEP3" magic (4) | total length (2, big-endian)
    then chunks: vendor id (2) + chunk type (2) + length (2, INCLUDING this
    6-byte header) + value

Until 1.50.8 this parser read 4-byte chunk headers after an 8-byte packet
header. Every value was then shifted by two bytes (which looked like
"padding"), and the last chunk — the SIP or RTCP message itself — seemed to
run past the datagram and was dropped, so every captured message was stored
as "unknown" with no method and no RTCP counters (found with a live
Asterisk 22 capture, 2026-10-23).

What still needs care:

  - The protocol type (chunk 0x000B: 1 SIP, 5 RTCP) is a hint only; payloads
    are classified by their CONTENT, so a mislabelled datagram is still read
    right.
  - A chunk whose length would run past the datagram is refused, not read.
"""

from __future__ import annotations

import ipaddress
import json
import re
import zlib
from typing import Iterator, Optional

HEP_MAGIC = b"HEP3"
MAX_GZ_PAYLOAD_LEN = 65536

# Chunk types (Homer Encapsulation Protocol v3, the ones Asterisk's res_hep sends).
HEP_IP_FAMILY = 0x0001        # 2 = IPv4, 10 = IPv6
HEP_IP_PROTO = 0x0002         # 17 = UDP, 6 = TCP
HEP_SRC_IP4 = 0x0003
HEP_DST_IP4 = 0x0004
HEP_SRC_IP6 = 0x0005
HEP_DST_IP6 = 0x0006
HEP_SRC_PORT = 0x0007
HEP_DST_PORT = 0x0008
HEP_TIMESTAMP = 0x0009        # seconds; 0x000A carries the microseconds
HEP_PROTOCOL_TYPE = 0x000B    # 1 = SIP, 5 = RTCP (JSON)
HEP_CAPTURE_ID = 0x000C
HEP_AUTH_KEY = 0x000E
HEP_PAYLOAD = 0x000F
HEP_PAYLOAD_GZ = 0x0010
HEP_CALL_ID = 0x0011

HEADER_LEN = 6
CHUNK_HEADER_LEN = 6

TRANSPORT_NAMES = {17: "udp", 6: "tcp", 132: "sctp"}

PROTOCOL_NAMES = {
    1: "sip",
    2: "sip",
    3: "sdp",
    4: "rtp",
    5: "rtcp",
    6: "rtcp-xr",
    7: "mgcp",
    8: "megaco",
    9: "h323",
    10: "iax2",
}

#: Longest summary line stored. One line per message, enough to read a call
#: ladder and to search on; the SIP body itself is never stored.
MAX_LINE = 500

#: A SIP request line starts with a method token, a response with "SIP/2.0".
_SIP_METHODS = (
    "INVITE", "ACK", "BYE", "CANCEL", "REGISTER", "OPTIONS", "INFO", "PRACK",
    "SUBSCRIBE", "NOTIFY", "PUBLISH", "MESSAGE", "REFER", "UPDATE", "SERVICE",
)
_SIP_REQUEST_RE = re.compile(r"^(%s)\s+\S+\s+SIP/2\.0$" % "|".join(_SIP_METHODS))
_SIP_STATUS_RE = re.compile(r"^SIP/2\.0\s+(\d{3})(?:\s+(.*))?$")

#: RTCP report types worth naming (RFC 3550 / RFC 3611).
_RTCP_TYPES = {200: "sender-report", 201: "receiver-report", 202: "sdes",
               203: "bye", 204: "app", 205: "rr", 206: "xr"}


def _u16(raw: bytes) -> int:
    return int.from_bytes(raw[:2].ljust(2, b"\x00"), "big")


def _u32(raw: bytes) -> int:
    return int.from_bytes(raw[:4].ljust(4, b"\x00"), "big")


def _ip(raw: bytes) -> str:
    """An address chunk: 4 bytes IPv4, 16 bytes IPv6."""
    if len(raw) >= 16:
        try:
            return str(ipaddress.IPv6Address(raw[:16]))
        except ValueError:
            return ""
    if len(raw) >= 4:
        try:
            return str(ipaddress.IPv4Address(raw[:4]))
        except ValueError:
            return ""
    return ""


def iter_tlvs(data: bytes) -> Iterator[tuple[int, bytes]]:
    """Yields (chunk type, value) for every complete chunk of a HEPv3 datagram.

    Stops at the first chunk whose declared length is shorter than its header
    or would run past the datagram. Only the generic vendor (0) is read; a
    vendor chunk is skipped.
    """
    pos = HEADER_LEN
    end = len(data)
    while pos + CHUNK_HEADER_LEN <= end:
        vendor = _u16(data[pos:pos + 2])
        chunk_type = _u16(data[pos + 2:pos + 4])
        chunk_len = _u16(data[pos + 4:pos + 6])
        if chunk_len < CHUNK_HEADER_LEN:
            return
        value_end = pos + chunk_len
        if value_end > end:
            return
        if vendor == 0:
            yield chunk_type, data[pos + CHUNK_HEADER_LEN:value_end]
        pos = value_end


def _decode(payload: bytes) -> str:
    return payload.decode("utf-8", errors="replace")


def sip_summary(text: str) -> Optional[dict]:
    """Summary of a SIP message, or None when the text is not SIP.

    Recognised by content: a request line with a known method, or a
    "SIP/2.0 <code>" status line. Anything else is not SIP — see
    :func:`classify`.
    """
    lines = text.split("\r\n")
    if not lines:
        return None
    first_line = lines[0].strip()
    request = _SIP_REQUEST_RE.match(first_line)
    status = _SIP_STATUS_RE.match(first_line)
    if not request and not status:
        return None

    summary = {
        "first_line": first_line[:160],
        "from": "",
        "to": "",
        "call_id": "",
        "cseq": "",
        "method": "",
        "status_code": "",
    }
    for line in lines[1:]:
        if ":" not in line:
            continue
        name, _, value = line.partition(":")
        name = name.strip().lower()
        if name in ("from", "to", "call-id", "cseq"):
            summary[name.replace("-", "_")] = value.strip()[:220]

    cseq = summary["cseq"]
    if cseq:
        summary["method"] = cseq.split()[-1].upper()
    elif request:
        summary["method"] = request.group(1)
    if status:
        summary["status_code"] = status.group(1)
        summary["method"] = "RESPONSE"
    return summary


def _rtcp_int(value) -> Optional[int]:
    """An RTCP counter as an int; strings are accepted (Asterisk sends some).

    Asterisk's counters are unsigned 32-bit, and so are the columns. Anything
    outside that range did not come from Asterisk and is dropped (None): one
    value the column refuses would lose the whole batch, SIP rows included.
    """
    try:
        number = int(float(value))
    except (TypeError, ValueError, OverflowError):
        return None
    return number if 0 <= number <= 0xFFFFFFFF else None


def rtcp_summary(text: str) -> Optional[dict]:
    """Summary of an RTCP report, or None when the text is not one.

    res_hep_rtcp serializes Asterisk's `rtcp_report` object, whose shape is
    fixed by main/rtp_engine.c:

        {"ssrc": N, "type": 200|201, "report_count": N,
         "sender_information": {...}|null,
         "report_blocks": [{"source_ssrc": N, "fraction_lost": 0..255,
                            "packets_lost": N, "highest_seq_no": N,
                            "ia_jitter": N, "lsr": "N", "dlsr": N}, ...]}

    One datagram can carry several report blocks (one per source). The worst
    block is the one kept: a call is as good as its lossiest stream.
    `fraction_lost` is the 8-bit fixed-point value from RFC 3550 (255 = 100 %);
    `ia_jitter` is in RTP timestamp units, NOT milliseconds — the sample rate
    is not part of the report, so the raw value is stored rather than a
    converted one that would be a guess.

    The older flat shape ("SSRC"/"TYPE"/"lost"/"jitter") is still accepted:
    a collector or a test may send it, and rejecting it would lose reports.
    """
    text = text.strip()
    if not text.startswith("{"):
        return None
    try:
        report = json.loads(text)
    except ValueError:
        return None
    if not isinstance(report, dict):
        return None

    rtype = report.get("TYPE", report.get("type", ""))
    ssrc = report.get("SSRC", report.get("ssrc", ""))

    blocks = report.get("report_blocks")
    if not isinstance(blocks, list):
        blocks = []
    worst = None
    for block in blocks:
        if not isinstance(block, dict):
            continue
        fraction = _rtcp_int(block.get("fraction_lost"))
        candidate = {
            "ssrc": block.get("source_ssrc", ssrc),
            "fraction_lost": fraction,
            "lost": _rtcp_int(block.get("packets_lost")),
            "jitter": _rtcp_int(block.get("ia_jitter")),
        }
        if worst is None or (fraction or 0) > (worst.get("fraction_lost") or 0):
            worst = candidate

    # Flat reports: res_hep_rtcp's own JSON is nested, but the flat spelling is
    # what older captures and hand-written tests use. Do not lose them.
    if worst is None:
        fraction = _rtcp_int(report.get("fraction_lost"))
        worst = {
            "ssrc": ssrc,
            "fraction_lost": fraction,
            "lost": _rtcp_int(report.get("lost")),
            "jitter": _rtcp_int(report.get("jitter", report.get("ia_jitter"))),
        }

    name = _RTCP_TYPES.get(int(rtype), "") if str(rtype).isdigit() else ""
    label = "RTCP %s" % (name or rtype) if (name or rtype != "") else "RTCP"
    summary = {
        "first_line": ("RTCP %s SSRC=%s" % (name, ssrc)).strip()[:160] if name
        else ("RTCP SSRC=%s" % ssrc)[:160],
        "method": label[:32] if name else "RTCP"[:32],
        "ssrc": str(worst.get("ssrc", ssrc))[:32],
        "report_type": str(rtype)[:16],
    }
    for key in ("fraction_lost", "lost", "jitter"):
        if worst.get(key) is not None:
            summary[key] = worst[key]
    return summary


def classify(payload: bytes, family: int = 0) -> tuple[str, Optional[dict]]:
    """Names a payload and summarises it.

    Content decides, not the protocol family: an RTCP report that arrives
    labelled as SIP is still an RTCP report. The family is used only when the
    content is unrecognised, so an unknown family is never mistaken for SIP.
    """
    if not payload:
        return PROTOCOL_NAMES.get(family, "unknown"), None

    text = _decode(payload)
    summary = sip_summary(text)
    if summary is not None:
        return "sip", summary

    summary = rtcp_summary(text)
    if summary is not None:
        return "rtcp", summary

    hinted = PROTOCOL_NAMES.get(family, "")
    if hinted in ("rtcp", "rtcp-xr", "rtp"):
        return hinted, None
    return (hinted or "unknown"), None


def parse_event(data: bytes) -> Optional[dict]:
    """One HEPv3 datagram -> one event dict, or None when unusable.

    Keys match the columns of the `pbx_signaling_events` table.
    """
    if len(data) < 8 or data[0:4] != HEP_MAGIC:
        return None

    fields: dict[int, bytes] = {}
    for tlv_type, value in iter_tlvs(data):
        # First wins: res_hep does not repeat a TLV, and keeping the first
        # makes parsing independent of how a packet was assembled.
        fields.setdefault(tlv_type, value)

    family = fields.get(HEP_PROTOCOL_TYPE, b"")
    family_id = family[0] if family else 0

    payload = fields.get(HEP_PAYLOAD) or b""
    gz = fields.get(HEP_PAYLOAD_GZ)
    if not payload and gz:
        try:
            d = zlib.decompressobj(16 + zlib.MAX_WBITS)
            chunk = d.decompress(gz, MAX_GZ_PAYLOAD_LEN + 1)
            if len(chunk) <= MAX_GZ_PAYLOAD_LEN and not d.unconsumed_tail:
                payload = chunk + d.flush()
                if len(payload) > MAX_GZ_PAYLOAD_LEN:
                    payload = b""
        except (zlib.error, OSError, EOFError):
            payload = b""

    transport_raw = fields.get(HEP_IP_PROTO, b"")
    transport = TRANSPORT_NAMES.get(transport_raw[0] if transport_raw else 0, "")

    src_ip = _ip(fields.get(HEP_SRC_IP4, b"")) or _ip(fields.get(HEP_SRC_IP6, b""))
    dst_ip = _ip(fields.get(HEP_DST_IP4, b"")) or _ip(fields.get(HEP_DST_IP6, b""))
    src = "%s:%d" % (src_ip, _u16(fields.get(HEP_SRC_PORT, b"")))
    dst = "%s:%d" % (dst_ip, _u16(fields.get(HEP_DST_PORT, b"")))

    # The Call-ID in its own TLV is what res_hep's uuid_type=call-id fills in;
    # the header of the SIP message is the fallback.
    hep_call_id = _decode(fields.get(HEP_CALL_ID, b"")).rstrip("\x00 \t").strip()

    # A datagram with no payload carries no signaling: storing it would fill
    # the table with empty rows (an unknown protocol and nothing else). It is
    # only kept when it still identifies a call.
    if not payload and not hep_call_id:
        return None

    proto, summary = classify(payload, family_id)

    # A payload that is not a SIP message is never filed as one. Without this,
    # any two bytes sent to the port would become a "sip / UNKNOWN" row and
    # every report built on the SIP stream would count it. Such a datagram is
    # still kept when it names a call — as "unknown", so the ladder shows the
    # gap in that call instead of the statistics counting a message that was
    # never understood.
    if proto == "sip" and summary is None:
        if not hep_call_id:
            return None
        proto = "unknown"

    call_id = hep_call_id or (summary or {}).get("call_id", "")

    method = (summary or {}).get("method", "")
    if not method:
        method = proto.upper() if proto != "sip" else "UNKNOWN"

    event = {
        "ts": _u32(fields.get(HEP_TIMESTAMP, b"")),
        "call_id": call_id[:128],
        "method": method[:32],
        "proto": proto[:8],
        "transport": transport[:8],
        "src": src[:64],
        "dst": dst[:64],
        "from_uri": (summary or {}).get("from", "")[:255],
        "to_uri": (summary or {}).get("to", "")[:255],
        "cseq": (summary or {}).get("cseq", "")[:64],
        "status_code": (summary or {}).get("status_code", "")[:8],
        "first_line": (summary or {}).get("first_line", "")[:200],
        "line": "",
        "capture_id": str(_u32(fields.get(HEP_CAPTURE_ID, b"")))[:32],
        # RTCP quality counters. None when this is not an RTCP report or the
        # report did not carry them: an absent measurement must stay absent,
        # never become a zero that looks healthy.
        "rtcp_ssrc": str((summary or {}).get("ssrc", ""))[:32],
        "rtcp_lost": (summary or {}).get("lost"),
        "rtcp_fraction_lost": (summary or {}).get("fraction_lost"),
        "rtcp_jitter": (summary or {}).get("jitter"),
    }
    event["line"] = summary_line(event)
    return event


def summary_line(event: dict) -> str:
    """The searchable one-line text of an event (stored, never the SIP body)."""
    if event.get("proto") == "sip" and event.get("first_line"):
        line = ('first="%s" from="%s" to="%s" call_id="%s" cseq="%s" src=%s dst=%s'
                % (event.get("first_line", ""), event.get("from_uri", ""),
                   event.get("to_uri", ""), event.get("call_id", ""),
                   event.get("cseq", ""), event.get("src", ""), event.get("dst", "")))
    else:
        line = ("%s src=%s dst=%s first=%s"
                % (event.get("proto", "").upper(), event.get("src", ""),
                   event.get("dst", ""), event.get("first_line", "")))
    return line[:MAX_LINE]


def caller_number(from_uri: str) -> str:
    """The number out of a From header, for display: <sip:101@host> -> 101."""
    if not from_uri:
        return ""
    match = re.search(r"sip:([^@>;]+)", from_uri)
    if match:
        return match.group(1)[:32]
    return ""
