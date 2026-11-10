#!/usr/bin/env python3
"""Disposable installed systems only: sudo env AIPBX_E2E=1 python3 turn_security.py."""
import base64
import grp
import hashlib
import hmac
import ipaddress
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import time

CONF = Path('/etc/turnserver.conf')


def settings():
    return dict(line.split('=', 1) for line in CONF.read_text().splitlines()
                if '=' in line and not line.lstrip().startswith('#'))


def check(ok, name):
    if not ok:
        raise RuntimeError(name)
    print('PASS ' + name, flush=True)


def can_read(user, path):
    result = subprocess.run(['/usr/sbin/runuser', '-u', user, '--', '/usr/bin/python3', '-c',
                             'import sys; open(sys.argv[1], "rb").close()', str(path)], capture_output=True)
    return result.returncode == 0


def relay_check(messages=4, interval=20):
    conf = settings()
    address = str(ipaddress.ip_address(conf['relay-ip']))
    # Only short-lived REST credentials reach the test client's argv. The long
    # lived shared key stays in this root process and is never printed.
    username = str(int(time.time()) + 180) + ':aipbx-permission-test'
    password = base64.b64encode(hmac.new(conf['static-auth-secret'].encode(), username.encode(), hashlib.sha1).digest()).decode()
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as reservation:
        reservation.bind((address, 0))
        port = reservation.getsockname()[1]
    peer = subprocess.Popen(['/usr/bin/turnutils_peer', '-L', address, '-p', str(port)],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            if peer.poll() is not None:
                raise RuntimeError('TURN test peer could not start')
            with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as probe:
                try:
                    probe.bind((address, port))
                except OSError:
                    break
            time.sleep(0.05)
        result = subprocess.run(['/usr/bin/turnutils_uclient', '-t', '-S', '-p', '5349',
                                 '-E', '/etc/ssl/aipbx/active.crt', '-c', '-m', '1',
                                 '-e', address, '-r', str(port), '-n', str(messages), '-z', str(interval),
                                 '-u', username, '-w', password, address], capture_output=True, text=True,
                                timeout=max(30, messages * interval / 1000 + 30))
        output = result.stdout + result.stderr
        sent = re.findall(r'tot_send_msgs=(\d+)', output)
        received = re.findall(r'tot_recv_msgs=(\d+)', output)
        check(result.returncode == 0 and bool(sent) and bool(received)
              and int(sent[-1]) >= messages and sent[-1] == received[-1],
              'authenticated TLS TURN allocation relays packets without loss')
    finally:
        peer.terminate()
        try:
            peer.wait(timeout=5)
        except subprocess.TimeoutExpired:
            peer.kill()
            peer.wait()


def main():
    if os.environ.get('AIPBX_E2E') != '1' or os.geteuid() != 0:
        raise RuntimeError('Run as root with AIPBX_E2E=1 only in a disposable installation')
    gid = grp.getgrnam('turnserver').gr_gid
    info = CONF.lstat()
    check(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_gid == gid
          and stat.S_IMODE(info.st_mode) == 0o640, 'active TURN config is root:turnserver 0640')
    check(can_read('turnserver', CONF), 'coturn service user can read its configuration')
    for user in ('nobody', 'www-data', 'asterisk'):
        check(not can_read(user, CONF), user + ' cannot open the TURN configuration')
    for path in CONF.parent.glob('turnserver.conf.*'):
        if path.is_file() and re.search(r'^static-auth-secret=.+', path.read_text(), re.M):
            info = path.lstat()
            mode = stat.S_IMODE(info.st_mode)
            check(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and mode in (0o600, 0o640)
                  and (mode == 0o600 or info.st_gid == gid),
                  'secret-bearing temporary/backup file has restricted permissions: ' + path.name)
            check(not can_read('nobody', path), 'unrelated user cannot read ' + path.name)
    secret = settings()['static-auth-secret']
    pid = subprocess.check_output(['/usr/bin/systemctl', 'show', 'coturn', '-p', 'MainPID', '--value'])
    subprocess.run(['/usr/local/sbin/aipbx-netcfg', 'coturn-addresses'], check=True)
    check(settings()['static-auth-secret'] == secret, 'address refresh preserves the shared key')
    check(CONF.stat().st_gid == gid and stat.S_IMODE(CONF.stat().st_mode) == 0o640,
          'address refresh preserves restricted ownership and mode')
    check(subprocess.check_output(['/usr/bin/systemctl', 'show', 'coturn', '-p', 'MainPID', '--value']) == pid,
          'address refresh does not restart coturn')
    relay_check()


if __name__ == '__main__':
    main()
