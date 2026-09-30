# Security

## Firewall

The installer configures **firewalld** (ufw is disabled) and opens the ports listed in
[INSTALL.md](../INSTALL.md#34-firewall-firewalld). Manage them on **Firewall** or with `firewall-cmd`.

**Recommended:** SIP (5060/udp+tcp, 5061/tcp) is scanned constantly on the internet. If only your
carrier and local phones use SIP, remove the open ports and allow the sources instead:

```bash
for src in 203.0.113.10 10.0.0.0/8; do            # carrier IP, internal networks
  for pp in 5060/udp 5060/tcp 5061/tcp; do
    firewall-cmd --permanent --add-rich-rule="rule family=\"ipv4\" source address=\"$src\" port port=\"${pp%/*}\" protocol=\"${pp#*/}\" accept"
  done
done
for pp in 5060/udp 5060/tcp 5061/tcp; do firewall-cmd --permanent --remove-port=$pp; done
firewall-cmd --reload
```

The web softphone and the mobile apps connect over 443 and are not affected. RTP (10000–20000/udp) must
stay open for carrier audio. The **Firewall** page shows and removes these rules.

Asterisk's own TLS WebSocket (8089) listens on loopback only: browsers and the mobile apps use 443
`/ws`, which goes through the nginx/Apache edge. Earlier versions opened 8089 publicly; installing or
updating closes it.

**SSH** is left as the operating system configured it. If root can log in with a password, the
installer prints a warning; prefer key-only logins and allow port 22 only from trusted networks.

## fail2ban

Jails: `asterisk` (failed SIP registrations/auth), `aipbx-web` (portal logins, using the real client IP
from the PROXY protocol) and `sshd`. Ban time, find time, max retries and the ignore list are set on the
**fail2ban** page; the portal writes them to `/etc/fail2ban/jail.d/zz-ai-pbx.local`, which is read last so
it overrides the installer's defaults. Add your internal networks and carrier to the ignore list so a
misconfigured phone cannot lock them out.

## Root helper (`aipbx-priv`)

The portal never runs arbitrary commands as root. `/etc/sudoers.d/aipbx` allows `www-data` to run only
`/usr/local/sbin/aipbx-priv`, whose subcommands each map to one fixed operation with validated
arguments (service reloads, postfix relay, firewall rules, fail2ban, update check/start).

## Apache sandbox

Ubuntu's `apache2.service` is hardened with systemd sandboxing. The installer's override
(`/etc/systemd/system/apache2.service.d/override.conf`) relaxes only what the portal needs:

- `/etc/sudoers` and `/etc/sudoers.d` readable, so `sudo aipbx-priv` works;
- `/etc/asterisk`, `/etc/postfix` and `/etc/fail2ban/jail.d` writable (everything else under `/etc`
  stays read-only);
- `/root`, `/boot`, `/etc/ssh`, `/etc/apt` stay inaccessible.

## Other measures

TOTP two-factor authentication, passkeys, account/IP lockout with CAPTCHA, CSRF tokens on every form
and API call, sessions revoked on password change, least-privilege database users (runtime vs.
migrations), secrets only in `/etc/ai-pbx.env` (`640 root:asterisk`), Asterisk HTTP/AMI on loopback.
