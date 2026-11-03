#!/usr/bin/env python3
"""Unit tests of conf/sbin/aipbx-vpn: above all, that an uploaded profile cannot run anything."""

import importlib.machinery
import importlib.util
import ipaddress
import os
import sys
import unittest
from unittest import mock

PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'conf', 'sbin', 'aipbx-vpn')
sys.dont_write_bytecode = True
_loader = importlib.machinery.SourceFileLoader('aipbx_vpn', PATH)
_spec = importlib.util.spec_from_loader('aipbx_vpn', _loader)
vpn = importlib.util.module_from_spec(_spec)
_loader.exec_module(vpn)

KEY = 'YAnz5TF+lXXJte14tji3zlMNq+hd2rYUIgJBgB3fBmk='
PUB = 'xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg='
PEM = '-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ0xvY2FsVGVzdENlcnRpZmljYXRl\n-----END CERTIFICATE-----'
PRIV = '-----BEGIN PRIVATE KEY-----\nMIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg\n-----END PRIVATE KEY-----'


def wg(extra_iface='', extra_peer='', allowed='192.168.50.0/24'):
    return f"""[Interface]
PrivateKey = {KEY}
Address = 10.50.0.2/32
{extra_iface}

[Peer]
PublicKey = {PUB}
Endpoint = vpn.example.com:51820
AllowedIPs = {allowed}
{extra_peer}
"""


def ovpn(extra=''):
    return f"""client
dev tun
proto udp
remote vpn.example.com 1194
nobind
remote-cert-tls server
{extra}
<ca>
{PEM}
</ca>
<cert>
{PEM}
</cert>
<key>
{PRIV}
</key>
"""


class WireGuardProfile(unittest.TestCase):
    def test_good_profile(self):
        profile, ignored = vpn.parse_wg_profile(wg('DNS = 1.1.1.1'))
        self.assertEqual(profile['addresses'], ['10.50.0.2/32'])
        self.assertEqual(profile['peers'][0]['host'], 'vpn.example.com')
        self.assertEqual(ignored, ['dns'], 'DNS would change the name servers of the whole PBX')

    def test_commands_refused(self):
        for line in ('PostUp = id > /tmp/pwned', 'PreUp = id', 'PostDown = id', 'PreDown = id', 'SaveConfig = true',
                     'Table = off', 'FwMark = 1', 'postup=id', 'POSTUP = id'):
            with self.subTest(line=line), self.assertRaises(vpn.Refused):
                vpn.parse_wg_profile(wg(line))

    def test_comment_is_not_a_way_in(self):
        profile, _ = vpn.parse_wg_profile(wg('# PostUp = id'))
        self.assertNotIn('postup', profile)

    def test_newline_cannot_be_smuggled_into_generated_file(self):
        bad = wg().replace('Endpoint = vpn.example.com:51820', 'Endpoint = vpn.example.com\nPostUp=id:51820')
        with self.assertRaises(vpn.Refused):
            vpn.parse_wg_profile(bad)

    def test_bad_values(self):
        for text in (wg().replace(KEY, 'nope'), wg().replace(PUB, 'nope'),
                     wg().replace('vpn.example.com:51820', 'a;id:51820'),
                     wg().replace('vpn.example.com:51820', '$(id):51820'),
                     wg().replace('10.50.0.2/32', '$(id)'), wg().replace('192.168.50.0/24', '$(id)'),
                     'not a config', ''):
            with self.subTest(text=text[:30]), self.assertRaises(vpn.Refused):
                vpn.parse_wg_profile(text)

    def test_default_route_is_not_taken_from_the_profile(self):
        profile, _ = vpn.parse_wg_profile(wg(allowed='0.0.0.0/0, ::/0'))
        self.assertEqual(vpn.wg_routes_from_profile(profile), [])
        profile, _ = vpn.parse_wg_profile(wg(allowed='0.0.0.0/1, 128.0.0.0/1, 192.168.50.0/24'))
        self.assertEqual(vpn.wg_routes_from_profile(profile), ['192.168.50.0/24'])

    def test_route_must_fit_allowed_ips(self):
        profile, _ = vpn.parse_wg_profile(wg(allowed='0.0.0.0/0'))
        vpn.check_routes_in_profile(profile, ['192.168.9.0/24'])
        profile, _ = vpn.parse_wg_profile(wg())
        with self.assertRaises(vpn.Refused):
            vpn.check_routes_in_profile(profile, ['192.168.9.0/24'])

    def test_generated_file_holds_only_known_settings(self):
        profile, _ = vpn.parse_wg_profile(wg('MTU = 1400'))
        text = vpn.wg_conf_tunnel({'id': 3, 'profile': profile})
        keys = {l.split('=')[0].strip() for l in text.splitlines() if '=' in l}
        self.assertLessEqual(keys, {'PrivateKey', 'ListenPort', 'PublicKey', 'PresharedKey', 'Endpoint', 'AllowedIPs', 'PersistentKeepalive'})


class OpenVpnProfile(unittest.TestCase):
    def test_good_profile(self):
        profile, _ = vpn.parse_ovpn_profile(ovpn('cipher AES-256-GCM\nverb 4\nkeepalive 10 60'))
        self.assertEqual(profile['remotes'], [{'host': 'vpn.example.com', 'port': 1194}])
        self.assertIn(['cipher', 'AES-256-GCM'], profile['opts'])

    def test_common_settings_pass(self):
        for line in ('tls-version-min 1.2', 'tls-version-min 1.2 or-highest', 'data-ciphers AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305',
                     'auth SHA256', 'resolv-retry infinite', 'comp-lzo no', 'reneg-sec 0', 'explicit-exit-notify 2', 'key-direction 1',
                     'verify-x509-name server_abc name', 'ignore-unknown-option block-outside-dns', 'block-outside-dns', 'tun-mtu 1500'):
            with self.subTest(line=line):
                vpn.parse_ovpn_profile(ovpn(line))

    def test_our_own_device_profile_is_accepted(self):
        state = {'server': {'type': 'ovpn', 'port': 1194, 'proto': 'udp', 'net': '10.99.0.0/24', 'endpoint': 'pbx.example.com'}}
        def fake(path):
            return PRIV if path.endswith('.key') and 'tls' not in path else ('-----BEGIN OpenVPN Static key V1-----\nabcdef0123456789abcdef\n-----END OpenVPN Static key V1-----' if 'tls' in path else PEM)
        with mock.patch.object(vpn, 'read_file', side_effect=fake):
            text = vpn.ovpn_profile_peer(state, {'name': 'x', 'cn': 'peer-1'})
        vpn.parse_ovpn_profile(text)
        with mock.patch.object(vpn, 'read_file', return_value=KEY), mock.patch.object(vpn, 'wg_server_keys', return_value=(KEY, PUB)):
            text = vpn.wg_profile_peer({'server': {**state['server']}}, {'id': 1, 'name': 'x', 'ip': '10.99.0.2', 'psk': KEY})
        vpn.parse_wg_profile(text)

    def test_commands_and_file_access_refused(self):
        for line in ('up /tmp/x.sh', 'down /tmp/x.sh', 'script-security 2', 'plugin /tmp/x.so', 'route-up /tmp/x',
                     'route-pre-down /tmp/x', 'ipchange /tmp/x', 'tls-verify /tmp/x', 'tls-export-cert /tmp',
                     'config /etc/shadow', 'ca /etc/shadow', 'cert /etc/shadow', 'key /etc/shadow', 'tls-auth /etc/shadow 1',
                     'log /etc/cron.d/x', 'log-append /etc/cron.d/x', 'status /etc/cron.d/x', 'writepid /etc/cron.d/x', 'cd /tmp',
                     'management 0.0.0.0 7505', 'daemon', 'dev-type tap', 'dev tap0', 'http-proxy evil 80', 'socks-proxy evil 80', 'askpass /etc/shadow',
                     'learn-address /tmp/x', 'client-connect /tmp/x', 'auth-user-pass-verify /tmp/x via-file', 'iproute /tmp/x', 'tmp-dir /tmp',
                     'chroot /tmp', 'replay-persist /etc/x', 'ifconfig 1.1.1.1 2.2.2.2', 'UP /tmp/x', '"up" /tmp/x', 'u"p" /tmp/x'):
            with self.subTest(line=line):
                # UP is not OpenVPN's option either; every spelling ends the same way.
                with self.assertRaises(vpn.Refused):
                    vpn.parse_ovpn_profile(ovpn(line))

    def test_quoted_name_cannot_slip_by(self):
        with self.assertRaises(vpn.Refused):
            vpn.parse_ovpn_profile(ovpn('verb"\nup /tmp/x"'))

    def test_inline_blocks(self):
        with self.assertRaises(vpn.Refused):
            vpn.parse_ovpn_profile(ovpn('<connection>\nremote evil\n</connection>'))
        with self.assertRaises(vpn.Refused, msg='not closed'):
            vpn.parse_ovpn_profile(ovpn().replace('</key>', ''))
        with self.assertRaises(vpn.Refused, msg='not a key'):
            vpn.parse_ovpn_profile(ovpn().replace(PRIV, 'up /tmp/x'))

    def test_a_block_cannot_end_early_and_add_options(self):
        text = ovpn().replace(PRIV, PRIV + '\n</key>\nup /tmp/x\n<key>\n' + PRIV)
        with self.assertRaises(vpn.Refused):
            vpn.parse_ovpn_profile(text)

    def test_text_around_a_certificate_is_dropped(self):
        profile, _ = vpn.parse_ovpn_profile(ovpn().replace(PEM, 'Certificate:\n  up /tmp/x\n' + PEM, 1))
        self.assertNotIn('up /tmp/x', profile['inline']['ca'])

    def test_password_protected_key_refused(self):
        with self.assertRaises(vpn.Refused):
            vpn.parse_ovpn_profile(ovpn().replace('PRIVATE KEY', 'ENCRYPTED PRIVATE KEY'))

    def test_generated_file(self):
        profile, ignored = vpn.parse_ovpn_profile(ovpn('redirect-gateway def1\ndhcp-option DNS 1.1.1.1\nroute 8.8.8.0 255.255.255.0'))
        self.assertEqual(sorted(ignored), ['dhcp-option', 'redirect-gateway'])
        self.assertEqual(profile['routes'], ['8.8.8.0/24'])
        text = vpn.ovpn_conf_tunnel({'id': 7, 'profile': profile, 'routes': ['192.168.50.0/24']})
        self.assertIn('dev aipbxc7', text)
        self.assertIn('route-nopull', text, 'a server must not be able to push the default route')
        self.assertIn('script-security 1', text)
        self.assertIn('route 192.168.50.0 255.255.255.0', text)
        self.assertNotIn('redirect-gateway', text)
        self.assertNotIn('8.8.8.0', text)

    def test_login_file_is_never_used(self):
        profile, _ = vpn.parse_ovpn_profile(ovpn('auth-user-pass /etc/shadow'))
        self.assertTrue(profile['login'])
        text = vpn.ovpn_conf_tunnel({'id': 7, 'profile': profile, 'routes': []})
        self.assertNotIn('/etc/shadow', text)
        self.assertIn('auth-user-pass ' + vpn.priv('run', 't7.auth'), text)

    def test_login_inside_the_profile(self):
        profile, _ = vpn.parse_ovpn_profile(ovpn('<auth-user-pass>\nuser1\nsecret pass\n</auth-user-pass>'))
        self.assertTrue(profile['login'])
        self.assertEqual(profile['credentials'], ['user1', 'secret pass'])
        self.assertNotIn('secret pass', vpn.ovpn_conf_tunnel({'id': 7, 'profile': profile, 'routes': []}))
        for bad in ('<auth-user-pass>\nonly-one\n</auth-user-pass>', '<auth-user-pass>\na\nb\nc\n</auth-user-pass>'):
            with self.subTest(bad=bad), self.assertRaises(vpn.Refused):
                vpn.parse_ovpn_profile(ovpn(bad))

    def test_profile_routes(self):
        profile, ignored = vpn.parse_ovpn_profile(ovpn('route 192.168.5.0 255.255.255.0\nroute 10.1.2.3\nroute 0.0.0.0 0.0.0.0\nroute 0.0.0.0 128.0.0.0\nroute evil x\nroute 212.1.1.1 255.255.255.255 net_gateway\nroute 172.16.0.0 255.255.0.0 vpn_gateway'))
        self.assertEqual(profile['routes'], ['192.168.5.0/24', '10.1.2.3/32', '172.16.0.0/16'])
        self.assertEqual(ignored.count('route'), 4)

    def test_login_profile(self):
        profile, _ = vpn.parse_ovpn_profile(ovpn('auth-user-pass').replace(f'<cert>\n{PEM}\n</cert>\n<key>\n{PRIV}\n</key>\n', ''))
        self.assertTrue(profile['login'])
        text = vpn.ovpn_conf_tunnel({'id': 7, 'profile': profile, 'routes': []})
        self.assertIn('auth-user-pass ' + vpn.priv('run', 't7.auth'), text)


class Values(unittest.TestCase):
    def test_names(self):
        self.assertEqual(vpn.valid_name('  Mahir laptop '), 'Mahir laptop')
        for bad in ('', '../x', 'a;b', '$(id)', 'a\nb', 'x' * 41):
            with self.subTest(bad=bad), self.assertRaises(vpn.Refused):
                vpn.valid_name(bad)

    def test_hosts(self):
        self.assertEqual(vpn.valid_host('vpn.example.com'), 'vpn.example.com')
        self.assertEqual(vpn.valid_host('1.2.3.4'), '1.2.3.4')
        for bad in ('', '-x', 'a b', 'a;b', 'a\nb', '$(id)'):
            with self.subTest(bad=bad), self.assertRaises(vpn.Refused):
                vpn.valid_host(bad)

    def test_args(self):
        self.assertEqual(vpn.parse_args(['id=3'], {'id'}), {'id': '3'})
        with self.assertRaises(vpn.Refused):
            vpn.parse_args(['evil=3'], {'id'})


class Routes(unittest.TestCase):
    """The tunnel must not take the PBX's own networks or its default route."""
    ROUTES = [(ipaddress.IPv4Network('10.42.3.0/24'), 'ens192'), (ipaddress.IPv4Network('10.8.0.0/24'), 'tun0')]

    def setUp(self):
        p = mock.patch.object(vpn, 'connected_networks', return_value=self.ROUTES)
        p.start()
        self.addCleanup(p.stop)

    def state(self, **kw):
        return {'server': {'type': 'off', 'net': '10.99.0.0/24'}, 'tunnels': [], **kw}

    def test_default_and_near_default_refused(self):
        for r in ('0.0.0.0/0', '0.0.0.0/1', '128.0.0.0/1', '1.0.0.0/7'):
            with self.subTest(r=r), self.assertRaises(vpn.Refused):
                vpn.valid_routes(r, self.state())

    def test_network_in_use_refused(self):
        for r in ('10.0.0.0/8', '10.42.3.0/24', '10.42.3.80/32', '10.8.0.0/16'):
            with self.subTest(r=r), self.assertRaises(vpn.Refused):
                vpn.valid_routes(r, self.state())

    def test_good(self):
        self.assertEqual(vpn.valid_routes('192.168.50.0/24, 172.16.1.5/32 192.168.50.0/24', self.state()), ['192.168.50.0/24', '172.16.1.5/32'])

    def test_overlap_with_vpn_server_and_other_connections(self):
        s = self.state(server={'type': 'wg', 'net': '10.99.0.0/24'})
        with self.assertRaises(vpn.Refused):
            vpn.valid_routes('10.99.0.0/25', s)
        s = self.state(tunnels=[{'id': 1, 'name': 'a', 'routes': ['192.168.50.0/24']}])
        with self.assertRaises(vpn.Refused):
            vpn.valid_routes('192.168.50.0/24', s)
        self.assertEqual(vpn.valid_routes('192.168.50.0/24', s, skip_tunnel=1), ['192.168.50.0/24'], 'a connection may keep its own networks')

    def test_endpoint_inside_tunnel_refused(self):
        with self.assertRaises(vpn.Refused):
            vpn.check_endpoint_outside('192.168.50.9', ['192.168.50.0/24'])
        vpn.check_endpoint_outside('192.168.51.9', ['192.168.50.0/24'])


class ServerFiles(unittest.TestCase):
    def state(self, kind='wg'):
        return {'server': {'type': kind, 'port': 1194, 'proto': 'udp', 'net': '10.99.0.0/24', 'endpoint': 'pbx.example.com', 'peers': {'wg': [], 'ovpn': []}}, 'tunnels': []}

    def test_openvpn_server_needs_a_known_device(self):
        text = vpn.ovpn_conf_server(self.state('ovpn'))
        for needed in ('ccd-exclusive', 'verify-client-cert require', 'script-security 1', 'tls-crypt '):
            self.assertIn(needed, text)
        for never in ('client-to-client', 'redirect-gateway', 'push '):
            self.assertNotIn(never, text)

    def test_peer_reaches_only_the_server(self):
        with mock.patch.object(vpn, 'read_file', return_value='KEY\n'), mock.patch.object(vpn, 'wg_server_keys', return_value=('s', 'PUB')):
            text = vpn.wg_profile_peer(self.state(), {'id': 1, 'name': 'x', 'ip': '10.99.0.2', 'psk': 'P'})
        self.assertIn('AllowedIPs = 10.99.0.1/32', text)

    def test_tcp_has_no_exit_notify(self):
        s = self.state('ovpn')
        s['server']['proto'] = 'tcp'
        text = vpn.ovpn_conf_server(s)
        self.assertIn('proto tcp-server', text)
        self.assertNotIn('explicit-exit-notify', text)

    def test_address_allocation(self):
        s = self.state()['server']
        s['peers']['wg'] = [{'ip': '10.99.0.2'}]
        self.assertEqual(vpn.free_ip(s, 'wg'), '10.99.0.3')


class NothingForwards(unittest.TestCase):
    def test_no_forwarding_or_nat_in_the_helper(self):
        with open(PATH) as f:
            code = f.read()
        for word in ('ip_forward', 'masquerade', 'MASQUERADE', '--add-forward'):
            self.assertNotIn(word, code)


if __name__ == '__main__':
    unittest.main()
