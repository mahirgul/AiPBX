# Spec 003: Reverse Proxy Architecture, Network Firewall Matrix & Cloudflare DNS-01 Validation

- **Status:** Implemented
- **Author:** Mahir Gül
- **Target Release:** v1.50.36
- **Date:** 2026-11-01
- **GitHub Issue:** [#148](https://github.com/mahirgul/AiPBX/issues/148)

---

## 1. Problem & Goal (What & Why)

### 1.1 Problem Statement
Deploying AiPBX in modern enterprise infrastructure often requires running behind a front-facing reverse proxy (e.g. Nginx, Caddy, Traefik, HAProxy, or Apache) for TLS termination, shared public IP routing, or cloud edge security. However:
1. **VoIP vs Web Multiplexing Confusion**: Administrators frequently attempt to proxy all traffic (including SIP and RTP) through HTTP reverse proxies, causing broken call signaling, one-way audio, and failed WebRTC calls.
2. **IP Spoofing & Brute-Force Vulnerability**: Reading `X-Forwarded-For` without verifying whether the direct peer connection is a trusted reverse proxy allows external attackers to spoof client IPs and bypass rate limiters or trigger denial-of-service lockouts against arbitrary IPs.
3. **Internal Server SSL/TLS Barrier**: Let's Encrypt HTTP-01 validation requires public port 80 exposure. PBXs deployed on private LANs, behind restrictive firewalls, or without public DNS A records could not automatically obtain valid certificates, breaking remote WebRTC and TURNS.
4. **WebSocket & SSO Callback Breaks**: Edge TLS termination (HTTP between proxy and AiPBX, HTTPS between client and proxy) failed to set secure cookies and broke OIDC/SAML OAuth redirect URIs.

### 1.2 Proposed Solution
- Deliver a dedicated **Network → Reverse Proxy** (`/reverse-proxy`) management interface.
- Implement a hardened, spoof-proof client IP resolution engine (`ReverseProxyService::resolveClientIp` & `getClientIP()`) with configurable CIDR trusted proxy ranges.
- Provide a real-time HTTP Header Diagnostic Inspector and live configuration generators for Nginx, Caddy, Traefik, HAProxy, and Apache 2.4.
- Provide an interactive Network Ports & Firewall Matrix with copyable bash setup scripts for UFW, firewalld, and iptables.
- Integrate Let's Encrypt **DNS-01 Cloudflare API validation** with wildcard support (`*.<domain>`) in `/certificates` and `conf/sbin/aipbx-cert`.
- Integrate `ReverseProxyService::isHttps()` across chat cookies, OIDC callbacks, and SAML metadata generators.

---

## 2. User Stories & Acceptance Criteria (DoD)

### User Stories
- As an **Administrator**, I want to configure trusted reverse proxy CIDR blocks so that real client IPs are logged in audit trails and used for brute-force prevention without being spoofed.
- As an **Administrator**, I want production-ready reverse proxy config snippets for my chosen proxy (Nginx, Caddy, Traefik, HAProxy, Apache) so that web traffic and WebRTC WebSockets work out of the box while VoIP bypass rules are clearly understood.
- As an **Administrator**, I want to obtain Let's Encrypt certificates using Cloudflare DNS-01 API validation so that private/internal PBX servers can get trusted certificates and wildcards without opening port 80 to the internet.

### Acceptance Criteria (Definition of Done)
- [x] **AC-1:** `/reverse-proxy` route accessible to administrators with full view and configuration persistence in `sys_settings`.
- [x] **AC-2:** Client IP resolver traverses `X-Forwarded-For` right-to-left only when proxy mode is enabled and the connection originates from configured trusted addresses/CIDRs, falling back to `X-Real-IP`. Only loopback (`127.0.0.1`, `::1`) is trusted by default; private networks require explicit administrator configuration. Direct untrusted connections never honor forwarded headers.
- [x] **AC-3:** Live reverse proxy config generators support Nginx, Caddy, Traefik, HAProxy, and Apache with WebSocket pass-through for `/ws` and `/chat/ws`.
- [x] **AC-4:** Cloudflare DNS-01 challenge support added in `CertificateService`, `CertificateController`, and `conf/sbin/aipbx-cert` with wildcard support and secure credential staging (`mktemp -d`, `0600` permissions).
- [x] **AC-5:** `ReverseProxyService::isHttps()` and `isHttpsRequest()` honor `X-Forwarded-Proto: https` from trusted proxies across Web Chat, OIDC, and SAML callbacks.
- [x] **AC-6:** 100% translation key parity across all 22 supported languages (5,632 / 5,632 strings, 0 problems).
- [x] **AC-7:** Automated unit test suite in `ReverseProxyTest.php` passes with 100% green coverage, 0 PHPStan errors, and clean smoke test.

---

## 3. Technical Design (How)

### 3.1 Settings & Storage
Settings stored in `sys_settings`:
- `proxy_enabled`: `'0'` or `'1'`
- `proxy_trusted_proxies`: Comma/newline separated list of IPv4/IPv6 addresses and CIDRs (defaults to loopback only; at most 128 entries)
- `proxy_tls_termination`: `'edge'`, `'re_encrypt'`, or `'none'`
- `proxy_external_fqdn`: External portal domain name
- `proxy_external_port`: External HTTPS port (default `443`)
- `proxy_type`: `'nginx'`, `'caddy'`, `'traefik'`, `'haproxy'`, or `'apache'`

### 3.2 Spoof-Proof IP Resolution Algorithm
```
1. Get remote peer IP from $_SERVER['REMOTE_ADDR']
2. If proxy_enabled is false -> return remote peer IP
3. If remote peer IP is NOT in trusted_proxies -> return remote peer IP (prevent spoofing)
4. Parse X-Forwarded-For header from right to left:
   - For each candidate IP:
     - If candidate is untrusted -> return candidate (first untrusted client)
5. If all hops trusted -> fallback to X-Real-IP if valid, else remote peer IP
```

### 3.3 Network Ports Matrix & Firewall Architecture
- **VoIP Direct Bypass (L4)**:
  - SIP UDP/TCP: `5060`
  - SIP-TLS TCP: `5061`
  - RTP Media UDP: `10000-20000`
  - STUN/TURNS: `3478`, `5349` (UDP/TCP)
- **Web Reverse Proxied (L7)**:
  - HTTP Web / ACME: `80`
  - HTTPS Web: `443`
  - Asterisk WSS / WebRTC: proxied through `/ws`
  - Go Chat WebRTC/IM: proxied through `/chat/ws`

---

## 4. Verification & Status
- **Implemented in:** v1.50.36
- **Test Suite:** `web/tests/unit/ReverseProxyTest.php` (CIDR IPv4/IPv6, spoofing prevention, HTTPS detection, config generators)
- **Documentation:**
  - Architecture: [`docs/reverse-proxy.md`](../reverse-proxy.md)
  - Ports Matrix: [`docs/network-ports.md`](../network-ports.md)
  - Certificates: [`docs/certificates.md`](../certificates.md)
