<?php
// Recording player (WaveSurfer) shared by the call report and the queue log:
// playCdrAudio(asteriskcdr id, caller, date) opens it; /api/cc_audio.php checks access.
?>
<!-- WaveSurfer Audio Player Modal Dialog -->
<div class="modal-overlay" id="cdrAudioModal">
    <div class="modal-card" style="max-width: 620px;">
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(0, 242, 254, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                    <i class="fas fa-file-audio"></i>
                </div>
                <div>
                    <h3 class="u-title u-m-0" id="cdrModalTitle"><?php echo t('cdr_reports.player_title'); ?></h3>
                    <small class="u-muted u-fs-11" id="cdrModalInfo"><?php echo t('cdr_reports.player_caller_prefix'); ?> -</small>
                </div>
            </div>
            <button class="btn btn-secondary u-btn-pad" onclick="closeCdrAudioModal()" title="<?php echo t('cdr_reports.close_tooltip'); ?>"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <!-- Waveform Visualizer Canvas Container -->
            <div style="background: rgba(0, 0, 0, 0.04); padding: 16px; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 16px; position: relative;">
                <div id="cdrWaveform" style="width: 100%; min-height: 90px;"></div>
                <div id="cdrWaveformLoading" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.85); border-radius: 12px; font-size: 13px; color: var(--primary); gap: 8px; font-weight: 600; z-index: 5;">
                    <i class="fas fa-spinner fa-spin"></i> <?php echo t('cdr_reports.loading_recording'); ?>
                </div>
            </div>

            <!-- Controls bar -->
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div class="u-flex-center">
                    <button class="btn btn-primary" id="cdrWavePlayBtn" onclick="toggleCdrWavePlay()" style="min-width: 44px;" title="<?php echo t('cdr_reports.play_pause_tooltip'); ?>">
                        <i class="fas fa-play"></i>
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="cdrWaveSkip(-5)" title="<?php echo t('cdr_reports.back5s_tooltip'); ?>">
                        <i class="fas fa-undo"></i> -5s
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="cdrWaveSkip(5)" title="<?php echo t('cdr_reports.forward5s_tooltip'); ?>">
                        <i class="fas fa-redo"></i> +5s
                    </button>
                    <a id="cdrDownloadLink" href="#" class="btn btn-secondary btn-sm" title="<?php echo t('cdr_reports.download_tooltip'); ?>">
                        <i class="fas fa-download"></i>
                    </a>
                </div>

                <!-- Time indicator -->
                <div style="font-family: monospace; font-size: 14px; font-weight: 700; color: var(--primary); background: rgba(0, 242, 254, 0.1); padding: 6px 14px; border-radius: 8px;">
                    <span id="cdrCurrentTime">00:00</span> / <span id="cdrTotalDuration">00:00</span>
                </div>

                <!-- Volume slider -->
                <div class="u-flex-center">
                    <button class="btn btn-secondary btn-sm" id="cdrMuteBtn" onclick="toggleCdrMute()" style="padding: 6px 10px;" title="<?php echo t('cdr_reports.mute_tooltip'); ?>">
                        <i class="fas fa-volume-up" id="cdrMuteIcon"></i>
                    </button>
                    <input type="range" id="cdrVolumeSlider" min="0" max="1" step="0.05" value="1" style="width: 80px; cursor: pointer;" oninput="setCdrVolume(this.value)">
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/wavesurfer.min.js"></script>
<script src="<?php echo asset('/assets/js/cdr_reports.js'); ?>"></script>
