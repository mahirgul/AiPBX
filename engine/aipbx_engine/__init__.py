"""AiPBX desk-phone discovery engine.

Finds the desk phones sitting on the networks the PBX serves and records them
for the portal's Phones page (PBX -> Phones -> *Discovered on the network*).

What it does, and what it deliberately does not
-----------------------------------------------

It **observes**. Every fact it gathers is written to one table,
`pbx_phone_discoveries`. The portal
(`web/src/services/PhoneDiscoveryService.php`) is the only thing that turns an
observation into a phone, so tokens, encrypted admin passwords, vendor
templates and the TFTP export all keep living in one place
(`PhoneProvisionService`) and there is exactly one provisioning path.

It never touches a phone that is already provisioned: a MAC that exists in
`pbx_phones` is listed as such and never re-created, and the upsert below never
rewrites the status of a row the portal has already decided on.

Sources, in the order they are read
-----------------------------------

    lease    the AiPBX DHCP server's lease file (Network services -> DHCP)
    arp      the kernel's neighbour table (/proc/net/arp)
    journal  dnsmasq's own DHCP messages, so a phone that asked is still seen
             after the lease file was rotated or the server restarted
    sweep    an ARP scan of the networks in `provision_discovery_networks`;
             switched off until an administrator turns it on, because it is the
             only source that sends packets of its own

The first three send nothing and need nothing but a readable file, so the
engine is useful on an installation that never turns the sweep on.

Run it with ``python3 -m aipbx_engine once``; see engine/README.md for what an
administrator sees and engine/tests for what is pinned down.
"""

from __future__ import annotations

__all__ = ["__version__"]

#: Printed by ``python3 -m aipbx_engine version`` and written into the log line
#: of every run, so a log file says which engine produced it.
__version__ = "1.0.0"
