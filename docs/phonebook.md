# Phonebook

**Dashboard → Address Book** keeps the company contacts everyone can reach (suppliers, customers,
branches) and serves them, together with the extensions, to desk phones over LDAP.

Who may do what is set per role (**Roles & Permissions → Address Book**). By default everyone
can look contacts up, agents can also add a caller, administrators and call center managers can
change and delete. The LDAP and matching settings below are for whoever may change **Phones**.

## Contacts

- Add, change or delete contacts by hand, or import a CSV file: name, company, work phone, mobile,
  other phone, e-mail (`,` or `;`; a header row is skipped). A contact needs a name (or a company)
  and at least one number.
- A contact can have up to 12 numbers, each with a label: mobile, work, work 2, home, fax, other.
  Desk phones see three of them (LDAP has three number fields): the first mobile, the first work
  number and the first of the rest.
- Select **VIP** when editing a contact to give calls from its numbers priority 100 in the queue
  they reach. The call moves ahead of waiting callers with lower priority. Calls already in
  progress and explicit priorities set by call routing keep their existing behavior.
- **Call Reports** show the contact's name under a number that is in the address book, and a
  <i>Save to address book</i> button next to an outside caller (or a dialled outside number) that
  is not; it opens this form with the number filled in.
- Other programs (a CRM, a script) read and write the address book over the
  [REST API](rest-api-v1.md) (`/api/v1/contacts`, scopes `contacts:read`/`contacts:write`).

## The caller's name on calls

When an outside call comes in, the PBX looks the caller's number up in the address book before
the call rings anyone. If the number is there, the call carries the contact's name — "Name
(Company)" — as its caller ID name, so desk phones, queues, the web phone and the apps all show
it; if not, the call carries what the trunk sent (often only the number). The lookup waits one
second at most and never stops a call. The [CNAM plugin](cnam.md), when enabled, comes after it.

Desk phones can also read the whole address book over LDAP (below).

The web phone shows a card under the name of a ringing outside call: the number, the company and
a link to the address book, or **Save to address book** when the number is not in it (for roles
that may add contacts).

## Matching caller numbers

A number is found however it was written: `+90 532 111 22 33`, `0532 111 22 33` and
`0090 532 111 22 33` are one number, because the last digits are compared. **Matching caller
numbers** sets how many: the last 10 by default, which suits most countries; 7, 8, 9, 11 or 12;
or the whole number (an international `00` still counts as `+`). With fewer digits, two numbers
from different area codes can match. Numbers shorter than the setting, such as extensions, are
compared whole.

**Add outside callers who are not in the address book** (same card, off by default) saves every
inbound caller that is not found yet when the call ends, named by the caller ID or by the number.

- Contacts brought from another directory by a plugin (for example
  [`ldap-sync`](https://github.com/mahirgul/aipbx-plugins/tree/main/plugins/community/ldap-sync) for
  Active Directory and OpenLDAP) are listed with their source and changed there, not on this page.
- Fax extensions are not part of the phonebook, nor of the contacts in the mobile apps and My Phone.

## LDAP phonebook for desk phones

Turn on **Serve the phonebook over LDAP**. The chat service (`aipbx-chat`) then answers within
15 seconds on:

| Port | Connection |
|---|---|
| 389 | LDAP, StartTLS available |
| 636 | LDAPS |

The certificate is the portal's own (systemd hands it to the service; `aipbx-cert` restarts the
service when the certificate changes). The page shows the values to enter on the phone:

| Setting | Value |
|---|---|
| Base DN | `ou=phonebook,dc=aipbx` |
| User (bind DN) | `cn=phonebook,dc=aipbx` (some phones: `phonebook`) |
| Password | made when you turn it on; **Make a new password** replaces it |
| Name filter | `(\|(cn=%*)(sn=%*))` |
| Number filter | `(\|(telephoneNumber=%)(mobile=%)(homePhone=%))` |
| Name attribute | `cn` |
| Number attributes | `telephoneNumber mobile homePhone` |

- Entries: every active extension (not fax lines) with its number as `telephoneNumber`, and every
  contact with `cn`, `displayName`, `givenName`, `sn`, `o`, `telephoneNumber`, `mobile`, `homePhone`, `mail`.
- Numbers are compared by their digits, the last ten when both are that long: `+90 532 111 22 33`
  is found by `05321112233`, so the phone shows the caller's name.
- It can only be read: add, change and delete requests are refused. At most 500 entries per search.
- Reading without a password (anonymous) is off unless you allow it.
- The ports are closed by the firewall until you open them; open them on the **Firewall** page only
  for the network the phones are in.
