# Health checks

**System → Health checks** watches the server and writes a **notice** when something stops working
— and another when it works again (see [Notices](notices.md)). Every morning it can send a **daily
summary** for managers: yesterday's calls and faxes and the state of every check.

## Checks

| Check | Default | Warning / failed when |
|-------|---------|------------------------|
| Asterisk | 5 min | Asterisk does not answer |
| Database | 5 min | MariaDB does not answer |
| Web portal | 5 min | the sign-in page does not answer 200 |
| Chat service | 5 min | `aipbx-chat` is not running |
| Trunks | 5 min | an active trunk is unreachable (qualify) or its registration is rejected/unregistered |
| Disk space | 1 h | warning from 85 %, failed from 95 % (`/`, `/var`, `/var/spool/asterisk`) |
| Memory | 15 min | warning under 10 % available, failed under 5 % |
| E-mail sending | 1 h | postfix not running (failed); messages queued for over an hour (warning) |
| Pending changes | 1 h | changes not applied for over an hour |
| Outgoing faxes | 1 h | faxes waiting to be sent for over an hour |
| DHCP / TFTP / FTP | 15 min | switched on, but the service does not run |
| Server address | 60 min | a stored address (server, phones, coturn, mDNS) is not this server's any more: see [Network settings](network-settings.md#when-the-servers-ip-address-changed) |
| Local AI service | 15 min | installed and enabled, but not running |
| TLS certificate | daily | warning 14 days before it ends, failed 3 days before |
| Daily backup | daily | the newest file in `/var/backups/aipbx-daily` is older than 26 h |
| Installation | daily | something `install.sh` sets up is missing or has the wrong owner/rights (helper, sudo rule, cron, TFTP folder, voicemail folders, masked vsftpd unit) |
| AiPBX version | daily | a newer release is out — information only, never an alarm |

Each check's **interval** can be changed on the page (off, 5 or 15 minutes, 1 or 6 hours, daily).
A check that does not apply to the server (no trunks, FTP switched off) shows *Not used*.

## Alarms

Alarms are **notices**: they appear under the bell for every administrator, and the cabinet decides
whether they are also e-mailed, when to stay quiet and how often a lasting problem is repeated
(*gear icon in the bell*). The health checks keep no mail path of their own.

- Checks that run every 15 minutes or more often report a problem only after **two bad results in
  a row**, so a short hiccup stays quiet. Less frequent checks report the first bad result.
- One problem is one notice, keyed by the check and its status: a warning that escalates to failed
  is a new notice at once; a failed check that improves to warning is not (the alarm row already
  says what is wrong). While the problem lasts, the cabinet's window repeats it — six hours by
  default.
- When it works again, a **back to normal** notice follows. It is informational, so it is never
  e-mailed.

The only e-mail the health checks send themselves is the daily summary; its text is the *Daily
summary* template (*E-mail → Templates*). A server that is completely down cannot write a notice
or send an e-mail: watch it from outside for that (e.g. a monitoring service that opens the
sign-in page).

## Daily summary

At the chosen time (default 08:00), to the people ticked on the page:

- yesterday's calls: total, incoming, outgoing, internal, answered, missed, busy, average and total
  talk time (counted per call, as on the call report), and faxes received / sent / failed;
- it goes to the users ticked on the page (each in their own language) and to the extra addresses;
- the state of every check now;
- the checks that raised an alarm yesterday.

*Send the summary now* on the page sends it at once (to check the recipients and the mail settings).

## Command line

```
sudo aipbx-selftest
```

runs every check at once and prints the results; the exit code is 1 when a check fails. It sends no
e-mail and writes no notice. `install.sh` runs it at the end of every installation and update, and CI runs it after a
fresh install.

## How it works

- `/etc/cron.d/aipbx` runs `/usr/local/bin/aipbx_health.php` every minute as root; it runs the checks
  whose interval has passed (most need root: Asterisk CLI, systemd, file owners), stores the
  results in `health_checks`, status changes in `health_events` (kept 30 days), writes the notices
  and sends the daily summary. *Check now* on the page asks the next run to do every check.
- `aipbx-selftest` (`aipbx_health.php --all --no-mail`) checks everything but writes no notice: a
  test run must not raise an alarm.
- Checks only read; none of them changes anything on the server.
