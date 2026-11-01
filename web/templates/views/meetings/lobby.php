<?php
/**
 * Standalone WebRTC Video Meeting Room & Pre-Join Lobby (/meet/{token})
 * Open to anonymous guests and logged-in participants.
 */
$pageTitle = htmlspecialchars($meeting['title'] ?? 'Video Meeting') . ' - AiPBX';
$brandName = defined('BRAND_TITLE') ? BRAND_TITLE : 'AiPBX';
$roomNumber = (string)($meeting['room_number'] ?? '');
$videoMode = (string)($meeting['video_mode'] ?? 'sfu');
$hasPin = !empty($meeting['pin']) || !empty($meeting['user_pin']) || !empty($meeting['host_pin']);

// STUN/TURN ice servers
$iceServers = [];
if (defined('TURN_SECRET') && TURN_SECRET !== '' && defined('TURN_HOST') && defined('TURNS_PORT')) {
    $uname = (time() + 3600) . ':guest_' . $token;
    $iceServers[] = [
        'urls' => ['turns:' . TURN_HOST . ':' . TURNS_PORT . '?transport=tcp'],
        'username' => $uname,
        'credential' => base64_encode(hash_hmac('sha1', $uname, TURN_SECRET, true)),
    ];
}

$meetingConfig = [
    'token' => $token,
    'join_token' => $joinToken,
    'room_number' => $roomNumber,
    'title' => $meeting['title'] ?? 'Video Meeting',
    'video_mode' => $videoMode,
    'role' => $role ?? 'guest',
    'default_name' => $defaultName ?? '',
    'has_pin' => $hasPin,
    'ws_path' => defined('PJSIP_WS_PATH') ? PJSIP_WS_PATH : '/ws',
    'ice_servers' => $iceServers,
    'sip_user' => 'meeting-guest',
    'sip_password' => getMeetingGuestSipSecret(),
    'i18n' => [
        'you' => t('meetings.lobby_you', 'You'),
        'participant' => t('meetings.col_participants', 'Participant'),
        'link_copied' => t('js.meetings.link_copied', 'Meeting link copied to clipboard!'),
        'copy_prompt' => t('js.meetings.copy_prompt', 'Copy meeting link:'),
    ],
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getUserLanguage()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-surface: #1e293b;
            --bg-card: #334155;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --success: #10b981;
            --warning: #f59e0b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #475569;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* Top Bar */
        .top-bar {
            height: 56px;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 50;
        }
        .top-bar .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: -0.5px;
        }
        .top-bar .brand i { color: var(--primary); }
        .top-bar .meeting-info {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-sfu { background: #8b5cf6; color: #fff; }
        .badge-talker { background: #06b6d4; color: #fff; }
        .badge-room { background: var(--bg-card); color: var(--text-muted); border: 1px solid var(--border); }

        /* Container & Screens */
        .screen-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }
        .hidden { display: none !important; }

        /* Lobby Card */
        .lobby-card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            width: 100%;
            max-width: 900px;
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
        }
        @media (max-width: 768px) {
            .lobby-card { grid-template-columns: 1fr; }
        }
        .preview-pane {
            background: #000;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 360px;
        }
        .preview-pane video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }
        .preview-placeholder {
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            gap: 12px;
        }
        .preview-controls {
            position: absolute;
            bottom: 16px;
            display: flex;
            gap: 12px;
            z-index: 10;
        }
        .round-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: none;
            background: rgba(30, 41, 59, 0.85);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.2s ease;
            backdrop-filter: blur(4px);
        }
        .round-btn:hover { background: var(--bg-card); transform: scale(1.05); }
        .round-btn.btn-off { background: var(--danger); color: #fff; }

        .form-pane {
            padding: 36px 32px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .form-pane h2 { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
        .form-pane p { font-size: 13px; color: var(--text-muted); margin-bottom: 24px; }
        .input-group { margin-bottom: 18px; }
        .input-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; }
        .input-control {
            width: 100%;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 14px;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: border 0.2s;
        }
        .input-control:focus { border-color: var(--primary); }
        .btn-join {
            width: 100%;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
            transition: background 0.2s;
        }
        .btn-join:hover { background: var(--primary-hover); }

        /* Room Screen */
        .room-screen {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            background: #0b1120;
        }
        .video-grid {
            flex: 1;
            display: grid;
            gap: 12px;
            padding: 16px;
            align-content: center;
            justify-content: center;
            overflow: hidden;
        }
        /* Grid auto sizing */
        .video-grid.tiles-1 { grid-template-columns: minmax(300px, 960px); grid-template-rows: 1fr; }
        .video-grid.tiles-2 { grid-template-columns: repeat(2, minmax(280px, 640px)); grid-template-rows: 1fr; }
        .video-grid.tiles-3, .video-grid.tiles-4 { grid-template-columns: repeat(2, minmax(280px, 500px)); grid-template-rows: repeat(2, 1fr); }
        .video-grid.tiles-5, .video-grid.tiles-6 { grid-template-columns: repeat(3, minmax(240px, 420px)); grid-template-rows: repeat(2, 1fr); }
        .video-grid.tiles-7, .video-grid.tiles-8, .video-grid.tiles-9 { grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(3, 1fr); }

        .video-tile {
            background: var(--bg-surface);
            border-radius: 12px;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            aspect-ratio: 16 / 9;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3);
        }
        .video-tile.speaking { border-color: var(--success); }
        .video-tile video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .video-tile.local-tile video {
            transform: scaleX(-1);
        }
        .tile-name {
            position: absolute;
            bottom: 10px;
            left: 10px;
            background: rgba(15, 23, 42, 0.75);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            backdrop-filter: blur(4px);
        }
        .avatar-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
        }

        /* Bottom Controls Bar */
        .controls-bar {
            height: 72px;
            background: var(--bg-dark);
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 0 20px;
            z-index: 40;
        }
        .controls-bar .round-btn { width: 44px; height: 44px; font-size: 16px; }
        .controls-bar .btn-leave { background: var(--danger); }
        .controls-bar .btn-leave:hover { background: var(--danger-hover); }

        /* Left/Ended Screen */
        .ended-card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 48px;
            text-align: center;
            max-width: 480px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);
        }
        .ended-card i { font-size: 48px; color: var(--primary); margin-bottom: 16px; }
        .ended-card h2 { font-size: 22px; margin-bottom: 10px; }
        .ended-card p { color: var(--text-muted); font-size: 14px; margin-bottom: 24px; }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="top-bar">
        <div class="brand">
            <i class="fas fa-video"></i>
            <span><?php echo htmlspecialchars($brandName); ?> Meet</span>
        </div>
        <div class="meeting-info">
            <span class="badge badge-room"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($roomNumber); ?></span>
            <span class="badge <?php echo $videoMode === 'sfu' ? 'badge-sfu' : 'badge-talker'; ?>">
                <i class="fas fa-satellite-dish"></i> <?php echo strtoupper($videoMode); ?>
            </span>
            <span id="callTimer" class="badge badge-room hidden"><i class="far fa-clock"></i> 00:00</span>
        </div>
    </header>

    <div class="screen-container">

        <!-- SCREEN 1: PRE-JOIN LOBBY -->
        <div id="lobbyScreen" class="lobby-card">
            <div class="preview-pane">
                <video id="lobbyVideo" autoplay playsinline muted></video>
                <div id="previewPlaceholder" class="preview-placeholder hidden">
                    <i class="fas fa-video-slash fa-3x"></i>
                    <span><?php echo t('meetings.lobby_cam_off', 'Camera is off'); ?></span>
                </div>
                <div class="preview-controls">
                    <button id="lobbyMicBtn" class="round-btn" title="Microphone" type="button">
                        <i class="fas fa-microphone"></i>
                    </button>
                    <button id="lobbyCamBtn" class="round-btn" title="Camera" type="button">
                        <i class="fas fa-video"></i>
                    </button>
                </div>
            </div>

            <div class="form-pane">
                <h2><?php echo htmlspecialchars($meeting['title'] ?? 'Video Conference'); ?></h2>
                <p><?php echo t('meetings.lobby_subtitle', 'Check your audio and video before entering the room.'); ?></p>

                <div class="input-group">
                    <label for="userNameInput"><?php echo t('meetings.lobby_your_name', 'Your Name'); ?></label>
                    <input type="text" id="userNameInput" class="input-control" placeholder="<?php echo htmlspecialchars(t('meetings.lobby_name_ph', 'Enter your display name')); ?>" value="<?php echo htmlspecialchars($defaultName); ?>" required>
                </div>

                <?php if ($hasPin): ?>
                    <div class="input-group">
                        <label for="pinInput"><?php echo t('conferences.user_pin_lbl', 'Room PIN'); ?></label>
                        <input type="password" id="pinInput" class="input-control" placeholder="<?php echo htmlspecialchars(t('meetings.ph_optional', 'Optional PIN')); ?>" pattern="[0-9]*" inputmode="numeric">
                    </div>
                <?php endif; ?>

                <button id="btnJoinRoom" class="btn-join" type="button">
                    <i class="fas fa-sign-in-alt"></i> <?php echo t('meetings.btn_join_meeting', 'Join Video Meeting'); ?>
                </button>
            </div>
        </div>

        <!-- SCREEN 2: ACTIVE MEETING ROOM -->
        <div id="roomScreen" class="room-screen hidden">
            <div id="videoGrid" class="video-grid tiles-1">
                <!-- Local Video Tile -->
                <div class="video-tile local-tile" id="localTile">
                    <video id="localVideo" autoplay playsinline muted></video>
                    <div class="tile-name">
                        <i class="fas fa-microphone" id="localMicIcon"></i>
                        <span id="localNameTag"><?php echo t('meetings.lobby_you', 'You'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Bottom Floating Dock -->
            <div class="controls-bar">
                <button id="roomMicBtn" class="round-btn" title="Microphone" type="button">
                    <i class="fas fa-microphone"></i>
                </button>
                <button id="roomCamBtn" class="round-btn" title="Camera" type="button">
                    <i class="fas fa-video"></i>
                </button>
                <button id="roomShareBtn" class="round-btn" title="<?php echo htmlspecialchars(t('meetings.lobby_share_screen', 'Share Screen')); ?>" type="button">
                    <i class="fas fa-desktop"></i>
                </button>
                <button id="roomCopyBtn" class="round-btn" title="<?php echo htmlspecialchars(t('meetings.btn_copy_link', 'Copy Link')); ?>" type="button">
                    <i class="fas fa-link"></i>
                </button>
                <button id="roomLeaveBtn" class="round-btn btn-leave" title="<?php echo htmlspecialchars(t('meetings.lobby_leave', 'Leave Meeting')); ?>" type="button">
                    <i class="fas fa-phone-slash"></i>
                </button>
            </div>
        </div>

        <!-- SCREEN 3: MEETING ENDED -->
        <div id="endedScreen" class="ended-card hidden">
            <i class="fas fa-door-open"></i>
            <h2><?php echo t('meetings.lobby_ended_title', 'You left the meeting'); ?></h2>
            <p><?php echo t('meetings.lobby_ended_desc', 'Thank you for participating. You can rejoin at any time.'); ?></p>
            <button class="btn-join" onclick="location.reload()" type="button">
                <i class="fas fa-redo"></i> <?php echo t('meetings.lobby_rejoin', 'Rejoin Meeting'); ?>
            </button>
        </div>

    </div>

    <!-- Hidden audio element for remote stream audio playback -->
    <audio id="remoteAudio" autoplay></audio>

    <script>
        window.MEETING_CONFIG = <?php echo json_encode($meetingConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="/assets/js/jssip.min.js"></script>
    <script src="/assets/js/meeting_room.js"></script>
</body>
</html>
