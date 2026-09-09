/**
 * Sound Prompts & Music on Hold (MOH) Sub-Module Manager JS
 * Features: WaveSurfer 7 Modal Visualizer & Audio Player
 */
// NOT: SPA ile tekrar çalıştırıldığında top-level `let` redeclaration
// SyntaxError verir (bkz. cc_supervisor.php/agent_ui.js'teki aynı not) —
// `var` kullanılır.
var wavesurfer = null;
var currentPlayingFile = null;

function switchSoundTab(tabName) {
    document.querySelectorAll('.sub-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));

    const activeBtn = document.getElementById('tab-btn-' + tabName);
    const activePane = document.getElementById('tab-pane-' + tabName);

    if (activeBtn) activeBtn.classList.add('active');
    if (activePane) activePane.classList.add('active');

    localStorage.setItem('active_sounds_tab', tabName);
}

function openUploadSoundModal() {
    document.getElementById('soundModalTitle').innerHTML = '<i class="fas fa-file-audio" style="color: var(--primary);"></i> Yeni Ses Dosyası Yükle';
    document.getElementById('modal_action_upload').value = '1';
    document.getElementById('modal_action_upload').disabled = false;
    document.getElementById('modal_action_save').value = '0';
    document.getElementById('modal_action_save').disabled = true;
    document.getElementById('modal_anc_id').value = '0';

    document.getElementById('sound_name_group').style.display = 'block';
    document.getElementById('modal_sound_name').required = true;
    document.getElementById('modal_sound_name').value = '';
    document.getElementById('modal_title').value = '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = '';


    document.getElementById('audio_file_label').innerText = 'Ses Dosyası (.wav / .gsm / .alaw) *';
    document.getElementById('modal_audio_file').required = true;
    document.getElementById('modal_audio_file').value = '';
    document.getElementById('audio_file_help').style.display = 'none';

    document.getElementById('modal_sound_submit').innerHTML = '<i class="fas fa-upload"></i> Yükle';

    const modal = document.getElementById('uploadSoundModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditSoundModal(anc) {
    if (!anc) return;

    document.getElementById('soundModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Anons Düzenle #' + anc.id;
    document.getElementById('modal_action_upload').value = '0';
    document.getElementById('modal_action_upload').disabled = true;
    document.getElementById('modal_action_save').value = '1';
    document.getElementById('modal_action_save').disabled = false;
    document.getElementById('modal_anc_id').value = anc.id;

    document.getElementById('sound_name_group').style.display = 'none';
    document.getElementById('modal_sound_name').required = false;
    document.getElementById('modal_sound_name').value = anc.audio_file ? anc.audio_file.replace('custom/', '') : '';
    document.getElementById('modal_title').value = anc.title || '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = (anc.internal_number || '');


    document.getElementById('audio_file_label').innerText = 'Yeni Ses Dosyası Değiştir (Opsiyonel)';
    document.getElementById('modal_audio_file').required = false;
    document.getElementById('modal_audio_file').value = '';
    document.getElementById('audio_file_help').style.display = 'block';

    document.getElementById('modal_sound_submit').innerHTML = '<i class="fas fa-save"></i> Güncelle';

    const modal = document.getElementById('uploadSoundModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeUploadSoundModal() {
    UIHelper.closeOverlayModal('uploadSoundModal');
}

/**
 * MOH sınıfına müzik yükleme modalı.
 *
 * Önceki hâlde MOH satırındaki buton, sayfada HİÇ BULUNMAYAN bir elemana
 * (upload_moh_class_select) yazmaya çalışıyordu; getElementById null döndüğü
 * için JS o satırda duruyor ve modal hiç açılmıyordu. Sessiz bir hataydı —
 * konsolu açmayan kimse sebebini göremezdi. Dolayısıyla upload_moh_file
 * backend'i de hiç çağrılmıyordu (2026-09-01).
 */
function openMohUploadModal(className) {
    const sel = document.getElementById('upload_moh_class_select');
    if (sel && className) { sel.value = className; }

    const dosya = document.getElementById('modal_moh_audio');
    if (dosya) { dosya.value = ''; }

    const modal = document.getElementById('mohUploadModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeMohUploadModal() {
    UIHelper.closeOverlayModal('mohUploadModal');
}

/**
 * WaveSurfer 7 Audio Player & Modal Visualizer
 */
function playAnnouncement(soundFile, title) {
    const cleanName = soundFile.replace('custom/', '').replace('.wav', '');
    const audioUrl = '/api/sound_play.php?file=' + encodeURIComponent(cleanName);

    document.getElementById('playerModalTitle').innerText = title || cleanName;
    document.getElementById('playerModalFile').innerText = 'custom/' + cleanName + '.wav';

    const dlBtn = document.getElementById('waveDownloadBtn');
    if (dlBtn) {
        dlBtn.href = '/api/sound_play.php?file=' + encodeURIComponent(cleanName) + '&download=1';
    }

    const modal = document.getElementById('audioPlayerModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }

    const loader = document.getElementById('waveformLoading');
    if (loader) loader.style.display = 'flex';

    if (wavesurfer) {
        try {
            wavesurfer.destroy();
        } catch (e) {}
        wavesurfer = null;
    }

    currentPlayingFile = cleanName;

    // Detect theme primary colors dynamically
    const primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#4f46e5';

    if (typeof WaveSurfer !== 'undefined') {
        wavesurfer = WaveSurfer.create({
            container: '#waveform',
            waveColor: 'rgba(99, 102, 241, 0.35)',
            progressColor: primaryColor,
            cursorColor: '#f59e0b',
            barWidth: 3,
            barGap: 2,
            barRadius: 3,
            height: 90,
            url: audioUrl,
            normalize: true,
        });

        wavesurfer.on('ready', function() {
            if (loader) loader.style.display = 'none';
            const duration = wavesurfer.getDuration();
            document.getElementById('waveTotalDuration').innerText = formatTime(duration);
            document.getElementById('waveCurrentTime').innerText = '00:00';
            
            wavesurfer.play();
            updateWavePlayButton(true);
        });

        wavesurfer.on('audioprocess', function() {
            const time = wavesurfer.getCurrentTime();
            document.getElementById('waveCurrentTime').innerText = formatTime(time);
        });

        wavesurfer.on('play', function() {
            updateWavePlayButton(true);
        });

        wavesurfer.on('pause', function() {
            updateWavePlayButton(false);
        });

        wavesurfer.on('finish', function() {
            updateWavePlayButton(false);
            document.getElementById('waveCurrentTime').innerText = '00:00';
        });

        wavesurfer.on('error', function(err) {
            console.error('WaveSurfer Load Error:', err);
            if (loader) loader.style.display = 'none';
            if (window.notify && window.notify.error) {
                window.notify.error('Ses dalga formu oluşturulamadı: ' + err);
            }
        });
    } else {
        if (loader) loader.style.display = 'none';
        console.error('WaveSurfer library is not loaded');
    }
}

function toggleWavePlay() {
    if (!wavesurfer) return;
    wavesurfer.playPause();
}

function updateWavePlayButton(isPlaying) {
    const btn = document.getElementById('wavePlayBtn');
    if (!btn) return;
    if (isPlaying) {
        btn.innerHTML = '<i class="fas fa-pause"></i> Durdur';
        btn.classList.add('btn-warning');
        btn.classList.remove('btn-primary');
    } else {
        btn.innerHTML = '<i class="fas fa-play"></i> Oynat';
        btn.classList.add('btn-primary');
        btn.classList.remove('btn-warning');
    }
}

function waveSkip(seconds) {
    if (!wavesurfer) return;
    const currentTime = wavesurfer.getCurrentTime();
    const duration = wavesurfer.getDuration();
    let newTime = currentTime + seconds;
    if (newTime < 0) newTime = 0;
    if (newTime > duration) newTime = duration;
    wavesurfer.seekTo(newTime / duration);
}

function setWaveVolume(val) {
    if (!wavesurfer) return;
    wavesurfer.setVolume(parseFloat(val));
    const icon = document.getElementById('muteIcon');
    if (icon) {
        if (val == 0) {
            icon.className = 'fas fa-volume-mute';
        } else {
            icon.className = 'fas fa-volume-up';
        }
    }
}

function toggleWaveMute() {
    if (!wavesurfer) return;
    const isMuted = wavesurfer.getMuted();
    wavesurfer.setMuted(!isMuted);
    const icon = document.getElementById('muteIcon');
    const slider = document.getElementById('volumeSlider');
    if (icon) {
        if (!isMuted) {
            icon.className = 'fas fa-volume-mute';
            if (slider) slider.value = 0;
        } else {
            icon.className = 'fas fa-volume-up';
            if (slider) slider.value = 1;
        }
    }
}

function closeAudioPlayerModal() {
    if (wavesurfer) {
        try {
            wavesurfer.pause();
        } catch (e) {}
    }
    UIHelper.closeOverlayModal('audioPlayerModal');
}

function formatTime(seconds) {
    seconds = Math.floor(seconds || 0);
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;
}

function openMohModal() {
    const modal = document.getElementById('mohModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeMohModal() {
    UIHelper.closeOverlayModal('mohModal');
}

document.addEventListener('DOMContentLoaded', function() {
    const savedTab = localStorage.getItem('active_sounds_tab') || 'announcements';
    switchSoundTab(savedTab);
});
document.addEventListener('spa:pageLoaded', function() {
    // Bu dinleyici document'a bağlı olduğu için sounds.php'den başka bir
    // sayfaya SPA ile geçildikten SONRA da (o sayfanın kendi spa:pageLoaded
    // olayında) tetiklenmeye devam ediyor. sounds.php'ye özgü bir eleman
    // (mohModal) artık DOM'da yoksa artık bu sayfada değiliz demektir — hâlâ
    // çalıyor olabilecek wavesurfer örneği durdurulup temizleniyor, aksi
    // halde ses arka planda çalmaya devam edip kullanıcının durduracak hiçbir
    // arayüzü kalmıyordu (2026-08-21 denetiminde bulundu).
    if (!document.getElementById('mohModal')) {
        if (wavesurfer) {
            try { wavesurfer.destroy(); } catch (e) {}
            wavesurfer = null;
        }
        return;
    }
    const savedTab = localStorage.getItem('active_sounds_tab') || 'announcements';
    switchSoundTab(savedTab);
});
