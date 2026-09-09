<?php
/**
 * Header Phone Settings Modal (Microphone/Speaker Device Selection, Ring Volume)
 * Header'daki telefon durum rozetine tıklanınca açılır (bkz. topbar.php).
 */
?>
<div class="modal-overlay" id="phoneSettingsModal">
    <div class="modal-card" style="max-width: 460px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;"><i class="fas fa-sliders-h" style="color: var(--primary);"></i> <?php echo t('phone_settings.title'); ?></h3>
            <button class="btn btn-secondary" onclick="closePhoneSettingsModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-microphone"></i> <?php echo t('phone_settings.field_mic'); ?></label>
                <select id="phone_mic_select" class="form-control" onchange="savePhoneMicDevice(this.value)">
                    <option value="default"><?php echo t('phone_settings.loading'); ?></option>
                </select>
                <small style="color: var(--text-muted); font-size: 11px;"><?php echo t('phone_settings.mic_help'); ?></small>
            </div>

            <div class="form-group" id="phone_speaker_select_wrapper">
                <label class="form-label"><i class="fas fa-volume-up"></i> <?php echo t('phone_settings.field_speaker'); ?></label>
                <div style="display: flex; gap: 8px;">
                    <select id="phone_speaker_select" class="form-control" onchange="savePhoneSpeakerDevice(this.value)" style="flex: 1;">
                        <option value="default"><?php echo t('phone_settings.loading'); ?></option>
                    </select>
                    <button type="button" class="btn btn-secondary" onclick="testPhoneSpeaker()" title="<?php echo t('phone_settings.test_sound_tooltip'); ?>"><i class="fas fa-play"></i></button>
                </div>
            </div>
            <div class="form-group" id="phone_speaker_unsupported" style="display: none;">
                <small style="color: var(--text-muted); font-size: 11px;"><i class="fas fa-info-circle"></i> <?php echo t('phone_settings.speaker_unsupported'); ?></small>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label"><i class="fas fa-bell"></i> <?php echo t('phone_settings.field_ring_volume'); ?></label>
                <input type="range" id="phone_ring_volume_slider" min="0" max="100" value="100" style="width: 100%;" oninput="savePhoneRingVolume(this.value)">
            </div>
        </div>
    </div>
</div>
