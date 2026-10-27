# `engine/` — desk phones found on the network

A small Python module that looks at the network the PBX serves and tells the portal what
desk phones are on it, so nobody types a MAC address.

```
python3 -m aipbx_engine once      # one detection cycle (cron: every 5 minutes)
python3 -m aipbx_engine status    # what the last run saw, read-only
python3 -m aipbx_engine check     # exit 0 when the engine could run
```

Nothing here provisions. It writes one table, `pbx_phone_discoveries`, and the portal
(`web/src/services/PhoneDiscoveryService.php`) decides what happens to each row. The install
wires both halves into one 5-minute cron entry (`/usr/local/sbin/aipbx-phone-discovery`), so
the decision is always made on data that was just read.

## What it does *not* do

- It never writes `pbx_phones`, a provisioning token, an admin password or a phone file.
- It never provisions a MAC that is already a phone: those rows are marked `provisioned` and
  are listed on the page as "on the network". The only code that creates a phone is
  `PhoneProvisionService::upsertPhone()`, which every other path (the form, the CSV import,
  the waiting list) already uses.
- It never turns discovery into a second source of truth: turn it off on the page
  (*Phones → Discovery*) and the next run reads nothing at all.

## Sources

| Source | Sends packets? | What it sees | Setting |
|--------|----------------|--------------|---------|
| `lease` | no | phones that took an address from AiPBX's own DHCP server | always on |
| `arp` | no | anything on the local segment that talked recently (`/proc/net/arp`) | always on |
| `journal` | no | dnsmasq's DHCP replies, so a phone is still seen after the lease file was rotated or dnsmasq restarted | always on |
| `sweep` | **yes** | the same as `arp`, but for every address, so an idle phone answers too | `provision_discovery_sweep` + `provision_discovery_networks` |

Only a MAC whose prefix is in `PhoneModels::OUI` (mirrored in `oui.py`, kept honest by
`tests/test_parity.py`) counts as a phone, so laptops, printers, access points and the PBX
itself are filtered out. Anything unrecognised is ignored entirely — it is not even recorded.

The sweep is bounded on every axis: at most 1024 addresses per network, two networks per run,
about 30 seconds of wall clock, one UDP datagram per address with a 10 ms gap. It needs no raw
socket and no root: sending to a closed port makes the kernel resolve the address to a MAC,
which is the ARP exchange that puts the machine in the neighbour table. When the budget runs
out the rest of the network is left for the next run, and the log line says so.

## Settings

The engine has no configuration file. It reads the same two places as the rest of AiPBX:

- `/etc/ai-pbx.env` for the database credentials (the file the portal and the signaling daemon
  read), and
- the portal's `sys_settings` table for the switches an administrator edits on the page.

| Setting | Default | Meaning |
|---------|---------|---------|
| `provision_discovery` | `1` | off: the engine reads nothing at all |
| `provision_discovery_sweep` | `0` | the ARP sweep, off until asked for |
| `provision_discovery_networks` | *(empty)* | CIDRs or addresses the sweep may touch |
| `provision_discovery_pool` | *(empty)* | extensions an automatic run may take (`5000-5099`) |
| `provision_discovery_models` | *(empty)* | `vendor=model` per line; a vendor not listed is never automatic |

The defaults live in `engine/aipbx_engine/config.py` and
`PhoneDiscoveryService::SETTINGS`; `tests/test_parity.py` fails when the two drift apart.

## Files it writes

| Path | Purpose |
|------|---------|
| `/var/lib/aipbx/engine/engine.log` | one line per run (counts, which sources answered), truncated to 512 KiB when it grows |
| `/var/lib/aipbx/engine/state.json` | the last run's counters and the devices it saw; read by `aipbx_phone_discovery.php status` and shown on the page |

Both are `0600`, owned by the user the cron entry runs as (`www-data`), so the portal can read
them without either process needing a secret of its own.

## Checks

```sh
cd engine && python3 -m unittest discover -s tests -v      # unit + parity tests
python3 -m aipbx_engine check                              # on a real box, as www-data
php ../web/bin/aipbx_phone_discovery.php status            # what the portal would do
```

`tests/` uses only the standard library: the readers are given fixture files (a lease file, an
ARP table, dnsmasq log lines), and the database, the journal and the network are stubbed, so
the suite runs anywhere — no PBX, no root, no network.
