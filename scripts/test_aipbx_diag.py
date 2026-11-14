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


if __name__ == '__main__':
    unittest.main()
