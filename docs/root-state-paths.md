# Privileged job state and portal input

Privileged jobs keep their output in `/var/lib/aipbx-system`, owned by
`root:www-data` with mode `0750`. The portal can read public job status,
backup retention, the installed version and sound-pack metadata. It cannot
create, replace or delete entries there. Network rollback data and upload
snapshots are in root-only `0700` subdirectories (`netcfg` and `private`).
`/var/lib/aipbx` remains the portal's writable data directory; do not put
root-written state or temporary output in it.

The root-owned `aipbx-system-state prepare` helper initializes the namespace
under a root-only lock. It rejects symlink directories, non-root ownership
and writable group/other permissions, and clears inherited ACLs. Existing
unsafe namespaces require administrator investigation rather than recursive
ownership repair.

On the first upgrade, the helper imports validated backup retention, the
installed version and sound-pack catalog/manifests from the old directory.
It pins each input directory and file descriptor, refuses symlinks, hardlinks,
special files and oversized input, bounds catalog/manifests, and regenerates
sound target paths from validated package identifiers. It checks all input
before writing imports. A protected marker prevents subsequent imports.
Cached progress/check results and restore tokens are discarded. The first
upgrade can therefore reset progress displayed by an older running updater;
its logs remain available. Finish or revert any pending network change before
upgrading; migration refuses a pending legacy network operation. The old
network rollback files are never loaded by the new network helper.

Postfix credentials and Fail2ban overrides travel over stdin to `aipbx-priv`.
The helper captures them once in a root-private temporary file, validates that
copy and installs it. Postfix source and compiled credential maps are root-only
`0600`. No credential staging file is written by PHP.

For certificate, DNS credential and backup uploads, root opens every portal
directory with `O_NOFOLLOW`, pins a bounded, regular, single-link, portal-owned
file and copies it into a root-private snapshot. Certificate/archive validation
then uses that snapshot, including when the portal changes the original file
concurrently. Root never unlinks certificate staging paths; PHP removes its
own upload files after the command finishes. Never validate an untrusted path
and reopen it later for a privileged write or import.

Run `python3 scripts/test_system_state.py` locally. In a disposable installed
PBX only, run `sudo env AIPBX_E2E=1 python3 scripts/e2e/state_security.py`.
The integration checks exercise the legacy retention symlink attack, write and
rename denial as the web user, upload path attacks and real Postfix/Fail2ban
stdin installation. They temporarily replace test configuration and restore
it afterward. These checks complement fresh-install and upgrade tests.

This policy covers job state and input transfer. Portal secrets, plugin storage
and Asterisk-owned media require their own ownership and privilege policies.
