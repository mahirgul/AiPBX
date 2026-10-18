"""Tests for the HEPv3 parser (aipbx_signaling.hep).

The fixtures are built here byte by byte, in the shape res_hep actually sends:
padded TLV values, the payload under 0x000F, and a final payload TLV whose
declared length can overshoot the datagram.

Runs with either runner: `python3 -m unittest discover signaling/tests`
or `pytest signaling/tests`.
"""

from __future__ import annotations

import gzip
import json
import os
import socket
import struct
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from aipbx_signaling import hep  # noqa: E402

INVITE = (
    "INVITE sip:101@192.0.2.20:5060 SIP/2.0\r\n"
    "Via: SIP/2.0/UDP 192.0.2.10:5060;branch=z9hG4bK-1\r\n"
    "From: <sip:201@192.0.2.10>;tag=aa11\r\n"
    "To: <sip:101@192.0.2.20>\r\n"
    "Call-ID: 88cc0c5b-ef95-4d22-b8a9-b381b8dc152f\r\n"
    "CSeq: 1 INVITE\r\n"
    "Content-Length: 0\r\n\r\n"
)

OK_200 = (
    "SIP/2.0 200 OK\r\n"
    "Via: SIP/2.0/UDP 192.0.2.10:5060;branch=z9hG4bK-1\r\n"
    "From: <sip:201@192.0.2.10>;tag=aa11\r\n"
    "To: <sip:101@192.0.2.20>;tag=bb22\r\n"
    "Call-ID: 88cc0c5b-ef95-4d22-b8a9-b381b8dc152f\r\n"
    "CSeq: 1 INVITE\r\n\r\n"
)

OPTIONS = (
    "OPTIONS sip:101@192.0.2.20:51522 SIP/2.0\r\n"
    "From: <sip:101@192.0.2.10>;tag=cc33\r\n"
    "To: <sip:101@192.0.2.20>\r\n"
    "Call-ID: 43c45272-efc8-430a-ac74-cd669a5c3405\r\n"
    "CSeq: 102 OPTIONS\r\n\r\n"
)

#: What res_hep_rtcp actually sends: a JSON report.
RTCP_REPORT = json.dumps({"SSRC": 4001170063, "TYPE": 201, "R": 0,
                          "lost": 0, "jitter": 12})


def tlv(tlv_type: int, value: bytes) -> bytes:
    return struct.pack(">HH", tlv_type, len(value) + 4) + value


def hep_packet(payload: bytes, *, family: int = 1, transport: int = 0,
               src: str = "192.0.2.10", sport: int = 5060,
               dst: str = "192.0.2.20", dport: int = 5060,
               ts: int = 1792181287, capture_id: int = 1234,
               call_id: str = "", payload_tlv: int = hep.HEP_PAYLOAD_ALT,
               gzip_payload: bool = False, overshoot: bool = True) -> bytes:
    """A res_hep-shaped HEPv3 datagram."""
    body = b""
    body += tlv(hep.HEP_PROTOCOL_FAMILY, bytes([family]))
    body += tlv(hep.HEP_TRANSPORT, bytes([transport]))
    # Addresses, timestamp and capture id are padded, as res_hep sends them.
    body += tlv(hep.HEP_SRC_IP4, socket.inet_aton(src) + b"\x00" * 4)
    body += tlv(hep.HEP_DST_IP4, socket.inet_aton(dst) + b"\x00" * 4)
    body += tlv(hep.HEP_SRC_PORT, struct.pack(">H", sport) + b"\x00\x00")
    body += tlv(hep.HEP_DST_PORT, struct.pack(">H", dport) + b"\x00\x00")
    body += tlv(hep.HEP_TIMESTAMP, struct.pack(">I", ts) + b"\x00" * 4)
    body += tlv(hep.HEP_CAPTURE_ID, struct.pack(">I", capture_id))
    if call_id:
        body += tlv(hep.HEP_CALL_ID, call_id.encode())
    if gzip_payload:
        body += tlv(hep.HEP_PAYLOAD_GZ, gzip.compress(payload))
    else:
        body += tlv(payload_tlv, payload)

    declared = len(body) + 8
    if overshoot:
        # res_hep's last TLV length can overshoot the datagram: the header
        # says the whole packet is this long, while the bytes are not.
        declared += 37
    return hep.HEP_MAGIC + struct.pack(">HH", declared, 0) + body


class TestSip(unittest.TestCase):
    def test_invite(self):
        event = hep.parse_event(hep_packet(INVITE.encode()))
        self.assertIsNotNone(event)
        self.assertEqual("sip", event["proto"])
        self.assertEqual("INVITE", event["method"])
        self.assertEqual("88cc0c5b-ef95-4d22-b8a9-b381b8dc152f", event["call_id"])
        self.assertEqual("", event["status_code"])
        self.assertEqual("192.0.2.10:5060", event["src"])
        self.assertEqual("192.0.2.20:5060", event["dst"])
        self.assertEqual("udp", event["transport"])
        self.assertEqual(1792181287, event["ts"])
        self.assertEqual("1234", event["capture_id"])
        self.assertIn("<sip:201@192.0.2.10>", event["from_uri"])
        self.assertEqual("201", hep.caller_number(event["from_uri"]))

    def test_response(self):
        event = hep.parse_event(hep_packet(OK_200.encode()))
        self.assertEqual("RESPONSE", event["method"])
        self.assertEqual("200", event["status_code"])
        self.assertTrue(event["first_line"].startswith("SIP/2.0 200"))

    def test_options_keepalive(self):
        event = hep.parse_event(hep_packet(OPTIONS.encode()))
        self.assertEqual("OPTIONS", event["method"])
        self.assertEqual("101", hep.caller_number(event["from_uri"]))

    def test_tcp_transport_name(self):
        event = hep.parse_event(hep_packet(OPTIONS.encode(), transport=1))
        self.assertEqual("tcp", event["transport"])

    def test_payload_under_0x000d(self):
        event = hep.parse_event(hep_packet(INVITE.encode(), payload_tlv=hep.HEP_PAYLOAD))
        self.assertEqual("INVITE", event["method"])

    def test_gzip_payload(self):
        event = hep.parse_event(hep_packet(INVITE.encode(), gzip_payload=True))
        self.assertEqual("INVITE", event["method"])

    def test_call_id_from_hep_tlv_when_header_is_missing(self):
        event = hep.parse_event(hep_packet(b"not sip at all", call_id="hep-tlv-call-id"))
        self.assertEqual("hep-tlv-call-id", event["call_id"])


class TestRtcp(unittest.TestCase):
    def test_rtcp_is_not_labelled_sip(self):
        """res_hep_rtcp datagrams arrive with family 1 (SIP) and an RTCP body.

        Classifying by content must still call it RTCP — trusting the family is
        exactly the bug that puts an RTCP report in the SIP stream.
        """
        event = hep.parse_event(hep_packet(RTCP_REPORT.encode(), family=1))
        self.assertEqual("rtcp", event["proto"])
        self.assertEqual("RTCP receiver-report", event["method"])

    def test_sender_report_type(self):
        event = hep.parse_event(hep_packet(
            json.dumps({"SSRC": 4001170051, "TYPE": 200}).encode(), family=1))
        self.assertEqual("rtcp", event["proto"])
        self.assertEqual("RTCP sender-report", event["method"])

    def test_unknown_rtcp_type_stays_rtcp(self):
        event = hep.parse_event(hep_packet(
            json.dumps({"SSRC": 1, "TYPE": 999}).encode(), family=1))
        self.assertEqual("rtcp", event["proto"])
        self.assertEqual("RTCP", event["method"])

    def test_rtcp_family_without_json_is_rtcp(self):
        event = hep.parse_event(hep_packet(RTCP_REPORT.encode(), family=5))
        self.assertEqual("rtcp", event["proto"])

    def test_rfc3611_xr_hint(self):
        event = hep.parse_event(hep_packet(b"\x80\x00\x00\x01", family=6))
        self.assertEqual("rtcp-xr", event["proto"])


class TestRobustness(unittest.TestCase):
    def test_overshooting_payload_length_does_not_raise(self):
        for payload in (INVITE.encode(), b"", RTCP_REPORT.encode()):
            event = hep.parse_event(hep_packet(payload))
            if payload:
                self.assertIsNotNone(event)

    def test_not_a_hep_packet(self):
        self.assertIsNone(hep.parse_event(b""))
        self.assertIsNone(hep.parse_event(b"HEP3"))
        self.assertIsNone(hep.parse_event(b"\x00" * 32))
        self.assertIsNone(hep.parse_event(b"SIP/2.0 200 OK\r\n\r\n"))

    def test_truncated_datagram_keeps_earlier_tlvs(self):
        packet = hep_packet(INVITE.encode())
        event = hep.parse_event(packet[:len(packet) - 40])
        # The SIP payload is gone, so nothing is stored that pretends to be a
        # SIP message: no method is invented from the surviving TLVs.
        self.assertNotEqual("INVITE", (event or {}).get("method"))

    def test_tlv_length_below_header_is_refused(self):
        body = hep.HEP_MAGIC + struct.pack(">HH", 16, 0) + struct.pack(">HH", 0x0001, 0)
        self.assertIsNone(hep.parse_event(body))
        self.assertEqual([], list(hep.iter_tlvs(body)))

    def test_no_payload_is_not_stored_unless_it_names_a_call(self):
        # Nothing but transport and addresses: no signaling, no row.
        self.assertIsNone(hep.parse_event(hep_packet(b"")))
        # A datagram that still carries the Call-ID is kept, so a call is
        # never lost from the ladder just because the body was dropped.
        event = hep.parse_event(hep_packet(b"", call_id="keep-me"))
        self.assertIsNotNone(event)
        self.assertEqual("keep-me", event["call_id"])

    def test_line_is_bounded(self):
        # A valid message with absurdly long headers: the stored line and the
        # stored address must both be cut, so one message cannot bloat a row.
        long_uri = "9" * 900
        message = ("OPTIONS sip:101@192.0.2.20 SIP/2.0\r\n"
                   "From: <sip:%s@192.0.2.10>;tag=aa\r\n"
                   "To: <sip:101@192.0.2.20>\r\n"
                   "Call-ID: bound-check\r\n"
                   "CSeq: 1 OPTIONS\r\n\r\n") % long_uri
        event = hep.parse_event(hep_packet(message.encode()))
        self.assertIsNotNone(event)
        self.assertLessEqual(len(event["line"]), hep.MAX_LINE)
        self.assertLessEqual(len(event["from_uri"]), 255)
        self.assertEqual("bound-check", event["call_id"])


class TestClassify(unittest.TestCase):
    def test_unknown_payload_uses_family_hint(self):
        self.assertEqual(("mgcp", None), hep.classify(b"\x01\x02", 7))

    def test_unknown_family_is_not_sip(self):
        proto, summary = hep.classify(b"\xff\xfe\xfd", 0)
        self.assertNotEqual("sip", proto)
        self.assertIsNone(summary)

    def test_empty_payload(self):
        self.assertEqual(("sip", None), hep.classify(b"", 1))

    def test_sip_by_content_even_with_wrong_family(self):
        proto, summary = hep.classify(INVITE.encode(), 5)
        self.assertEqual("sip", proto)
        self.assertEqual("INVITE", summary["method"])


if __name__ == "__main__":
    unittest.main()
