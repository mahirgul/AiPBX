#!/usr/bin/env python3
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest

loader = importlib.machinery.SourceFileLoader('system_state', str(Path(__file__).resolve().parents[1] / 'conf/sbin/aipbx-system-state'))
spec = importlib.util.spec_from_loader('system_state', loader)
state = importlib.util.module_from_spec(spec)
loader.exec_module(state)


class SystemStateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.legacy = self.base / 'portal'
        self.legacy.mkdir()
        self.root = self.base / 'system'

    def prepare(self):
        state.prepare(self.root, self.legacy, os.getgid(), self.base / 'lock')

    def test_retention_and_catalog_migrate_once_but_job_states_do_not(self):
        (self.legacy / 'backup-keep-days').write_text('21\n')
        (self.legacy / 'update-status.json').write_text('{"state":"running","waiting_calls":999}')
        (self.legacy / 'sound-packs').mkdir()
        key = 'core-tr-wav'
        (self.legacy / 'sound-packs.json').write_text(json.dumps({key: {'version': '1.2', 'path': '/evil', 'files': 1}}))
        (self.legacy / 'sound-packs' / (key + '.list')).write_text('digits/1.wav\n')
        self.prepare()
        self.assertEqual('21\n', (self.root / 'backup-keep-days').read_text())
        self.assertFalse((self.root / 'update-status.json').exists())
        catalog = json.loads((self.root / 'sound-packs.json').read_text())
        self.assertEqual('/var/lib/asterisk/sounds/tr', catalog[key]['path'])
        self.assertEqual('digits/1.wav\n', (self.root / 'sound-packs' / (key + '.list')).read_text())
        (self.legacy / 'backup-keep-days').write_text('99\n')
        self.prepare()
        self.assertEqual('21\n', (self.root / 'backup-keep-days').read_text())
        self.assertEqual(0o750, self.root.stat().st_mode & 0o777)
        self.assertEqual(0o700, (self.root / 'private').stat().st_mode & 0o777)

    def test_legacy_symlink_is_not_read_or_chmodded(self):
        canary = self.base / 'canary'
        canary.write_text('private')
        canary.chmod(0o600)
        (self.legacy / 'backup-keep-days').symlink_to(canary)
        with self.assertRaises(OSError): self.prepare()
        self.assertEqual('private', canary.read_text())
        self.assertEqual(0o600, canary.stat().st_mode & 0o777)
        self.assertFalse((self.root / '.initialized').exists())

    def test_manifest_traversal_is_rejected_before_commit(self):
        (self.legacy / 'sound-packs').mkdir()
        (self.legacy / 'sound-packs.json').write_text('{"core-en-wav":{"version":"current"}}')
        (self.legacy / 'sound-packs/core-en-wav.list').write_text('../../etc/shadow\n')
        with self.assertRaises(ValueError): self.prepare()
        self.assertFalse((self.root / '.initialized').exists())
        self.assertFalse((self.root / 'sound-packs.json').exists())

    def test_pending_network_change_must_finish_before_migration(self):
        (self.legacy / 'netcfg').mkdir()
        (self.legacy / 'netcfg/state.json').write_text('{"state":"pending"}')
        with self.assertRaises(state.Refused): self.prepare()
        self.assertFalse((self.root / '.initialized').exists())

    def test_portal_fd_rejects_parent_symlink_and_hardlink(self):
        outside = self.base / 'outside'
        outside.mkdir()
        (outside / 'input').write_text('private')
        (self.legacy / 'stage').symlink_to(outside, target_is_directory=True)
        with self.assertRaises(OSError): state.open_portal(('stage', 'input'), 1024, self.legacy, {os.getuid()})
        os.link(outside / 'input', self.legacy / 'input')
        with self.assertRaises(ValueError): state.open_portal(('input',), 1024, self.legacy, {os.getuid()})

    def test_open_fd_pins_original_despite_portal_rename(self):
        path = self.legacy / 'input'
        path.write_bytes(b'validated original')
        fd = state.open_portal(('input',), 1024, self.legacy, {os.getuid()})
        path.rename(self.legacy / 'old')
        path.symlink_to('/etc/passwd')
        with os.fdopen(fd, 'rb') as stream: self.assertEqual(b'validated original', stream.read())

    def test_snapshot_limits_and_fifo_do_not_block(self):
        (self.legacy / 'input').write_bytes(b'x' * 65)
        with self.assertRaises(ValueError): state.open_portal(('input',), 64, self.legacy, {os.getuid()})
        os.mkfifo(self.legacy / 'fifo')
        with self.assertRaises(ValueError): state.open_portal(('fifo',), 64, self.legacy, {os.getuid()})

    def test_state_directory_symlink_and_writable_namespace_are_rejected(self):
        self.root.symlink_to(self.legacy, target_is_directory=True)
        with self.assertRaises(OSError): self.prepare()
        self.root.unlink()
        self.root.mkdir()
        self.root.chmod(0o777)
        with self.assertRaises(ValueError): self.prepare()


if __name__ == '__main__': unittest.main()
