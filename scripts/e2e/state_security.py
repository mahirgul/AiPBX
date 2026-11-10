#!/usr/bin/env python3
"""Root-only regression checks for a disposable installed PBX."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path('/var/lib/aipbx-system')
LEGACY = Path('/var/lib/aipbx')
HELPER = '/usr/local/sbin/aipbx-system-state'
PRIV = '/usr/local/sbin/aipbx-priv'


def run(args, data=None):
    return subprocess.run(args, input=data, capture_output=True, timeout=60)


def check(ok, label):
    if not ok:
        raise RuntimeError(label)
    print('PASS ' + label, flush=True)


def web(code, *args):
    return run(['runuser', '-u', 'www-data', '--', 'python3', '-c', code, *map(str, args)])


def main():
    if os.geteuid() != 0 or os.environ.get('AIPBX_E2E') != '1':
        raise RuntimeError('Use root and AIPBX_E2E=1 only on a disposable installation')
    check(run([HELPER, 'prepare']).returncode == 0, 'protected state initialization succeeds')
    check(ROOT.stat().st_uid == 0 and ROOT.stat().st_mode & 0o777 == 0o750,
          'state namespace belongs to root with mode 0750')
    for name in ('private', 'netcfg'):
        check(web('import os,sys; os.listdir(sys.argv[1])', ROOT / name).returncode != 0,
              'portal cannot read private ' + name)
    for name in ('backup-keep-days', 'sound-packs.json', 'restore-status.json', 'update-status.json'):
        check(web('import sys; open(sys.argv[1],"w").write("forged")', ROOT / name).returncode != 0,
              'portal cannot replace ' + name)
    check(web('import os,sys; os.rename(sys.argv[1],sys.argv[1]+"-evil")', ROOT).returncode != 0,
          'portal cannot rename the protected namespace')
    with tempfile.TemporaryDirectory(prefix='state-e2e-', dir='/run') as work:
        work = Path(work)
        canary = work / 'canary'
        canary.write_bytes(b'protected\n')
        canary.chmod(0o600)
        old = LEGACY / 'backup-keep-days'
        previous = old.read_bytes() if old.exists() and not old.is_symlink() else None
        keep = ROOT / 'backup-keep-days'
        previous_keep = keep.read_bytes() if keep.exists() else None
        try:
            if old.exists() or old.is_symlink():
                old.unlink()
            old.symlink_to(canary)
            check(run([PRIV, 'backup', 'keep', '17']).returncode == 0,
                  'retention setting works with a hostile legacy symlink')
            check(canary.read_bytes() == b'protected\n' and canary.stat().st_mode & 0o777 == 0o600,
                  'legacy retention attack leaves root canary unchanged')
            check(web('import sys; assert open(sys.argv[1]).read()=="17\\n"', keep).returncode == 0,
                  'portal reads the new retention value')
            check(web('import os,sys; os.unlink(sys.argv[1])', keep).returncode != 0,
                  'portal cannot delete root retention')
        finally:
            old.unlink(missing_ok=True)
            if previous is not None:
                old.write_bytes(previous)
                os.chown(old, 33, 33)
            if previous_keep is not None:
                run([HELPER, 'keep', previous_keep.decode().strip()])
            else:
                keep.unlink(missing_ok=True)
        stage = LEGACY / 'cert-stage'
        saved = LEGACY / ('cert-stage-state-test-' + work.name)
        had_stage = stage.exists() or stage.is_symlink()
        if had_stage:
            stage.rename(saved)
        try:
            stage.symlink_to(work, target_is_directory=True)
            (work / 'cert.pem').write_bytes(b'not a portal upload')
            check(run([HELPER, 'snapshot', 'cert', str(work / 'copied')]).returncode != 0,
                  'snapshot rejects an intermediate directory symlink')
            stage.unlink()
            stage.mkdir(mode=0o700)
            os.chown(stage, 33, 33)
            leaf = stage / 'cert.pem'
            leaf.symlink_to(canary)
            check(run([HELPER, 'snapshot', 'cert', str(work / 'copied')]).returncode != 0,
                  'snapshot rejects a leaf symlink')
            leaf.unlink()
            leaf.write_bytes(b'bounded upload fixture')
            os.chown(leaf, 33, 33)
            check(run([HELPER, 'snapshot', 'cert', str(work / 'copied')]).returncode == 0
                  and (work / 'copied').read_bytes() == leaf.read_bytes(),
                  'snapshot accepts a regular portal-owned upload')
            check((work / 'copied').stat().st_mode & 0o777 == 0o600,
                  'snapshot is private to root')
            leaf.write_bytes(b'x' * 65537)
            check(run([HELPER, 'snapshot', 'cert', str(work / 'oversized')]).returncode != 0,
                  'snapshot rejects oversized input')
            leaf.unlink()
            os.mkfifo(leaf)
            os.chown(leaf, 33, 33)
            check(run([HELPER, 'snapshot', 'cert', str(work / 'fifo')]).returncode != 0,
                  'snapshot refuses a FIFO without blocking')
            leaf.unlink()
        finally:
            if stage.is_symlink():
                stage.unlink()
            elif stage.exists():
                for entry in stage.iterdir():
                    entry.unlink()
                stage.rmdir()
            if had_stage:
                saved.rename(stage)
        paths = [Path('/etc/postfix/sasl_passwd'), Path('/etc/postfix/sasl_passwd.db'),
                 Path('/etc/fail2ban/jail.d/zz-ai-pbx.local')]
        originals = {p: (p.read_bytes(), p.stat().st_mode & 0o777) if p.exists() else None for p in paths}
        try:
            check(run([PRIV, 'postfix', 'postmap'], b'[smtp.example.invalid]:587 fixture:secret\n').returncode == 0,
                  'Postfix credentials arrive through stdin')
            check(all(p.stat().st_uid == 0 and p.stat().st_mode & 0o777 == 0o600 for p in paths[:2]),
                  'Postfix map and source are root-only 0600')
            snapshot = paths[0].read_bytes()
            check(run([PRIV, 'postfix', 'postmap'], b'bad\nsecond line\n').returncode != 0
                  and paths[0].read_bytes() == snapshot, 'invalid SMTP input leaves credentials unchanged')
            check(run([PRIV, 'postfix', 'postmap'], b'').returncode != 0,
                  'empty SMTP stdin is refused')
            check(run([PRIV, 'f2b', 'install-override'], b'[DEFAULT]\nignoreip = 127.0.0.0/8 ::1\n').returncode == 0,
                  'Fail2ban override arrives through stdin')
            snapshot = paths[2].read_bytes()
            check(run([PRIV, 'f2b', 'install-override'], b'[DEFAULT]\nactionban = touch /root/evil\n').returncode != 0
                  and paths[2].read_bytes() == snapshot, 'Fail2ban command injection is refused')
            check(run([PRIV, 'f2b', 'install-override'], b'').returncode != 0,
                  'empty Fail2ban stdin is refused')
        finally:
            for path, original in originals.items():
                if original is None:
                    path.unlink(missing_ok=True)
                else:
                    path.write_bytes(original[0])
                    path.chmod(original[1])


if __name__ == '__main__':
    main()
