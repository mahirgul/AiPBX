# Transports

**System → Transports** shows where Asterisk listens for SIP: the built-in UDP, TCP, TLS, WS and WSS, and any you add.

A phone can use any transport that is on. A call to it goes back over the transport it registered on. Before 1.60.28 every extension was tied to UDP, so a phone on TCP or TLS registered fine and never got a call.

## Changing a transport

You can change the address and port of UDP, TCP and TLS, and turn TCP and TLS on or off. UDP stays on. WS and WSS cannot be changed, as web phones reach them through the web server.

After saving, press **Apply**, then restart Asterisk. Asterisk keeps a port it has open until it restarts, and **a restart ends all calls**, so choose a quiet moment. The page reminds you until the restart is done.

- **Firewall.** A new port opens on all network cards when you save. An old port stays open until you close it on the Firewall page.
- **Phones.** They learn a new port when they next fetch their configuration, so they can stay offline until then.
- **Refused ports.** Those of other AiPBX services, those inside the media ranges, and those another transport already uses.

## Adding a transport

Add one for a group of phones, for example phones outside your network on their own port. Give it a name, protocol, address and port, and a public address if it differs from the one under *PBX Settings → PJSIP & Net*. It answers phones from the port they registered on.

To point phones at it, set the protocol and SIP port on their region under *Provisioning → Regions*. To use it for a trunk or for one object, use Advanced Configuration.

## One extension

An extension's own **SIP settings** can name UDP, TCP or TLS. Leave it on Default unless you have a reason: a chosen transport must match the phone, or calls will not reach it.
