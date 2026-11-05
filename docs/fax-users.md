# Fax users and numbers

**Fax → Fax Units** is the one place for fax users. They do not appear under Extensions or System Users, though they sign in to the portal like anyone else.

## Adding one

The form holds everything: the name, the fax numbers, the e-mail address, the portal username and password, the outgoing caller ID, the dial permission group and the role. Press **Apply** afterwards so the numbers work.

## Several numbers, shared numbers

- **One user, several numbers:** separate them with commas. Faxes to all of them reach the same inbox, and the user chooses which number to send from.
- **One number, several users:** write the same number for each user. A shared number is marked in the list.

A received fax on a shared number is **one copy**, seen by everyone on that number. Anyone with the right to delete it deletes it for all.

**E-mail** goes to each user on the number who has an address and has *E-mail incoming faxes to this user* ticked, in that person's own language. A number with users mails only them, even if none of them wants mail.

A number stays on while one of its users is active, and goes when its last user is deleted. Received and sent faxes stay.

## Deleting received faxes

This is a right of the role. The **fax user** role does not have it. To let someone delete, grant *Incoming Faxes → Delete* under **Roles**: on the fax user role for everyone, or on a role of its own for a few, and choose that role in the fax user's form.

## Cover templates

Each user can keep cover templates of their own under *Send Fax*, and choose one when sending. The organisation's template stays the default. See [Fax cover page](fax-cover-page.md).
