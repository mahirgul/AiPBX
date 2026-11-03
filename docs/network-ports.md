# AiPBX Network Ports & Firewall Matrix

This document details all network ports, protocols, and access scopes required by AiPBX for VoIP signaling, RTP media, WebRTC, administration, and provisioning.

---

## 1. Complete Port Matrix

| Port Range | Protocol | Service / Purpose | Default Scope | Reverse Proxy Bypass | Notes |
|:---|:---|:---|:---|:---:|:---|
| **5060** | UDP / TCP | SIP Signaling | Public / LAN | **YES** | Desk phones, external trunks, softphones. |
| **5061** | TCP | SIP-TLS Signaling | Public / LAN | **YES** | Encrypted SIP signaling over TLS. |
| **10000–20000** | UDP | RTP / SRTP Media Streams | Public / LAN | **YES** | 2-way audio & video RTP packets. Must route directly. |
| **80** | TCP | HTTP Web & Provisioning | Public / LAN | NO | Web portal, phone auto-provisioning, ACME HTTP-01. |
| **443** | TCP | HTTPS Web Portal & API | Public / LAN | NO | Secure web interface, REST API, WebRTC WSS upgrade. |
| **8089** | TCP | Asterisk WSS | Localhost (127.0.0.1) | NO | Not used by clients and never opened: browsers and the mobile apps use 443 `/ws`. |
| **8088** | TCP | Asterisk HTTP / WS | Localhost (127.0.0.1) | NO | Behind `/ws` of the portal. A reverse proxy connects to the portal, not to this port. |
| **3478** | UDP / TCP | STUN / TURN (coturn) | Public | **YES** | NAT traversal candidate discovery & media relay. |
| **5349** | TCP | TURNS TLS (coturn) | Public | **YES** | Encrypted TLS TURN relay for strict networks. |
| **51820** / **1194** | UDP (OpenVPN also TCP) | VPN server, default ports, off by default ([VPN](vpn.md)) | Public WAN | **YES** | Opened only while the VPN server is on, on the port chosen on the page |
| **69** | UDP | TFTP Phone Provisioning | LAN only | **YES** | Legacy desk phone configuration and firmware. |
| **9061** | UDP | HEP / Homer SIP Capture | Internal / LAN | **YES** | Encapsulated SIP packet capture for monitoring. |
| **5038** | TCP | AMI (Asterisk Manager) | Localhost (127.0.0.1) | **YES** | Internal PBX daemon management. **Never expose.** |
| **3306** | TCP | MariaDB Database | Localhost (127.0.0.1) | **YES** | Internal PBX relational database. **Never expose.** |

---

## 2. Traffic Classification & Security Zones

### A. Public WAN (Internet-Facing)
If you provide remote softphone access, remote mobile app calling, or SIP trunks over the public internet:
- **Allow Inbound**: `80/tcp`, `443/tcp`, `5060/udp,tcp`, `5061/tcp`, `10000:20000/udp`, `3478/udp,tcp`, `5349/tcp`.
- **Behind Reverse Proxy**: Only `80/tcp` and `443/tcp` go through the reverse proxy. All SIP and RTP ports (`5060`, `5061`, `10000-20000/udp`) **MUST route directly** to the server host.
- **Fail2ban Protection**: AiPBX automatically monitors authentication attempts on SIP and Web with built-in jails.

### B. Internal LAN Only
- **69/udp (TFTP)**: Desk phones fetching initial bootstrap firmware. Restrict to internal voice VLANs (e.g., `192.168.10.0/24`).
- **9061/udp (HEP)**: Homer or Sipcapture monitoring collector. Restrict to your private monitoring subnet.

### C. Localhost Only (`127.0.0.1`)
- **5038/tcp (AMI)**: Asterisk Manager Interface credentials allow administrative execution. Bound strictly to `127.0.0.1`.
- **3306/tcp (MariaDB)**: Core database. Bound strictly to `127.0.0.1`.

---

## 3. VoIP & NAT Routing Guidelines

### 1. Disable SIP ALG (Application Layer Gateway)
Router and firewall SIP ALG implementations frequently corrupt SIP packet headers, SDP payloads, and Via branches.
> **IMPORTANT**: Disable SIP ALG, SIP Inspection, or SIP Fixup on all edge routers and firewalls.

### 2. RTP Port Forwarding
Ensure your edge firewall or NAT gateway forwards the entire UDP port range `10000:20000` to the AiPBX host. Asymmetrical or missing UDP forwarding causes "one-way audio" or dropped calls after 30 seconds.

### 3. External IP & Local Networks
On the PBX portal at **Network → Network Settings**:
- Set **External IP** to your public WAN IP or dynamic DNS hostname.
- Set **Local Networks** to your internal subnets (e.g., `192.168.1.0/24, 10.0.0.0/8`). This ensures Asterisk rewrites SDP addresses correctly for public versus internal callers.

---

## 4. Production Firewall Recipes

### A. UFW (Ubuntu / Debian)
```bash
# Set default policies
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Management & Web
sudo ufw allow 22/tcp comment 'SSH'
sudo ufw allow 80/tcp comment 'HTTP / ACME'
sudo ufw allow 443/tcp comment 'HTTPS Portal'

# SIP Signaling
sudo ufw allow 5060/udp comment 'SIP UDP'
sudo ufw allow 5060/tcp comment 'SIP TCP'
sudo ufw allow 5061/tcp comment 'SIP-TLS'

# RTP Audio / Video Media Range
sudo ufw allow 10000:20000/udp comment 'RTP Audio Media'

# WebRTC STUN / TURNS
sudo ufw allow 3478/udp comment 'STUN / TURN UDP'
sudo ufw allow 3478/tcp comment 'STUN / TURN TCP'
sudo ufw allow 5349/tcp comment 'TURNS TLS'

# Enable firewall
sudo ufw enable
```

### B. firewalld (RHEL / Rocky / AlmaLinux)
```bash
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https

sudo firewall-cmd --permanent --add-port=5060/udp
sudo firewall-cmd --permanent --add-port=5060/tcp
sudo firewall-cmd --permanent --add-port=5061/tcp
sudo firewall-cmd --permanent --add-port=10000-20000/udp
sudo firewall-cmd --permanent --add-port=3478/udp
sudo firewall-cmd --permanent --add-port=3478/tcp
sudo firewall-cmd --permanent --add-port=5349/tcp

sudo firewall-cmd --reload
```

### C. AWS / Cloud Security Group Rules
| Type | Protocol | Port Range | Source | Description |
|:---|:---|:---|:---|:---|
| SSH | TCP | 22 | Admin IP / Bastion | Management |
| HTTP | TCP | 80 | 0.0.0.0/0 | Portal & Auto-Provisioning |
| HTTPS | TCP | 443 | 0.0.0.0/0 | Encrypted Web Portal |
| Custom UDP | UDP | 5060 | 0.0.0.0/0 or Trunk IP | SIP Signaling |
| Custom TCP | TCP | 5060–5061 | 0.0.0.0/0 or Trunk IP | SIP / SIP-TLS |
| Custom UDP | UDP | 10000–20000 | 0.0.0.0/0 | RTP Two-Way Voice Streams |
| Custom UDP/TCP | UDP/TCP | 3478 | 0.0.0.0/0 | STUN / TURN NAT Relay |
| Custom TCP | TCP | 5349 | 0.0.0.0/0 | TURNS TLS Relay |
