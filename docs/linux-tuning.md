# Linux OS & Kernel Tuning for High-Concurrency VoIP

AiPBX turns a clean Linux server into an enterprise telecommunications appliance. Standard Linux distributions (Ubuntu, Debian) ship with kernel and systemd defaults configured for generic web, database, or batch workloads. Telephony traffic, however, is unique: **RTP audio and video streams demand hard real-time scheduling, zero-packet-loss UDP delivery, and predictable I/O latency**.

AiPBX configures and maintains these optimizations automatically via `install.sh` on fresh installations and on every update via `sudo aipbx-update`. This document outlines each optimization, why it is applied, and how to verify it on a running host.

---

## 1. Kernel Network Sysctl Tuning (`/etc/sysctl.d/99-aipbx.conf`)

VoIP audio uses UDP packets arriving every 20 milliseconds (50 packets per second per channel). When dozens or hundreds of calls occur simultaneously, sudden bursts of incoming RTP packets can overwhelm default Linux socket receive queues, causing dropped packets, audio jitter, or robotic voice.

AiPBX applies the following parameters in `/etc/sysctl.d/99-aipbx.conf`:

```ini
# Managed by AiPBX: VoIP & Asterisk high-concurrency kernel network tuning
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.core.rmem_default = 1048576
net.core.wmem_default = 1048576
net.core.netdev_max_backlog = 10000
net.core.somaxconn = 4096
vm.swappiness = 10
vm.dirty_background_ratio = 5
vm.dirty_ratio = 10
# Security: disable Linux MPTCP (MultiPath TCP) at kernel level
# Mitigates CVE-2026-98432 and eliminates exposure to MPTCP subflow attacks
net.mptcp.enabled = 0
```

### Technical Breakdown:

| Kernel Parameter | Value | Standard Default | Why It Is Tuned |
|---|---|---|---|
| `net.core.rmem_max` | `16777216` (16 MB) | 212 KB | Sets the maximum socket receive buffer ceiling. Allows Asterisk and WebRTC to buffer bursts of media packets during CPU spikes without dropping frames. |
| `net.core.wmem_max` | `16777216` (16 MB) | 212 KB | Sets the maximum socket send buffer ceiling for outgoing audio/video packets. |
| `net.core.rmem_default` | `1048576` (1 MB) | 212 KB | Default receive buffer allocated to newly opened sockets. Eliminates socket buffer renegotiation overhead. |
| `net.core.wmem_default` | `1048576` (1 MB) | 212 KB | Default send buffer allocated to newly opened sockets. |
| `net.core.netdev_max_backlog` | `10000` | 1000 | The maximum number of packets queued on the network interface input queue before the kernel processes them. Crucial under high-call-volume spikes (e.g. call centers or emergency ring groups). |
| `net.core.somaxconn` | `4096` | 128 / 4096 | Upper limit on the listen backlog queue for TCP sockets. Prevents connection resets during bursts of WebRTC WebSocket (`/ws`), web portal, and SIP/TLS handshakes. |

---

## 2. Virtual Memory & Disk Paging Optimization

Disk swapping introduces random latency spikes ranging from 5 to 50 milliseconds. While a database or web server can absorb a 30 ms delay without incident, a 30 ms delay on a VoIP stream results in an audible voice hiccup or dropped audio frame.

| Parameter | Value | Standard Default | Purpose |
|---|---|---|---|
| `vm.swappiness` | `10` | 60 | Tells the Linux kernel to strongly prefer keeping telephony processes (Asterisk, MariaDB, Chat engine, AI runtime) in physical RAM and only swap when RAM is genuinely exhausted. |
| `vm.dirty_background_ratio` | `5` | 10 | The percentage of system memory that can hold unwritten disk pages before background kernel flusher threads begin writing them to disk. Continuous early flushing ensures call recordings and CDR writes are written smoothly. |
| `vm.dirty_ratio` | `10` | 20 | The hard ceiling where writing processes are paused while data is synced to disk. Keeping this bounded prevents massive I/O stalls that could stutter active call audio. |

---

## 3. Security: Kernel MPTCP Disabling (`net.mptcp.enabled = 0`)

* **Background:** Linux kernel MultiPath TCP (MPTCP) was designed to split TCP connections across multiple network interfaces. However, VoIP systems (SIP, RTP, WebSockets) rely on direct single-path IP connections and have no use for MPTCP.
* **Vulnerability Mitigation (CVE-2026-98432):** In 2026, research revealed a critical Use-After-Free race condition in the Linux IPv4 TCP option handler specifically exploitable via MPTCP subflow handshakes to achieve remote root access. Furthermore, Go 1.24+ began enabling MPTCP listeners by default on Linux.
* **AiPBX Defense-in-Depth:**
  1. **Kernel Level:** `net.mptcp.enabled = 0` is enforced in `/etc/sysctl.d/99-aipbx.conf`, closing the MPTCP attack surface entirely across all applications on the server.
  2. **Go Binary Level:** The chat service (`chat/go.mod`) compiles with `godebug multipathtcp=0`, embedding the disablement into the executable's `DefaultGODEBUG`.
  3. **Service Level:** `aipbx-chat.service` runs with `Environment="GODEBUG=multipathtcp=0"`.

---

## 4. SIP ALG Blacklist (`/etc/modprobe.d/aipbx-blacklist-sip.conf`)

Linux Netfilter provides kernel connection tracking helpers (`nf_conntrack_sip` and `nf_nat_sip`) collectively known as **SIP ALG (Application Layer Gateway)**.

### Why SIP ALG Must Be Disabled:
Kernel SIP ALGs attempt to inspect and rewrite SIP headers and SDP payload bodies on the fly. In modern VoIP and WebRTC environments, this causes severe defects:
- Corrupts private-to-public IP translation, causing **one-way audio** or **silent calls**.
- Breaks SIP over TLS (where the kernel cannot inspect encrypted payloads and mangles port state).
- Corrupts WebRTC SDP candidates (ICE/STUN/TURN), causing browser webphones to fail to connect.

AiPBX blacklists both modules at installation time:
```ini
# /etc/modprobe.d/aipbx-blacklist-sip.conf
# Managed by AiPBX: disable kernel SIP ALG modules that corrupt VoIP/WebRTC SDP
blacklist nf_conntrack_sip
blacklist nf_nat_sip
```
The installer also unloads them immediately if currently loaded in the running kernel:
```bash
modprobe -r nf_nat_sip nf_conntrack_sip 2>/dev/null || true
```

---

## 5. Systemd Service Scheduling, Limits & OOM Protection

### Telephony Engine (`asterisk.service`)
Asterisk handles real-time audio threads and must never be blocked by background maintenance tasks or killed by the Linux Out-Of-Memory (OOM) killer.

`install.sh` configures the following service overrides:
* **Real-Time CPU Scheduling:** `CPUSchedulingPolicy=rr` with `CPUSchedulingPriority=10`. Ensures RTP media transcoding and mixing threads are prioritized by the Linux kernel scheduler over non-real-time userland processes.
* **OOM Killer Immunity (`OOMScoreAdjust=-1000`):** If the server runs critically low on memory, Linux will terminate web workers, PHP processes, or logging daemons first — **active phone calls will never be dropped**.
* **File Descriptors (`LimitNOFILE=65536`):** Every active call requires multiple file descriptors (UDP sockets, audio channels, recordings, database handles). 65,536 handles support thousands of concurrent calls.
* **Task Limit (`LimitNPROC=65536`):** Ensures high-capacity threading across queues and conference bridges.
* **Memory Locking (`LimitMEMLOCK=infinity`):** Allows Asterisk to lock critical memory pages into physical RAM without paging faults.

### Instant Messaging Service (`aipbx-chat.service`)
* `LimitNOFILE=65536` (high concurrent WebSocket connections)
* `Environment="GODEBUG=multipathtcp=0"`
* Automatic restarts on failure: `Restart=always` with `RestartSec=3`.

---

## 6. Protection Against Unattended Upgrades (`needrestart`)

On Debian and Ubuntu, `unattended-upgrades` periodically updates system libraries in the background. After updates, the `needrestart` utility scans running processes and restarts services that link to outdated shared libraries.

In early deployments, `needrestart` restarted Asterisk automatically at 06:35 AM after a glibc update, abruptly dropping all active calls.

AiPBX shields Asterisk by placing an override in `/etc/needrestart/conf.d/aipbx.conf`:
```perl
# Managed by AiPBX install.sh: never restart Asterisk automatically (drops calls).
$nrconf{override_rc}{qr(^asterisk\.service$)} = 0;
```
Asterisk picks up updated system libraries smoothly during the administrator-controlled maintenance window or via `sudo aipbx-update`.

---

## 7. Apache / PHP Sandbox & Security Drop-in

Ubuntu and Debian systemd units for Apache isolate `/proc` and `/etc/sudoers`. AiPBX deploys a drop-in unit `/etc/systemd/system/apache2.service.d/override.conf`:
* `ProcSubset=all` & `ProtectProc=default`: Allows the web portal to monitor CPU/memory statistics (`/proc/meminfo`) and Asterisk health without granting arbitrary root privileges.
* Sandboxed path exemptions: `/etc/asterisk`, `/etc/postfix`, and `/etc/fail2ban/jail.d` are marked read-write for administrative actions applied through the root helper `aipbx-priv`.

---

## 8. Verification & Diagnostic Commands

To verify these kernel and system optimizations on your server:

```bash
# 1. Check network and memory sysctl parameters
sysctl net.core.rmem_max net.core.wmem_max net.core.netdev_max_backlog net.mptcp.enabled vm.swappiness

# 2. Verify SIP ALG modules are NOT loaded in the kernel (should return empty)
lsmod | grep -E 'nf_nat_sip|nf_conntrack_sip'

# 3. Check Asterisk OOM protection and scheduling priorities
systemctl show asterisk -p CPUSchedulingPolicy,CPUSchedulingPriority,OOMScoreAdjust,LimitNOFILE

# 4. Check needrestart exemption
cat /etc/needrestart/conf.d/aipbx.conf
```
