# Notices: what the PBX found and you should know

A notice is one line of "the system noticed something". It shows in the bell in
the top bar of every page, and — if you asked for it — arrives by e-mail.

The health checks are the first writer: a check that fails or warns writes a
notice, and another one when it works again
([Health checks](health.md#alarms)). Other modules follow the same two rules,
which are what keep the cabinet readable.

## The two rules

**One problem, one notice.** Every notice carries a *dedupe key* — a short
string that names the problem, not the event (`trunk-1-down`, `disk-full`,
`quality-trunk-3`). A second notice with the same key inside the window is
dropped; the first one keeps its row. The window is six hours by default, and
`0` turns deduplication off.

This is why the key matters: `trunk-1-down` reports once per six hours while the
trunk stays down, and a *different* trunk failing is a different notice.

**Do not wake anybody.** Mail is off until you turn it on. When it is on, only
`warn` and `critical` are e-mailed, and a quiet window silences even those
(`22:00-07:00`, crossing midnight is fine). The notice itself is always written
first: a mail failure costs the notice its e-mail, never its existence.

## From code

```php
NoticeService::notify(
    'warn',                       // info | warn | critical (anything else is info)
    'Trunk 1 is not registering',
    'REGISTER failed 5 times in 60 s; the carrier answered 403.',
    'trunk-1-down',               // dedupe key; '' derives one from severity+title
    'trunks'                      // source, shown as the origin
);
```

The return value is `['id' => n, 'emailed' => bool]`, or `['deduped' => true]`
when the same problem was already reported inside the window.

## Settings

The gear in the bell edits them; `api/notices.php` validates and saves them
through `NoticeService::saveSettings()`. Stored in `sys_settings`, so they
survive an update:

| key | default | meaning |
|---|---|---|
| `notice_email` | `0` | e-mail warn/critical notices |
| `notice_email_to` | *(empty)* | comma-separated recipients; empty means every admin user with an address |
| `notice_quiet_hours` | *(empty)* | `HH:MM-HH:MM`; empty, malformed or zero-length means no quiet window |
| `notice_window_hours` | `6` | dedupe window in hours, `0`–`168`; `0` disables deduplication |

`NoticeService::saveSettings([...])` validates all four and writes them
(`notice_email` as `1`/`0`). The e-mail body comes from the `notice` mail
template, which is editable under **E-mail → Templates** like the others; the
mail goes to at most five addresses per notice.

An installation that was mailing health alarms before the cabinet existed is
carried over once: `RetireHealthAlarmMail` copies the alarm recipients into
`notice_email_to`, switches `notice_email` on and turns `health_repeat_hours`
into `notice_window_hours`, so nothing goes quiet on update. Settings an
administrator already saved are never overwritten.

## Where it lives

| file | what |
|---|---|
| `web/src/services/NoticeService.php` | the rules: dedupe, quiet hours, mail |
| `web/src/repositories/NoticeRepository.php` | `pbx_notices`: recent, unread, dedupe lookup, mark read |
| `web/api/notices.php` | JSON for the bell: list + unread count, mark one/all read, save the settings |
| `web/assets/js/notices.js` | the dropdown; opening it re-reads the list |
| `web/templates/topbar.php` | the bell itself |

## Limits

- **One text, one language.** A notice is stored as written, so everyone reads
  it in the installation's language. The daily-summary e-mail is still built per
  recipient; notice mail is not.
- **The newest twenty.** The bell lists the newest 20 rows and stops; there is
  no archive page yet. The rows stay in the table (nothing prunes them) so a
  page can be added without a migration.
- **No mail for `info`.** An informational notice is a row and a badge, never an
  e-mail — the quiet default matters more than the notification.
