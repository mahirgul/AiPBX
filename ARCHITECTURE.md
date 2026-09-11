# AI PBX — System Architecture & Design Specification

**AI PBX** is an open-source, enterprise-grade IP PBX and Unified Communications platform integrating:
- **Asterisk 22** as the real-time VoIP & WebRTC telephony engine
- **Nginx / Apache 2.4** as the high-concurrency ingress, TLS termination, and WebSocket multiplexing layer
- **PHP 8 MVC** as the strictly layered, secure administration and self-service management portal
- **Go** as the lightweight, high-performance instant messaging WebSocket service
- **Android Client (Kotlin)** with dual-engine PJSIP/WebRTC communication and background persistence
- **coturn** for enterprise-grade STUN/TURN NAT traversal

---

## 1. High-Level System Architecture

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                                      CLIENT LAYER                                       │
│   ┌───────────────────────────────┐               ┌─────────────────────────────────┐   │
│   │     Android Mobile App        │               │      Web Browser Client         │   │
│   │     (Kotlin + WebRTC/SIP)     │               │   (WebRTC Softphone + Portal)   │   │
│   └───────────────┬───────────────┘               └────────────────┬────────────────┘   │
└───────────────────┼────────────────────────────────────────────────┼────────────────────┘
                    │ HTTPS / WSS / DTLS-SRTP                        │ HTTPS / WSS
                    ▼                                                ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                         INGRESS & REVERSE PROXY LAYER (EDGE)                            │
│                                                                                         │
│     [ Option A: Nginx (Recommended) ]        OR        [ Option B: Apache 2.4 ]         │
│     - TLS 1.3 / HTTP/2 Termination                     - mod_ssl / HTTP/2               │
│     - WebSocket Multiplexing                           - mod_proxy / mod_proxy_wstunnel │
│     - Static Asset Caching (30d)                       - mod_headers / mod_deflate      │
│     - Auth Rate Limiting                               - .htaccess rewrite rules        │
│                                                                                         │
│   Routing Table:                                                                        │
│     ├── /               ──► Web Portal (PHP 8 FastCGI via PHP-FPM or Apache mod_php)    │
│     ├── /ws             ──► Asterisk WebRTC SIP WebSocket (127.0.0.1:8088/ws)           │
│     ├── /chat/ws        ──► Go Messaging Hub WebSocket (127.0.0.1:9090/ws)              │
│     └── /assets/        ──► Zero-copy static asset serving                              │
└───────────────────┬────────────────────────────────────────────────┬────────────────────┘
                    │                                                │
         ┌──────────┴──────────┐                          ┌──────────┴──────────┐
         ▼                     ▼                          ▼                     ▼
┌─────────────────┐   ┌─────────────────┐        ┌─────────────────┐   ┌─────────────────┐
│   PHP 8 MVC     │   │   Asterisk 22   │        │     Go Chat     │   │     coturn      │
│   Web Portal    │   │   VoIP Engine   │        │     Service     │   │   TURN / STUN   │
│   (PHP-FPM)     │   │  (PJSIP/WebRTC) │        │   (Port 9090)   │   │   (Port 5349)   │
└────────┬────────┘   └────────┬────────┘        └────────┬────────┘   └─────────────────┘
         │                     │                          │
         │   AMI (Port 5038)   │                          │
         ├─────────────────────┘                          │
         │                                                │
         ▼                                                ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                                       DATA LAYER                                        │
│  MariaDB 11 (utf8mb4_unicode_ci)                                                        │
│   ├── aipbx_portal   (Runtime DML: SELECT, INSERT, UPDATE, DELETE)                      │
│   └── aipbx_migrator (Phinx DDL: Database Schema Migrations)                            │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Ingress & Reverse Proxy Tier (Nginx vs Apache 2.4)

The platform supports both **Nginx** and **Apache 2.4** as the edge reverse proxy. Both options provide unified single-port HTTPS/WSS access (port 443), eliminating the need to expose Asterisk's raw HTTP port (8088) or Go's internal WebSocket port (9090) to the public internet.

### 2.1 Why Nginx for Modern WebRTC & Voice Architectures?
1. **Event-Driven Non-Blocking Architecture**: Nginx maintains tens of thousands of idle WebSocket connections (`/ws` for SIP registration and `/chat/ws` for messaging) with minimal RAM usage (~2.5 MB per worker process), whereas thread/process-based servers incur higher overhead per connection.
2. **Multiplexed WebSocket Upgrades**: Native connection upgrade handling via the `$http_upgrade` and `$connection_upgrade` map guarantees smooth HTTP-to-WebSocket protocol switching.
3. **Optimized Timeout Tuning**: WebRTC SIP sessions require extended idle timeouts. Nginx's `proxy_read_timeout 3600s;` and `proxy_send_timeout 3600s;` prevent mobile carrier NAT timeouts from dropping active or standby calls.
4. **FastCGI Microcaching & Zero-Copy Static Delivery**: Nginx delivers CSS, JS, sound prompts, and favicons directly from the kernel filesystem buffer cache (`sendfile on; tcp_nopush on;`), bypassing the PHP runtime.

### 2.2 Reverse Proxy Topologies

#### Topology 1: Standalone Nginx + PHP-FPM (High Concurrency)
- **Front-End**: Nginx listening on ports 80 (HTTP redirect) and 443 (HTTPS/HTTP2).
- **PHP Execution**: Routed directly to `unix:/run/php/php-fpm.sock` using `fastcgi_pass`.
- **WebSocket Routing**:
  - `wss://<FQDN>/ws` is proxied to `http://127.0.0.1:8088/ws` (Asterisk `res_pjsip_transport_websocket`).
  - `wss://<FQDN>/chat/ws` is proxied to `http://127.0.0.1:9090/ws` (Go Chat).
- **Configuration Template**: Provided in [`conf/nginx/aipbx.conf.example`](file:///home/pbx/conf/nginx/aipbx.conf.example).

#### Topology 2: Nginx as Edge SSL/TLS Accelerator in front of Apache
- **Edge Layer**: Nginx terminates public TLS (443), terminates WSS, and handles static assets.
- **Dynamic Backend**: Nginx forwards `/` dynamic PHP requests to Apache listening on `127.0.0.1:8080` (or `127.0.0.1:8443`).
- **Benefit**: Retains existing Apache `.htaccess` rules while benefiting from Nginx's superior SSL acceleration and WebSocket concurrency.

#### Topology 3: Native Apache 2.4 (Default in automated installer)
- Uses Apache with `mod_ssl`, `mod_proxy`, `mod_proxy_http`, and `mod_proxy_wstunnel`.
- Proxies `/ws` and `/chat/ws` using Apache's `ProxyPass` directives.

### 2.3 WebSocket Reverse Proxy Specifications

| Endpoint | Target Backend | Protocol | Key Headers | Timeouts |
|----------|----------------|----------|-------------|----------|
| `/ws` | `127.0.0.1:8088/ws` | WebRTC SIP Signaling (JsSIP) | `Upgrade: websocket`, `Connection: Upgrade`, `Host`, `X-Real-IP`, `X-Forwarded-For` | Read/Send: `3600s`, Buffering: `off` |
| `/chat/ws` | `127.0.0.1:9090/ws` | Go Chat JSON Packets | `Upgrade: websocket`, `Connection: Upgrade`, `Host`, `X-Real-IP`, `X-Forwarded-For` | Read/Send: `3600s`, Buffering: `off` |

---

## 3. VoIP & Telephony Engine (Asterisk 22)

### 3.1 PJSIP Architecture
- Modular configuration managed under `/etc/asterisk/pbx/`:
  - `endpoints.conf`: Dynamic SIP extensions and WebRTC endpoints.
  - `transports.conf`: UDP (5060), TCP (5060), and WebSocket (`transport-ws` on 8088).
  - `aors.conf`: Address-of-Record definitions with multi-device registration support.
  - `auths.conf`: User authentication credentials.

### 3.2 Dual-Endpoint Extension Model
Each PBX user is allocated a twin-endpoint structure:
1. **Physical Desk Phone (`{ext}-sip`)**: Standard SIP over UDP/TCP port 5060.
2. **WebRTC & Mobile Client (`{ext}-webrtc`)**: WebRTC with DTLS-SRTP, ICE, and Opus audio over WSS `/ws`.

Both endpoints share the same user extension number via Asterisk ring groups or simultaneous dialing (`PJSIP/1001-sip&PJSIP/1001-webrtc`).

### 3.3 Media & Audio Pipelines
- **Codecs**: Opus (48kHz WebRTC HD voice), G.722 (wideband), G.711 A-law/U-law (PSTN compatibility).
- **Faxing**: SpanDSP integration (`res_fax_spandsp`) with T.38 gateway and fallback to inband G.711 audio faxing.
- **RTP Port Allocation**: Dedicated UDP range `10000-20000` with strict symmetrical RTP (`rtp_symmetric=yes`) to ensure two-way audio through corporate firewalls.

---

## 4. Web Management Portal (PHP 8 MVC)

### 4.1 Architectural Pattern
- **Strict Layered MVC**:
  - `Controllers`: HTTP request handling, input sanitization, and view rendering.
  - `Services`: Business logic (Asterisk config generator, CDR billing calculators, Brand management).
  - `Repositories`: Clean database abstraction using PDO with prepared statements.
  - `Models / Entities`: Domain representations.

### 4.2 Asterisk Synchronization & Rollback
- Configuration changes in the Web Portal (adding extensions, IVR menus, queues) generate new Asterisk `.conf` files in a staging buffer.
- The portal executes an atomic AMI reload (`sip reload` / `core reload`).
- If Asterisk reports a syntax error or reload failure, the changes are rolled back automatically to the previous working state.

### 4.3 Role-Based Access Control (RBAC)
- **Superadmin**: Complete system configuration, network settings, trunk lines, and database maintenance.
- **PBX Admin**: Extension management, IVR, queues, call recording playback, and call logs.
- **Standard User**: Self-service WebRTC softphone, personal call history, voicemails, and chat.

---

## 5. Android Mobile Application (Kotlin)

### 5.1 Architecture & Components
- **Architecture**: Single Activity (`DialerActivity`) hosting 5 primary navigation tabs:
  1. `Tuşlar` (Dialer & Call Control)
  2. `Geçmiş` (Call History / CDR)
  3. `Rehber` (Enterprise Directory & Local Contacts)
  4. `Sohbet` (Integrated Real-time Chat)
  5. `Santral` (PBX Features, System Diagnostics & Log Viewer)
- **VoIP Subsystem**: Headless WebRTC WebView executing an optimized JsSIP engine with DTLS-SRTP.
- **Keepalive & Stability Engine**:
  - Mobile NAT keepalive ping every 30 seconds.
  - `register_expires` optimized to 300s.
  - Watchdog race-condition guards preventing spurious `DISCONNECTED` restarts.
- **Diagnostics System**: Integrated [`AppLogManager`](file:///home/pbx/android/app/src/main/java/com/mhrgl/aipbx/util/AppLogManager.kt) capturing runtime system parameters and Logcat traces with one-click export/sharing.

---

## 6. Real-Time Messaging Engine (Go)

- High-throughput WebSocket server built in Go (`aipbx-chat`), running as a systemd service on `127.0.0.1:9090`.
- Proxied via `/chat/ws` with TLS termination at Nginx / Apache.
- Capabilities:
  - 1-to-1 direct messaging and multi-user team rooms.
  - Delivery and read receipts.
  - Typing indicators.
  - Online/offline user presence tracking.
  - Direct message storage in MariaDB (`chat_messages` table).

---

## 7. NAT Traversal & Media Relay (coturn)

- **STUN/TURN**: Provides ICE candidates for WebRTC clients behind symmetric NATs or restrictive cellular carriers.
- **Ports**: Port 3478 (STUN/TURN) and Port 5349 (TURNS over TLS).
- **Dynamic Credentials**: Ephemeral username/password generation based on HMAC-SHA1 tokens tied to active user sessions.

---

## 8. Network Ports & Firewall Specification

| Port | Protocol | Layer / Service | Description | Access |
|------|----------|-----------------|-------------|--------|
| **80** | TCP | Ingress (Nginx / Apache) | HTTP (ACME challenge & HTTPS redirect) | Public |
| **443** | TCP | Ingress (Nginx / Apache) | HTTPS Web Portal, WebRTC WSS (`/ws`), Chat WSS (`/chat/ws`) | Public |
| **5060** | UDP/TCP | Asterisk PJSIP | SIP Signaling for IP Desk Phones & Trunks | Trusted IPs / LAN |
| **10000–20000** | UDP | Asterisk RTP | Voice & Video Media Streams | Public |
| **3478** | UDP/TCP | coturn STUN/TURN | WebRTC NAT Traversal | Public |
| **5349** | TCP | coturn TURNS | WebRTC Secure TLS NAT Traversal | Public |
| **49152–65535** | UDP | coturn Relay | Relayed WebRTC Media Streams | Public |
| **5038** | TCP | Asterisk AMI | Manager Interface (Call control, monitoring) | Localhost (127.0.0.1) |
| **8088** | TCP | Asterisk HTTP | Internal WebRTC WebSocket backend | Localhost (127.0.0.1) |
| **9090** | TCP | Go Chat Service | Internal Chat WebSocket backend | Localhost (127.0.0.1) |
| **3306** | TCP | MariaDB | Database Server | Localhost (127.0.0.1) |

---

## 9. Security Architecture

1. **Principle of Least Privilege (Database)**:
   - `aipbx_portal`: Restricted solely to runtime DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`).
   - `aipbx_migrator`: Dedicated user for schema migrations (`CREATE`, `ALTER`, `DROP`).
2. **Strict Environment Isolation**: All production secrets, AMI passwords, and TLS paths are stored in `/etc/ai-pbx.env` (`chmod 640 root:www-data`), completely detached from source code.
3. **fail2ban Protection**: Pre-configured jails monitor Asterisk SIP registration failures, Web Portal login brute-force attacks, and Nginx rate limit triggers.
4. **HSTS & TLS Hardening**: TLS 1.2 and TLS 1.3 only, modern cipher suites, and mandatory HTTP-to-HTTPS redirection.
