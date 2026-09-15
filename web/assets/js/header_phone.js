/**
 * Persistent Header WebRTC Softphone Engine (Powered by JsSIP)
 * Enables Direct WebRTC Calling over WSS (WebSocket) for all users/admins
 * AI PBX — Santral, Faks & Çağrı Merkezi Portalı
 */

// Sadece TURNS (TLS/TCP, 5349) kullanılıyor — düz STUN/TURN (UDP/plain TCP)
// ağ kenar cihazında protokol imzasından filtrelendiği için kaldırıldı
// (bkz. api/sip_credentials.php). Ekstra aday denemesi gecikmeye yol açtığından
// tek, çalıştığı doğrulanmış yol bırakıldı.
var HEADER_PHONE_ICE_SERVERS = [];

// TURN kimligi 1 SAAT gecerlidir (bkz. api/sip_credentials.php:
// username = (time()+3600) + ":" + dahili). Eskiden yalnizca sayfa
// yuklenirken bir kez alinip bir daha tazelenmiyordu. Bir cagri merkezi
// sekmesi saatlerce acik kaldigi icin kimlik oluyor ve coturn TURN
// kimligini reddediyor:
//
//   check_stun_auth: Cannot find credentials of user <1789423958:19000>
//   ... error 401: Unauthorized
//   peer usage: rp=0, rb=0, sp=0, sb=0      <- roleden SIFIR paket
//
// Sonuc: medya rolesi hic kurulmuyor, cagri bastan sona sessiz kaliyor ve
// Asterisk rtp_timeout ile kanali kapatiyor. 2026-09-15 olcumu: 19000'in
// kimligi 40 dakika, 3002'ninki 17 dakika gecmisti.
//
// Kimlik artik periyodik tazeleniyor. Dizi YERINDE guncellenir: pcConfig
// her cagrida bu diziyi okudugu icin (headerPhoneMakeCall ve gelen cagri
// cevaplama) cagri anina ek istek/gecikme eklemeye gerek yoktur.
var HEADER_PHONE_TURN_INDEX = -1;
var HEADER_PHONE_TURN_TIMER = null;
const HEADER_PHONE_TURN_REFRESH_MS = 30 * 60 * 1000; // 30 dk < 1 saatlik omur

function applyHeaderPhoneTurn(turn) {
    if (!turn || !turn.urls) return;
    const entry = { urls: turn.urls, username: turn.username, credential: turn.credential };
    if (HEADER_PHONE_TURN_INDEX >= 0) {
        HEADER_PHONE_ICE_SERVERS[HEADER_PHONE_TURN_INDEX] = entry;
    } else {
        HEADER_PHONE_TURN_INDEX = HEADER_PHONE_ICE_SERVERS.push(entry) - 1;
    }
}

function refreshHeaderPhoneTurn() {
    fetch("/api/sip_credentials.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-CSRF-Token": window.CSRF_TOKEN || ""
        },
        body: "csrf_token=" + encodeURIComponent(window.CSRF_TOKEN || "")
    })
    .then(res => res.json())
    .then(data => {
        if (data && data.success && data.turn) applyHeaderPhoneTurn(data.turn);
    })
    .catch(err => console.warn("TURN kimligi tazelenemedi:", err));
}

function startHeaderPhoneTurnRefresh() {
    if (HEADER_PHONE_TURN_TIMER) return;
    HEADER_PHONE_TURN_TIMER = setInterval(refreshHeaderPhoneTurn, HEADER_PHONE_TURN_REFRESH_MS);
}

var headerPhoneDigits = window.headerPhoneDigits || "";
var headerJsSipUA = window.headerJsSipUA || null;
var headerJsSipSession = window.headerJsSipSession || null;
var headerSipRegistered = window.headerSipRegistered || false;
var headerIsOnHold = false;
var headerCallInProgress = false;
var _headerCallRinging = false;
var selectedAutocompleteIndex = -1;

// Zil/çevirme tonu admin panelde (Santral Ayarları) seçilebilir; seçilmemişse
// varsayılan dosyalar kullanılır (bkz. header.php).
var ringerAudio = new Audio(window.WEBRTC_RING_INCOMING_URL || "/assets/sounds/ringtone.mp3");
ringerAudio.loop = true;
// Giden aramada "çalıyor" durumu için gelen çağrı zilinden ayrı bir ton.
var ringbackAudio = new Audio(window.WEBRTC_RING_OUTGOING_URL || "/assets/sounds/ring1.mp3");
ringbackAudio.loop = true;
var activeCallNotification = null;

// =========================================================================
// 1. PHONE MODE & BREAK STATUS MANAGEMENT
// =========================================================================

function getActivePhoneModes() {
    if (Array.isArray(window.USER_PHONE_MODES) && window.USER_PHONE_MODES.length > 0) {
        return window.USER_PHONE_MODES;
    }
    const raw = (window.ALLOWED_PHONE_MODE || 'both').trim();
    if (raw === 'both') return ['web', 'mobil', 'sip', 'video'];
    if (raw === 'webrtc_only') return ['web', 'mobil', 'video'];
    if (raw === 'sip_only') return ['sip'];
    return raw.split(',').map(s => s.trim());
}

function setHeaderPhoneMode(mode) {
    const modes = getActivePhoneModes();
    const hasWeb = modes.includes('web');
    const hasSip = modes.includes('sip');

    if (hasWeb && !hasSip) {
        mode = "webrtc";
    } else if (hasSip && !hasWeb) {
        mode = "sip";
    }
    localStorage.setItem("phone_mode", mode);
    updateHeaderPhoneModeUI();
    if (typeof updatePhoneModeUI === "function") updatePhoneModeUI();
    if (window.notify) {
        window.notify.success(mode === "sip" ? "Telefon Modu: Masaüstü SIP Telefon" : "Telefon Modu: WebRTC (Tarayıcı)");
    }
}

function updateHeaderPhoneModeUI() {
    let mode = localStorage.getItem("phone_mode") || "webrtc";
    const modes = getActivePhoneModes();
    const hasWeb = modes.includes('web');
    const hasSip = modes.includes('sip');

    if (hasWeb && !hasSip) {
        mode = "webrtc";
    } else if (hasSip && !hasWeb) {
        mode = "sip";
    }

    const btnWeb = document.getElementById("header-mode-webrtc");
    const btnSip = document.getElementById("header-mode-sip");

    if (btnWeb && btnSip) {
        if (mode === "sip") {
            btnWeb.style.background = "transparent";
            btnWeb.style.color = "var(--text-muted)";
            btnSip.style.background = "var(--secondary)";
            btnSip.style.color = "#ffffff";
        } else {
            btnWeb.style.background = "var(--primary)";
            btnWeb.style.color = "#ffffff";
            btnSip.style.background = "transparent";
            btnSip.style.color = "var(--text-muted)";
        }
    }
}

function handleHeaderBreakChange(reason) {
    if (!reason || reason === "") {
        // End break (Çalışıyor)
        UIHelper.ccPost('unpause')
        .then(data => {
            if (data.success) {
                if (window.notify) window.notify.success("Moladan dönüldü, aktif durumdasınız");
                if (typeof checkAgentStatus === "function") checkAgentStatus();
            } else {
                if (window.notify) window.notify.error(data.error || "Moladan dönülemedi");
            }
            // Sunucudaki gerçek durumu yansıt: başarılı da olsa başarısız da
            // olsa, dropdown önceden seçilmiş yanlış bir değerde asılı kalmasın.
            syncHeaderBreakStatus();
        })
        .catch(() => {
            if (window.notify) window.notify.error("Moladan dönülemedi (bağlantı hatası)");
            syncHeaderBreakStatus();
        });
    } else {
        // Start break (Mola Ver)
        UIHelper.ccPost('pause', { reason: reason })
        .then(data => {
            if (data.success) {
                if (window.notify) window.notify.warning("Mola başlatıldı: " + reason);
                if (typeof checkAgentStatus === "function") checkAgentStatus();
            } else {
                if (window.notify) window.notify.error(data.error || "Mola başlatılamadı");
            }
            syncHeaderBreakStatus();
        })
        .catch(() => {
            if (window.notify) window.notify.error("Mola başlatılamadı (bağlantı hatası)");
            syncHeaderBreakStatus();
        });
    }
}

var _syncHeaderBreakStatusInFlight = false;
function syncHeaderBreakStatus() {
    // 15sn'lik polling döngüsü önceki isteğin bitip bitmediğini kontrol etmiyordu
    // — yavaş bir ağda yanıtlar sırayla dönmezse eski veri yeni veriyi ezebiliyordu
    // (düşük etkili ama gerçek bir race condition, 2026-08-21 denetiminde bulundu).
    if (_syncHeaderBreakStatusInFlight) return;
    _syncHeaderBreakStatusInFlight = true;

    UIHelper.ccGet('get_status')
        .then(data => {
            if (!data.success) return;
            const selectEl = document.getElementById("header-break-select");
            if (selectEl) {
                if (data.is_paused && data.pause_reason) {
                    selectEl.value = data.pause_reason;
                    selectEl.style.borderColor = "var(--warning)";
                    selectEl.style.background = "rgba(245, 158, 11, 0.15)";
                } else {
                    selectEl.value = "";
                    selectEl.style.borderColor = "var(--border-color)";
                    selectEl.style.background = "var(--bg-input)";
                }
            }
        }).catch(e => {}).finally(() => { _syncHeaderBreakStatusInFlight = false; });
}

// =========================================================================
// 2. SOFTPHONE DRAWER & DTMF
// =========================================================================

function toggleHeaderSoftphoneDrawer() {
    const drawer = document.getElementById("headerSoftphoneDrawer");
    if (drawer) {
        drawer.style.display = (drawer.style.display === "flex") ? "none" : "flex";
    }
}

function closeHeaderSoftphoneDrawer() {
    const drawer = document.getElementById("headerSoftphoneDrawer");
    if (drawer) {
        drawer.style.display = "none";
    }
}

function headerPhonePressKey(digit) {
    headerPhoneDigits += digit;
    const display = document.getElementById("header-phone-display");
    if (display) display.value = headerPhoneDigits;
    const input = document.getElementById("header-quick-dial-input");
    if (input) input.value = headerPhoneDigits;

    if (headerJsSipSession && typeof headerJsSipSession.sendDTMF === "function") {
        try {
            headerJsSipSession.sendDTMF(digit);
        } catch (e) {}
    }
}

function headerPhoneClear() {
    headerPhoneDigits = "";
    const display = document.getElementById("header-phone-display");
    if (display) display.value = "";
    const input = document.getElementById("header-quick-dial-input");
    if (input) input.value = "";
}

// =========================================================================
// 3. JSSIP WEBRTC CORE INITIALIZATION
// =========================================================================

function initHeaderWebRTCPhone() {
    if (window.HEADER_WEBRTC_INITIALIZED || headerSipRegistered) {
        console.log("JsSIP Phone already initialized. Skipping duplicate init.");
        return;
    }

    const extFallback = window.CURRENT_USER_EXT || "";
    if (!extFallback) {
        console.log("No extension assigned to current user. WebRTC softphone disabled.");
        return;
    }

    window.HEADER_WEBRTC_INITIALIZED = true;

    // Fetch SIP credentials securely on demand
    fetch("/api/sip_credentials.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-CSRF-Token": window.CSRF_TOKEN || ""
        },
        body: "csrf_token=" + encodeURIComponent(window.CSRF_TOKEN || "")
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success || !data.sip_password) {
            console.warn("SIP credentials unavailable. Softphone running in AMI Originate Mode.");
            updateHeaderPhoneStatus("HAZIR", "var(--text-muted)");
            return;
        }
        if (data.turn && data.turn.urls) {
            // Dış ağdan / symmetric NAT arkasından arayanlar için TURN rölesi
            // (STUN tek başına yetmiyor). Kimlik zaman-sınırlıdır (1 saat),
            // bu yüzden sayfa açık kaldığı sürece periyodik tazelenir.
            applyHeaderPhoneTurn(data.turn);
            startHeaderPhoneTurnRefresh();
        }
        initHeaderJsSIPPhone(data.extension || window.CURRENT_USER_EXT || "", data.sip_username || "", data.sip_password);
    })
    .catch(err => {
        console.error("SIP credentials fetch failed. AMI Originate Mode active:", err);
    });
}

// Bir RTCPeerConnection için uzak/yerel medya olay dinleyicilerini bağlar.
// Hem giden aramalarda (headerPhoneMakeCall -> eventHandlers.peerconnection)
// hem gelen aramalarda (newRTCSession -> session.on("peerconnection")) aynı
// fonksiyon kullanılır — bkz. initHeaderJsSIPPhone'daki not.
function attachPeerConnectionHandlers(pc) {
    pc.ontrack = (event) => {
        const audioEl = document.getElementById("globalRemoteAudio");
        if (!audioEl) return;
        // event.streams[0] bazı SDP/negotiation durumlarında boş gelebilir;
        // bu durumda track'ten elle bir MediaStream kurulur (tarayıcı sesi
        // yine de çalabilsin diye).
        const stream = (event.streams && event.streams[0]) ? event.streams[0] : new MediaStream([event.track]);
        audioEl.srcObject = stream;
        audioEl.play().catch((err) => console.warn("Uzak ses oynatma başarısız:", err));
    };
    pc.onaddstream = (event) => {
        const audioEl = document.getElementById("globalRemoteAudio");
        if (audioEl && event.stream) {
            audioEl.srcObject = event.stream;
            audioEl.play().catch(() => {});
        }
    };
}

function initHeaderJsSIPPhone(ext, webrtcUsername, pass) {
    if (typeof JsSIP === "undefined") {
        console.warn("JsSIP library not loaded. Falling back to AMI Originate Mode.");
        return;
    }

    try {
        if (JsSIP.debug && typeof JsSIP.debug.disable === "function") {
            JsSIP.debug.disable();
        }
    } catch (e) {}

    // Ensure Global Remote Audio Element
    let remoteAudio = document.getElementById("globalRemoteAudio");
    if (!remoteAudio) {
        remoteAudio = document.createElement("audio");
        remoteAudio.id = "globalRemoteAudio";
        remoteAudio.autoplay = true;
        document.body.appendChild(remoteAudio);
    }

    const host = window.location.hostname;
    const wsProto = window.location.protocol === "https:" ? "wss://" : "ws://";
    const wsPath = window.PORTAL_WS_PATH || "/ws";
    const wsUrl = wsProto + window.location.host + wsPath;

    try {
        const socket = new JsSIP.WebSocketInterface(wsUrl);
        const configuration = {
            sockets: [socket],
            uri: "sip:" + webrtcUsername + "@" + host,
            password: pass,
            display_name: "Kullanici " + ext,
            register: true,
            session_timers: false,
            connection_recovery_min_interval: 2,
            connection_recovery_max_interval: 30
        };

        headerJsSipUA = new JsSIP.UA(configuration);

        // Connection Lifecycle Events
        headerJsSipUA.on("connecting", () => {
            updateHeaderPhoneStatus("BAĞLANIYOR...", "var(--warning)");
        });

        headerJsSipUA.on("connected", () => {
            console.log("JsSIP: Connected to Asterisk WebSocket.");
        });

        headerJsSipUA.on("disconnected", () => {
            headerSipRegistered = false;
            updateHeaderPhoneStatus("ÇEVRİMDİŞİ", "var(--danger)");
        });

        headerJsSipUA.on("registered", () => {
            headerSipRegistered = true;
            console.log("JsSIP: Registered successfully as " + webrtcUsername);
            updateHeaderPhoneStatus("ÇEVRİMİÇİ (WebRTC)", "var(--success)");
            syncAgentAutoLogin();
        });

        headerJsSipUA.on("unregistered", () => {
            headerSipRegistered = false;
            updateHeaderPhoneStatus("ÇEVRİMDİŞİ", "var(--danger)");
        });

        headerJsSipUA.on("registrationFailed", (e) => {
            headerSipRegistered = false;
            console.warn("JsSIP Registration Failed:", e.cause);
            updateHeaderPhoneStatus("ÇEVRİMDİŞİ", "var(--danger)");
        });

        // Incoming / Outgoing Call Event
        headerJsSipUA.on("newRTCSession", (data) => {
            const session = data.session;
            headerJsSipSession = session;

            // Stream / Media Attachment
            session.on("peerconnection", (e) => attachPeerConnectionHandlers(e.peerconnection));

            if (session.direction === "incoming") {
                _headerCallRinging = true;
                const remoteId = session.remote_identity ? (session.remote_identity.display_name || session.remote_identity.uri.user) : "Gelen Arama";
                window.CURRENT_INCOMING_CALLER = remoteId;
                updateHeaderPhoneStatus("GELEN ÇAĞRI: " + remoteId, "var(--warning)");
                playRingtone();
                showDesktopNotification(remoteId);
                startTitleBlink(remoteId);
                showIncomingCallBanner(remoteId);
            } else {
                updateHeaderPhoneStatus("ARANIYOR...", "var(--warning)");
            }

            session.on("progress", () => {
                if (session.direction === "outgoing") {
                    updateHeaderPhoneStatus("ÇALIYOR...", "var(--warning)");
                    playRingback();
                }
            });

            session.on("accepted", () => {
                _headerCallRinging = false;
                stopRingtone();
                stopRingback();
                clearDesktopNotification();
                stopTitleBlink();
                hideIncomingCallBanner();
                updateHeaderPhoneStatus("GÖRÜŞÜLÜYOR (WebRTC)", "var(--success)");
                if (window.notify) window.notify.success("Çağrı cevaplandı");
            });

            session.on("confirmed", () => {
                _headerCallRinging = false;
                stopRingtone();
                stopRingback();
                clearDesktopNotification();
                stopTitleBlink();
                hideIncomingCallBanner();
                updateHeaderPhoneStatus("GÖRÜŞÜLÜYOR (WebRTC)", "var(--success)");
            });

            session.on("ended", () => {
                if (_headerCallRinging) {
                    if (window.notify) window.notify.info("Gelen çağrı sonlandı.");
                }
                headerPhoneResetUI();
            });

            session.on("failed", (e) => {
                if (session.direction === "incoming") {
                    if (_headerCallRinging && window.notify) {
                        window.notify.info("Gelen çağrı sonlandı (iptal edildi veya başka cihazdan cevaplandı).");
                    }
                } else {
                    // Giden WebRTC araması başarısız oldu (ör. bağlantı/kayıt o an
                    // kararsızdı, ICE/DTLS kurulamadı) — önceden burada hiçbir
                    // bildirim yoktu, kullanıcı "aradım ama hiçbir şey olmadı"
                    // diye şikayet ediyordu çünkü arayüz sessizce sıfırlanıyordu.
                    const cause = (e && e.cause) ? e.cause : "";
                    if (window.notify) {
                        window.notify.error("Arama başlatılamadı" + (cause ? " (" + cause + ")" : "") + ". Tekrar deneyin.");
                    }
                }
                _headerCallRinging = false;
                headerPhoneResetUI();
            });

            session.on("hold", () => {
                headerIsOnHold = true;
                updateHoldButtonUI(true);
            });

            session.on("unhold", () => {
                headerIsOnHold = false;
                updateHoldButtonUI(false);
            });
        });

        // Start UA
        headerJsSipUA.start();

    } catch (err) {
        console.error("JsSIP Init Error:", err);
    }
}

// =========================================================================
// 4. CALL CONTROL ACTIONS (ANSWER, REJECT, HANGUP, MAKE CALL)
// =========================================================================

function headerPhoneAnswerCall() {
    const remoteAudio = document.getElementById("globalRemoteAudio");
    if (remoteAudio) {
        remoteAudio.play().catch(e => {});
    }

    if (!headerJsSipSession || headerJsSipSession.isEnded()) {
        if (window.notify) window.notify.warning("Cevaplanacak aktif bir çağrı yok.");
        headerPhoneResetUI();
        return;
    }

    stopRingtone();
    stopRingback();
    clearDesktopNotification();

    const options = {
        mediaConstraints: { audio: getPreferredMicConstraint(), video: false },
        pcConfig: { iceServers: HEADER_PHONE_ICE_SERVERS }
    };

    try {
        headerJsSipSession.answer(options);
        updateHeaderPhoneStatus("GÖRÜŞÜLÜYOR (WebRTC)", "var(--success)");
        hideIncomingCallBanner();

        // Temsilci başka bir sayfadaysa, çağrı kopmadan müşteri ve not ekranına (cc-agent) SPA ile geç
        if (window.IS_CC_AGENT && !window.location.pathname.startsWith('/cc-agent')) {
            if (typeof window.loadSPAPage === 'function') {
                window.loadSPAPage('/cc-agent', true);
            }
        }
    } catch (e) {
        console.error("JsSIP Answer error:", e);
        headerPhoneHangup();
    }
}

function headerPhoneRejectCall() {
    stopRingtone();
    stopRingback();
    clearDesktopNotification();

    if (headerJsSipSession && !headerJsSipSession.isEnded()) {
        try {
            headerJsSipSession.terminate({
                status_code: 486,
                reason_phrase: "Busy Here"
            });
        } catch (e) {}
    }
    headerPhoneHangup();
}

function headerPhoneHangup() {
    headerIsOnHold = false;
    _headerCallRinging = false;
    stopRingtone();
    stopRingback();
    clearDesktopNotification();
    stopTitleBlink();
    hideIncomingCallBanner();

    if (headerJsSipSession && !headerJsSipSession.isEnded()) {
        try {
            headerJsSipSession.terminate();
        } catch (e) {
            console.warn("JsSIP Hangup error:", e);
        }
        headerJsSipSession = null;
    }

    // Parallel Server-Side AMI Hangup — "SIP Masaüstü Telefon" (AMI Originate)
    // modunda bu, çağrının kapandığını santrale bildiren TEK sinyaldir (JsSIP
    // session hiç oluşmaz). Önceden bu istek sessizce yutuluyordu (ccPost() HTTP
    // 200 + {success:false} durumunda hiç reddetmiyor, sadece ağ hatasında
    // reddediyor) — arayüz her durumda "kapandı" gösterip UI'yı sıfırlıyordu,
    // ama santral tarafında kanal hâlâ açık/köprülenmiş kalabiliyordu. Artık
    // hem {success:false} hem ağ hatası durumunda kullanıcı uyarılıyor.
    UIHelper.ccPost('hangup').then(res => {
        if (!res || !res.success) {
            if (window.notify) window.notify.warning("Çağrı santral tarafında tam olarak sonlandırılamamış olabilir, lütfen kontrol edin.");
        }
    }).catch(err => {
        if (window.notify) window.notify.warning("Çağrı santral tarafında tam olarak sonlandırılamamış olabilir, lütfen kontrol edin.");
    });

    // Clear and stop remote audio stream
    const remoteAudio = document.getElementById("globalRemoteAudio");
    if (remoteAudio) {
        try {
            if (remoteAudio.srcObject) {
                const stream = remoteAudio.srcObject;
                if (typeof stream.getTracks === "function") {
                    stream.getTracks().forEach(t => t.stop());
                }
                remoteAudio.srcObject = null;
            }
            remoteAudio.pause();
            remoteAudio.currentTime = 0;
        } catch(e) {}
    }

    headerPhoneResetUI();
}

function headerPhoneMakeCall() {
    if (headerCallInProgress) return;

    const display = document.getElementById("header-phone-display");
    const input = document.getElementById("header-quick-dial-input");
    const target = (input && input.value.trim()) ? input.value.trim() : (display ? display.value.trim() : headerPhoneDigits);
    if (!target) {
        if (window.notify) window.notify.warning("Lütfen bir numara veya dahili girin!");
        return;
    }

    headerCallInProgress = true;
    setTimeout(function () { headerCallInProgress = false; }, 3000);

    const phoneMode = localStorage.getItem("phone_mode") || "webrtc";
    if (phoneMode === "sip") {
        headerPhoneMakeCallAMI(target);
        return;
    }

    if (headerJsSipUA && headerSipRegistered) {
        const targetUri = "sip:" + target + "@" + window.location.hostname;
        updateHeaderPhoneStatus("ARANIYOR...", "var(--warning)");
        try {
            const options = {
                mediaConstraints: { audio: getPreferredMicConstraint(), video: false },
                pcConfig: { iceServers: HEADER_PHONE_ICE_SERVERS },
                // Giden aramalarda JsSIP, session.on("peerconnection", ...) ile
                // dinlenmeye fırsat kalmadan RTCPeerConnection'ı call() içinde
                // senkron olarak oluşturup 'peerconnection' olayını hemen ateşler
                // — bu yüzden dinleyici burada, call() tetiklenmeden önce
                // eventHandlers ile bağlanıyor (bkz. attachPeerConnectionHandlers).
                eventHandlers: {
                    peerconnection: (e) => attachPeerConnectionHandlers(e.peerconnection)
                }
            };
            headerJsSipSession = headerJsSipUA.call(targetUri, options);
            if (window.notify) window.notify.success("WebRTC arama başlatıldı: " + target);
        } catch (err) {
            console.error("JsSIP Call error, falling back to AMI:", err);
            headerPhoneMakeCallAMI(target);
        }
    } else {
        headerPhoneMakeCallAMI(target);
    }
}

function headerPhoneMakeCallAMI(target) {
    updateHeaderPhoneStatus("ARANIYOR...", "var(--warning)");

    UIHelper.ccPost('originate', { to: target })
    .then(data => {
        if (data.success) {
            updateHeaderPhoneStatus("GÖRÜŞMEDE", "var(--success)");
            if (window.notify) {
                window.notify.success("Santral araması başlatıldı: " + target);
            }
        } else {
            if (window.notify) {
                window.notify.error(data.error || "Arama başlatılamadı");
            }
            headerPhoneHangup();
        }
    })
    .catch(err => {
        console.error("Phone Originate Error:", err);
        if (window.notify) {
            window.notify.error("Arama başlatılırken sunucu hatası oluştu");
        }
        headerPhoneHangup();
    });
}

function headerPhoneToggleHold() {
    if (!headerJsSipSession) return;

    if (!headerIsOnHold) {
        try {
            headerJsSipSession.hold({ useUpdate: true });
        } catch (e) {}
        headerIsOnHold = true;
        updateHoldButtonUI(true);
    } else {
        try {
            headerJsSipSession.unhold({ useUpdate: true });
        } catch (e) {}
        headerIsOnHold = false;
        updateHoldButtonUI(false);
    }

    UIHelper.ccPost('hold').catch(err => {});
}

function updateHoldButtonUI(isOnHold) {
    const holdBtn = document.getElementById("header-hold-btn");
    const holdText = document.getElementById("header-hold-text");
    if (isOnHold) {
        if (holdText) holdText.textContent = "Sürdür";
        if (holdBtn) {
            holdBtn.className = "topbar-btn btn-info";
            const icon = holdBtn.querySelector("i");
            if (icon) icon.className = "fas fa-play";
        }
    } else {
        if (holdText) holdText.textContent = "Beklet";
        if (holdBtn) {
            holdBtn.className = "topbar-btn btn-warning";
            const icon = holdBtn.querySelector("i");
            if (icon) icon.className = "fas fa-pause";
        }
    }
}

function headerPhonePromptTransfer() {
    const target = prompt("Çağrıyı aktarmak istediğiniz dahili numarayı girin:");
    if (!target || !target.trim()) return;
    headerPhoneTransfer(target.trim());
}

function headerPhoneTransfer(target) {
    if (!target) return;

    if (headerJsSipSession && typeof headerJsSipSession.refer === "function") {
        try {
            const targetUri = "sip:" + target + "@" + window.location.hostname;
            headerJsSipSession.refer(targetUri);
        } catch (e) {
            console.warn("JsSIP transfer error:", e);
        }
    }

    UIHelper.ccPost('transfer', { to: target })
    .then(data => {
        if (data.success) {
            if (window.notify) window.notify.success("Çağrı " + target + " dahilisine aktarılıyor...");
        } else {
            if (window.notify) window.notify.error(data.error || "Aktarma başarısız");
        }
    })
    .catch(err => {
        if (window.notify) window.notify.error("Aktarma işlemi sırasında hata oluştu");
    });
}

// =========================================================================
// 5. TELEFON AYARLARI (MİKROFON/HOPARLÖR SEÇİMİ, ZİL SESİ DÜZEYİ)
// =========================================================================
// Tercihler tarayıcı bazlı (localStorage) saklanır — sunucuya gönderilmez.
// Header'daki telefon durum rozetine tıklanınca açılan modal (bkz.
// templates/phone_settings_modal.php) üzerinden yönetilir.

function getPreferredMicConstraint() {
    const savedId = localStorage.getItem("phone_mic_device_id");
    return {
        deviceId: savedId && savedId !== "default" ? { exact: savedId } : undefined,
        echoCancellation: { ideal: true },
        noiseSuppression: { ideal: true },
        autoGainControl: { ideal: true },
        sampleRate: { ideal: 48000 },
        channelCount: { ideal: 1 }
    };
}

function isSpeakerSelectionSupported() {
    const remoteAudio = document.getElementById("globalRemoteAudio");
    return !!(remoteAudio && typeof remoteAudio.setSinkId === "function");
}

function applySavedSpeakerDevice() {
    const savedId = localStorage.getItem("phone_speaker_device_id");
    const remoteAudio = document.getElementById("globalRemoteAudio");
    if (savedId && savedId !== "default") {
        if (remoteAudio && typeof remoteAudio.setSinkId === "function") {
            remoteAudio.setSinkId(savedId).catch(err => console.warn("Kayıtlı hoparlör ayarlanamadı:", err));
        }
        if (ringerAudio && typeof ringerAudio.setSinkId === "function") {
            ringerAudio.setSinkId(savedId).catch(err => console.warn("Kayıtlı zil hoparlörü ayarlanamadı:", err));
        }
    }
}

function applyRingVolume() {
    const saved = parseFloat(localStorage.getItem("phone_ring_volume"));
    const vol = isNaN(saved) ? 1 : Math.min(1, Math.max(0, saved));
    ringerAudio.volume = vol;
    ringbackAudio.volume = vol;
}

function openPhoneSettingsModal() {
    UIHelper.openOverlayModal("phoneSettingsModal");
    populatePhoneDeviceSelects();
}

function closePhoneSettingsModal() {
    UIHelper.closeOverlayModal("phoneSettingsModal");
}

function populatePhoneDeviceSelects() {
    const micSelects = [document.getElementById("phone_mic_select"), document.getElementById("my_phone_mic_select")].filter(Boolean);
    const spkSelects = [document.getElementById("phone_speaker_select"), document.getElementById("my_phone_speaker_select")].filter(Boolean);
    const spkWrapper = document.getElementById("phone_speaker_select_wrapper");
    const spkUnsupported = document.getElementById("phone_speaker_unsupported");
    const speakerSupported = isSpeakerSelectionSupported();

    if (spkWrapper) spkWrapper.style.display = speakerSupported ? "" : "none";
    if (spkUnsupported) spkUnsupported.style.display = speakerSupported ? "none" : "";

    const volSlider = document.getElementById("phone_ring_volume_slider");
    if (volSlider) {
        const savedVol = parseFloat(localStorage.getItem("phone_ring_volume"));
        volSlider.value = isNaN(savedVol) ? 100 : Math.round(savedVol * 100);
    }
    const myVolSlider = document.getElementById("my_phone_ring_slider");
    const myVolLabel = document.getElementById("my-phone-vol-label");
    if (myVolSlider) {
        const savedVol = parseFloat(localStorage.getItem("phone_ring_volume"));
        const pct = isNaN(savedVol) ? 100 : Math.round(savedVol * 100);
        myVolSlider.value = pct;
        if (myVolLabel) myVolLabel.textContent = pct + "%";
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
        micSelects.forEach(s => s.innerHTML = '<option value="default">Sistem Varsayılanı</option>');
        spkSelects.forEach(s => s.innerHTML = '<option value="default">Sistem Varsayılanı</option>');
        return;
    }

    const applyDevices = (devices) => {
        const savedMic = localStorage.getItem("phone_mic_device_id") || "default";
        const savedSpk = localStorage.getItem("phone_speaker_device_id") || "default";

        if (micSelects.length > 0) {
            let html = '<option value="default">Sistem Varsayılanı</option>';
            let micIdx = 1;
            devices.filter(d => d.kind === "audioinput").forEach(d => {
                const label = d.label || ("Mikrofon " + (micIdx++));
                html += '<option value="' + escapeHtml(d.deviceId) + '"' + (d.deviceId === savedMic ? " selected" : "") + '>' + escapeHtml(label) + '</option>';
            });
            micSelects.forEach(s => s.innerHTML = html);
        }

        if (spkSelects.length > 0) {
            let html = '<option value="default">Sistem Varsayılanı</option>';
            let spkIdx = 1;
            devices.filter(d => d.kind === "audiooutput").forEach(d => {
                const label = d.label || ("Hoparlör " + (spkIdx++));
                html += '<option value="' + escapeHtml(d.deviceId) + '"' + (d.deviceId === savedSpk ? " selected" : "") + '>' + escapeHtml(label) + '</option>';
            });
            spkSelects.forEach(s => s.innerHTML = html);
        }
    };

    navigator.mediaDevices.enumerateDevices().then(devices => {
        const hasLabels = devices.some(d => (d.kind === "audioinput" || d.kind === "audiooutput") && d.label);
        if (hasLabels) {
            applyDevices(devices);
        } else if (navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
                stream.getTracks().forEach(t => t.stop());
                return navigator.mediaDevices.enumerateDevices();
            }).catch(() => navigator.mediaDevices.enumerateDevices())
            .then(applyDevices);
        } else {
            applyDevices(devices);
        }
    }).catch(err => {
        console.warn("Cihaz listesi alınamadı:", err);
        micSelects.forEach(s => s.innerHTML = '<option value="default">Sistem Varsayılanı</option>');
        spkSelects.forEach(s => s.innerHTML = '<option value="default">Sistem Varsayılanı</option>');
    });
}

function savePhoneMicDevice(val) {
    localStorage.setItem("phone_mic_device_id", val);
    const m1 = document.getElementById("phone_mic_select");
    const m2 = document.getElementById("my_phone_mic_select");
    if (m1 && m1.value !== val) m1.value = val;
    if (m2 && m2.value !== val) m2.value = val;
    if (window.notify) window.notify.success("Mikrofon tercihi kaydedildi. Bir sonraki aramada geçerli olacak.");
}

function savePhoneSpeakerDevice(val) {
    localStorage.setItem("phone_speaker_device_id", val);
    const s1 = document.getElementById("phone_speaker_select");
    const s2 = document.getElementById("my_phone_speaker_select");
    if (s1 && s1.value !== val) s1.value = val;
    if (s2 && s2.value !== val) s2.value = val;
    applySavedSpeakerDevice();
    if (window.notify) window.notify.success("Hoparlör tercihi kaydedildi.");
}

function savePhoneRingVolume(val) {
    const vol = Math.min(100, Math.max(0, parseInt(val, 10) || 0)) / 100;
    localStorage.setItem("phone_ring_volume", String(vol));
    applyRingVolume();
    const v1 = document.getElementById("phone_ring_volume_slider");
    const v2 = document.getElementById("my_phone_ring_slider");
    const pct = Math.round(vol * 100);
    if (v1 && parseInt(v1.value, 10) !== pct) v1.value = pct;
    if (v2 && parseInt(v2.value, 10) !== pct) v2.value = pct;
    const lbl = document.getElementById("my-phone-vol-label");
    if (lbl) lbl.textContent = pct + "%";
}

function testPhoneSpeaker() {
    const spkSelect = document.getElementById("phone_speaker_select") || document.getElementById("my_phone_speaker_select");
    const savedId = (spkSelect && spkSelect.value) ? spkSelect.value : (localStorage.getItem("phone_speaker_device_id") || "default");
    const testAudio = new Audio(window.WEBRTC_RING_OUTGOING_URL || "/assets/sounds/ring1.mp3");
    testAudio.volume = parseFloat(localStorage.getItem("phone_ring_volume")) || 1;
    if (savedId && savedId !== "default" && typeof testAudio.setSinkId === "function") {
        testAudio.setSinkId(savedId).then(() => testAudio.play()).catch(err => {
            console.warn("Test hoparlörü ayarlanamadı:", err);
            testAudio.play().catch(() => {});
        });
    } else {
        testAudio.play().catch(() => {});
    }
}

// =========================================================================
// 6. UI STATE & RINGTONE
// =========================================================================

function playRingtone() {
    try {
        ringerAudio.currentTime = 0;
        const p = ringerAudio.play();
        if (p !== undefined) {
            p.catch(function(e) {});
        }
    } catch (e) {}
}

function stopRingtone() {
    try {
        ringerAudio.pause();
        ringerAudio.currentTime = 0;
    } catch (e) {}
}

function playRingback() {
    try {
        ringbackAudio.currentTime = 0;
        const p = ringbackAudio.play();
        if (p !== undefined) {
            p.catch(function(e) {});
        }
    } catch (e) {}
}

function stopRingback() {
    try {
        ringbackAudio.pause();
        ringbackAudio.currentTime = 0;
    } catch (e) {}
}

var _origDocTitle = document.title;
var _titleBlinkInterval = null;

function startTitleBlink(callerInfo) {
    if (_titleBlinkInterval) return;
    _origDocTitle = document.title;
    let toggle = false;
    _titleBlinkInterval = setInterval(() => {
        document.title = toggle ? "📞 GELEN ÇAĞRI: " + (callerInfo || "Santral") : "🔔 ÇAĞRI GELİYOR!";
        toggle = !toggle;
    }, 800);
}

function stopTitleBlink() {
    if (_titleBlinkInterval) {
        clearInterval(_titleBlinkInterval);
        _titleBlinkInterval = null;
        document.title = _origDocTitle;
    }
}

function showIncomingCallBanner(caller) {
    let banner = document.getElementById("globalIncomingCallBanner");
    if (!banner) {
        banner = document.createElement("div");
        banner.id = "globalIncomingCallBanner";
        banner.style.cssText = "position: fixed; top: 20px; right: 20px; z-index: 999999; background: var(--bg-card, #ffffff); border: 2px solid var(--primary, #0284c7); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); padding: 16px 20px; min-width: 320px; display: flex; flex-direction: column; gap: 12px; transition: all 0.2s ease;";
        document.body.appendChild(banner);
    }
    const safeCaller = escapeHtml(caller || "Santral Çağrısı");
    banner.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 44px; height: 44px; border-radius: 50%; background: rgba(34, 197, 94, 0.15); color: var(--success, #22c55e); display: flex; align-items: center; justify-content: center; font-size: 20px;" class="header-btn-pulse">
                <i class="fas fa-phone-volume"></i>
            </div>
            <div style="flex: 1; overflow: hidden;">
                <div style="font-size: 11px; font-weight: 700; color: var(--warning, #f59e0b); text-transform: uppercase; letter-spacing: 0.5px;">Gelen Çağrı</div>
                <div style="font-size: 16px; font-weight: 700; color: var(--text-main, #1e293b); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">${safeCaller}</div>
            </div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 4px;">
            <button type="button" class="btn btn-success" onclick="headerPhoneAnswerCall()" style="flex: 1; padding: 9px 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <i class="fas fa-phone"></i> Cevapla
            </button>
            <button type="button" class="btn btn-danger" onclick="headerPhoneRejectCall()" style="flex: 1; padding: 9px 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <i class="fas fa-phone-slash"></i> Reddet
            </button>
        </div>
    `;
    banner.style.display = "flex";
}

function hideIncomingCallBanner() {
    const banner = document.getElementById("globalIncomingCallBanner");
    if (banner) {
        banner.style.display = "none";
    }
}

function syncAgentAutoLogin() {
    if (window.IS_CC_AGENT) {
        // Temsilci bu oturumda elle çıkış yaptıysa otomatik girişi zorlama
        if (sessionStorage.getItem('cc_agent_manual_logout') === '1') {
            return;
        }
        UIHelper.ccPost('auto_login', { last_queues: '[]' }).catch(() => {});
    }
}

function showDesktopNotification(callerInfo) {
    if (!("Notification" in window) || Notification.permission !== "granted") return;
    try {
        if (activeCallNotification) activeCallNotification.close();
        activeCallNotification = new Notification("📞 GELEN ÇAĞRI: " + (callerInfo || "Santral Araması"), {
            body: "Gelen çağrıyı yanıtlamak için tıklayın.",
            icon: "/favicon.ico",
            tag: "ai_pbx_call",
            requireInteraction: true
        });
        activeCallNotification.onclick = function() {
            window.focus();
            headerPhoneAnswerCall();
            activeCallNotification.close();
        };
    } catch (e) {}
}

function clearDesktopNotification() {
    if (activeCallNotification) {
        try {
            activeCallNotification.close();
            activeCallNotification = null;
        } catch (e) {}
    }
}

function updateHeaderPhoneStatus(text, color) {
    const stateEl = document.getElementById("header-phone-state");
    if (stateEl) {
        stateEl.textContent = text;
        stateEl.style.color = color;
    }

    const textEl = document.getElementById("header-status-text");
    const dotEl = document.getElementById("header-status-dot");
    const pillEl = document.getElementById("header-phone-status-pill");

    const ext = window.CURRENT_USER_EXT || "3000";
    if (textEl) textEl.textContent = ext;
    if (dotEl) dotEl.style.background = color || "var(--text-muted)";
    if (pillEl) {
        pillEl.style.borderColor = color ? color : "var(--border-color)";
        pillEl.title = "Telefon Durumu: " + text;
    }

    const myPhoneWebrtc = document.getElementById("my-phone-webrtc-text");
    if (myPhoneWebrtc) {
        if (headerSipRegistered) {
            myPhoneWebrtc.textContent = "Çevrimiçi";
            myPhoneWebrtc.style.color = "var(--success)";
        } else if (text && text.includes("BAĞLANIYOR")) {
            myPhoneWebrtc.textContent = "Bağlanıyor...";
            myPhoneWebrtc.style.color = "var(--warning)";
        } else if (text && text.includes("ÇEVRİMDİŞİ")) {
            myPhoneWebrtc.textContent = "Çevrimdışı";
            myPhoneWebrtc.style.color = "var(--danger)";
        }
    }

    // Switch Header Call Control Groups
    const idleCtrls = document.getElementById("header-idle-controls");
    const incomingCtrls = document.getElementById("header-incoming-controls");
    const activeCtrls = document.getElementById("header-active-controls");

    const upperText = (text || "").toUpperCase();

    if (upperText.includes("GELEN")) {
        if (idleCtrls) idleCtrls.style.display = "none";
        if (incomingCtrls) incomingCtrls.style.display = "flex";
        if (activeCtrls) activeCtrls.style.display = "none";
    } else if (upperText.includes("GÖRÜŞ") || upperText.includes("ARAN") || upperText.includes("ÇALIYOR") || upperText.includes("BEKLET")) {
        if (idleCtrls) idleCtrls.style.display = "none";
        if (incomingCtrls) incomingCtrls.style.display = "none";
        if (activeCtrls) activeCtrls.style.display = "flex";
    } else {
        if (idleCtrls) idleCtrls.style.display = "flex";
        if (incomingCtrls) incomingCtrls.style.display = "none";
        if (activeCtrls) activeCtrls.style.display = "none";
    }
}

function headerPhoneResetUI() {
    headerIsOnHold = false;
    _headerCallRinging = false;
    stopRingtone();
    stopRingback();
    clearDesktopNotification();
    stopTitleBlink();
    hideIncomingCallBanner();
    updateHoldButtonUI(false);
    updateHeaderPhoneStatus(headerSipRegistered ? "ÇEVRİMİÇİ (WebRTC)" : "HAZIR", headerSipRegistered ? "var(--success)" : "var(--text-muted)");
    headerPhoneClear();
}

// =========================================================================
// 7. AUTOCOMPLETE & QUICK DIAL INPUT
// =========================================================================

function handleHeaderQuickDialInput(val) {
    const dropdown = document.getElementById("header-autocomplete-dropdown");
    if (!dropdown) return;

    val = val.trim().toLowerCase();
    if (!val || !window.SYSTEM_EXTENSIONS || !window.SYSTEM_EXTENSIONS.length) {
        dropdown.style.display = "none";
        return;
    }

    const matches = window.SYSTEM_EXTENSIONS.filter(item => {
        const extMatch = item.extension && item.extension.toLowerCase().includes(val);
        const nameMatch = item.full_name && item.full_name.toLowerCase().includes(val);
        return extMatch || nameMatch;
    }).slice(0, 8);

    if (matches.length === 0) {
        dropdown.style.display = "none";
        return;
    }

    selectedAutocompleteIndex = -1;
    let html = "";
    matches.forEach((item) => {
        const safeName = escapeHtml(item.full_name);
        const safeExt = escapeJsAttr(item.extension);
        html += `<div class="header-autocomplete-item" data-ext="${safeExt}" onclick="selectHeaderAutocomplete('${safeExt}')">
            <strong>${safeName}</strong>
            <span>${safeExt}</span>
        </div>`;
    });

    dropdown.innerHTML = html;
    dropdown.style.display = "block";
}

function selectHeaderAutocomplete(ext) {
    const input = document.getElementById("header-quick-dial-input");
    const dropdown = document.getElementById("header-autocomplete-dropdown");
    if (input) input.value = ext;
    if (dropdown) dropdown.style.display = "none";
    headerQuickDialCall();
}

function handleHeaderQuickDialKeydown(e) {
    const dropdown = document.getElementById("header-autocomplete-dropdown");
    const items = dropdown ? dropdown.querySelectorAll(".header-autocomplete-item") : [];
    const isVisible = dropdown && dropdown.style.display !== "none";

    if (e.key === "ArrowDown") {
        if (items.length > 0 && isVisible) {
            e.preventDefault();
            selectedAutocompleteIndex = (selectedAutocompleteIndex + 1) % items.length;
            highlightHeaderAutocompleteItem(items);
        }
    } else if (e.key === "ArrowUp") {
        if (items.length > 0 && isVisible) {
            e.preventDefault();
            selectedAutocompleteIndex = (selectedAutocompleteIndex - 1 + items.length) % items.length;
            highlightHeaderAutocompleteItem(items);
        }
    } else if (e.key === "Enter") {
        e.preventDefault();
        const input = document.getElementById("header-quick-dial-input");
        if (isVisible && items.length > 0) {
            let targetExt = "";
            if (selectedAutocompleteIndex >= 0 && items[selectedAutocompleteIndex]) {
                targetExt = items[selectedAutocompleteIndex].getAttribute("data-ext");
            } else if (selectedAutocompleteIndex === -1 && items[0]) {
                targetExt = items[0].getAttribute("data-ext");
            }
            if (targetExt && input) {
                input.value = targetExt;
            }
        }
        if (dropdown) dropdown.style.display = "none";
        headerQuickDialCall();
    } else if (e.key === "Escape") {
        if (dropdown) dropdown.style.display = "none";
    }
}

function highlightHeaderAutocompleteItem(items) {
    items.forEach((item, idx) => {
        if (idx === selectedAutocompleteIndex) {
            item.classList.add("selected");
        } else {
            item.classList.remove("selected");
        }
    });
}

function headerQuickDialCall() {
    const input = document.getElementById("header-quick-dial-input");
    const target = input ? input.value.trim() : "";
    if (!target) {
        if (window.notify) window.notify.warning("Lütfen bir numara veya dahili girin!");
        return;
    }
    const display = document.getElementById("header-phone-display");
    if (display) display.value = target;
    headerPhoneMakeCall();
}

function toggleUserProfileDropdown(e) {
    if (e) e.stopPropagation();
    const dropdown = document.getElementById("userProfileDropdown");
    const arrow = document.getElementById("user-dropdown-arrow");
    if (dropdown) {
        const isVisible = dropdown.style.display === "block";
        dropdown.style.display = isVisible ? "none" : "block";
        if (arrow) arrow.style.transform = isVisible ? "rotate(0deg)" : "rotate(180deg)";
    }
}

// =========================================================================
// 8. EVENT LISTENERS & BACKGROUND WATCHDOG
// =========================================================================

function ensureNotificationPermission() {
    if ("Notification" in window && Notification.permission === "default") {
        try {
            Notification.requestPermission().catch(() => {});
        } catch (e) {}
    }
}

document.addEventListener("click", function _unlockAudioAndNotify() {
    ensureNotificationPermission();
    if (ringerAudio) {
        try {
            const p = ringerAudio.play();
            if (p !== undefined) {
                p.then(() => {
                    ringerAudio.pause();
                    ringerAudio.currentTime = 0;
                }).catch(() => {});
            }
        } catch (e) {}
    }
    const remote = document.getElementById("globalRemoteAudio");
    if (remote) {
        try { remote.play().catch(() => {}); } catch (e) {}
    }
    document.removeEventListener("click", _unlockAudioAndNotify);
}, { once: true });

// WebRTC Bağlantı ve Kayıt İzleme (Arka plandaki sekmelerde WebSocket düşerse otomatik toparlar)
setInterval(() => {
    if (headerJsSipUA) {
        if (!headerJsSipUA.isConnected()) {
            try { headerJsSipUA.start(); } catch (e) {}
        } else if (!headerSipRegistered) {
            try { headerJsSipUA.register(); } catch (e) {}
        }
    }
}, 15000);

document.addEventListener("visibilitychange", () => {
    if (!document.hidden && headerJsSipUA) {
        if (!headerSipRegistered) {
            try { headerJsSipUA.register(); } catch (e) {}
        }
    }
});

document.addEventListener("DOMContentLoaded", () => {
    updateHeaderPhoneModeUI();
    syncHeaderBreakStatus();
    setInterval(syncHeaderBreakStatus, 15000);
    initHeaderWebRTCPhone();
    applyRingVolume();
    applySavedSpeakerDevice();
    syncAgentAutoLogin();
});

document.addEventListener("click", function(e) {
    const wrapper = document.querySelector(".header-quick-dial-wrapper");
    const dropdown = document.getElementById("header-autocomplete-dropdown");
    if (dropdown && wrapper && !wrapper.contains(e.target)) {
        dropdown.style.display = "none";
    }

    const userWrapper = document.querySelector(".user-profile-wrapper");
    const userDropdown = document.getElementById("userProfileDropdown");
    const userArrow = document.getElementById("user-dropdown-arrow");
    if (userDropdown && userWrapper && !userWrapper.contains(e.target)) {
        userDropdown.style.display = "none";
        if (userArrow) userArrow.style.transform = "rotate(0deg)";
    }
});
