# Security

## Firewall

The installer configures **firewalld** (ufw is disabled) and opens the ports listed in
[INSTALL.md](../INSTALL.md#34-firewall-firewalld). Manage them on **Security → Firewall** or with `firewall-cmd`.

- **Common tab:** the rules every network card uses. Adding, removing or turning off a rule there
  applies to all cards. A card button closes or reopens a common rule on that one card.
- **One tab per card:** only what differs on it: rules for that card alone and the common rules closed
  on it, so you can close the web page on the internet card and keep it on the office card. **Use
  general rules** takes a card back to the common rules.
- **Virtual interfaces** with rules of their own (a VPN, for example) do not take the common rules:
  their tab lists everything they open.
- **Turn off / Turn on:** try a change without deleting the rule; it comes back as it was. A custom
  rule can only be removed.
- **Source:** with an address or network, only that source may use the port.
- **Nothing is locked.** Before a rule goes, the page says when removing it can cut your own
  connection, drop phones registered through that card, or leave a service unreachable from outside.
  A firewall change does not come back by itself.
- **Opened by other pages:** a new transport port ([Transports](transports.md)) and the TFTP/FTP,
  NTP and syslog services ([Network services](network-services.md)) open what they need.

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
stay open for carrier audio, and UDPTL (4100–4999/udp) for T.38 fax. The **Firewall** page shows these rules and can remove them.

**Sign-in alerts.** The lockouts and Fail2ban react to wrong passwords, so they cannot see a stolen
password that works. For that, a user gets a mail when their account signs in from an internet network
(same /24 or /64) it has not used in the last 90 days. An account's first sign-in and addresses on your own
LAN or VPN send nothing. It needs the E-mail settings and an e-mail address on the user. Turn it off under
**Security → Sign-in → Sign-in alerts**.

**Sign-in check.** The sign-in and forgotten password pages ask a simple sum. Under **Security → Sign-in** you can
use Cloudflare Turnstile instead (site key and secret key from your Cloudflare account). The keys are checked
when you save. Turnstile loads a script from Cloudflare and sends the visitor's IP address to it, so the
browser needs internet access. If the server cannot reach Cloudflare, the sum comes back for ten minutes. If
browsers cannot load Turnstile, for example on a closed network, turn it off from the server:

```bash
sudo mysql asterisk -e "UPDATE sys_settings SET setting_value='math' WHERE setting_key='login_captcha_provider'"
```

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

- **Failed registration alarm:** The [Failed registrations](health.md#checks) health check monitors
  Asterisk's security log and alarms in the bell and by e-mail when failed SIP authentication attempts
  exceed the configured threshold (see [PBX fine tuning](pbx-tuning.md#failed-registration-alarm)).

## Toll fraud

Three brakes, all off until switched on:

- **Night dialling protection** (*Dial permissions* page): outside the night hours set there (and all
  weekend if wanted), calls to protected prefixes (default `00` international and `0900` premium) are
  blocked ("Your call cannot be completed as dialled", without answering the call) or need a night PIN
  (three tries; a new PIN needs at least 6 digits). It runs before the permission groups, so it also covers trunk-to-trunk transit;
  portal faxes are exempt. Blocked calls carry `NIGHT_GUARD` in the CDR user field.
- **Trunk limits** (*Trunks → Routing*): concurrent channels and calls per minute, with the health
  alarm *Trunk limits* (see [Trunks](trunks.md#limits)).
- **Dial permission groups**: which numbers a group of users may dial at all. The rules run in priority
  order and the first match wins; the group's default action covers every other number. A dialled
  number with any character other than digits, `*`, `#` and `+` is refused before the check. A call
  transferred from the Agent Screen follows the agent's group and outbound routes.
  **Before 1.76.0 the rules of a group never matched** and only its default action applied: after
  updating, check each group's rules, because they now take effect.

One brake is on from the start: a user can send 30 faxes an hour (*Fax → Fax Settings*; 0 turns it off,
administrators are not limited). Deleting faxes does not free the limit, so a stolen account cannot
hide what it sends and carry on.

## Root helper (`aipbx-priv`)

The portal never runs arbitrary commands as root. `/etc/sudoers.d/aipbx` allows `www-data` to run only
`/usr/local/sbin/aipbx-priv`, whose subcommands each map to one fixed operation with validated
arguments (service reloads, postfix relay, firewall rules, fail2ban, update check/start).

### Database and Asterisk privileges

The portal's runtime database account (normally `aipbx_portal`) has only
`SELECT`, `INSERT`, `UPDATE` and `DELETE` on the application database. Schema migrations use a
separate account in `/etc/ai-pbx-migrator.env` (`0600 root:root`); its rights are limited to this
database. Apache and the background worker cannot read that file. Do not give the runtime account
DDL or global database privileges to work around a plugin installation problem.

Plugin table creation and removal go through validated `plugin-schema` operations in the root
helper when necessary. An already installed schema is checked without invoking sudo, so the
`NoNewPrivileges` worker can use its existing tables. See [Plugin development](plugin-development.md).

`conf/asterisk/cli_permissions.conf` restricts the commands the web account can send to Asterisk.
Its allow-list patterns are anchored at both ends: Asterisk searches a regular expression anywhere
in a command. An allowed phrase embedded inside another command must not grant access to it.

### Job state, logs and uploads

Root-written job status, installed-version metadata and backup retention live in
`/var/lib/aipbx-system` (`root:www-data 0750`). The portal reads public status there but cannot
create or replace files. Network rollback and upload snapshots use root-only subdirectories.
`/var/lib/aipbx` remains the writable application-data directory, not a staging area for root output.

Root job logs in `/var/log/aipbx` use a protected parent and `root:www-data 0640` files. PHP can
read them; the separate login-failure log is writable by PHP. Certificate and backup uploads are
copied into a validated root-private snapshot before privileged processing. Postfix and Fail2ban
inputs travel through stdin rather than portal-written root configuration files.

See [Privileged job state](root-state-paths.md) and [Privileged logs](root-log-paths.md) for ownership,
migration and rotation details. Do not use recursive chmod/chown to make these areas web-writable.

## Apache sandbox

Ubuntu's `apache2.service` is hardened with systemd sandboxing. The installer's override
(`/etc/systemd/system/apache2.service.d/override.conf`) relaxes only what the portal needs:

- `/etc/sudoers` and `/etc/sudoers.d` readable, so `sudo aipbx-priv` works;
- `/etc/asterisk`, `/etc/postfix` and `/etc/fail2ban/jail.d` writable (everything else under `/etc`
  stays read-only);
- `/root`, `/boot`, `/etc/ssh`, `/etc/apt` and `/etc/ai-pbx-migrator.env` stay inaccessible.

The sandbox's writable directory list does not bypass Unix ownership. Protected files such as
`rtp.conf`, `udptl.conf` and `voicemail.conf` are written through the privilege helper when the
portal cannot write them directly. Only the dedicated generated proxy/SIP-443 configuration
locations are opened to the required helpers; arbitrary web input is not an Apache or nginx config.

## Scheduled task sandbox

Scheduled background scripts run in a hardened `systemd-run` sandbox as the unprivileged user `aipbx-task` with an isolated filesystem, strict resource limits, and private network namespaces (see [Scheduled tasks](scheduled-tasks.md#sandbox-security)).

## Read-only external databases

External database queries in IVR data steps and plugins run in strictly enforced read-only sessions with a 2.5-second statement timeout, single statement restrictions, and encrypted credentials (see [External databases](external-databases.md#supported-engines-and-security)).

## Other measures

TOTP two-factor authentication, passkeys, account/IP lockout with CAPTCHA, CSRF tokens on every form
and API call, sessions revoked on password change and when two-factor authentication is turned on (the
mobile app's too), a role change effective at once in open sessions, password reset and invitation
links stored only as a hash, one password rule (8 characters) on every path, least-privilege database users (runtime vs.
migrations), runtime credentials in `/etc/ai-pbx.env` (`640 root:asterisk`), migration credentials
in the root-only migrator file, and Asterisk HTTP/AMI on loopback. Plugin/provider secrets stored
encrypted in the database depend on `/var/lib/aipbx/settings.key` (`0600 www-data:www-data`);
include that key in protected backups.

Generated Asterisk files in `/etc/asterisk/pbx` (they hold voicemail PINs, the night PIN and SIP
passwords) are `0640`, group `asterisk`. The web server serves none of the server-side folders
(`src`, `vendor`, `db`, `bin`, `tests`, `templates`, `config`, `lang`, `docs`, `etc`) and no `.md` file.
Names and titles written into the dialplan keep only letters, digits, space and `. _ + -`.
