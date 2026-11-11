#!/usr/bin/env python3
"""Validate database migrator privilege isolation (P1.3) and Asterisk CLI restrictions (P1.4)."""
import ast
import configparser
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT_DIR = Path(__file__).resolve().parents[1]


class PrivilegeIsolationTest(unittest.TestCase):
    def test_cli_permissions_file_restricts_www_data(self):
        conf_path = ROOT_DIR / 'conf/asterisk/cli_permissions.conf'
        self.assertTrue(conf_path.is_file(), "cli_permissions.conf must exist")

        lines = conf_path.read_text().splitlines()
        current_section = None
        sections = {}
        for line in lines:
            line = line.strip()
            if not line or line.startswith(';'):
                continue
            if line.startswith('[') and line.endswith(']'):
                current_section = line[1:-1].strip()
                sections[current_section] = []
            elif current_section and '=' in line:
                k, v = line.split('=', 1)
                sections[current_section].append((k.strip(), v.strip()))

        # 1. [general] default_perm = deny
        self.assertIn('general', sections)
        general_dict = dict(sections['general'])
        self.assertEqual('deny', general_dict.get('default_perm'))

        # 2. [root] and [asterisk] have permit = all
        self.assertIn('root', sections)
        self.assertIn(('permit', 'all'), sections['root'])
        self.assertIn('asterisk', sections)
        self.assertIn(('permit', 'all'), sections['asterisk'])

        # 3. [www-data] has deny = all as first rule
        self.assertIn('www-data', sections)
        www_rules = sections['www-data']
        self.assertEqual(('deny', 'all'), www_rules[0])

        permitted_commands = [v for k, v in www_rules if k == 'permit']

        for pat in permitted_commands:
            self.assertTrue(pat.startswith('^') and pat.endswith('$'), f"permit '{pat}' must be anchored with ^...$")

        # 4. Check forbidden dangerous commands are NOT permitted
        dangerous = [
            'core stop',
            'core stop now',
            'core restart now',
            'core restart gracefully',
            'module load /tmp/bad.so',
            'module unload chan_pjsip.so',
            '! /bin/sh',
            '! id',
            'file convert /etc/shadow /tmp/shadow',
            'cli show permissions',
            'config reload',
            'channel originate Local/100@cc-internal application System /bin/sh',
            'channel originate Local/100@other extension 100@from-internal',
            # Asterisk searches a permit anywhere in the command: an allowed phrase
            # inside a forbidden command must not open it (hence the ^...$ anchors).
            'module load x pjsip show.x',
            'zz core show version',
            'channel originate Local/1@x application System "sh -c id # pjsip show x"',
        ]
        for cmd in dangerous:
            matched = False
            for pat in permitted_commands:
                if re.search(pat, cmd, re.IGNORECASE):
                    matched = True
                    break
            self.assertFalse(matched, f"Dangerous command '{cmd}' must NOT match any permit pattern in [www-data]")

        # 5. Check legitimate operational commands ARE permitted
        legitimate = [
            'core show version',
            'core show uptime seconds',
            'core show channels concise',
            'core show channels count',
            'pjsip show contacts',
            'pjsip show endpoints',
            'queue show',
            'queue add member Local/100@from-internal-pbx/n to support',
            'queue remove member Local/100@from-internal-pbx/n from support',
            'queue pause member Local/100@from-internal-pbx/n queue support',
            'queue unpause member Local/100@from-internal-pbx/n queue support',
            'database show trunkblock',
            'database get Queue/PersistentMembers support',
            'database put Queue/PersistentMembers support 100',
            'database del Queue/PersistentMembers support',
            'confbridge list 800',
            'confbridge kick 800 PJSIP/100-00000001',
            'confbridge mute 800 PJSIP/100-00000001',
            'confbridge unmute 800 PJSIP/100-00000001',
            'dialplan reload',
            'pjsip reload',
            'queue reload all',
            'core reload',
            'moh reload',
            'voicemail reload',
            'module reload indications',
            'module reload res_rtp_asterisk.so',
            'module reload udptl',
            'devstate change Custom:dnd_100 INUSE',
            'pjsip set logger on',
            'pjsip set logger off',
            'database deltree blacklist',
            'config show help pjsip',
            'dialplan show 100@from-internal',
            'core restart when convenient',
            'fax show sessions',
            'pjsip send notify aipbx-check-cfg endpoint 100',
            'pjsip send notify aipbx-reboot endpoint 200',
            'channel originate Local/100@cc-internal extension 05551234567@from-internal-web',
            'channel request hangup PJSIP/100-00000001',
            'channel get PJSIP/100-00000001 CALLERID(num)',
            'logger rotate',
        ]
        for cmd in legitimate:
            matched = False
            for pat in permitted_commands:
                if re.search(pat, cmd, re.IGNORECASE):
                    matched = True
                    break
            self.assertTrue(matched, f"Operational command '{cmd}' should be permitted for [www-data]")

    def test_all_codebase_asterisk_commands_are_permitted_in_cli_permissions(self):
        """Dynamic audit: all Asterisk CLI commands invoked across web/ and plugins/
        must match at least one permitted pattern in cli_permissions.conf for [www-data]."""
        conf_path = ROOT_DIR / 'conf' / 'asterisk' / 'cli_permissions.conf'
        self.assertTrue(conf_path.is_file())
        permitted = []
        in_www = False
        for line in conf_path.read_text().splitlines():
            l = line.strip()
            if l == '[www-data]':
                in_www = True
            elif l.startswith('[') and l.endswith(']'):
                in_www = False
            elif in_www and l.startswith('permit = '):
                pat = l.partition('=')[2].strip()
                permitted.append(re.compile(pat, re.IGNORECASE))

        php_targets = list((ROOT_DIR / 'web').rglob('*.php')) + list((ROOT_DIR / 'plugins').rglob('*.php'))
        # Exclude vendor and unit tests from source audit
        php_files = [p for p in php_targets if 'vendor' not in p.parts and 'tests' not in p.parts]

        def sanitize_cmd(raw: str) -> str:
            # Replace PHP variables / expressions with safe dummy values
            s = re.sub(r'\{\$[A-Za-z0-9_]+\}', '100', raw)
            s = re.sub(r'\$[A-Za-z0-9_]+', '100', s)
            s = re.sub(r'sprintf\s*\([^,]+,\s*', '', s)
            s = s.replace('%s', '100').replace('%d', '100')
            s = s.replace('"', '').replace("'", '').replace('\\', '').strip()
            return s

        commands_found = []
        for pf in php_files:
            txt = pf.read_text(encoding='utf-8', errors='ignore')
            lines = txt.splitlines()
            for idx, line in enumerate(lines, 1):
                if 'execCLI' in line or 'asterisk -rx' in line or 'notifyCommand' in line:
                    # Match literals inside execCLI('...') or execCLI("...")
                    for m in re.finditer(r'execCLI\s*\(\s*([\'\"][^\'\"]+[\'\"])', line):
                        val = m.group(1)[1:-1].strip()
                        if val and not val.startswith('$') and val != 'a command that does not exist':
                            commands_found.append((pf, idx, sanitize_cmd(val)))
                    # Match literals inside escapeshellarg('...')
                    for m in re.finditer(r'escapeshellarg\s*\(\s*([\'\"][^\'\"]+[\'\"])', line):
                        val = m.group(1)[1:-1].strip()
                        if val and not val.startswith('$') and val != 'a command that does not exist':
                            commands_found.append((pf, idx, sanitize_cmd(val)))
                    # Match string concatenation with asterisk -rx
                    for m in re.finditer(r'asterisk\s+-rx\s+[\'\"].*?escapeshellarg\s*\(\s*([^\)]+)\)', line):
                        val = m.group(1).strip()
                        if val and not val.startswith('$'):
                            commands_found.append((pf, idx, sanitize_cmd(val)))
                    # Match sprintf channel originate or similar commands
                    for m in re.finditer(r'sprintf\s*\(\s*[\'\"](channel originate[^\'\"]+)[\'\"]', line):
                        val = m.group(1).strip()
                        commands_found.append((pf, idx, sanitize_cmd(val)))
                    # Match pjsip send notify template
                    for m in re.finditer(r'[\'\"](pjsip send notify[^\'\"]+)[\'\"]', line):
                        val = m.group(1).strip()
                        commands_found.append((pf, idx, sanitize_cmd(val)))

        self.assertGreater(len(commands_found), 15, "Should find multiple Asterisk CLI commands across codebase")

        unmatched = []
        for pf, idx, cmd in commands_found:
            # Skip non-command fragments or empty strings
            if not cmd or cmd.startswith('.'):
                continue
            matched = any(p.search(cmd) for p in permitted)
            if not matched:
                unmatched.append(f"{pf.relative_to(ROOT_DIR)}:{idx} -> '{cmd}'")

        self.assertEqual(unmatched, [], f"The following codebase CLI commands are NOT permitted in cli_permissions.conf:\n" + "\n".join(unmatched))

    def _get_installer_code(self):
        code = (ROOT_DIR / 'install.sh').read_text()
        steps_dir = ROOT_DIR / 'scripts' / 'install'
        if steps_dir.is_dir():
            for p in sorted(steps_dir.glob('*.sh')):
                code += '\n' + p.read_text()
        return code

    def test_install_script_separates_ddl_and_dml_environments(self):
        install_sh = self._get_installer_code()

        # DDL user/pass must NOT appear in generated /etc/ai-pbx.env template
        env_heredoc_match = re.search(r'cat > /etc/ai-pbx\.env << ENVFILE(.*?)ENVFILE', install_sh, re.DOTALL)
        self.assertIsNotNone(env_heredoc_match, "install.sh must generate /etc/ai-pbx.env")
        env_content = env_heredoc_match.group(1)

        self.assertNotIn('MIGRATOR_DB_USER', env_content, "MIGRATOR_DB_USER must NOT be written to /etc/ai-pbx.env")
        self.assertNotIn('MIGRATOR_DB_PASS', env_content, "MIGRATOR_DB_PASS must NOT be written to /etc/ai-pbx.env")
        self.assertIn('DB_USER=${DB_USER}', env_content)
        self.assertIn('DB_PASS=${DB_PASS}', env_content)

        # Migrator env file must be created with 0600 root:root
        self.assertIn('/etc/ai-pbx-migrator.env', install_sh)
        self.assertIn('chmod 0600 /etc/ai-pbx-migrator.env', install_sh)
        self.assertIn('chown root:root /etc/ai-pbx-migrator.env', install_sh)

        # Parent /etc/asterisk must NOT be 2775 (group writable)
        self.assertNotIn('chmod 2775 /etc/asterisk\n', install_sh)
        self.assertIn('chmod 0755 /etc/asterisk', install_sh)

        # cli_permissions.conf must be installed and protected
        self.assertIn('cli_permissions.conf', install_sh)
        self.assertIn('/etc/asterisk/cli_permissions.conf', install_sh)

    def test_aipbx_priv_validates_plugin_schema_subcommand(self):
        priv_sh = (ROOT_DIR / 'conf/sbin/aipbx-priv').read_text()
        self.assertIn('plugin-schema)', priv_sh)
        self.assertIn('plugin_schema.php', priv_sh)
        # Test command argument validation pattern
        slug_match = re.search(r'plugin-schema\).*?match "\$2" \'([^\']+)\'', priv_sh, re.DOTALL)
        self.assertIsNotNone(slug_match)
        re_pattern = slug_match.group(1)
        self.assertTrue(re.match(re_pattern, 'auto-dialer'))
        self.assertTrue(re.match(re_pattern, 'ai_voicemail'))
        self.assertFalse(re.match(re_pattern, '../evil'))
        self.assertFalse(re.match(re_pattern, 'plugin;rm -rf /'))

    def test_nginx_stream_connection_limits(self):
        install_sh = self._get_installer_code()
        self.assertIn('limit_conn_zone $binary_remote_addr zone=aipbx_stream_conn:10m', install_sh)
        self.assertIn('limit_conn aipbx_stream_conn 128', install_sh)
        self.assertIn('preread_timeout 5s', install_sh)

    def test_service_sandbox_inaccessible_paths(self):
        install_sh = self._get_installer_code()
        self.assertIn('-/etc/ai-pbx-migrator.env', install_sh)
        self.assertIn('UMask=0027', install_sh)

    def test_sync_transports_tls_verification(self):
        sync_code = (ROOT_DIR / 'web/src/sync/SyncTransports.php').read_text()
        self.assertIn('ca_list_file', sync_code)
        self.assertIn('verify_server', sync_code)

    def test_restore_rejects_reserved_database_users(self):
        restore_code = (ROOT_DIR / 'conf/sbin/aipbx-restore').read_text()
        self.assertIn('RESERVED_DB_USERS', restore_code)
        self.assertIn("'root'", restore_code)
        self.assertIn("'mysql'", restore_code)
        self.assertIn("'mariadb.sys'", restore_code)
        # Check that untrusted restore preserves local DB_USER and DB_PASS
        self.assertIn("local_db", restore_code)
        self.assertIn('preserve_runtime_db', restore_code)

    def restore_env_merger(self):
        source = (ROOT_DIR / 'conf/sbin/aipbx-restore').read_text()
        python = source.split("<<'PY'\n", 1)[1].split('\nPY\n}', 1)[0]
        definition = next(node for node in ast.parse(python).body
                          if isinstance(node, ast.FunctionDef) and node.name == 'preserve_runtime_db')
        namespace = {}
        exec(compile(ast.Module(body=[definition], type_ignores=[]), '<restore-env>', 'exec'), namespace)
        return namespace['preserve_runtime_db']

    def test_restore_keeps_literal_local_passwords(self):
        merge = self.restore_env_merger()
        for password in [r'fixture&value/with\backslash', "'quoted&value'", 'fixture=with$characters']:
            with self.subTest(password=password):
                current = 'DB_USER=aipbx_portal\nDB_PASS=' + password + '\n'
                result = merge('DB_USER=uploaded\nDB_PASS=uploaded\nSITE_NAME=Restored\n', current)
                self.assertEqual('SITE_NAME=Restored\n' + current, result)

    def test_restore_adds_missing_credentials_and_removes_duplicates(self):
        merge = self.restore_env_merger()
        current = 'DB_USER=aipbx_portal\nDB_PASS=fixture\n'
        for uploaded in ['SITE_NAME=Restored', 'SITE_NAME=Restored\nDB_USER=uploaded\nDB_USER=other\n DB_PASS=uploaded\n']:
            self.assertEqual('SITE_NAME=Restored\n' + current, merge(uploaded, current))

    def test_restore_refuses_missing_local_credentials(self):
        merge = self.restore_env_merger()
        for current in ['', 'DB_USER=aipbx_portal\n', 'DB_USER=aipbx_portal\nDB_PASS=\n']:
            with self.assertRaises(ValueError):
                merge('DB_USER=uploaded\nDB_PASS=uploaded\n', current)

    def test_mariadb_installer_rejects_reserved_users(self):
        install_sh = self._get_installer_code()
        self.assertIn('for reserved in root admin mysql mariadb.sys', install_sh)
        self.assertIn('Runtime database user cannot be reserved user', install_sh)


if __name__ == '__main__':
    unittest.main()
