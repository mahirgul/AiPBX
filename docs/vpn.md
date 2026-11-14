# VPN server and VPN client

**Network → VPN Server** and **VPN Client**. Both pages are admin-only and everything is off by default.
You can choose **WireGuard** or **OpenVPN** on each page.

## VPN Server: devices reach this PBX

Use it for phones, softphones and laptops outside your network.

1. Choose the type. WireGuard is the simpler and faster one; use OpenVPN when a device needs it or when only TCP gets through a firewall.
2. Enter the public address of this server (IP or host name), the port and a private VPN network that is not used anywhere else. The port is opened in the firewall for you.
3. Add a device, then download its profile (or scan the QR code with the WireGuard app) and load it on the device. A profile holds the device's private key, and every download is in the audit log.
4. Register the phone to the server's VPN address, shown under **Devices** (the first address of the VPN network, `10.99.0.1` by default).
5. Press **Apply** so that calls use the VPN network.

Deleting a device shuts it out at once; the other devices stay connected.

**What a device can reach:** this PBX only: the portal, SIP, call audio, fax (T.38) and TURN. The server does not route between networks, so a device cannot reach other devices or the networks behind the server, and SSH, FTP and TFTP are not open to it.

Changing the type, the port or the public address means loading the profiles again. Devices of the other type are kept and work again when you go back. To change the VPN network, remove the devices first.

### Yealink phones

With the OpenVPN server, each device has a **Yealink** button next to **Download**. It gives the
package Yealink phones load: a `.tar` with `vpn.cnf` and a `keys/` folder (the device's certificate and key,
the server's CA and the `tls-crypt` key).

1. Add a device for the phone and press **Yealink**.
2. On the phone's web page: **Network → Advanced → VPN**, upload the `.tar`, turn **Active** on and confirm. The phone restarts.
3. Set the phone's provisioning server to the server's VPN address (**Settings → Auto Provision**), or
   register it to that address by hand.

The phone's OpenVPN must know `tls-crypt` (OpenVPN 2.4 or newer): firmware that only knows `tls-auth`
cannot connect. Newer options the phone does not know (`data-ciphers`) are skipped. Discovery by broadcast
does not cross the tunnel, so a phone behind the VPN does not appear under *Discovered on the network*.

## VPN Client: this PBX connects to another network

Use it for a SIP trunk or another PBX that is only reachable through a VPN, or to manage this server through a VPN.

1. Give the connection a name and upload the profile (`.conf` or `.ovpn`) or paste it. The type is found from the content.
2. List the **networks to reach through this connection**, for example the provider's SIP network. Left empty, they are taken from the profile: `AllowedIPs` with WireGuard, the `route` lines with OpenVPN.
3. If the profile asks for a login and does not hold it, enter the username and password.
4. Turn on **Manage this server through this connection** only if you want SSH and the portal open to the other side. Without it, only SIP and call audio are open there.
5. Press **Apply** so that calls use these networks.

Several connections can run together. **Show log** tells why one does not connect, for example a wrong password.

### Moving an existing tunnel to this page

An OpenVPN you started by hand (`openvpn-client@NAME`) keeps running and is not touched. To move it, stop it first (otherwise both use the same address) and upload the same file:

```bash
sudo systemctl disable --now openvpn-client@NAME
```

Do this from a console, or from a connection that does not use the tunnel, and turn on **Manage this server through this connection** if you reach the server through it. The interface is then called `aipbxc<number>` instead of `tun0`, so check any firewall rule of your own that names `tun0`.

## What is refused, and why

| Refused | Why |
|---|---|
| `PostUp`, `PreDown`, `up`, `down`, `plugin`, `script-security` and other settings not on the list | These files can run commands on the server as root |
| `ca`, `cert`, `key` naming a file | Certificates must be inside the profile (`<ca>...</ca>`) |
| A key protected by a password | A service cannot type a password in |
| The default route, or a network wider than /8 | All calls, trunks and access to the server would move into the tunnel and could be lost |
| A network this server already has a route to | That network would be cut off |

Left out without an error (the card lists them): DNS settings, `redirect-gateway`, routes the far side sends, `route ... net_gateway`. IPv6 addresses in a profile are not used. A profile with `0.0.0.0/0` is accepted as long as you list the real networks.

## Good to know

- Keys and the settings of these pages are in `/var/lib/aipbx-vpn`, readable by root only, and go into the daily backup. Treat backups as secret. Restoring a backup uploaded from a file leaves them out, so such a file cannot let anyone in over the VPN.
- Each server or connection is a service of its own: `systemctl status aipbx-vpn@server`, `aipbx-vpn@t<number>`. They start at boot. An update does not restart them unless their configuration changed.
- In an Incus or LXD container, OpenVPN needs `/dev/net/tun` and WireGuard needs the kernel module on the host.
