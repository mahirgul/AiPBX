# Running AiPBX Behind a Reverse Proxy

This guide covers deploying AiPBX behind existing reverse proxies such as **Nginx**, **Caddy**, **Traefik**, **HAProxy**, or **Apache**.

---

## 1. Architecture & The VoIP Boundary

AiPBX delivers two distinct categories of network traffic:

```
                  +-----------------------------------+
                  |           Internet / WAN          |
                  +-----------------+-----------------+
                                    |
            +-----------------------+-----------------------+
            | HTTP / HTTPS / WSS                            | SIP & RTP Media
            v                                               v
   +--------------------+                         +-------------------+
   |   Reverse Proxy    |                         | Direct L4 Bypass  |
   | (Nginx/Caddy/etc.) |                         | (1:1 NAT / Router)|
   +---------+----------+                         +---------+---------+
             |                                              |
             | HTTP and WebSockets (80)                     | SIP (5060/5061 UDP/TCP)
             v                                              | RTP (10000-20000 UDP)
   +--------------------------------------------------------+---------+
   |                            AiPBX Server                          |
   +------------------------------------------------------------------+
```

### Critical VoIP Rule:
Standard reverse proxies operate on **Layer 7 (HTTP/HTTPS/WebSocket)**. They **CANNOT** proxy SIP signaling (UDP/TCP 5060/5061) or RTP audio/video media (UDP 10000-20000).
- **HTTP / HTTPS / WebSockets**: Route through your reverse proxy.
- **SIP & RTP Traffic**: **MUST bypass the reverse proxy** and reach the AiPBX server host directly via port forwarding or Layer 4 routing.

For complete port details, see [Network Ports & Firewall Matrix](network-ports.md).

---

## 2. Portal Configuration

Log in to the AiPBX portal as an administrator and navigate to **Network → Reverse Proxy** (`/reverse-proxy`):

1. **Enable Reverse Proxy Mode**: Check the toggle.
2. **External FQDN**: Enter your public domain (e.g., `pbx.example.com`).
3. **External Port**: Enter `443` (or your public HTTPS port).
4. **TLS Termination Mode**:
   - `Edge Termination` (recommended): The reverse proxy handles SSL certificates and connects to AiPBX via HTTP.
   - `Re-encryption`: The reverse proxy terminates external SSL and connects to AiPBX via internal HTTPS. HAProxy only, and the page generates no configuration for the others: port 443 of AiPBX tells HTTPS from TURNS by ALPN, which they do not send on the connections softphones need, so softphones would get 502. Use Edge Termination over a private network with them.
5. **Trusted Reverse Proxies**: The IP addresses of your reverse proxies; the default is this host only (`127.0.0.1, ::1`). Add the exact address of a proxy on another machine, and avoid whole private networks: every device in a trusted range can claim any client IP in `X-Forwarded-For`, which avoids sign-in lockouts and passes the allowed networks of phone provisioning.

### Security: Spoofing Prevention
AiPBX honors forwarded headers only when reverse proxy mode is enabled and the
immediate connection comes from a configured trusted proxy. Untrusted forwarded
headers do not establish client identity or HTTPS.

The HTTP listener applies the same enabled/trusted-proxy settings before serving
the portal or proxying WebSocket connections. A direct client sending
`X-Forwarded-Proto: https` is still redirected to HTTPS. The decision uses the TCP
peer (`CONN_REMOTE_ADDR`), independently of forwarded client addresses.

Saving this page validates IPv4/IPv6 addresses and CIDRs, applies a fixed Apache
condition through the privileged helper, and gracefully reloads Apache. If Apache
rejects the change, the database settings and previous condition are restored.
The condition is also rebuilt from saved settings on installation/upgrade.

Configure the reverse proxy to **overwrite** `X-Forwarded-Proto` with the actual
client-facing scheme. The HTTP listener accepts one `https` value; a comma-separated
chain such as `https, http` is redirected. ACME, phone provisioning and local
Asterisk HTTP callbacks keep their existing exceptions.

A proxy **on the same machine** must always send `X-Forwarded-For` (all examples
below do). A few endpoints are meant for Asterisk on this server only (IVR data
lookups, the meeting join check, voicemail text): they accept a request from
127.0.0.1 that carries no forwarded header. A local proxy that leaves the header
out would let anyone on the internet call them as if they were Asterisk.

---

## 3. WebRTC WebSocket (`/ws`) Requirements

In-browser calling via WebRTC relies on persistent WebSocket connections for SIP signaling:
- **Path**: `/ws`, on the same port as the portal. Asterisk's own ports (8088, 8089) listen on loopback only, on purpose, so that signaling cannot bypass the portal's edge and its fail2ban protection. This is also what lets a proxy run on a different server: it connects to the portal's port 80 or 443 and nothing else needs opening.
- **Headers**: `Upgrade` and `Connection` must be passed, and `X-Forwarded-Proto: https` must be set, or the portal answers with a redirect.
- **Timeouts**: Reverse proxies typically terminate idle connections after 60 seconds. Set `proxy_read_timeout` to at least `3600s` (1 hour) so softphones do not disconnect while idle.

---

## 4. Configuration Examples

### A. Nginx
```nginx
# /etc/nginx/sites-available/aipbx.conf

map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

upstream aipbx_web {
    server 127.0.0.1:80;
    keepalive 32;
}

server {
    listen 80;
    listen [::]:80;
    server_name pbx.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name pbx.example.com;

    ssl_certificate /etc/letsencrypt/live/pbx.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pbx.example.com/privkey.pem;

    client_max_body_size 64M;

    # 1. WebRTC WebSocket Signaling
    location /ws {
        proxy_pass http://127.0.0.1:80/ws;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Keepalive timeout for softphone standby
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # 2. Web Portal & Auto-Provisioning
    location / {
        proxy_pass http://aipbx_web;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;

        proxy_connect_timeout 60s;
        proxy_send_timeout 300s;
        proxy_read_timeout 300s;
        proxy_buffering off;
    }
}
```

### B. Caddy
```caddyfile
# /etc/caddy/Caddyfile

pbx.example.com {
    encode zstd gzip

    # Web Portal, Phone Provisioning and the WebSockets (/ws, /chat/ws)
    reverse_proxy 127.0.0.1:80 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto {scheme}
        header_up X-Forwarded-Host {host}
    }
}
```

### C. Traefik (Dynamic File Provider)
```yaml
# traefik-dynamic.yaml

http:
  routers:
    aipbx-web:
      rule: "Host(`pbx.example.com`)"
      entryPoints: ["websecure"]
      service: "aipbx-web-svc"
      middlewares: ["forwarded-https"]
      tls:
        certResolver: "letsencrypt"

  middlewares:
    # Traefik says "wss" for a WebSocket, and the portal redirects anything but "https".
    forwarded-https:
      headers:
        customRequestHeaders:
          X-Forwarded-Proto: "https"

  services:
    aipbx-web-svc:
      loadBalancer:
        passHostHeader: true
        servers:
          - url: "http://127.0.0.1:80"
```

### D. HAProxy
```haproxy
# /etc/haproxy/haproxy.cfg

frontend http_in
    bind *:80
    bind *:443 ssl crt /etc/ssl/certs/pbx.example.com.pem alpn h2,http/1.1
    redirect scheme https code 301 if !{ ssl_fc }

    acl is_aipbx hdr(host) -i pbx.example.com
    acl is_websocket hdr(Upgrade) -i WebSocket
    acl is_ws_path path_beg /ws

    use_backend aipbx_ws if is_aipbx is_websocket or is_aipbx is_ws_path
    use_backend aipbx_web if is_aipbx

backend aipbx_web
    mode http
    option forwardfor
    http-request set-header X-Forwarded-Proto https if { ssl_fc }
    http-request set-header X-Forwarded-Host %[req.hdr(host)]
    server aipbx1 127.0.0.1:80 check

backend aipbx_ws
    mode http
    option forwardfor
    timeout tunnel 3600s
    http-request set-header X-Forwarded-Proto https if { ssl_fc }
    server aipbx_ws1 127.0.0.1:80 check
```

---

## 5. Verification & Diagnostics

Once your reverse proxy is active:
1. Open **Network → Reverse Proxy** in your browser.
2. Check the **Real-Time Request Diagnostics** panel:
   - **Immediate Remote IP**: Shows your proxy's internal IP.
   - **Status**: Must indicate `Trusted Proxy` (green badge).
   - **Resolved Client IP**: Must show your actual client workstation's public/LAN IP, matching `X-Forwarded-For`.
3. Test a WebRTC call from the portal or mobile app to confirm WebSocket connectivity.
