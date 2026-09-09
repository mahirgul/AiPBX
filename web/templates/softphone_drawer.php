<?php
/**
 * Header Softphone DTMF Keypad Drawer Component
 */
?>
<!-- Floating Header Softphone Keypad Drawer -->
<div class="header-phone-drawer" id="headerSoftphoneDrawer">
    <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
        <div style="font-size: 13px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
            <i class="fas fa-th" style="color: var(--primary);"></i> <?php echo t('softphone_drawer.title'); ?>
        </div>
        <button type="button" class="btn btn-secondary" onclick="closeHeaderSoftphoneDrawer()" style="padding: 2px 8px; font-size: 11px;" title="<?php echo t('softphone_drawer.close_tooltip'); ?>">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="header-phone-keypad">
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('1')">1</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('2')">2</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('3')">3</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('4')">4</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('5')">5</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('6')">6</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('7')">7</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('8')">8</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('9')">9</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('*')">*</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('0')">0</button>
        <button type="button" class="header-phone-key" onclick="headerPhonePressKey('#')">#</button>
    </div>

    <div style="display: flex; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 8px;">
        <button type="button" class="btn btn-secondary" onclick="headerPhoneClear()" style="font-size: 12px; padding: 6px 12px; width: 100%; justify-content: center; gap: 6px;" title="<?php echo t('softphone_drawer.clear_tooltip'); ?>">
            <i class="fas fa-backspace"></i> <?php echo t('softphone_drawer.clear_label'); ?>
        </button>
    </div>
</div>
