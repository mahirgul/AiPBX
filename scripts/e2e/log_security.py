#!/usr/bin/env python3
"""Disposable root test: reproduce legacy log symlinks, run real jobs and rotate."""
import datetime
import grp
import os
from pathlib import Path
import pwd
import re
import secrets
import subprocess
import tempfile
import time

DIRECTORY = Path('/var/log/aipbx')
NAMES = ('backup', 'restore', 'update', 'sound-packs')


def check(ok, text):
    if not ok:
        raise RuntimeError(text)
    print('PASS ' + text, flush=True)


def run(args, **kwargs):
    return subprocess.run(args, capture_output=True, text=True, timeout=180, **kwargs)


def as_web(code, *args):
    return run(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/python3', '-c', code, *map(str, args)])


def modes():
    info = DIRECTORY.stat()
    web_gid = grp.getgrnam('www-data').gr_gid
    check(info.st_uid == 0 and info.st_gid == web_gid and info.st_mode & 0o7777 == 0o750,
          'log directory is root:www-data 0750')
    for name in NAMES:
        path = DIRECTORY / (name + '.log')
        info = path.lstat()
        check(path.is_file() and not path.is_symlink() and info.st_nlink == 1
              and info.st_uid == 0 and info.st_gid == web_gid and info.st_mode & 0o777 == 0o640,
              name + ': root-owned regular log with read-only portal access')
        check(as_web('import sys; open(sys.argv[1],"rb").close()', path).returncode == 0,
              name + ': portal can open the log for reading')
        check(as_web('import sys; open(sys.argv[1],"ab").close()', path).returncode != 0,
              name + ': portal cannot open the log for writing')


def main():
    if os.environ.get('AIPBX_E2E') != '1' or os.geteuid() != 0:
        raise RuntimeError('Run as root with AIPBX_E2E=1 only in a disposable installation')
    install = os.environ.get('AIPBX_INSTALL_DIR', '/opt/aipbx')
    env = dict(os.environ, AIPBX_INSTALL_DIR=install)
    web_uid = pwd.getpwnam('www-data').pw_uid
    # Hold full originals in a private directory; the four legacy entries become
    # attacker-controlled symlinks. The canary contains no real system data.
    work = Path(tempfile.mkdtemp(prefix='aipbx-log-review-', dir='/var/log'))
    work.chmod(0o711)
    originals = work / 'originals'
    originals.mkdir(mode=0o700)
    canary = work / 'canary'
    canary.write_bytes(b'root-owned canary: unchanged\n')
    canary.chmod(0o600)
    original = canary.read_bytes()
    for name in NAMES:
        path = DIRECTORY / (name + '.log')
        if path.exists() or path.is_symlink():
            os.replace(path, originals / path.name)
    os.chown(DIRECTORY, web_uid, grp.getgrnam('adm').gr_gid)
    DIRECTORY.chmod(0o1750)
    for name in NAMES:
        result = as_web('import os,sys; os.symlink(sys.argv[1],sys.argv[2])', canary, DIRECTORY / (name + '.log'))
        check(result.returncode == 0, 'legacy web user can plant ' + name + ' symlink')
    try:
        result = run(['/usr/local/sbin/aipbx-backup', '--label', 'log-safety'], env=env)
        check(result.returncode == 0, 'real backup completes after safe log preparation')
        # Valid name, deliberately absent: the job logs the refusal without any restore.
        missing = 'aipbx-19000101-0000'
        check(not (Path('/var/backups/aipbx-daily') / missing).exists(), 'refusal fixture is absent')
        result = run(['/usr/local/sbin/aipbx-restore', missing], env=env)
        check(result.returncode == 1, 'restore refusal logs safely without restoring a database')
        with tempfile.TemporaryDirectory(prefix='aipbx-log-git-', dir='/srv') as repo:
            run(['/usr/bin/git', 'init', '-q', repo], check=True)
            Path(repo, 'VERSION').write_text('1.72.12\n')
            # No remote: no network access or release checkout; exercise error logging.
            result = run(['/usr/local/sbin/aipbx-update', '--check'], env=dict(env, AIPBX_INSTALL_DIR=repo))
            check(result.returncode == 2, 'update check logs a local missing-remote error safely')
        check(not Path('/var/lib/aipbx-system/sound-packs/extra-zz-wav.list').exists(), 'sound refusal fixture is absent')
        result = run(['/usr/local/sbin/aipbx-sounds', 'remove', 'extra', 'zz', 'wav'], env=env)
        check(result.returncode != 0, 'sound helper logs a missing-pack refusal safely')
        check(canary.read_bytes() == original and canary.stat().st_mode & 0o777 == 0o600,
              'all four real privileged writers leave the root canary untouched')
        run(['/usr/local/sbin/aipbx-log-init', 'install'], check=True)
        modes()
        probe = DIRECTORY / ('probe-' + work.name)
        check(as_web('import os,sys; os.symlink(sys.argv[1],sys.argv[2])', canary, probe).returncode != 0,
              'portal can no longer create a symlink in the log directory')
        check(as_web('open("/run/aipbx-log-init.lock","rb").close()').returncode != 0,
              'portal cannot hold the private initialization lock')
        login = DIRECTORY / 'web_login_failures.log'
        def write_login(ip='127.0.0.1'):
            # Loopback is ignored by Fail2ban; no address is banned by the test.
            line = ip + ' - [' + datetime.datetime.now().astimezone().isoformat(timespec='seconds') + '] FAILED_LOGIN user=e2e-log-review\n'
            check(as_web('import sys; open(sys.argv[1],"a").write(sys.argv[2])', login, line).returncode == 0,
                  'portal can append a login failure')
        write_login()
        filter_result = run(['/usr/bin/fail2ban-regex', '--print-all-matched', str(login), '/etc/fail2ban/filter.d/aipbx-web.conf'])
        matched = re.search(r'(\d+) matched', filter_result.stdout)
        check(filter_result.returncode == 0 and matched is not None and int(matched[1]) > 0
              and 'user=e2e-log-review' in filter_result.stdout,
              'Fail2ban can read and match the login log')
        # Root writers hold stderr open, so copytruncate must preserve that inode.
        with (DIRECTORY / 'update.log').open('ab') as held:
            held.write(b'e2e-before-rotation\n')
            held.flush()
            inode = os.fstat(held.fileno()).st_ino
            result = run(['/usr/sbin/logrotate', '-f', '-s', str(work / 'rotate.state'), '/etc/logrotate.d/aipbx'])
            check(result.returncode == 0, 'real logrotate completes with the secured directory')
            held.write(b'e2e-after-rotation\n')
            held.flush()
        check((DIRECTORY / 'update.log').stat().st_ino == inode
              and b'e2e-after-rotation' in (DIRECTORY / 'update.log').read_bytes(),
              'active root stderr continues writing to the visible log after rotation')
        modes()
        check(login.stat().st_uid == web_uid and login.stat().st_gid == grp.getgrnam('adm').gr_gid,
              'rotation recreates the login log for www-data:adm')
        write_login()
        check(run(['/usr/bin/fail2ban-client', 'status', 'aipbx-web']).returncode == 0,
              'Fail2ban jail stays active after rotation')
        def total_failed():
            match = re.search(r'Total failed:\s*(\d+)', run(['/usr/bin/fail2ban-client', 'status', 'aipbx-web']).stdout)
            return int(match[1]) if match else -1
        before = total_failed()
        # One failure from a documentation-only address, below the ban threshold.
        write_login('192.0.2.' + str(200 + secrets.randbelow(55)))
        for _ in range(30):
            if total_failed() > before:
                break
            time.sleep(0.5)
        check(before >= 0 and total_failed() > before, 'Fail2ban consumes a new failure from the rotated file')
    finally:
        # Also repairs the directory if a writer failed part-way through the test.
        run(['/usr/local/sbin/aipbx-log-init', 'install'], check=True)
        work.chmod(0o700)


if __name__ == '__main__':
    main()
