#!/usr/bin/env python3
"""Validate the privileged HTTP proxy policy boundary without a running Apache."""
import importlib.machinery
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

loader = importlib.machinery.SourceFileLoader('proxy_https', str(Path(__file__).resolve().parents[1] / 'conf/sbin/aipbx-proxy-https'))
spec = importlib.util.spec_from_loader('proxy_https', loader)
policy = importlib.util.module_from_spec(spec)
loader.exec_module(policy)


class ProxyPolicyTest(unittest.TestCase):
    def test_disabled_and_empty_trust_cannot_bypass_redirect(self):
        for settings in [dict(enabled=False, trusted_proxies='0.0.0.0/0'), dict(enabled=True, trusted_proxies='')]:
            self.assertIn('RewriteCond expr "true"', policy.render(settings))

    def test_only_tcp_peer_and_exact_https_header_can_bypass(self):
        content = policy.render(dict(enabled=True, trusted_proxies='192.0.2.17/24, ::1, 192.0.2.0/24'))
        self.assertIn("%{CONN_REMOTE_ADDR} -ipmatch '192.0.2.0/24'", content)
        self.assertIn("%{CONN_REMOTE_ADDR} -ipmatch '::1/128'", content)
        self.assertEqual(1, content.count("'192.0.2.0/24'"))
        self.assertIn("req('X-Forwarded-Proto') =~ /^https$/i", content)
        self.assertNotIn('X-Forwarded-For', content)

    def test_injection_and_invalid_prefixes_never_reach_apache(self):
        for value in ['127.0.0.1\nInclude /tmp/evil', "127.0.0.1' || true", '0.0.0.0/no',
                      '127.0.0.1/255.255.255.0', '::1/129', 'fe80::1%eth0', '127.0.0.1/33',
                      '127.0.0.1/-1', '1.2.3.4 ' * 129, 'x' * 8193]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                policy.render(dict(enabled=True, trusted_proxies=value))
        for value in [[], dict(enabled='yes', trusted_proxies='::1'), dict(enabled=True, trusted_proxies=['::1'])]:
            with self.assertRaises(ValueError):
                policy.render(value)

    def test_configtest_failure_restores_previous_bytes_without_reload(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            path = directory / 'proxy-https.conf'
            path.write_bytes(b'previous policy\n')
            path.chmod(0o644)
            with patch.object(policy.subprocess, 'run', side_effect=subprocess.CalledProcessError(1, 'apache2ctl')) as run:
                with self.assertRaises(RuntimeError):
                    policy.configure(dict(enabled=True, trusted_proxies='::1'), directory=directory)
            self.assertEqual(b'previous policy\n', path.read_bytes())
            self.assertEqual(1, run.call_count)

    def test_failed_reload_restores_active_policy_and_file(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            path = directory / 'proxy-https.conf'
            path.write_bytes(b'previous policy\n')
            path.chmod(0o644)
            with patch.object(policy.subprocess, 'run', side_effect=[None, subprocess.CalledProcessError(1, 'reload'), None]) as run:
                with self.assertRaises(RuntimeError):
                    policy.configure(dict(enabled=True, trusted_proxies='::1'), directory=directory)
            self.assertEqual(b'previous policy\n', path.read_bytes())
            self.assertEqual(['/usr/bin/systemctl', 'reload', 'apache2'], run.call_args.args[0])
            self.assertEqual(3, run.call_count)

    def test_symlink_and_web_writable_directory_are_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            canary = directory / 'canary'
            canary.write_bytes(b'unchanged')
            (directory / 'proxy-https.conf').symlink_to(canary)
            with self.assertRaises(ValueError):
                policy.configure(dict(enabled=False, trusted_proxies=''), reload=False, directory=directory)
            self.assertEqual(b'unchanged', canary.read_bytes())
            (directory / 'proxy-https.conf').unlink()
            directory.chmod(0o777)
            with self.assertRaises(ValueError):
                policy.configure(dict(enabled=False, trusted_proxies=''), reload=False, directory=directory)


if __name__ == '__main__':
    unittest.main()
