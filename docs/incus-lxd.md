# Running AiPBX in an Incus or LXD Container

AiPBX manages a whole machine: systemd, the firewall, fail2ban, coturn, the 443 edge, DHCP/TFTP and `aipbx-update`. An
Incus or LXD *system container* is a complete Ubuntu, so the normal installer runs inside it unchanged, and everything
works as it does on a server. It is a good way to try AiPBX, to keep a server tidy, and to take a snapshot before an
update.

Docker is not supported, because it runs one program per container. See the [roadmap](roadmap.md), section 3.1.

Incus is the community continuation of LXD, and the two use almost the same commands. The commands below are for Incus.
With LXD, type `lxc` instead of `incus`.

> The VPN pages need the WireGuard kernel module on the host and `/dev/net/tun` in the container for OpenVPN (see [VPN](vpn.md)).

## What was tested

Tested on LXD 6.10 with an Ubuntu 26.04 image and 4 GB of memory, on its default bridge network. The install took about
6 minutes, with no failed self test. The end-to-end checks, call flow checks and a two-browser video meeting all passed.
Restarting the container, taking a snapshot and restoring it also worked.

Not tested yet: real phones or carriers from outside the host, a container with its own IP address on the LAN (below),
and Incus itself.

## 1. Create the container

```bash
incus launch images:ubuntu/26.04 pbx1 -c limits.memory=4GiB
incus exec pbx1 -- cloud-init status --wait
```

With LXD the image is `ubuntu:26.04`. The first download takes a while.

`systemctl is-system-running` inside the container can say `degraded` because of `console-getty.service`. That is normal
in a container and does not affect AiPBX.

## 2. Install AiPBX

```bash
incus exec pbx1 -- bash
apt-get update && apt-get install -y git
git clone https://github.com/mahirgul/AiPBX.git /opt/aipbx
cd /opt/aipbx
AIPBX_FQDN=pbx1.example.com bash install.sh
```

When it finishes, the login details are in `/root/aipbx-credentials.txt` inside the container.

Find the container's address with `incus list`. From the host, open `https://<that address>/`. The certificate is
self-signed until you set up your own.

## 3. Let phones and carriers reach it

On the default bridge, only the host can reach the container. A PBX needs many ports, including a large UDP range for
audio, so giving the container its own address on your network is the simplest way:

```bash
incus config device add pbx1 eth0 nic nictype=macvlan parent=eth0
incus restart pbx1
```

Replace `parent=eth0` with the host's network interface. The container then gets an address from your network, like any
other machine. With macvlan, the host itself cannot reach the container directly: use another computer. Run the NAT check
on **Network settings** afterwards.

For one web port there is also a proxy device, which is enough to try the portal from another computer:

```bash
incus config device add pbx1 web proxy listen=tcp:0.0.0.0:8443 connect=tcp:127.0.0.1:443
```

It does not suit SIP and audio: SIP sees the wrong addresses behind it.

## 4. Snapshots and updates

```bash
incus snapshot create pbx1 before-update
incus exec pbx1 -- aipbx-update
```

If something goes wrong:

```bash
incus snapshot restore pbx1 before-update
incus start pbx1
```

A restore also goes back in time for call records, recordings and everything else in the container. In the test, a 3 GB
container took 39 seconds to snapshot and 11 seconds to restore on the plain `dir` storage driver. Calls in progress drop
when the container restarts.

## Several customers on one server

Running one container per customer, with a super-admin page to manage them, is planned. See the [roadmap](roadmap.md),
section 3.2. AiPBX assumes port 5060 in some places today, so a second AiPBX on the same IP address does not work yet.
Containers with their own IP addresses do not have this limit.
