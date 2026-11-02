# Advanced Configuration

AiPBX generates the PJSIP and dialplan files under `/etc/asterisk/pbx` from its database, and replaces them on
**Apply** and on updates. To change something the portal has no field for, do not edit those files. Use one of these
instead, and your change stays:

| You want to change | Use |
| --- | --- |
| An option of a transport, endpoint, AOR, auth, identify or registration | **PBX Management → Advanced Configuration** |
| An option of a trunk's endpoint | The trunk's **Custom parameters**, or the page above |
| The dialplan | **PBX Management → Custom Dialplan** |

## Adding options

Choose the type, enter the object's name (the field suggests the names in use) and write one `option=value` per line:

```ini
tos=cs3
symmetric_transport=yes
```

Then press **Apply**. Your value replaces the generated one. Options are kept in the database and written to
`pjsip_zz_advanced.conf`, added to the generated section with `[name](+type=endpoint)`.

Endpoint-level NAT settings such as `rtp_symmetric`, `force_rport`, `rewrite_contact` and `direct_media` can be set for a
single phone this way. Trunks have them on their own page.

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
