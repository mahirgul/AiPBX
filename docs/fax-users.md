# Fax users and numbers

**Fax → Fax Units** is the one place for fax users. They do not appear under Extensions or System Users, though they sign in to the portal like anyone else.

## Adding a unit

Give the unit a name, its fax numbers and an e-mail address, and choose **People who use this unit**. Press **Apply** afterwards so the numbers work.

Anyone with an account can be chosen, such as an extension user. They sign in as usual and find the unit's numbers under Fax, whatever their role, so nobody needs a second account for fax.

For someone with no account, tick **Create a fax-only login** and fill in the username and password. Such logins appear here, not under Extensions or System Users.

**The number a fax goes out with** is the unit's own **Outgoing caller ID** on this form, whoever sends through the unit (in the portal or by e-mail). It comes before the trunk's outbound number, as for a phone. Without one, the trunk's default caller ID is used. A fax machine on an ATA is an ordinary extension and uses its own External Call CID.

## Several numbers, shared numbers

- **Several numbers:** separate them with commas. A person sees the faxes of all their numbers and chooses which to send from. A person sends from fax numbers only, not from their phone extension.
- **Several people on one number:** choose them on the unit, or write the same number for two fax logins. A shared number is marked in the list.

A received fax is **one copy**, seen by everyone on that number. Anyone with the right to delete it deletes it for all.

**E-mail** goes to the unit's address, and to the people on the number if *E-mail incoming faxes to them too* is ticked, each in their own language and once per address. The address can be one that is already on someone's account.

A number with a fax login stays on while one of those logins is active, and goes when the last one is deleted. Received and sent faxes stay.

## Fax Settings

**Fax → Fax Settings** holds what applies to every unit:

- **Sender** address and name of the fax mails, and whether mails go out for incoming faxes (with or
  without the PDF) and for the result of a sent fax.
- **Retention:** received and sent faxes older than 30, 60, 90 or 365 days are deleted, or never.
- **Fax header text** at the top of sent pages (the sender's name and extension are added), and the
  **station ID** (TSID) the receiving machine shows.
- **Retries:** how many, the wait between them and how long the first attempt rings.
- **Faxes per user per hour** (30 by default; 0 turns it off). Deleting faxes does not free the limit; administrators are not limited.

## Deleting received faxes

This is a right of the role. The **fax user** role does not have it. To let someone delete, grant *Incoming Faxes → Delete* under **Roles**: on the fax user role for everyone, or on a role of its own for a few, and choose that role in the fax user's form.

## Cover templates

Each user can keep cover templates of their own under *Send Fax*, and choose one when sending. The organisation's template stays the default. See [Fax cover page](fax-cover-page.md).
