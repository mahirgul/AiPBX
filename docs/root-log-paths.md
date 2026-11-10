# Privileged job logs

`/var/log/aipbx` is `root:www-data 0750`. Backup, restore, update and sound-pack
logs are regular, single-link files owned by `root:www-data` with mode `0640`.
The portal reads these files; it cannot create or replace directory entries or
open these four logs for writing. Keep the web account out of the system logging
group that can manage the parent `/var/log` directory.

The exception is `web_login_failures.log`, owned by `www-data:adm 0640`. PHP
appends authentication failures to that existing file. Its parent is not writable
by PHP. Logrotate recreates this file with the same owner and mode; Fail2ban follows
the rotated path.

Every privileged writer calls the installed root-owned `aipbx-log-init` before
any log redirection. It rejects a symlink for the directory, fixes directory
ownership and mode, clears legacy access/default ACLs and uses a root-only lock
under `/run`. It never accepts arbitrary paths or Apache/PHP input.

Old unsafe entries are moved, without following links or changing their targets,
into a root-only `.legacy-*` directory. A new safe inode replaces them; an attacker
holding the old writable descriptor cannot modify the replacement. Up to the last
8 MiB of a regular single-link log is retained in the visible file. The full old
entry remains in the private archive for administrator inspection. Symlink,
hardlink and non-regular contents are not copied. Existing safe root log inodes
are retained so an in-progress updater's stderr remains attached.

The four root logs use logrotate `copytruncate` to retain long-lived stderr
descriptors. As with any copytruncate rotation, a small concurrent logging window
can lose diagnostic bytes; job status is stored separately. See the
[logrotate manual](https://github.com/logrotate/logrotate/blob/main/logrotate.8.in)
for this copytruncate tradeoff. The login log uses
rename/create rotation. Both policies retain 14 rotations with compression and a
10 MiB size trigger. The private legacy archives are not recursively rotated or
deleted by the application.

This policy covers logs. Other root-written state or staging files need their
own protected parent or a validated descriptor-based transfer. A `mktemp` filename
inside a web-writable directory is not sufficient when a later command reopens it
by pathname. Do not extend this log helper to accept arbitrary filenames.
