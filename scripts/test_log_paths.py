#!/usr/bin/env python3
"""Root log setup: real filesystem attacks, without requiring root in unit CI."""
import importlib.machinery
import importlib.util
import os
import errno
import struct
from pathlib import Path
import tempfile
import unittest

loader = importlib.machinery.SourceFileLoader('log_init', str(Path(__file__).resolve().parents[1] / 'conf/sbin/aipbx-log-init'))
spec = importlib.util.spec_from_loader('log_init', loader)
logs = importlib.util.module_from_spec(spec)
loader.exec_module(logs)


class LogPathsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.directory = self.base / 'logs'
        self.directory.mkdir()
        self.directory.chmod(0o777)
        self.identities = {'root': (os.getuid(), os.getgid()), 'web': (os.getuid(), os.getgid())}

    def prepare(self, names=('backup',)):
        logs.configure(names, directory=self.directory, identities=self.identities, lock_path=self.base / 'lock')

    def test_symlink_cannot_redirect_root_append_or_metadata_changes(self):
        canary = self.base / 'canary'
        canary.write_bytes(b'unchanged')
        canary.chmod(0o600)
        (self.directory / 'backup.log').symlink_to(canary)
        self.prepare()
        with (self.directory / 'backup.log').open('ab') as stream:
            stream.write(b'new root log')
        self.assertEqual(b'unchanged', canary.read_bytes())
        self.assertEqual(0o600, canary.stat().st_mode & 0o777)
        self.assertFalse((self.directory / 'backup.log').is_symlink())

    def test_hardlink_is_detached_without_touching_target(self):
        canary = self.base / 'canary'
        canary.write_bytes(b'private')
        canary.chmod(0o600)
        os.link(canary, self.directory / 'backup.log')
        self.prepare()
        self.assertEqual(b'', (self.directory / 'backup.log').read_bytes())
        self.assertEqual(b'private', canary.read_bytes())
        self.assertEqual(0o600, canary.stat().st_mode & 0o777)

    def test_retained_writable_fd_cannot_modify_replacement_log(self):
        path = self.directory / 'backup.log'
        path.write_bytes(b'old history\n')
        path.chmod(0o666)
        with path.open('ab') as held:
            self.prepare()
            held.write(b'attacker writes through old fd\n')
            held.flush()
        self.assertEqual(b'old history\n', path.read_bytes())
        self.assertIn(b'attacker', next(self.directory.glob('.legacy-*/backup.log')).read_bytes())

    def test_safe_root_fd_is_preserved_for_in_progress_update(self):
        path = self.directory / 'update.log'
        path.write_bytes(b'initial\n')
        path.chmod(0o640)
        with path.open('ab') as held:
            inode = os.fstat(held.fileno()).st_ino
            self.prepare(('update',))
            held.write(b'still updating\n')
            held.flush()
        self.assertEqual(inode, path.stat().st_ino)
        self.assertIn(b'still updating', path.read_bytes())

    def test_fifo_is_quarantined_without_blocking(self):
        os.mkfifo(self.directory / 'backup.log')
        self.prepare()
        self.assertTrue((self.directory / 'backup.log').is_file())

    def test_all_files_are_created_with_restricted_modes(self):
        self.prepare(logs.ROOT_LOGS + ('web_login_failures',))
        self.assertEqual(0o750, self.directory.stat().st_mode & 0o777)
        for path in self.directory.glob('*.log'):
            self.assertEqual(0o640, path.stat().st_mode & 0o777)
        self.assertEqual(0o600, (self.base / 'lock').stat().st_mode & 0o777)

    def test_directory_symlink_is_rejected(self):
        self.directory.rmdir()
        self.directory.symlink_to(self.base, target_is_directory=True)
        with self.assertRaises(OSError):
            self.prepare()

    def test_legacy_named_and_default_acls_are_removed(self):
        entries = [(1, 7, 0xffffffff), (2, 7, 65534), (4, 0, 0xffffffff),
                   (16, 7, 0xffffffff), (32, 0, 0xffffffff)]
        acl = struct.pack('<I', 2) + b''.join(struct.pack('<HHI', *entry) for entry in entries)
        try:
            os.setxattr(self.directory, 'system.posix_acl_access', acl)
            os.setxattr(self.directory, 'system.posix_acl_default', acl)
        except OSError as error:
            if error.errno == errno.ENOTSUP:
                self.skipTest('filesystem has no POSIX ACL support')
            raise
        self.prepare()
        for name in ('system.posix_acl_access', 'system.posix_acl_default'):
            with self.assertRaises(OSError) as caught:
                os.getxattr(self.directory, name)
            self.assertEqual(errno.ENODATA, caught.exception.errno)
        self.assertEqual(0o750, self.directory.stat().st_mode & 0o777)
        self.assertEqual(0o640, (self.directory / 'backup.log').stat().st_mode & 0o777)

    def test_large_history_is_bounded_and_full_original_is_preserved(self):
        path = self.directory / 'backup.log'
        path.write_bytes(b'a' * (logs.TAIL_BYTES + 20) + b'end')
        path.chmod(0o666)
        self.prepare()
        self.assertEqual(logs.TAIL_BYTES, path.stat().st_size)
        self.assertTrue(path.read_bytes().endswith(b'end'))
        self.assertEqual(logs.TAIL_BYTES + 23, next(self.directory.glob('.legacy-*/backup.log')).stat().st_size)


if __name__ == '__main__':
    unittest.main()
