# Health checks

**System → Health checks** watches the server and e-mails the chosen people when something stops
working — and again when it works again. Every morning it can send a **daily summary** for
managers: yesterday's calls and faxes and the state of every check.

## Checks

| Check | Default | Warning / failed when |
|-------|---------|------------------------|
| Asterisk | 5 min | Asterisk does not answer (failed); it still runs on libraries a system update replaced, so it needs a restart when no calls are in progress (warning) |
| Database | 5 min | MariaDB does not answer |
| Web portal | 5 min | the sign-in page does not answer 200 |
| Chat service | 5 min | `aipbx-chat` is not running |
| Trunks | 5 min | an active trunk is unreachable (qualify) or its registration is rejected/unregistered |
| Disk space | 1 h | warning from 85 %, failed from 95 % (`/`, `/var`, `/var/spool/asterisk`) |
| Memory | 15 min | warning under 10 % available, failed under 5 % |
| E-mail sending | 1 h | postfix not running (failed); messages queued for over an hour (warning) |
| Pending changes | 1 h | changes not applied for over an hour |
| Outgoing faxes | 1 h | faxes waiting to be sent for over an hour |
| Call records | 15 min | Asterisk has handled calls since it started (15 min or more ago) but none reached the call history — the CDR database connection is broken; restart Asterisk |
| Call quality | 5 min | a trunk or extension is losing audio (from RTCP, see [Call quality](call-quality.md)); not used while signaling capture is off |
| Other alerts | 5 min | a plugin reports a problem in the alert bell, so it is e-mailed too |
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

- E-mails go to the **users** ticked on the page (in their own language) and to **other e-mail
  addresses**.
- Checks that run every 15 minutes or more often report a problem only after **two bad results in
  a row**, so a short hiccup stays quiet. Less frequent checks report the first bad result.
- While a problem lasts, the alarm is **repeated** after the chosen number of hours; a check that gets
  worse (warning → failed) is reported at once.
- When it works again, a **back to normal** e-mail follows.
- All problems found in one run go into one e-mail.

The texts are e-mail templates (*E-mail → Templates*: Health alarm, Health back to normal, Daily
summary). A server that is completely down cannot send an e-mail itself: watch it from outside
for that (e.g. a monitoring service that opens the sign-in page).

## Alert bell

Administrators have a **bell** in the top bar. Its number is how many problems are open now (red when one
has failed); a dot marks changes since you last opened it. It lists:

- every check that is *warning* or *failed* now;
- problems plugins report (see [Plugin development](plugin-development.md#d-other-hooks)), e-mailed through the check *Other alerts*;
- **the alarm path itself**: no recipient chosen ("Alarm e-mails go to nobody"), or no check has run for
  30 minutes ("Health checks are not running", i.e. the cron job stopped);
- below, the changes of the last 7 days (went wrong / back to normal).

It shows state, not a log: an item goes away by itself when its problem is fixed, nothing has to be
cleared. Everything in it is also e-mailed to the recipients above when they are set; the bell works
without e-mail, so a PBX whose mail is not set up or broken still shows its problems.

## Daily summary

At the chosen time (default 08:00), to the same people:

- yesterday's calls: total, incoming, outgoing, internal, answered, missed, busy, average and total
  talk time (counted per call, as on the call report), and faxes received / sent / failed;
- the state of every check now;
- the checks that raised an alarm yesterday.

*Send the summary now* on the page sends it at once (to check the recipients and the mail settings).

## Command line

```
sudo aipbx-selftest
```

runs every check at once and prints the results; the exit code is 1 when a check fails. It sends no
e-mail. `install.sh` runs it at the end of every installation and update, and CI runs it after a
fresh install.

## How it works

- `/etc/cron.d/aipbx` runs `/usr/local/bin/aipbx_health.php` every minute as root; it runs the checks
  whose interval has passed (most need root: Asterisk CLI, systemd, file owners), stores the
  results in `health_checks`, status changes in `health_events` (kept 30 days) and sends the
  e-mails. *Check now* on the page asks the next run to do every check.
- Checks only read; none of them changes anything on the server.
