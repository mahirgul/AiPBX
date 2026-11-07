# Advanced Configuration

AiPBX generates the PJSIP and dialplan files under `/etc/asterisk/pbx` from its database, and replaces them on
**Apply** and on updates. To change something the portal has no field for, do not edit those files. Use one of these
instead, and your change stays:

| You want to change | Use |
| --- | --- |
| An option of a transport, endpoint, AOR, auth, identify or registration | **PBX → Advanced Configuration** |
| A line in rtp, features, queues, voicemail, musiconhold, cdr, logger, manager, udptl, indications or confbridge | The same page |
| A PJSIP object AiPBX does not generate, such as a hand-written trunk | The same page |
| An option of a trunk's endpoint | The trunk's **Custom parameters**, or the page above |
| The dialplan | **PBX → Custom Dialplan** |

## Adding options

Choose the type, enter the object's name (the field suggests the names in use) and write one `option=value` per line:

```ini
tos=cs3
symmetric_transport=yes
```

Then press **Apply**. Your value replaces the generated one, and the list shows which value it replaced. Options are kept in the database and written to
`pjsip_zz_advanced.conf`, added to the generated section with `[name](+type=endpoint)`.

Endpoint-level NAT settings such as `rtp_symmetric`, `force_rport`, `rewrite_contact` and `direct_media` can be set for a
single phone this way. Trunks have them on their own page.

A **recipe** at the top of the form fills it for a common job: NAT, voice priority, P-Asserted-Identity, codec order,
voicemail time zone and a hand-written trunk. It only fills the form, so read it and choose the object before saving.
While you type, **Find an option** offers the names Asterisk lists for that type, each with a short description.
When you edit an entry, the form shows which lines changed from the saved version.

`allow=` adds to what an endpoint has. To set an order, start with `disallow=all`, as the recipe does.

### Who it applies to

**Applies to** decides what a PJSIP entry reaches:

| Choice | Reaches |
| --- | --- |
| One object | The object you name |
| All extensions (SIP) | Every extension's SIP object of that type. Browser and mobile objects keep their own settings |
| All trunks | Every trunk's object of that type |
| Phone model | The SIP objects of the extensions using that model |
| New object | Creates an object AiPBX does not generate |

An extension added later gets its group's options on the next reload. A value set on one object wins over its group.

## Other Asterisk files

Choose the file and the section, then write the lines. They are written to `/etc/asterisk/pbx/user_<file>.conf`, which
the file pulls in with `#tryinclude`, so Apply and updates do not replace them. Only a file whose lines changed is reloaded.

A few things differ from PJSIP:

- **The first value can win.** In `rtp`, `voicemail` and `logger` Asterisk uses the first value it finds, so a line
  cannot replace a value the file already has. The list says *No effect* when that happens. In the other files your value wins.
- **Some files have no option list.** `rtp`, `queues`, `voicemail`, `musiconhold`, `logger`, `manager` and `indications`
  offer none and ignore an unknown name without an error, so only the format and the load result are checked.
- **Some options are refused.** Those that run a program (`mailcmd`, `externnotify`, `application`, `exec_after_rotate`,
  `[applicationmap]`), open the management interface to others, or depend on a page and the firewall (`rtpstart`,
  `udptlstart`). In `manager.conf` only `[general]` can change.

## Raw text

**Raw text** is your own part of a file, with its `[sections]`. Asterisk's load result decides whether it is accepted.
The lines refused above and all `#` directives are refused here too. An addition such as `[sales](+)` is left out once
that section no longer exists, because it would stop Asterisk reading the rest of the file.

Objects you create here or with **New object** do not appear on the Trunks or Extensions pages, and routes do not offer them.

## Reload or restart

Each entry says whether a reload is enough. Only PJSIP transports need a restart: a new one opens on a reload, but
changing or removing one takes effect after a restart. The list marks those entries.

## Export and import

**Export** downloads the list as a file; it can contain passwords you typed here, so keep it safe. **Import** replaces
the whole list. Every entry passes the same checks as the form, nothing changes if one fails, and the present list is
kept under Earlier versions. Imported entries take effect after Apply.

## Why a mistake cannot take an object away

A wrong option name is easy to miss in Asterisk: `pjsip reload` reports success, the old settings stay in memory, and
**at the next restart that endpoint is gone**. So:

- **When you save,** option names are checked against the running Asterisk's own documentation. `#include`, `#exec`, new sections and changing `type` are refused. The same name check applies to a trunk's custom parameters. If Asterisk cannot be asked at that moment, the check runs at Apply instead.
- **When you apply,** Asterisk's log is read after the reload. If it refused anything, the previous list goes back and the error is shown on the Apply page.
- **If an object goes away,** for example a deleted extension, its entry is left out of the file and shown as *Object not found*. It is used again if the object returns, at the next PJSIP reload.

A value is not checked in advance: Asterisk accepted `rtp_symmetric=maybe` without an error in testing, so test the
behaviour you changed.

## Earlier versions

The list is saved before every change, and the last 50 versions are kept. **Restore** puts a version back, and it takes
effect after Apply. The present list is saved first, so a restore can be undone too.

## Generated files changed by hand

If a generated file differs from what AiPBX last wrote, the next regeneration keeps a copy as
`<file>.edited-<date>` beside it, records it in the audit log and lists it on this page. AiPBX keeps the last five copies
of each file. Move what you need to this page or to Custom Dialplan: the hand edit itself is replaced.

Files generated before this feature have no record yet, so a hand edit is noticed from the second regeneration on.
