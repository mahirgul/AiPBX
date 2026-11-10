#!/usr/bin/python3
"""Checks and netplan editing of conf/sbin/aipbx-netcfg."""
import importlib.machinery, importlib.util, os, tempfile, unittest
from types import SimpleNamespace
from unittest.mock import patch
path = os.path.join(os.path.dirname(__file__), '..', 'conf', 'sbin', 'aipbx-netcfg')
loader = importlib.machinery.SourceFileLoader('netcfg', path)
spec = importlib.util.spec_from_loader('netcfg', loader)
nc = importlib.util.module_from_spec(spec)
loader.exec_module(nc)


class Checks(unittest.TestCase):
    def test_good(self):
        self.assertEqual(nc.check_static('10.23.3.70/24', '10.23.3.1', '10.0.0.4, 10.0.0.5'),
                         ('10.23.3.70/24', '10.23.3.1', ['10.0.0.4', '10.0.0.5']))

    def test_bad(self):
        for a, g, d in [('10.23.3.70', '10.23.3.1', '8.8.8.8'), ('x/24', '10.23.3.1', '8.8.8.8'),
                        ('10.23.3.70/24', '10.23.3.70', '8.8.8.8'), ('10.23.3.70/24', 'x', '8.8.8.8'),
                        ('10.23.3.70/24', '10.23.3.1', 'x'), ('10.23.3.70/24', '10.23.3.1', '1.1.1.1,2.2.2.2,3.3.3.3,4.4.4.4')]:
            with self.assertRaises(nc.Refused, msg=(a, g, d)):
                nc.check_static(a, g, d)

    def test_unusual_addresses_are_the_admin_s_choice(self):
        self.assertEqual(nc.check_static('203.0.113.5/32', '203.0.113.1', ''), ('203.0.113.5/32', '203.0.113.1', []))
        e = nc.edit_config({'network': {'ethernets': {'eth1': {}}}}, 'eth1', 'static', '203.0.113.5/32', '203.0.113.1', [])['network']['ethernets']['eth1']
        self.assertEqual(e['routes'], [{'to': 'default', 'via': '203.0.113.1', 'on-link': True}])

    def test_inner_card_needs_no_gateway_or_dns(self):
        self.assertEqual(nc.check_static('10.23.3.70/24', '', ''), ('10.23.3.70/24', '', []))
        e = nc.edit_config({'network': {'ethernets': {'eth1': {}}}}, 'eth1', 'static', '10.23.3.70/24', '', [])['network']['ethernets']['eth1']
        self.assertEqual(e, {'dhcp4': False, 'addresses': ['10.23.3.70/24']})

    def test_iface_name(self):
        self.assertTrue(nc.valid_iface('ens192'))
        for bad in ['', 'a b', 'a;b', '../x', 'x' * 16]:
            self.assertFalse(nc.valid_iface(bad), bad)


class Edit(unittest.TestCase):
    def cfg(self):
        return {'network': {'version': 2, 'ethernets': {'ens192': {
            'addresses': ['10.23.3.70/24'], 'match': {'macaddress': 'aa'}, 'set-name': 'ens192', 'mtu': 1400,
            'nameservers': {'addresses': ['10.0.0.4'], 'search': []}, 'routes': [{'to': 'default', 'via': '10.23.3.1'}]},
            'ens224': {'dhcp4': True}}}}

    def test_to_dhcp_keeps_the_rest(self):
        e = nc.edit_config(self.cfg(), 'ens192', 'dhcp')['network']['ethernets']
        self.assertEqual(e['ens192'], {'match': {'macaddress': 'aa'}, 'set-name': 'ens192', 'mtu': 1400, 'dhcp4': True})
        self.assertEqual(e['ens224'], {'dhcp4': True})

    def test_to_static(self):
        e = nc.edit_config(self.cfg(), 'ens192', 'static', '192.168.1.5/24', '192.168.1.1', ['1.1.1.1'])['network']['ethernets']['ens192']
        self.assertFalse(e['dhcp4'])
        self.assertEqual(e['addresses'], ['192.168.1.5/24'])
        self.assertEqual(e['routes'], [{'to': 'default', 'via': '192.168.1.1'}])
        self.assertEqual(e['nameservers'], {'addresses': ['1.1.1.1']})
        self.assertEqual(e['mtu'], 1400)

    @unittest.skipUnless(importlib.util.find_spec('yaml'), 'needs python3-yaml, which install.sh installs')
    def test_finds_the_file(self):
        with tempfile.TemporaryDirectory() as d:
            with open(d + '/00-a.yaml', 'w') as f:
                f.write('network: {version: 2, ethernets: {ens192: {dhcp4: true}}}\n')
            with open(d + '/90-b.yaml', 'w') as f:
                f.write('network: {version: 2}\n')
            with open(d + '/note.txt', 'w') as f:
                f.write('x')
            self.assertEqual(nc.files_with('ens192', d), ['00-a.yaml'])
            self.assertEqual(nc.files_with('eth9', d), [])



class Router(unittest.TestCase):
    def test_a_card_with_its_own_router(self):
        e = nc.edit_config({'network': {'ethernets': {'eth1': {}}}}, 'eth1', 'static', '10.1.1.5/24', '10.1.1.1', [], False, ['10.12.0.0/16'])['network']['ethernets']['eth1']
        t = nc.table_of('eth1')
        self.assertEqual(e['routes'], [{'to': 'default', 'via': '10.1.1.1', 'table': t}, {'to': '10.12.0.0/16', 'via': '10.1.1.1'}])
        self.assertEqual(e['routing-policy'], [{'from': '10.1.1.5', 'table': t}])

    def test_nets_need_a_router(self):
        self.assertEqual(nc.check_nets('10.12.3.9/16, 192.168.5.0/24', '10.1.1.1'), ['10.12.0.0/16', '192.168.5.0/24'])
        for nets, gw in [('10.0.0.0/8', ''), ('x', '10.1.1.1'), ('0.0.0.0/0', '10.1.1.1')]:
            with self.assertRaises(nc.Refused):
                nc.check_nets(nets, gw)

    def test_the_old_default_keeps_its_router(self):
        eth = {'addresses': ['10.0.0.5/24'], 'routes': [{'to': 'default', 'via': '10.0.0.1'}]}
        self.assertTrue(nc.demote_default(eth, 'eth0'))
        self.assertEqual(eth['routes'], [{'to': 'default', 'via': '10.0.0.1', 'table': nc.table_of('eth0')}])
        self.assertEqual(eth['routing-policy'], [{'from': '10.0.0.5', 'table': nc.table_of('eth0')}])
        self.assertFalse(nc.demote_default({'addresses': ['10.1.1.1/24']}, 'eth2'))
        dhcp = {'dhcp4': True}
        self.assertTrue(nc.demote_default(dhcp, 'eth3'))
        self.assertEqual(dhcp['dhcp4-overrides'], {'use-routes': False})

    def test_network_manager_settings(self):
        a = nc.nm_settings('static', '10.1.1.5/24', '10.1.1.1', ['9.9.9.9'], False, ['10.12.0.0/16'], 200)
        d = dict(zip(a[::2], a[1::2]))
        self.assertEqual(d['ipv4.gateway'], '')
        self.assertEqual(d['ipv4.routes'], '10.12.0.0/16 10.1.1.1, 0.0.0.0/0 10.1.1.1 table=200')
        self.assertEqual(d['ipv4.routing-rules'], 'priority 200 from 10.1.1.5/32 table 200')
        self.assertEqual(d['ipv4.never-default'], 'yes')
        d = dict(zip(*[iter(nc.nm_settings('static', '10.1.1.5/24', '10.1.1.1', [], True))] * 2))
        self.assertEqual((d['ipv4.gateway'], d['ipv4.routes'], d['ipv4.never-default']), ('10.1.1.1', '', 'no'))


class Coturn(unittest.TestCase):
    CONF = 'realm=x\nrelay-ip=10.0.0.1\nallowed-peer-ip=10.0.0.1\nallowed-peer-ip=10.0.0.2\ndenied-peer-ip=10.0.0.0-10.255.255.255\n'

    def test_nothing_changes_when_the_addresses_are_the_same(self):
        self.assertEqual(nc.coturn_conf(self.CONF, ['10.0.0.1', '10.0.0.2'], '10.0.0.2'), self.CONF)

    def test_a_new_card_is_allowed_and_the_relay_stays(self):
        out = nc.coturn_conf(self.CONF, ['10.0.0.1', '10.0.0.2', '192.168.7.1'], '10.0.0.2')
        self.assertIn('relay-ip=10.0.0.1\n', out)
        self.assertIn('allowed-peer-ip=192.168.7.1\ndenied-peer-ip', out)

    def test_a_relay_address_that_went_away_moves(self):
        out = nc.coturn_conf(self.CONF, ['10.0.0.9'], '10.0.0.9')
        self.assertEqual(out.count('allowed-peer-ip='), 1)
        self.assertIn('relay-ip=10.0.0.9\n', out)
        self.assertNotIn('10.0.0.2', out)

    def test_refresh_secures_legacy_file_without_changing_content(self):
        with tempfile.TemporaryDirectory() as temp:
            path = temp + '/turnserver.conf'
            with open(path, 'w') as stream: stream.write(self.CONF)
            os.chmod(path, 0o644)
            with patch.object(nc, 'COTURN_CONF', path), patch.object(nc.grp, 'getgrnam', return_value=SimpleNamespace(gr_gid=os.getgid())), \
                    patch.object(nc.os, 'fchown', side_effect=lambda fd, uid, gid: self.assertEqual(os.fstat(fd).st_mode & 0o777, 0o600)) as chown, \
                    patch.object(nc, 'run', side_effect=[SimpleNamespace(stdout='10.0.0.1 10.0.0.2'), SimpleNamespace(stdout='src 10.0.0.1')]):
                self.assertFalse(nc.refresh_coturn())
                chown.assert_called_once()
            self.assertEqual(os.stat(path).st_mode & 0o777, 0o640)
            with open(path) as stream: self.assertEqual(stream.read(), self.CONF)

    def test_refresh_atomic_replacement_keeps_secret_private(self):
        with tempfile.TemporaryDirectory() as temp:
            path = temp + '/turnserver.conf'
            with open(path, 'w') as stream: stream.write(self.CONF + 'static-auth-secret=test-only\n')
            with patch.object(nc, 'COTURN_CONF', path), patch.object(nc.grp, 'getgrnam', return_value=SimpleNamespace(gr_gid=os.getgid())), \
                    patch.object(nc.os, 'fchown'), patch.object(nc, 'run', side_effect=[SimpleNamespace(stdout='10.0.0.9'), SimpleNamespace(stdout='src 10.0.0.9')]):
                self.assertTrue(nc.refresh_coturn())
            self.assertEqual(os.stat(path).st_mode & 0o777, 0o640)
            with open(path) as stream:
                text = stream.read()
            self.assertIn('static-auth-secret=test-only\n', text)
            self.assertIn('relay-ip=10.0.0.9\n', text)
            self.assertEqual(os.listdir(temp), ['turnserver.conf'])

    def test_refresh_rejects_symlink_without_modifying_target(self):
        with tempfile.TemporaryDirectory() as temp:
            target, path = temp + '/canary', temp + '/turnserver.conf'
            with open(target, 'w') as stream: stream.write('unchanged')
            os.chmod(target, 0o644)
            os.symlink(target, path)
            with patch.object(nc, 'COTURN_CONF', path), self.assertRaises(nc.Refused):
                nc.refresh_coturn()
            self.assertEqual(os.stat(target).st_mode & 0o777, 0o644)
            with open(target) as stream: self.assertEqual(stream.read(), 'unchanged')


if __name__ == '__main__':
    unittest.main()
