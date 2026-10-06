# Certificates

AiPBX uses **one TLS certificate** for everything that speaks TLS:

| Service | Uses it for |
|---------|-------------|
| Apache (behind the 443 edge) | the portal, the mobile API, WebRTC signalling (`/ws`), chat |
| coturn | TURNS, the encrypted media relay for WebRTC clients on restricted networks |
| Asterisk | SIP-TLS (5061) and its WSS transport |

The active certificate lives in `/etc/ssl/aipbx/active.{crt,key}`. coturn and Asterisk run as their
own users, so they get copies (`/etc/coturn/aipbx.*`, `/etc/asterisk/keys/`); `/etc/ssl/aipbx/mode`
records where the certificate came from (`letsencrypt`, `custom` or `selfsigned`).

## The Certificates page

**Security → Certificates** (admin only) shows the active certificate — name, the names it is valid for,
issuer, validity, chain and SHA-256 fingerprint — and checks whether coturn and Asterisk really use the
same one. It flags the problems that break calls without an obvious error:

- **self-signed** — browsers show a warning for the portal and refuse TURNS entirely, so remote WebRTC
  calls have no audio;
- **domain mismatch** — the certificate does not cover `PORTAL_DOMAIN`;
- **incomplete chain** — the intermediate certificate is missing; some browsers and TURNS clients cannot
  verify it;
- **expired / expiring soon / not yet valid**;
- **a service's copy differs** from the active certificate — apply it again.

From the page an admin can:

1. **Get a Let's Encrypt certificate.** Requirements: the DNS record of the portal's domain points to the
   server's public address and port 80 is reachable from the internet. *Test* runs against Let's
   Encrypt's staging server first, so a misconfiguration does not use up the production rate limit.
   Renewal is automatic (checked twice a day, renewed when less than 30 days are left); *Test renewal*
   and *Renew now* are on the page.
2. **Upload a certificate** — PEM files (certificate, optional intermediate chain, private key) or a
   single PFX/P12 file with its password. The key, validity, domain and chain are checked before
   anything changes; a certificate that does not cover the portal's domain is only accepted when you
   tick the override.
3. **Go back to a self-signed certificate.**

Switching the certificate reloads Apache, restarts coturn (TURNS connections drop for a few seconds)
and reloads Asterisk's PJSIP module.

## From the command line

The page runs `aipbx-cert` through the root helper; you can run it directly:

```bash
sudo aipbx-cert letsencrypt --dry-run --email you@example.com   # test against staging
sudo aipbx-cert letsencrypt --email you@example.com             # issue and apply
sudo aipbx-cert renew --dry-run
sudo aipbx-cert selfsigned
```

The result of the last operation is in `/var/lib/aipbx/cert-status.json` and on the page.

## Renewals

certbot's deploy hook (`/etc/letsencrypt/renewal-hooks/deploy/aipbx.sh`) calls
`aipbx-cert renewal-hook`. It only acts while Let's Encrypt is the active source, and copies the
renewed certificate with its **full chain** to coturn and Asterisk, so TURNS keeps working after a
renewal.

## Self-signed and internal domains

Let's Encrypt only issues certificates for names that resolve on the internet. For an internal name
(e.g. `pbx.company.local`) upload a certificate from your own CA and install that CA on the clients.
With a self-signed certificate the portal works after accepting the warning, but **WebRTC TURNS does
not**: calls from browsers or the apps outside the server's network will have no audio. See
[WebRTC, NAT and TURN](webrtc-nat.md).
