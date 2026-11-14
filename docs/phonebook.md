# Phonebook

**Provisioning → Phonebook** keeps the company contacts everyone can reach (suppliers, customers,
branches) and serves them, together with the extensions, to desk phones over LDAP.

## Contacts

- Add, change or delete contacts by hand, or import a CSV file: name, company, work phone, mobile,
  other phone, e-mail (`,` or `;`; a header row is skipped). A contact needs a name (or a company)
  and at least one number.
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
