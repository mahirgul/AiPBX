# AiPBX — Changelog (1.6 Series)

← [Current Changelog (1.7 Series)](CHANGELOG.md) | [1.5 Series](CHANGELOG-1.5.md) | [1.0 - 1.34 Archive](CHANGELOG-archive.md)

---

## 1.61.2

- **A phone keeps its region when you edit it.** The form always showed the default region, and saving it without a change moved the phone there.
- **Copy URL gives the whole address.** For Snom and Cisco SPA it left out the file name, so a phone given that address found nothing. A second button copies the HTTPS address when the first is plain HTTP.

## 1.61.1

- **Link anyone to a fax unit.** Fax Units can list the people who use a unit, such as extension users, so nobody needs a second account for fax. They see its numbers under Fax, and can send from them. A unit can have no login of its own, and its e-mail address no longer has to be unique.
- **Inbound routes can start with +.** The form dropped it without a word, so a carrier that delivers +1844… could not match a route written that way. Thanks to @quietvw for reporting it (#150).
- **Preview as a fax works again.** The button sent the form to its own page: on Fax Units it saved the settings instead of showing the cover, and on Send Fax it asked for a document first.
- **Updates finish sooner.** The test of every page now runs after the update, and the System Update page tells you if it found a problem.

## 1.61.0

The same code as 1.60.28, numbered for what 1.60.26 to 1.60.28 add together:

- **Phones on TCP and TLS get their calls,** and a new Transports page and SIP settings on each extension let you choose how phones connect.
- **Provisioning has its own menu,** with regions for phones inside and outside your network, Snom D865 and Snom XML in model settings.
- **Advanced Configuration** reaches more Asterisk files, groups of extensions and trunks, new objects and raw text.
- **Fax:** one number for voice and fax, fax users in one place with several or shared numbers, and cover templates for each user.

After updating, phones on TCP or TLS need no change. The fax user role no longer deletes received faxes: grant it under Roles if needed.

## 1.60.28

- **Phones on TCP or TLS now get their calls.** They registered fine but no incoming call reached them, as every extension was tied to UDP. A call now goes back over the transport the phone registered on. This also applies to TLS set in provisioning or in a region.
- **Transports page.** See UDP, TCP, TLS, WS and WSS in one list, change an address or port, turn TCP and TLS on or off, and add your own transports, each with its own public address if you need one. A new port opens in the firewall, and the page says when Asterisk needs a restart.
- **More SIP settings on each extension.** Transport, DTMF mode, media encryption, NAT options, codec order, devices at once, check interval and prompt language.
- **TCP for desk phones.** Provisioning and regions can now choose TCP (Yealink, Grandstream, Fanvil, Snom, Cisco SPA, Poly). A region can also name the SIP port of a transport added for it.
- **One number for voice and fax.** An inbound route can listen for a fax tone first: a fax goes to fax, everyone else to the route's destination. Callers hear ringing for those seconds, 5 by default. Thanks to @quietvw for the request (#151).
- **Fax users are managed in one place.** Fax Units now adds a fax user with their login, e-mail and numbers in one form. They no longer appear under Extensions or System Users. One user can have several numbers, separated by commas, and chooses which to send from. Write a number for two users and they share it: one copy, seen by both, with an e-mail to each who wants it.
- **Cover templates for each user.** A user can keep their own templates and choose one when sending; the organisation's stays the default.
- **Deleting received faxes is granted by role.** The fax user role no longer has it. Grant it under Roles, on a role of its own if only some should have it; fax users can have that role.

## 1.60.27

- **Regions for desk phones.** Phones can get different addresses and settings by region: SIP server, outbound proxy, address for phone files, transport, SRTP, codecs, NTP, syslog, time zone and language. Internal and External come ready, you can add more, and you choose the region on each phone. New phones start in Internal, and nothing changes until you fill a region.
- **Provisioning has its own menu.** Phones, Regions and phone logs now sit under Provisioning.
- **Snom model settings take XML.** Paste single lines or a whole Snom file; attributes such as `perm` and a key's `label` reach the phone.

## 1.60.26

- **All services on the dashboard.** Every service of the server is listed with its state and a restart button, not only four restart buttons.
- **Advanced Configuration is a list with an Add button.** Adding and editing open in a window, and a save Asterisk refuses keeps what you typed.
- **Opus first for desk phones on new installations.** The order is Opus, G.722, PCMA, PCMU, and a phone without Opus uses the next one it has. On an existing server, tick Opus under PBX settings and Phones to use it.
- **Advanced Configuration reaches more files and whole groups.** Beside PJSIP you can add lines to rtp, features, queues, voicemail, musiconhold, cdr, logger, manager, udptl, indications and confbridge; they live in their own file, so Apply and updates do not replace them, and a set Asterisk refuses is rolled back. A PJSIP entry can apply to all extensions (SIP), all trunks or one phone model. Options that run a program or open AMI to others cannot be set.
- **Advanced Configuration helps you write it.** Find an option offers Asterisk's names with a short description, and recipes fill the form for NAT, voice priority, P-Asserted-Identity, codec order, voicemail time zone and a hand-written trunk.
- **New objects, raw text, export and import.** Create PJSIP objects AiPBX does not generate, write your part of a file as text, see line changes while editing, and move the list between servers. Each entry says whether a reload is enough, and whether your value replaces a generated one or has no effect.
- **Snom D865 provisioning.** The phone gets its account, 40 function keys on 4 pages and the logins of its new web interface. An expansion module is not written yet.
- **Model settings always win.** A setting you add for a phone model replaces the one AiPBX writes, whatever its case, so the file holds one value. Snom phones now take model settings too: `name.2` means idx 2, `fkey.0` sets a function key.

## 1.60.25

- **Fix: installing and updating stopped at the firewall step.** 1.60.24 stopped there on any server without a card that has its own firewall rules, so an update failed and a new install did not finish. Update from an earlier version straight to this one.

## 1.60.24

- **Network settings is simpler.** One row per network card with its IP address, subnet mask, gateway and DNS, and an Edit button beside it. Gateway and DNS may stay empty on an inner card, a new card with no configuration can be set up here too, and every interface has its Edit button. Confirming a new address now also moves the phone files, settings and certificate, so *Apply the new address* only appears when the address changed outside this page.
- **Firewall rules per network card.** Each card has its own tab, so you can close the web page on the internet card and keep it on the office card. All rules now look the same, including SSH and web, which were not listed before. Nothing is locked for an admin any more: before a rule that can cut your connection, calls or phones goes, the page says what it will do.
- **Each card can have its own router.** One card holds the server's default gateway; another keeps its router for traffic that arrives on it, and the routes behind a router go in the same Edit window. Cards under NetworkManager can now be edited too.
- **Servers with several cards.** The networks of the server's own cards count as local without being typed in. TURN now follows new card addresses and no longer settles on a VPN tunnel address.

## 1.60.23

- **Adding a user takes two fields.** Only the username and extension are required, and they are now one under the other at the top of the form. An empty password is generated, as an invitation or a one-time password shown to you, and an empty full name becomes the username.

- **See and set the server's own address.** Network settings now shows whether each network card is on DHCP or a static address, and can change it, with the gateway and DNS servers. A wrong address cuts a server off, so the change goes back by itself after 3 minutes unless you open the portal at the new address and confirm it. Only cards managed with netplan can be changed; others are shown.

## 1.60.22

- **Model settings for desk phones.** The Phones page can add settings to the files of every phone of one model, one `name = value` per line, such as a dial plan for NEC phones. They are kept in the database, so updates keep them, and they win over what AiPBX writes. Available for NEC, Yealink, Grandstream and Poly.

## 1.60.21

- **The caller header can be seen and changed on each desk phone extension.** The extension form now has a *Send the caller in a header* box, on by default, as it has been for every desk phone since 1.60.16. It can be turned off for a phone that has trouble with this header.

## 1.60.20

- **One time zone for the whole system.** PBX Settings → Language now sets the time zone of the server, the portal, call records and time conditions together. Every installation's portal used to be fixed to Europe/Istanbul while Asterisk followed the server's clock, so the two could differ by hours; the page warns when they do. A new installation starts on the server's zone. An update changes nothing by itself: a new zone moves working hours, so an administrator chooses. Changing it restarts the database, Asterisk and the portal. Thanks to @quietvw for reporting this.
- **Ring and busy tones by country.** The same page sets them, without disturbing calls. Every installation was using the United States tones. Turkey is added to Asterisk's list.

## 1.60.19

- **Mail on a sign-in from a new network.** When an account signs in from an internet network it has not used in the last 90 days, its owner gets a mail with the address, time and browser. It covers every way in: password, two-factor, single sign-on, passkey, QR and the mobile app. A stolen password that works looks like a normal sign-in to the lockouts and Fail2ban, so this is what tells the owner. On by default; Appearance → Sign-in alerts turns it off, and the text is on the Mail templates page.
- **You choose what happens to calls when an update must restart Asterisk:** wait until they end, or end them. While it waits, the page offers *End the calls now* and *Cancel the update*, so a critical update is not held up by a long call. The updater now hangs calls up cleanly first, so they keep their call records. On the command line: `--wait-calls` or `--allow-calls`, and at a terminal it asks.
- **Files changed by hand no longer stop an update.** They go into the update's backup and stay in place, unless the new version changes the same file. Then the new version is used and the log names the file.
- **The update says what it is doing.** A log line about calls could be read as calls blocking the update, although none did, and the last step tested every page for minutes in silence (three minutes on a one-CPU server). Both now say so plainly.
- **An update cannot hang on a stuck service.** Questions to Asterisk and restarts of Asterisk and coturn have a time limit. A failed page test after an update is now a warning when the services and the portal work, not a reason to go back. An update that died, for instance in a reboot, no longer blocks the next one.
- **VPN delete buttons always ask first.** In French and Ukrainian the question never appeared and a click on the VPN Client page deleted the connection at once.
- **A failing VPN helper no longer changes your settings.** It could take the VPN networks out of Asterisk's local networks, which broke calls over the VPN at the next Apply. Pages and the health check now say the helper did not answer, instead of showing the VPN as off.
- **An uploaded backup's database no longer runs as MySQL root.** It runs as the migration user, which has rights on this one database only, so a prepared dump cannot create accounts, load plugins or write files.
- **A fax unit cannot take the number of a queue, IVR or other internal number.** Dialling that number used to reach the fax instead. Sharing a number with its fax user stays normal. Numbers can hold only digits and an optional leading +.
- **OIDC sign-in checks the signature of the ID token** against the provider's published keys (RS256/384/512, ES256/384, or HMAC with the client secret). A provider that signs in another way, such as PS256, is refused.
- **Audit log:** backup and restore actions now name who did them, saving a fax unit no longer writes two rows, and password reset requests are recorded (the row did not fit before and was lost).

## 1.60.18

- **VPN Server and VPN Client pages (Network & devices).** Choose WireGuard or OpenVPN. The server gives each phone, softphone or laptop its own keys and a profile to download (a QR code for WireGuard); deleting a device shuts it out at once and leaves the others connected. The client connects the PBX to another network by uploading a `.conf` or `.ovpn` profile, or pasting it. VPN networks become local networks for Asterisk, so a phone on the VPN gets the right address in its calls. See `docs/vpn.md`.
- **A VPN profile cannot run commands on the server.** Profiles are read against a list of allowed settings and written out again, so `PostUp`, `up`, `plugin`, `script-security` and files named by path are refused. Keys are kept in a folder only root can read and are part of the daily backup; restoring an uploaded backup leaves them out.
- **A VPN cannot take over the PBX's traffic.** The default route, name servers and routes sent by the far side are never taken. VPN devices reach this server only, not other devices or the networks behind it. An OpenVPN or WireGuard the server already runs by itself is left alone.

## 1.60.17

- **Updates work again on systems with TFTP switched on.** 1.60.16 stopped during the update on such a system and went back to the previous release, with nothing lost. Skip 1.60.16 and take this one. Other systems are not affected.

## 1.60.16

- **Desk phones show who is calling:** NEC DT700/DT800 phones showed "asterisk" instead of the caller's number. Extensions now send the caller in a header these phones read. A caller who hides their number stays hidden.
- **"Another admin is currently active" can be read in the light theme.** The text was white on no background.
- **Network services: another TFTP server on the same port is now reported.** If a separate program such as tftpd-hpa held UDP port 69, TFTP restarted for ever without a word, and phones got that program's files. The page now refuses with the program's name, the health check says why, and an update warns.
- **A number of ours is never sent to a trunk:** a fax user's or Fax Unit's number that also matched an outbound route used to leave through that trunk, so a fax for a local number went to the other exchange. It now reaches the fax receiver here. A call arriving from a trunk with transit allowed also looks at extensions first. Numbers that are not on this system still follow the routes.
- **Fax numbers are matched exactly:** the old rule that treated 19350 and 9350 as the same number is gone. A fax user gets the faxes of their own number and of the Fax Units linked to them. If you relied on that rule, add a Fax Unit for each such number before you update, or its faxes send no mail and stay visible only to administrators.

## 1.60.15

- **Sign-in only through the identity provider: updates work again (update soon if you use this).** Every update rolled back, the health check reported a failure, and the page tests failed, because `/login` redirected and `/forgot-password` showed nothing. Now only a browser is sent on to the provider. The sign-in page of this mode also has its logo and layout now.
- **Fax: the Write Text editor did not appear** on the first visit through the menu, so the box stayed empty. It was a script loading order problem, which is fixed for every page. The Send Fax page uses the full width, its toolbar follows the dark theme, and the button says Send Fax. **Fax Units** is in the Fax menu.
- **Scheduled Tasks, External Databases, IVR data steps and Custom Dialplan:** their page scripts did not run at all, so their buttons did nothing. Text inside the script was escaped for HTML.
- **New: Backup & Restore page** (Administration). List backups, make one now, download or upload one, and restore everything or only the database, also on another server. A backup of the present state is made first and put back if the restore fails. Uploaded backups are checked and cannot run anything on the server. See [Backups](docs/backup-and-rollback.md).
- **A plugin zip may unpack to at most 200 MB.** The upload limit counted only the compressed size.
- **`aipbx-update`:** if restoring the database fails during a rollback, it tries again and then stops with the command to run by hand, instead of starting the services on half a database. Services are now checked twice after an update, so one that keeps crashing is caught.
- **Chat:** a slow line can now finish a 25 MB upload or download (it was cut off after 30 seconds). A replaced group picture is deleted. Two people opening the same new chat at once no longer get an error for one of them. Chat, DHCP/TFTP, FTP and the mDNS alias run with tighter system limits.
- **Audit log:** when a record cannot be written, it now goes to the error log instead of disappearing. API changes to plugin data made with an administrator token are recorded.
- **Custom Dialplan:** a row with a file directive or a reserved context name, which the portal refuses, is now also skipped when it arrives through a restored backup or SQL.
- **Passkey sign-in** no longer shows whether a user name has passkeys.
- **A double quote in an extension's display name** no longer breaks its caller ID.
- **Upgrades** work when a database password holds a quote or a backslash.
- **Translations:** all 23 languages are complete again.

## 1.60.14

Found on a live system whose fax account was taken over, and in a code review.

- **Caller ID override on a route now overrides:** on outside routes it lost to the user's External Call CID and to the trunk's number, so it worked only when both were empty. If you filled one in and it had no effect, your calls will now go out with that number: check your routes before updating.
- **Faxes per hour:** a user can now send 30 faxes an hour, and deleting faxes does not free the limit. Change it under Fax settings (0 turns it off). Administrators are not limited.
- **A number that cannot be dialled is refused at once:** a fax to a number with no outbound route used to wait out every retry and then read "no answer".
- **Changing a password now ends web sessions:** before, it ended only the sessions on phones, so a stolen browser session stayed signed in. Disabling an account ends its sessions too. Whoever changes their own password stays signed in. After five wrong current passwords on the Security page, the session ends.
- **Custom Dialplan in Turkish deleted without asking:** an apostrophe in the translated question broke the confirmation. It now asks.
- **`install.sh` on an installed system now stops:** a run without `--upgrade` reset the admin password and made new secrets. To do that on purpose, use `--reinstall`.
- **Chat:** two admins leaving a group at the same moment could leave it with no admin. If the delete time limit cannot be read, 5 minutes applies instead of no limit.
- **OpenID Connect:** the sign-in token must come from the configured issuer and must carry an expiry and an audience. Tested in code only, not against a real provider: please report if your provider's sign-in stops working.
- **REST API:** deleting a queue now needs `queues:write`, the same as creating or changing one. A token with only `pbx:write` can no longer delete queues.
- **Webhooks:** a host name that cannot be resolved now fails the delivery.
- **Administrators setting a password:** at least 8 characters, the same as everyone else.
- **Signaling capture** no longer runs as root.

## 1.60.13

- **New guides:** [how numbers change on a call](docs/number-normalization.md), with the order of every setting and a diagram, and [running AiPBX in an Incus or LXD container](docs/incus-lxd.md).
- **New installations start in English:** the default calling permission group, some feature code titles and the User role were created in Turkish. Existing installations keep the names they have.

## 1.60.12

- **Cancel in a confirmation did nothing (update soon):** on most portal forms, pressing Cancel in "Are you sure?" still carried out the action, including deleting. Cancel now stops it.
- **Inbound Call Normalization:** loading a country's ready-made rules now asks first and replaces the existing rules, as on the outbound page.

## 1.60.11

- **Outbound Call Normalization: Add, Edit and Copy did nothing:** the rule window never opened, because the page called a function that does not exist. Fixed.
- **Inbound Call Normalization** now looks and works like the outbound page.
- **Ready-made normalization rules are in JSON files:** one file per country, which you can override under `/var/lib/aipbx/normalization_presets/`. A new installation loads none of them, on the inbound or outbound page, until an administrator asks.

## 1.60.10

- **Single Logout:** signing out of AiPBX can also end the session at the SAML or OpenID Connect provider. Off until you set it up in the plugin.
- **Inbound Call Normalization:** a new page rewrites the caller's number or the dialled number of incoming calls, for one trunk or all of them, with rules for a country and a test field. The earlier page is now called Outbound Call Normalization. Thanks to @quietvw for the request (#149).
- **SIP headers per trunk:** a trunk can add its own SIP headers to outgoing calls.
- **Limits in the help:** the help boxes of Reverse Proxy, Trunks, Advanced Configuration, Network settings, Video Meetings, Appearance and Fax Units now say what cannot work, and why.

## 1.60.9

- **Sign in only through the identity provider:** passwords, passkeys, Google and the password reset can be turned off for signing in, so everyone goes through SAML or OpenID Connect (Appearance). It can only be turned on from a session that signed in through that provider, and a one-time link from the server's command line gets an administrator in if the provider is down. Thanks to @quietvw for the request (#149).

## 1.60.8

- **A trunk's caller ID kept losing its leading `+`:** the outbound caller ID of a trunk and a route's caller ID override dropped the `+`, so carriers that require `+E.164` in the caller ID could refuse the call. It is now kept.

## 1.60.7

- **Fax cover page:** faxes sent from the portal can start with a cover page: organisation name, logo, confidentiality notice, recipient, subject, message and page count. It can be off, up to the sender, or on every fax (Fax Units), and it can be previewed in black and white as the far end receives it. Thanks to @quietvw for the request (#149).

## 1.60.6

- **Outbound routes kept losing a leading `+`:** a route's Prepend field dropped the `+` of `+1`, so carriers that require it refused the call. It is now kept.
- **Trunks can send E.164:** choose a country on the trunk and numbers go out as `+<country code>...` with that country's dialling plan, without doubling a code that is already there. Short numbers such as emergency numbers are left alone. Try a number on the same page. Thanks to @quietvw for the request (#149).
- **Reverse proxy settings did not work:** the configurations generated on the Reverse Proxy page and the guide sent WebSockets to Asterisk's port 8089, which only listens on loopback, so softphones behind them could not connect. They now go through the portal's own port, which also works for a proxy on another server. The firewall commands no longer open 8089.
- **A wrong trunk parameter no longer loses the trunk:** a mistyped option in a trunk's custom parameters looked fine after a reload, and the trunk's endpoint disappeared at the next Asterisk restart. Option names are now checked when you save. Check any custom parameters you already have.
- **Advanced Configuration:** add your own PJSIP options to any generated transport, endpoint, AOR, auth, identify or registration. They survive regeneration and updates, are checked against Asterisk before saving and after applying, and have versions you can restore. A generated file changed by hand is copied before it is replaced, and the copy is listed there.
- **Behind NAT:** the public address can be a host name such as dynamic DNS, and media can have its own public address. A new NAT check on the Network settings page looks for the usual causes of no audio or one-way audio.
- **Default theme:** administrators can set dark as the default for the sign-in page and for users who have not chosen a theme, or use one theme for everyone (Appearance).
- **Cloudflare Turnstile:** can replace the sum on the sign-in and forgotten password pages (Appearance). Off by default.

## 1.60.5

- **Video meetings: guests were dropped after 30 seconds (fixed, update if you run 1.60.4):** 1.60.4 added an RTP timeout to meeting guests, and Asterisk does not see bundled WebRTC video as active, so every participant was disconnected. A browser that vanishes without saying goodbye is now found by SIP session timers within about two minutes.
- **Video meetings: video starts right away:** rooms of meetings no longer record each person's name or play join announcements, which held video back for over 30 seconds. Existing meeting rooms are updated.
- **Video meetings: a new meeting works at once:** its room used to wait for an administrator's *Apply*, and until then guests were refused. The room now goes live when the meeting is saved.
- **Video meetings:** the empty *Participants* tile is gone.

## 1.60.4

- **Command execution through the emergency Geolocation URI fixed (security, update now):** the URI of an outbound route went into the dialplan unchecked, so anyone allowed to edit outbound routes, or an API key with `pbx:write`, could run commands as the `asterisk` user. The URI is now validated on save and when the dialplan is generated.
- **Emergency location was cut short:** a `geo:` URI with parameters (`geo:41.00,28.97;u=15`) lost everything from the semicolon on, and the closing bracket with it, because a semicolon starts a comment in the dialplan. It now reaches the carrier whole.
- **Scheduled tasks can no longer become root (security):** the root runner wrote each script into the folder the task itself can write to, so a task could plant a link there and take over any file on the next run. The task folders moved to `/var/lib/aipbx-tasks` (root's, existing data moves along) and scripts to a root-only place. Tasks can no longer read Asterisk's configuration (with SIP passwords), the portal or other tasks' folders. A deleted task's folder is removed within a minute.
- **Stored XSS on the meeting page fixed (security):** a meeting title could run script on the public `/meet` page, in the session of whoever opened the link.
- **SAML 2.0 sign-in bypass fixed (security, update if the SAML plugin is on):**
  - Anyone with an account at the Identity Provider could sign in as any other user, administrators included, by adding an unsigned NameID inside an extra `ds:Signature` element. The signature check now accepts one signature only and reads only the signed content.
  - A response must answer a sign-in started in the same browser and is accepted once. **Sign-ins started from the Identity Provider's own portal (IdP-initiated) are no longer accepted:** start from the AiPBX login page.
  - The bearer subject confirmation, its expiry and the audience are now required; only the transforms and algorithms of the SSO profile are accepted; a DOCTYPE is refused.
  - A disabled SAML plugin no longer accepts responses through its stored certificate.
  - Fixed: mobile SAML sign-in fell back to a web session, because the Lax session cookie is not sent with the Identity Provider's POST (the request now has its own short-lived cookie). Fixed: valid responses were refused when namespaces were declared on the Response element.
- **Video meetings: guests reach only their own meeting (security):**
  - Every `/meet` page shares one SIP password, with which anyone holding a meeting link could enter every active conference room without a PIN. Each call now carries the page's token and Asterisk checks it (`[app-meeting-guest]`): it must belong to this room and the meeting must be open.
  - Fails closed: a failed check refuses the call. A meeting without guests no longer accepts its link at SIP level either; signed-in users and invited guests still join.
- **Video meetings: join window and limit are enforced:** from 30 minutes before the start until one hour after the end, with clear pages outside of it; existing links of past meetings stop working. `max_participants` is enforced for browser participants. A browser that disappears without hanging up frees its seat after 30 seconds without media.
- **Video meetings no longer take over conference rooms:** scheduling a meeting on an existing room number replaced that room's title and PINs, and cancelling the meeting deactivated the room. Such a number is now refused.
- **Custom dialplan:** `#include`/`#exec` directives and extending reserved contexts (`[from-internal-pbx-ortak](+)`) are refused. The supervisor listen/whisper and DISA templates now carry a random PIN; the DISA template had `1234`. **Review recipes you already inserted from these templates:** existing entries are not changed.
- **Phone provisioning behind a reverse proxy (security):** the allowed networks were checked against the proxy's address, so with the proxy inside them anyone reaching it could fetch phone files with SIP passwords by MAC address. The real client address is checked now, and the website widget's rate limit uses it too.
- **Reverse proxy:** the default list of trusted proxies is now this host only. With private networks in it, any device on the LAN could spoof `X-Forwarded-For` and avoid sign-in lockouts. A saved list is not changed: **if yours holds whole private networks, reduce it to your proxy's address.**
- **SAML default role:** the roles the plugin offers (Call Center Manager, Call Center Agent, Read-Only Admin) were silently replaced by `user`.
- **Let's Encrypt with Cloudflare DNS:** the certbot plugin is now installed, without which the feature could not work; a successful run with a new token no longer ended in an error.
- **Local AI on arm64 (Raspberry Pi, Graviton):** the runtime could not be installed at all, because one package needed only by EMA Lightning has no arm64 build. That package is now left out on arm64, and EMA Lightning says it is not available on this processor instead of reading numbers wrong.
- **Phone discovery:** 25 phones of a vendor with no model configured could keep every other vendor's phones from being provisioned, one row that could not become a phone stopped each run at the same place, and one over-long DHCP hostname lost everything a run had seen.
- **Cisco 7900 firmware:** a phone's file no longer names a load from the per-model list while that load's files are missing on the server, so a working phone that restarts does not stall at *Upgrading* on a half-copied package. After copying the files, `aipbx_cisco_firmware.php` rewrites the phone's file.
- **Docs:** turning MPTCP off reduces exposure to CVE-2026-98432; the fix is the distribution's kernel update.

## 1.60.3

- **Security & RBAC Authorization Enforcement:**
  - Enforced strict module permissions (`meetings:edit`, `meetings:delete`) inside `MeetingController` POST action dispatchers (`save_meeting`, `cancel_meeting`, `add_invite`), preventing unauthorized meeting creation, modification, or cancellation.
- **Audit Logging Alignment:**
  - Corrected parameter ordering in `MeetingService::saveMeeting` and `cancelMeeting` so that entity IDs are logged to `entity_id` and action strings to `action`.
- **Chat Service Concurrency & Sync Hardening:**
  - Fixed read/delivered marker initialization in `AddGroupMembers`: newly added group participants inherit the conversation's `MAX(id)`, preventing spurious unread counts and read receipt regression.
  - Wrapped `UpdateGroupMemberRole` in a database transaction with `FOR UPDATE` row locking to prevent race conditions during concurrent admin role changes.
  - Eliminated data races in `hub.go` by copying message instances before concurrent broadcasts and preserving delivery statuses.
  - Secured attachment cleanup in `message_delete.go` by safely retaining files upon transient database connection failures.
- **Engine Diagnostic Resilience:**
  - Fixed `aipbx_engine check` false-positive failure on newly provisioned systems without existing log lines.
- **AI Service Robustness & Stability:**
  - Ensured atomic rollback in model catalog and manager if `DiskLimit` is reached during model installation.
  - Aligned call disposition recording in `VoiceRequestsCall` to reflect `"hangup"` when callers disconnect during playback.
  - Hardened Vosk/Whisper `pcm16_from_wav` audio decoding against empty or unaligned byte payloads.

## 1.60.2

- **WebRTC Meeting Lobby & Asset Path Fix:**
  - Fixed runtime fatal error in `/meet/{token}` lobby view caused by undefined `currentLang()` call; switched to system-standard `getUserLanguage()`.
  - Corrected FontAwesome CSS asset path to `/assets/css/fontawesome.min.css`.
- **REST API v1 Sub-Action Routing & 404 Guards:**
  - Enforced strict sub-action validation across PBX resource handlers (`/api/v1/extensions`, `/api/v1/trunks`, `/api/v1/conferences`, `/api/v1/ring-groups`, `/api/v1/time-conditions`, `/api/v1/time-groups`, `/api/v1/boss-secretary`, `/api/v1/dial-permissions`, `/api/v1/hangup-actions`).
  - Prevented unsupported sub-action calls from falling through to single-entity handlers, returning HTTP 404 (`NOT_FOUND`) as expected by REST specifications.
- **Database Test Fixture Isolation:**
  - Added `pbx_conferences`, `pbx_meetings`, `pbx_meeting_invites`, `pbx_ring_groups`, and `pbx_ai_apps` to `Fixtures::TABLES` cleanup list, preventing cross-test data pollution.
- **Regression Test Suite:**
  - Added automated tests for clean lobby view rendering and API v1 sub-action 404 responses.

## 1.60.1

- **Conference Voice Quick Dial & Interactive Join (`#9` / `*9`):**
  - Added configurable conference feature code (`#9` default, customizable to `*9` or custom code) for dialing conference rooms directly from mobile apps and desk phones (`#9<room_number>`).
  - Added interactive prompt extension (`#9` without room number) prompting callers to enter their target room.
  - Implemented dynamic ConfBridge routing context (`[app-confbridge-dispatch]`) with security guards preventing unauthorized guest creation.
- **REST API v1 Video Meetings & Conference Telemetry:**
  - Added `/api/v1/meetings` endpoints (`GET`, `POST`, `GET /id`, `PATCH /id`, `DELETE /id`, and `POST /id/invites`) to manage video meetings and dispatch invitations programmatically.
  - Added `/api/v1/conferences/{id}/members` endpoint for live participant telemetry.
  - Updated OpenAPI 3.1 schema specification (`/api/v1/openapi.json`).
- **Mobile API Conference Room Directory:**
  - Enhanced `GET / POST /api/mobile/features.php` to return active conference rooms and dial numbers alongside `conference_prefix`.
- **Internal Number Conflict & Room Protection:**
  - Enforced `internalNumberOwner()` conflict checks when scheduling video meetings to prevent collisions with existing extensions, queues, IVRs, or ring groups.
  - Added protection preventing deletion of conference rooms actively in use by scheduled meetings or PBX routing.
- **RBAC Matrix & Permission Enforcement:**
  - Registered `meetings` module in `RoleRepository` with dedicated Phinx migration and localized role matrix labels across all 22 languages.
  - Enforced fine-grained permissions (`can_access`, `can_edit`, `can_delete`) across `MeetingController`, `ConferenceController`, and views.
  - Extended `bin/smoke.php` RBAC audit coverage to newly added administrator-only routes.
- **Asterisk Sync & PHP 8.5 Compatibility Hardening:**
  - Included `helpers.php` dependency in CLI `asterisk_sync.php` ensuring seamless unattended updates.
  - Resolved PHP 8.5 deprecation and notice warnings in CSV processing and conference service.

## 1.60.0

- **Web Video Meetings & Guest Conference Scheduling System (`/meetings`) (Spec 004):**
  - **Calendar & Meeting Scheduler (`/meetings`):**
    - Schedule ad-hoc or planned video conferences with date/time pickers, topic, agenda, participant limits, and PIN protection.
    - Video architecture mode selection: **SFU (Selective Forwarding Unit)** multi-stream WebRTC gallery grid vs **Follow-Talker** active-speaker switching for SIP video phones.
    - Automatic ConfBridge room creation and dialplan synchronization via `SyncConferences.php` and `internal_numbers`.
    - Real-time meeting status badges (Scheduled, Live Now, Ended, Cancelled).
    - One-click .ics calendar file download and invite link copying.
  - **Public Guest Magic Link Gateway (`/meet/{token}`):**
    - Anonymous, zero-install, zero-registration browser portal for clients, partners, and external guests.
    - Interactive Pre-Join Lobby: camera preview check, microphone level inspection, device mute/camera toggles, display name input, and room PIN verification.
    - Live WebRTC Conference Room (`assets/js/meeting_room.js`):
      - Responsive CSS gallery video grid with dynamic layout resizing (`tiles-1` to `tiles-9`).
      - Local self-view with camera toggle and mute controls.
      - Screen sharing via `navigator.mediaDevices.getDisplayMedia`.
      - JsSIP WebSocket integration to Asterisk ConfBridge WSS endpoints.
      - Elapsed meeting timer and leave meeting dialog.
  - **ConfBridge SFU & Follow-Talker Dynamic Bridge Profiles (`SyncConferences.php`):**
    - Asterisk `app_confbridge` bridge profiles with `CONFBRIDGE(bridge,video_mode)=sfu|follow_talker`.
    - Keyframe and bandwidth optimization flags: `video_update_discard=0`, `remb_send_interval=1000`, `remb_behavior=average`.
    - Expanded WebRTC PJSIP endpoint streams (`max_audio_streams=10`, `max_video_streams=10` in `SyncExtensions.php`).
    - Standard Conference management (`/conferences`) now supports configuring Video Mode (`sfu`, `follow_talker`, `none`) and Max Video Streams directly from the admin panel.
  - **Email Invitations & ICS Calendar Attachments (`MailTemplateService.php`):**
    - Dedicated `meeting_invite` branded email template with host name, schedule, topic, PIN, and one-click magic join button.
    - RFC 5545 `.ics` iCalendar MIME attachment with VCALENDAR, VEVENT, UID, and join URL.
  - **Quality Gates, Tests & i18n:**
    - Comprehensive unit tests in `MeetingServiceTest.php` and `SyncConferencesCoverageTest.php`.
    - 100% translation key parity across all 22 languages (5,703 / 5,703 strings, 0 problems).
    - Full PHPStan analysis clean (0 errors across 381 files), and clean smoke test verification (`php bin/smoke.php`).
