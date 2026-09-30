# Users & roles

## Built-in roles

| Role | Key | Lands on | Access |
|------|-----|----------|--------|
| Administrator | `admin` | Dashboard | everything |
| Viewer | `read_only_admin` | Dashboard | sees every page, cannot change or delete anything |
| Queue manager | `cc_manager` | Call-center board | live board, queues, agents, breaks, reports, listen/whisper/barge |
| Agent | `cc_agent` | Agent desk | agent desk, breaks, own calls |
| Fax | `fax_user` | Fax inbox | fax inbox, send, sent |
| User | `user` | My Phone | My Phone, chat, own call history — no fax or call-center modules |

**User** is the default: new extensions and new portal users get it unless another role is chosen. The
built-in roles cannot be deleted. Custom roles can be created on **Roles** with per-module *view /
access / edit / delete* permissions.

Some pages are admin-only regardless of the permission matrix: roles, system users, firewall, fail2ban,
mail settings and system update.

## My Phone

Every user has **My Phone**:

| Tab | Content |
|-----|---------|
| Call history | own calls with recordings |
| Call settings | Do Not Disturb, active phone modes (web, mobile, desk SIP, video), call forwarding (always / busy / no answer with timeout), voicemail (PIN, e-mail, when calls go to voicemail) |
| Phone & device settings | web phone audio devices and ringtone, desk phone (SIP) credentials, mobile app sign-in QR code |
| Voicemail | messages with in-browser player |

The four *Call settings* cards share one form: saving any card stores all of them.

Saving own settings needs the *edit* permission on `my_phone`. The **User** role has it; for **Agent**
and **Fax** it is off by default and can be enabled on **Roles**.
