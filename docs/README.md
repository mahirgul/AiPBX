<p align="center"><img src="logo.png" alt="AiPBX" width="96"></p>

# AiPBX Documentation

Administrator documentation for AiPBX on Ubuntu 26.04 LTS. Start with the
[installation guide](../INSTALL.md), then use the pages below while configuring the system.

## Getting started

| Page | What it covers |
|------|----------------|
| [Installation & updating](../INSTALL.md) | Requirements, one-line installer, certificates, ports, `aipbx-update` |
| [Portal basics](portal.md) | The *Apply* workflow, edit dialogs, ordering lists, copying records |
| [Architecture](../ARCHITECTURE.md) | Components, traffic flow, security model |

## Telephony

| Page | What it covers |
|------|----------------|
| [Trunks](trunks.md) | Connection modes, inbound DID trimming, transit routing, outbound caller ID normalization, copy / rename / order |
| [Outbound routes](outbound-routes.md) | Dial patterns, number manipulation, trunk failover, route groups, multiple PBXes with different number formats |
| [Inbound routes (DIDs)](inbound-routes.md) | DID matching, destinations, fax DIDs |
| [Call recordings](recordings.md) | Where recordings live, automatic MP3 conversion, playback |
| [Sounds & languages](sounds.md) | Custom sounds, Turkish prompts, voicemail prompts still to be recorded |

## Users and security

| Page | What it covers |
|------|----------------|
| [Users & roles](users-and-roles.md) | Built-in roles, landing pages, permissions, *My Phone* |
| [Security](security.md) | Firewall, fail2ban, the `aipbx-priv` root helper, Apache sandbox |
| [E-mail](mail.md) | Mail relay for fax-to-e-mail, voicemail and password e-mails |
| [Troubleshooting](troubleshooting.md) | Changes not applied, calls hung up, sounds not found, SIP captures |

## Mobile apps

The Android and iOS apps are built by GitHub Actions and attached to each
[GitHub Release](https://github.com/mahirgul/AiPBX/releases). Users sign in with their portal
username and password (plus 2FA when enabled), by scanning the QR code on *My Phone*, or with the
link in their invitation e-mail.
