/**
 * WebRTC Video Meeting & Lobby Controller (Powered by Asterisk ConfBridge SFU)
 */
(function () {
    'use strict';

    const config = window.MEETING_CONFIG || {};
    let localStream = null;
    let screenStream = null;
    let micEnabled = true;
    let camEnabled = true;
    let isScreenSharing = false;
    let callTimerInterval = null;
    let callStartTime = null;

    // DOM Elements
    const lobbyScreen = document.getElementById('lobbyScreen');
    const roomScreen = document.getElementById('roomScreen');
    const endedScreen = document.getElementById('endedScreen');
    const lobbyVideo = document.getElementById('lobbyVideo');
    const localVideo = document.getElementById('localVideo');
    const previewPlaceholder = document.getElementById('previewPlaceholder');
    const userNameInput = document.getElementById('userNameInput');
    const pinInput = document.getElementById('pinInput');
    const btnJoinRoom = document.getElementById('btnJoinRoom');
    const callTimer = document.getElementById('callTimer');
    const videoGrid = document.getElementById('videoGrid');
    const localNameTag = document.getElementById('localNameTag');
    const localMicIcon = document.getElementById('localMicIcon');

    // Buttons
    const lobbyMicBtn = document.getElementById('lobbyMicBtn');
    const lobbyCamBtn = document.getElementById('lobbyCamBtn');
    const roomMicBtn = document.getElementById('roomMicBtn');
    const roomCamBtn = document.getElementById('roomCamBtn');
    const roomShareBtn = document.getElementById('roomShareBtn');
    const roomCopyBtn = document.getElementById('roomCopyBtn');
    const roomLeaveBtn = document.getElementById('roomLeaveBtn');

    // 1. Initialize Device Media
    async function initMedia() {
        try {
            localStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: { width: { ideal: 1280 }, height: { ideal: 720 } }
            });
            lobbyVideo.srcObject = localStream;
        } catch (err) {
            console.warn('Could not acquire audio/video stream:', err);
            // Fallback to audio-only if video fails
            try {
                localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                camEnabled = false;
                updateCamUI();
            } catch (audioErr) {
                console.error('No audio/video devices found:', audioErr);
            }
        }
    }

    function toggleMic() {
        micEnabled = !micEnabled;
        if (localStream) {
            localStream.getAudioTracks().forEach(t => t.enabled = micEnabled);
        }
        updateMicUI();
    }

    function toggleCam() {
        camEnabled = !camEnabled;
        if (localStream) {
            localStream.getVideoTracks().forEach(t => t.enabled = camEnabled);
        }
        updateCamUI();
    }

    function updateMicUI() {
        const iconClass = micEnabled ? 'fa-microphone' : 'fa-microphone-slash';
        lobbyMicBtn.innerHTML = `<i class="fas ${iconClass}"></i>`;
        roomMicBtn.innerHTML = `<i class="fas ${iconClass}"></i>`;
        lobbyMicBtn.classList.toggle('btn-off', !micEnabled);
        roomMicBtn.classList.toggle('btn-off', !micEnabled);
        if (localMicIcon) {
            localMicIcon.className = `fas ${iconClass}`;
            localMicIcon.style.color = micEnabled ? '#fff' : 'var(--danger)';
        }
    }

    function updateCamUI() {
        const iconClass = camEnabled ? 'fa-video' : 'fa-video-slash';
        lobbyCamBtn.innerHTML = `<i class="fas ${iconClass}"></i>`;
        roomCamBtn.innerHTML = `<i class="fas ${iconClass}"></i>`;
        lobbyCamBtn.classList.toggle('btn-off', !camEnabled);
        roomCamBtn.classList.toggle('btn-off', !camEnabled);

        if (lobbyVideo) {
            lobbyVideo.style.display = camEnabled ? 'block' : 'none';
        }
        if (previewPlaceholder) {
            previewPlaceholder.classList.toggle('hidden', camEnabled);
        }
        if (localVideo) {
            localVideo.style.opacity = camEnabled ? '1' : '0';
        }
    }

    // 2. Screen Sharing
    function replacePeerVideoTrack(newTrack) {
        if (!jsSipSession || !jsSipSession.connection) return;
        const senders = jsSipSession.connection.getSenders ? jsSipSession.connection.getSenders() : [];
        const videoSender = senders.find(s => s.track && s.track.kind === 'video');
        if (videoSender && typeof videoSender.replaceTrack === 'function') {
            videoSender.replaceTrack(newTrack).catch(err => console.warn('replaceTrack failed:', err));
        }
    }

    async function toggleScreenShare() {
        if (!isScreenSharing) {
            try {
                screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
                const screenTrack = screenStream.getVideoTracks()[0];
                localVideo.srcObject = screenStream;
                replacePeerVideoTrack(screenTrack);
                isScreenSharing = true;
                roomShareBtn.classList.add('btn-off');

                screenTrack.onended = () => {
                    stopScreenShare();
                };
            } catch (e) {
                console.warn('Screen share cancelled:', e);
            }
        } else {
            stopScreenShare();
        }
    }

    function stopScreenShare() {
        if (screenStream) {
            screenStream.getTracks().forEach(t => t.stop());
            screenStream = null;
        }
        if (localStream) {
            localVideo.srcObject = localStream;
            const camTrack = localStream.getVideoTracks()[0] || null;
            if (camTrack) {
                replacePeerVideoTrack(camTrack);
            }
        }
        isScreenSharing = false;
        roomShareBtn.classList.remove('btn-off');
    }

    // 3. Grid Layout Auto-Resize
    function updateGridLayout() {
        const tiles = videoGrid.querySelectorAll('.video-tile:not(.hidden)');
        const count = tiles.length;
        videoGrid.className = 'video-grid';
        if (count <= 1) videoGrid.classList.add('tiles-1');
        else if (count === 2) videoGrid.classList.add('tiles-2');
        else if (count <= 4) videoGrid.classList.add('tiles-4');
        else if (count <= 6) videoGrid.classList.add('tiles-6');
        else videoGrid.classList.add('tiles-9');
    }

    // 4. Timer
    function startTimer() {
        callStartTime = Date.now();
        callTimer.classList.remove('hidden');
        callTimerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - callStartTime) / 1000);
            const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
            const s = String(elapsed % 60).padStart(2, '0');
            callTimer.innerHTML = `<i class="far fa-clock"></i> ${m}:${s}`;
        }, 1000);
    }

    function stopTimer() {
        if (callTimerInterval) clearInterval(callTimerInterval);
        callTimer.classList.add('hidden');
    }

    // 5. Join Room Action
    function joinMeeting() {
        const name = (userNameInput.value || '').trim();
        if (!name) {
            userNameInput.focus();
            return;
        }

        const i18n = config.i18n || {};
        localNameTag.textContent = name + ' (' + (i18n.you || 'You') + ')';

        // Switch Screen
        lobbyScreen.classList.add('hidden');
        roomScreen.classList.remove('hidden');

        if (localStream) {
            localVideo.srcObject = localStream;
        }

        startTimer();
        updateGridLayout();

        // Connect WebRTC SIP / Asterisk ConfBridge session
        initAsteriskWebRTC(name);
    }

    let jsSipSession = null;
    let jsSipUA = null;

    function initAsteriskWebRTC(displayName) {
        if (typeof JsSIP === 'undefined') {
            console.warn('JsSIP library not loaded. Running in local simulation mode.');
            return;
        }

        const wsProto = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
        const wsUrl = wsProto + window.location.host + (config.ws_path || '/ws');
        const socket = new JsSIP.WebSocketInterface(wsUrl);

        const sipUser = config.sip_user || ('guest_' + (config.token || Math.random().toString(36).substr(2, 9)));
        const sipUri = 'sip:' + sipUser + '@' + window.location.hostname;

        const uaConfig = {
            sockets: [socket],
            uri: sipUri,
            display_name: displayName,
            register: false
        };
        if (config.sip_password) {
            uaConfig.authorization_user = sipUser;
            uaConfig.password = config.sip_password;
        }

        try {
            jsSipUA = new JsSIP.UA(uaConfig);
            jsSipUA.start();

            jsSipUA.on('connected', () => {
                const target = 'sip:' + config.room_number + '@' + window.location.hostname;
                const callOptions = {
                    mediaStream: localStream,
                    extraHeaders: ['X-Meeting-Token: ' + (config.join_token || '')],
                    pcConfig: { iceServers: config.ice_servers || [] },
                    eventHandlers: {
                        peerconnection: (e) => {
                            const pc = e.peerconnection;
                            pc.addEventListener('track', (ev) => {
                                handleRemoteTrack(ev);
                            });
                        },
                        confirmed: () => {
                            const enteredPin = pinInput ? (pinInput.value || '').replace(/[^0-9]/g, '') : '';
                            if (enteredPin !== '' && jsSipSession) {
                                setTimeout(() => {
                                    try {
                                        jsSipSession.sendDTMF(enteredPin + '#');
                                    } catch (dtmfErr) {
                                        console.warn('DTMF PIN send warning:', dtmfErr);
                                    }
                                }, 800);
                            }
                        },
                        ended: () => leaveMeeting(),
                        failed: (e) => {
                            console.warn('Conference Call failed:', e);
                            leaveMeeting();
                        }
                    }
                };
                jsSipSession = jsSipUA.call(target, callOptions);
            });
        } catch (e) {
            console.warn('JsSIP initialization warning:', e);
        }
    }

    function handleRemoteTrack(ev) {
        if (ev.track.kind === 'audio') {
            const remoteAudio = document.getElementById('remoteAudio');
            if (remoteAudio && ev.streams && ev.streams[0]) {
                remoteAudio.srcObject = ev.streams[0];
                remoteAudio.play().catch(() => {});
            }
            return;
        }

        // SFU Remote Video Track
        const stream = (ev.streams && ev.streams[0]) ? ev.streams[0] : new MediaStream([ev.track]);
        const streamId = stream.id || ev.track.id;
        const i18n = config.i18n || {};
        let tile = document.getElementById('remote_' + streamId);
        if (!tile) {
            tile = document.createElement('div');
            tile.id = 'remote_' + streamId;
            tile.className = 'video-tile remote-tile';
            tile.innerHTML = `
                <video autoplay playsinline></video>
                <div class="tile-name">
                    <i class="fas fa-satellite-dish"></i>
                    <span></span>
                </div>
            `;
            const nameSpan = tile.querySelector('.tile-name span');
            if (nameSpan) {
                nameSpan.textContent = i18n.participant || 'Participant';
            }
            // Asterisk answers every call with a video stream of its own that may never
            // carry a frame: the tile appears when media arrives, not before.
            if (ev.track.muted) {
                tile.classList.add('hidden');
                ev.track.addEventListener('unmute', () => {
                    tile.classList.remove('hidden');
                    updateGridLayout();
                }, { once: true });
            }
            videoGrid.appendChild(tile);
            updateGridLayout();

            ev.track.addEventListener('ended', () => {
                if (tile && tile.parentNode) {
                    tile.parentNode.removeChild(tile);
                    updateGridLayout();
                }
            });
        }

        const vid = tile.querySelector('video');
        if (vid) {
            vid.srcObject = stream;
            vid.play().catch(() => {});
        }
    }

    function leaveMeeting() {
        stopTimer();
        stopScreenShare();
        if (localStream) {
            localStream.getTracks().forEach(t => t.stop());
            localStream = null;
        }
        if (jsSipSession) {
            try { jsSipSession.terminate(); } catch (e) {}
            jsSipSession = null;
        }
        if (jsSipUA) {
            try { jsSipUA.stop(); } catch (e) {}
            jsSipUA = null;
        }

        roomScreen.classList.add('hidden');
        endedScreen.classList.remove('hidden');
    }

    function copyLink() {
        const url = window.location.href;
        const i18n = config.i18n || {};
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(() => {
                alert(i18n.link_copied || 'Meeting link copied to clipboard!');
            }).catch(() => {
                prompt(i18n.copy_prompt || 'Copy link:', url);
            });
        } else {
            prompt(i18n.copy_prompt || 'Copy link:', url);
        }
    }

    // Event Listeners
    lobbyMicBtn.addEventListener('click', toggleMic);
    lobbyCamBtn.addEventListener('click', toggleCam);
    roomMicBtn.addEventListener('click', toggleMic);
    roomCamBtn.addEventListener('click', toggleCam);
    roomShareBtn.addEventListener('click', toggleScreenShare);
    roomCopyBtn.addEventListener('click', copyLink);
    roomLeaveBtn.addEventListener('click', leaveMeeting);
    btnJoinRoom.addEventListener('click', joinMeeting);

    // Initialize
    initMedia();
    updateMicUI();
    updateCamUI();

})();
