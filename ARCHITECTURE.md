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
                    │ HTTPS / WSS / TURNS                            │ HTTPS / WSS / TURNS
                    ▼                                                ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                   EDGE INGRESS: NGINX PORT 443 ALPN MULTIPLEXER                         │
│   Nginx TCP Stream module with ssl_preread on (Non-decrypting transparent proxy)       │
│   Evaluates ClientHello Application-Layer Protocol Negotiation (ALPN):                  │
│                                                                                         │
│   ├── [ ALPN Present ] (http/1.1, h2 from Web Browsers, Mobile Apps, REST API)          │
│   │     └──► Streamed directly to Apache 2.4 (127.0.0.1:8443)                           │
│   │                                                                                     │
│   └── [ ALPN Empty / None ] (WebRTC TURNS media relay behind restrictive firewalls)     │
│         └──► Streamed directly to coturn TURNS (127.0.0.1:5349)                         │
└───────────────────────────────────┬────────────────────────────────┬────────────────────┘
                                    │                                │
                     [ ALPN Present ]                                [ No ALPN ]
                                    ▼                                │
┌───────────────────────────────────────────────────────┐            │
│          APPLICATION & WEBSOCKET PROXY TIER           │            │
│  Apache 2.4 (127.0.0.1:8443 with TLS Termination)     │            │
│   ├── /                 ──► PHP 8 MVC Web Portal      │            │
│   ├── /ws               ──► Asterisk WebRTC (8088/ws) │            │
│   └── /chat/ws          ──► Go Messaging (9090/ws)    │            │
│                                                       │            │
│  Port 80: HTTP Redirect & Certbot ACME HTTP-01 Pass   │            │
└──────────┬────────────────────────┬───────────────────┘            │
           │                        │                                │
           ▼                        ▼                                ▼
┌─────────────────────┐  ┌─────────────────────┐          ┌─────────────────────┐
│     PHP 8 MVC       │  │     Asterisk 22     │          │       coturn        │
│     Web Portal      │  │     VoIP Engine     │          │     TURN / STUN     │
│     (Runtime)       │  │    (PJSIP/WebRTC)   │          │     (Port 5349)     │
└──────────┬──────────┘  └──────────┬──────────┘          └─────────────────────┘
           │                        │
           │    AMI (Port 5038)     │
           ├────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                                       DATA LAYER                                        │
│  MariaDB 11 (utf8mb4_unicode_ci)                                                        │
│   ├── aipbx_portal   (Runtime DML: SELECT, INSERT, UPDATE, DELETE)                      │
│   └── aipbx_migrator (Phinx DDL: Database Schema Migrations)                            │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Ingress & Reverse Proxy Tier (Nginx ALPN Multiplexer + Apache Backend)

The system deploys **Nginx at the edge on Port 443** paired with **Apache 2.4 on Port 8443** (and Port 80 for HTTP/ACME verification). This is the exact production architecture running across active nodes (including `10.8.0.10`).

### 2.1 The Core Challenge: Sharing Port 443 between Web & WebRTC TURNS
In enterprise, hospital, and university environments, corporate firewalls strictly block all outbound UDP traffic as well as non-standard TCP ports, permitting only **Port 80** and **Port 443**.
- WebRTC clients inside these restrictive networks cannot establish peer-to-peer audio or reach standard STUN/TURN ports (`3478`, `5349`).
- Therefore, the TURN server (**coturn**) **MUST be reachable over TLS on Port 443 (TURNS)** to relay audio packets through corporate firewalls.
- Simultaneously, the HTTPS Web Portal, Asterisk WebRTC SIP WebSocket (`/ws`), and Go Chat WebSocket (`/chat/ws`) **must ALSO be accessible on Port 443**.

### 2.2 The Solution: Nginx TCP Stream with ALPN Pre-Read (`ssl_preread on`)
Nginx is deployed as a transparent L4 TCP stream router on Port 443:
1. When an incoming TLS connection arrives on port 443, Nginx parses the initial TLS `ClientHello` packet using `ssl_preread on;` **without decrypting or terminating TLS**.
2. **ALPN Inspection**:
   - **Web Browsers & Mobile HTTPS/WSS Clients** always advertise ALPN protocols (e.g. `http/1.1` or `h2`). Nginx matches this and forwards the raw TLS stream to **Apache 2.4 on `127.0.0.1:8443`**.
   - **WebRTC TURNS (coturn) Clients** do not send ALPN protocols (`""`). Nginx matches this empty string and routes the connection directly to **coturn on `127.0.0.1:5349`**.
3. **Zero Encryption Overhead at the Edge**: Because Nginx does not decrypt TLS, there is zero certificate synchronization overhead between Nginx and Apache/coturn. Each backend handles its own TLS handshake natively.

```nginx
stream {
    log_format stream_debug '$remote_addr [$time_local] alpn="$ssl_preread_alpn_protocols" backend=$turn_backend status=$status';
    access_log /var/log/nginx/stream.log stream_debug;

    map $ssl_preread_alpn_protocols $turn_backend {
        default     127.0.0.1:8443;   # Web Portal, WebRTC /ws, Chat /chat/ws (Apache SSL)
        ""          127.0.0.1:5349;   # coturn TURNS (Firewall bypass)
    }

    server {
        listen 443;
        listen [::]:443;
        ssl_preread on;
        proxy_pass $turn_backend;
        proxy_timeout 3600s;
        proxy_connect_timeout 5s;
    }
}
```

### 2.3 Port 80 and Let's Encrypt ACME HTTP-01 Challenge Handling
Apache listens on Port 80 (`*:80`):
- All general web traffic is permanently 301-redirected to `https://%{HTTP_HOST}%{REQUEST_URI}`.
- Crucially, `/.well-known/acme-challenge/` requests from Certbot / Let's Encrypt are **exempted from HTTPS redirect**:
  ```apache
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [END,NE,R=permanent]
  ```
  *Rationale:* Let's Encrypt's automated validation bot does not send ALPN. If port 80 redirected ACME challenges to port 443, Nginx would misroute the validation traffic to coturn, causing SSL certificate renewals to fail. Serving ACME HTTP-01 directly over port 80 guarantees automated, uninterrupted certificate renewals.

### 2.4 Apache 2.4 Application & WebSocket Proxy Layer (127.0.0.1:8443)
Apache terminates TLS and acts as the internal application multiplexer:
- **Web Portal**: Executes PHP 8 via `libapache2-mod-php` or PHP-FPM.
- **Asterisk WebRTC SIP (`/ws`)**: Proxied via `mod_proxy_wstunnel` to `ws://127.0.0.1:8088/ws` with `timeout=3600`.
- **Go Chat Hub (`/chat/ws`)**: Proxied via `mod_proxy_wstunnel` to `ws://127.0.0.1:9090/ws` with `timeout=3600 keepalive=On`.

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

### 3.4 In-Band Disconnect Supervision
Legacy and analog trunks frequently fail to issue out-of-band signaling (e.g. SIP BYE / ISDN release) when remote callers hang up. To prevent channels from lingering indefinitely in IVR or queue loops:
- Wrapper dialplan context `[from-trunk-kapanma-tonu]` activates Asterisk cadence-based busy tone detection:
  ```ini
  same => n,Set(TONE_DETECT(0,,bg(kapanma-tonu,s,1))=)
  ```
- Upon detecting regular busy cadence, execution branches immediately to `[kapanma-tonu]`, issuing a clean `Hangup()` and releasing all allocated bridges.

### 3.5 Dynamic Star Code Execution Engine
Asterisk feature codes (*81 queue login, *80 queue logout, *60 DND, *72 forward) interface directly with the database and live channels without requiring full dialplan regenerations:
- `System(/usr/local/bin/feature_code_action.php ...)` asynchronously applies state changes to live Asterisk queues (`QueueHelper::setMembership`) and MariaDB.
- Provides immediate audio verification (`queue-agentlogin-success` / double confirmation beeps).
- Queue transfers implement bridge-traversal caller preservation (`findCallerChannelForAgent`), ensuring attended transfers redirect the caller channel rather than dropping the caller when separating the agent leg.

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
Granular security policies are enforced via the `sys_role_permissions` database matrix:
- **Matrix Dimensions**: Roles (`admin`, `read_only_admin`, `cc_manager`, `cc_agent`, `standard_user`, `fax_user`) mapped against modules (`extensions`, `trunks`, `inbound_routes`, `outbound_routes`, `queues`, `ivrs`, `time_conditions`, `sounds`, `call_center`, `fax`, `my_phone`, `chat`, `settings`, etc.) with discrete flags: `can_view`, `can_access`, `can_edit`, `can_delete`.
- **Strict Read-Only Viewer Mode (`read_only_admin`)**: All form submissions, destructive API endpoints, and modal modification triggers are denied at the controller layer and visually disabled in the UI.
- **Self-Service Boundaries**: `standard_user` is restricted to personal softphone settings (`my_phone`), extension chat (`chat`), and their own CDRs; system `fax_user` accounts are automatically excluded from interactive chat directories.

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
- **Dynamic Credentials**: Ephemeral username/password generation based on HMAC-SHA1 tokens tied to active user sessions via `/api/sip_credentials.php`.
- **Client Keep-Alive & Renewal**: In-browser softphones (`header_phone.js`) automatically renew ephemeral coturn credentials in-place every 30 minutes, preventing media relay timeouts and silent audio during continuous, all-day agent shifts.

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
