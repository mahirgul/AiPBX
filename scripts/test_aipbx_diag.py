#!/usr/bin/env python3
"""Unit tests of conf/sbin/aipbx-diag: what leaves the server in a problem recording."""

import importlib.machinery
import importlib.util
import os
import sys
import unittest

PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'conf', 'sbin', 'aipbx-diag')
sys.dont_write_bytecode = True
_loader = importlib.machinery.SourceFileLoader('aipbx_diag', PATH)
_spec = importlib.util.spec_from_loader('aipbx_diag', _loader)
diag = importlib.util.module_from_spec(_spec)
_loader.exec_module(diag)


class MaskTest(unittest.TestCase):
    def test_phone_numbers_lose_their_last_five_digits(self):
        for raw, want in (('From: <sip:05321112233@10.0.0.5>', 'From: <sip:053211xxxxx@10.0.0.5>'),
                          ('To: <sip:+905321112233@pbx>', 'To: <sip:+9053211xxxxx@pbx>'),
                          ('2026-11-15 00:01:02\t"Kemal" <02125551234>\t02125551234\t1001',
                           '2026-11-15 00:01:02\t"Kemal" <021255xxxxx>\t021255xxxxx\t1001'),
                          ('tel:4155551234;phone-context=x', 'tel:41555xxxxx;phone-context=x')):
            self.assertEqual(diag.mask(raw), want)

    def test_extensions_addresses_times_and_ids_stay(self):
        for keep in ('Contact: <sip:1001@10.218.49.121:5060;transport=udp>',
                     'Call-ID: a84b4c76e66710@pc33.example.com',
                     '1794686914|1794686914.12|sales|PJSIP/1001-00000003|CONNECT|5|1794686913.11|1',
                     'Uniqueid: 1794686914.123', 'ROOM cc039151640676', 'CSeq: 102 INVITE',
                     'User-Agent: Yealink SIP-T46S 66.86.0.15', '[Nov 15 00:01:02] VERBOSE[44065][C-00000003]'):
            self.assertEqual(diag.mask(keep), keep)

    def test_secrets_are_removed(self):
        text = ('[1001-sip]\ntype=auth\npassword = Sup3rSecret!\nmd5_cred=abc123def\n'
                'Authorization: Digest username="1001", realm="asterisk", nonce="1794-abc", '
                'uri="sip:pbx", response="0123456789abcdef0123456789abcdef"\n'
                'GET /api/x?token=abcdefghijk&id=1 HTTP/1.1\nX-Header: Bearer eyJhbGciOiJIUzI1NiJ9.e30.abc\n'
                'capture_password = aipbx\n')
        out = diag.mask(text)
        for secret in ('Sup3rSecret!', 'abc123def', '0123456789abcdef0123456789abcdef', 'abcdefghijk', 'eyJhbGciOiJIUzI1NiJ9'):
            self.assertNotIn(secret, out)
        self.assertIn('username="1001"', out)
        self.assertIn('password = ***', out)

    def test_arguments_are_checked(self):
        for argv in (['nope'], ['delete', 'name=../../etc/passwd'], ['start', 'minutes=5;id'], ['status', 'x=1']):
            with self.assertRaises(diag.Refused):
                diag.main(argv)



class CaptureSliceTest(unittest.TestCase):
    """The recording's SIP messages from the files aipbx-signaling writes, masked."""

    def setUp(self):
        import tempfile
        sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'signaling'))
        from aipbx_signaling.pcap import PcapWriter
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        diag.CAPTURE_DIR = self.tmp.name
        w = PcapWriter(self.tmp.name)
        w.configure(True, 10, 2048)
        invite = ('INVITE sip:05321112233@pbx SIP/2.0\r\nFrom: <sip:1001@pbx>;tag=a\r\n'
                  'Authorization: Digest username="1001", response="0123456789abcdef"\r\n\r\n').encode()
        self.t0 = 1794686914
        for ts, src in ((self.t0 - 3600, ('10.0.0.5', 5060)), (self.t0, ('10.0.0.5', 5060)), (self.t0 + 5, ('2001:db8::5', 5062))):
            w.write({'ts': ts, '_payload': invite, '_src': src, '_dst': ('10.0.0.1', 5060)})
        w.close()

    def packets(self, pcap):
        import struct
        out, pos = [], 24
        while pos < len(pcap):
            _s, _u, incl, _o = struct.unpack('<IIII', pcap[pos:pos + 16])
            out.append(pcap[pos + 16:pos + 16 + incl])
            pos += 16 + incl
        return out

    def test_only_the_recording_time_masked_with_right_lengths(self):
        import struct
        pcap = diag.capture_slice(self.t0, self.t0 + 10)
        pkts = self.packets(pcap)
        self.assertEqual(len(pkts), 2)  # the one an hour before is left out
        for pkt in pkts:
            head = (pkt[0] & 0x0F) * 4 if pkt[0] >> 4 == 4 else 40
            sip = pkt[head + 8:]
            self.assertIn(b'sip:053211xxxxx@pbx', sip)
            self.assertIn(b'response="***"', sip)
            self.assertNotIn(b'0123456789abcdef', sip)
            self.assertEqual(struct.unpack('!H', pkt[head + 4:head + 6])[0], 8 + len(sip))
            ip_len = struct.unpack('!H', pkt[2:4])[0] if head == 20 else 40 + struct.unpack('!H', pkt[4:6])[0]
            self.assertEqual(ip_len, len(pkt))

    def test_nothing_without_capture_files(self):
        diag.CAPTURE_DIR = os.path.join(self.tmp.name, 'none')
        self.assertIsNone(diag.capture_slice(self.t0, self.t0 + 10))

if __name__ == '__main__':
    unittest.main()
