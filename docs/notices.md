# Notices: what the PBX found and you should know

A notice is one line of "the system noticed something". It shows in the bell in
the top bar of every page, and — if you asked for it — arrives by e-mail.

Nothing produces notices by default except the modules that opt in (health checks
today, `call-quality` and others once they are installed). The point is that a
module with something to say has one place to say it, with two rules that keep
the cabinet readable.

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

Stored in `sys_settings`, so they survive an update:

| key | default | meaning |
|---|---|---|
| `notice_email` | `0` | e-mail warn/critical notices |
| `notice_email_to` | *(empty)* | comma-separated recipients; empty means every admin user with an address |
| `notice_quiet_hours` | *(empty)* | `HH:MM-HH:MM`; empty, malformed or zero-length means no quiet window |
| `notice_window_hours` | `6` | dedupe window in hours, `0`–`168`; `0` disables deduplication |

`NoticeService::saveSettings([...])` validates all four and writes them
(`notice_email` as `1`/`0`). The e-mail body comes from the `notice` mail
template, which is editable under **E-mail → Templates** like the others.

## Where it lives

| file | what |
|---|---|
| `web/src/services/NoticeService.php` | the rules: dedupe, quiet hours, mail |
| `web/src/repositories/NoticeRepository.php` | `pbx_notices`: recent, unread, dedupe lookup, mark read |
| `web/api/notices.php` | JSON for the bell: list + unread count, mark one/all read |
| `web/assets/js/notices.js` | the dropdown; opening it re-reads the list |
| `web/templates/topbar.php` | the bell itself |

## Limits

- **Read state is per install, not per user.** The first person who opens a
  notice marks it read for everyone. That is right for "the PBX found something"
  and wrong for "this is your task"; a per-user table is the change if notices
  ever need to be addressed to somebody.
- **The newest twenty.** The bell lists the newest 20 rows and stops; there is
  no archive page yet. The rows stay in the table (nothing prunes them) so a
  page can be added without a migration.
- **No mail for `info`.** An informational notice is a row and a badge, never an
  e-mail — the quiet default matters more than the notification.
