"""HEPv3 (Homer Encapsulation Protocol) parsing for captured phone signaling.

Asterisk's res_hep / res_hep_pjsip / res_hep_rtcp copy every SIP and RTCP
message the PBX handles and send it as a HEPv3 datagram to a capture address
(PBX settings -> Signaling, default 127.0.0.1:9061). This module turns one
datagram into the row the portal stores.

It is pure: no sockets, no database, no clock. Every function can therefore be
unit-tested against fixtures (see ../tests), including the datagrams with the
quirks below.

Wire format ("HEPv3"):

    "HEP3" magic (4) | total length (2, big-endian) | reserved (2)
    then TLVs: type (2) + length (2, INCLUDING this 4-byte header) + value

Quirks of res_hep, all handled here (verified against live captures with
tcpdump — a plain "obey the spec" parser silently drops most of these):

  - TLV values are padded. An IPv4 address carries 4 trailing zero bytes, a
    timestamp and a capture id carry trailing zeros, and a port carries two,
    so a port is the FIRST two bytes, not the whole value.
  - The last payload TLV's declared length can overshoot the datagram, so a
    TLV whose value would run past the datagram is refused instead of read.
  - The SIP payload arrives under TLV 0x000F; 0x000D is also seen.
  - The protocol family of an RTCP report is NOT reliable. res_hep_rtcp
    datagrams are seen carrying family 1 (SIP) with an RTCP body, which is why
    payloads are classified by their CONTENT here and the family is only a
    hint. Trusting the family makes RTCP reports end up labelled as SIP.
"""

from __future__ import annotations

import gzip
import ipaddress
import json
import re
from typing import Iterator, Optional

HEP_MAGIC = b"HEP3"

# TLV types (Homer Encapsulation Protocol, the subset Asterisk sends).
HEP_PROTOCOL_FAMILY = 0x0001
HEP_TRANSPORT = 0x0002
HEP_SRC_IP4 = 0x0003
HEP_DST_IP4 = 0x0004
HEP_SRC_IP6 = 0x0005
HEP_DST_IP6 = 0x0006
HEP_SRC_PORT = 0x0007
HEP_DST_PORT = 0x0008
HEP_TIMESTAMP = 0x0009
HEP_CAPTURE_ID = 0x000C
HEP_PAYLOAD = 0x000D
HEP_PAYLOAD_GZ = 0x000E
HEP_PAYLOAD_ALT = 0x000F
HEP_CALL_ID = 0x0011

TRANSPORT_NAMES = {0: "udp", 1: "tcp", 2: "tls", 3: "sctp", 4: "ws", 5: "wss"}

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
    """An address TLV value; res_hep pads IPv4 with 4 zero bytes."""
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
    """Yields (type, value) for every complete TLV in a HEPv3 datagram.

    Stops at the first TLV whose declared length would run past the datagram:
    res_hep's final payload TLV can overshoot, and reading past it either
    raises or invents garbage. Trailing bytes that cannot hold a TLV header
    are ignored.
    """
    pos = 8
    end = len(data)
    while pos + 4 <= end:
        tlv_type = _u16(data[pos:pos + 2])
        tlv_len = _u16(data[pos + 2:pos + 4])
        if tlv_len < 4:
            return
        value_end = pos + tlv_len
        if value_end > end:
            return
        yield tlv_type, data[pos + 4:value_end]
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


def rtcp_summary(text: str) -> Optional[dict]:
    """Summary of an RTCP report, or None when the text is not one.

    res_hep_rtcp sends the report as a JSON object (Asterisk's own
    representation), so that is what is parsed here; a report that is not JSON
    still gets a proto of "rtcp" with the type read off the first byte pair
    when it looks like raw RTCP.
    """
    text = text.strip()
    if text.startswith("{"):
        try:
            report = json.loads(text)
        except ValueError:
            return None
        if not isinstance(report, dict):
            return None
        rtype = report.get("TYPE", report.get("type", ""))
        ssrc = report.get("SSRC", report.get("ssrc", ""))
        name = _RTCP_TYPES.get(int(rtype), "") if str(rtype).isdigit() else ""
        label = "RTCP %s" % (name or rtype) if (name or rtype != "") else "RTCP"
        return {
            "first_line": ("RTCP %s SSRC=%s" % (name, ssrc)).strip()[:160] if name
            else ("RTCP SSRC=%s" % ssrc)[:160],
            "method": label[:32] if name else "RTCP"[:32],
            "ssrc": str(ssrc)[:32],
            "report_type": str(rtype)[:16],
        }
    return None


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

    family = fields.get(HEP_PROTOCOL_FAMILY, b"")
    family_id = family[0] if family else 0

    payload = fields.get(HEP_PAYLOAD) or fields.get(HEP_PAYLOAD_ALT) or b""
    gz = fields.get(HEP_PAYLOAD_GZ)
    if not payload and gz:
        try:
            payload = gzip.decompress(gz)
        except (OSError, EOFError):
            payload = b""

    transport_raw = fields.get(HEP_TRANSPORT, b"")
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
