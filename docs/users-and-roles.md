# Users & roles

## Adding users

**System → Users** adds portal users, with or without an extension ([Extensions](extensions.md)).

- **Two fields are enough:** the username and the extension. An empty full name becomes the username.
- **The password:** leave it empty. With an e-mail address the user gets an invitation and sets their
  own; without one a password is generated and shown to you once, and the user must change it at the
  first sign-in. **Send Login Email to Selected** sends the invitation again to several users.
- **Bulk import (CSV):** the preview shows which rows are ready and which have errors before anything
  is added; generated passwords can be downloaded as a CSV.
- **Call Pickup Group:** users in the same group pick up each other's ringing phone with `*20`.

**The `admin` account** made by the installer is an ordinary administrator: it can be renamed and
deleted, and nothing depends on its name. A good habit is a named administrator for each person, then
deleting `admin`. Two rules always stay: you cannot delete the account you are signed in with, and the
last active administrator cannot be deleted. `admin` owns extension 1000 by default, so deleting it
also removes that extension.

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
