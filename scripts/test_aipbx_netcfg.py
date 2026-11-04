#!/usr/bin/python3
"""Checks and netplan editing of conf/sbin/aipbx-netcfg."""
import importlib.machinery, importlib.util, os, tempfile, unittest
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
        for a, g, d in [('10.23.3.70', '10.23.3.1', '8.8.8.8'), ('10.23.3.70/32', '10.23.3.1', '8.8.8.8'),
                        ('10.23.3.0/24', '10.23.3.1', '8.8.8.8'), ('10.23.3.255/24', '10.23.3.1', '8.8.8.8'),
                        ('127.0.0.2/8', '127.0.0.1', '8.8.8.8'), ('10.23.3.70/24', '10.23.4.1', '8.8.8.8'),
                        ('10.23.3.70/24', '10.23.3.70', '8.8.8.8'), ('10.23.3.70/24', 'x', '8.8.8.8'),
                        ('10.23.3.70/24', '10.23.3.1', ''), ('10.23.3.70/24', '10.23.3.1', 'a'),
                        ('10.23.3.70/24', '10.23.3.1', '1.1.1.1,2.2.2.2,3.3.3.3,4.4.4.4'), ('$(reboot)/24', '1.1.1.1', '8.8.8.8')]:
            with self.assertRaises(nc.Refused, msg=(a, g, d)):
                nc.check_static(a, g, d)

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


if __name__ == '__main__':
    unittest.main()
