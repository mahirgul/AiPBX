/**
 * CDR Reports WaveSurfer 7 Audio Visualizer & Filter UI Logic
 */
var wavesurferCdr = window.wavesurferCdr || null;
var currentCdrAudioId = window.currentCdrAudioId || 0;

function toggleCustomDates() {
    const sel = document.getElementById('date_range_select').value;
    const inputs = document.getElementById('custom_date_inputs');
    if (inputs) {
        inputs.style.display = (sel === 'custom') ? 'flex' : 'none';
    }
}

function playCdrAudio(cdrId, callerNum, dateTime) {
    currentCdrAudioId = cdrId;
    const titleEl = document.getElementById('cdrModalTitle');
    const infoEl = document.getElementById('cdrModalInfo');
    const dlLink = document.getElementById('cdrDownloadLink');
    const modalEl = document.getElementById('cdrAudioModal');
    const loadingEl = document.getElementById('cdrWaveformLoading');

    if (titleEl) titleEl.innerText = 'Görüşme Kaydı #' + cdrId;
    if (infoEl) infoEl.innerText = 'Arayan: ' + callerNum + ' | ' + dateTime;
    if (dlLink) dlLink.href = '/api/cc_audio.php?id=' + cdrId + '&download=1';
    
    if (modalEl) modalEl.classList.add('active');
    if (loadingEl) loadingEl.style.display = 'flex';

    if (wavesurferCdr) {
        wavesurferCdr.destroy();
    }

    wavesurferCdr = WaveSurfer.create({
        container: '#cdrWaveform',
        waveColor: '#94a3b8',
        progressColor: '#00f2fe',
        cursorColor: '#3b82f6',
        barWidth: 2,
        barRadius: 2,
        cursorWidth: 2,
        height: 80,
        responsive: true
    });

    const audioUrl = '/api/cc_audio.php?id=' + cdrId;
    wavesurferCdr.load(audioUrl);

    wavesurferCdr.on('ready', function () {
        if (loadingEl) loadingEl.style.display = 'none';
        const dur = wavesurferCdr.getDuration();
        const totalDurEl = document.getElementById('cdrTotalDuration');
        const playBtn = document.getElementById('cdrWavePlayBtn');
        if (totalDurEl) totalDurEl.innerText = formatSeconds(dur);
        wavesurferCdr.play();
        if (playBtn) playBtn.innerHTML = '<i class="fas fa-pause"></i>';
    });

    wavesurferCdr.on('audioprocess', function () {
        const time = wavesurferCdr.getCurrentTime();
        const curTimeEl = document.getElementById('cdrCurrentTime');
        if (curTimeEl) curTimeEl.innerText = formatSeconds(time);
    });

    wavesurferCdr.on('finish', function () {
        const playBtn = document.getElementById('cdrWavePlayBtn');
        if (playBtn) playBtn.innerHTML = '<i class="fas fa-play"></i>';
    });
}

function toggleCdrWavePlay() {
    if (!wavesurferCdr) return;
    const playBtn = document.getElementById('cdrWavePlayBtn');
    if (wavesurferCdr.isPlaying()) {
        wavesurferCdr.pause();
        if (playBtn) playBtn.innerHTML = '<i class="fas fa-play"></i>';
    } else {
        wavesurferCdr.play();
        if (playBtn) playBtn.innerHTML = '<i class="fas fa-pause"></i>';
    }
}

function cdrWaveSkip(sec) {
    if (!wavesurferCdr) return;
    wavesurferCdr.skip(sec);
}

function setCdrVolume(val) {
    if (!wavesurferCdr) return;
    wavesurferCdr.setVolume(val);
}

function toggleCdrMute() {
    if (!wavesurferCdr) return;
    const isMuted = wavesurferCdr.getMute();
    wavesurferCdr.setMute(!isMuted);
    const muteIcon = document.getElementById('cdrMuteIcon');
    if (muteIcon) {
        muteIcon.className = isMuted ? 'fas fa-volume-up' : 'fas fa-volume-mute';
    }
}

function closeCdrAudioModal() {
    if (wavesurferCdr) {
        wavesurferCdr.pause();
    }
    UIHelper.closeOverlayModal('cdrAudioModal');
}

function formatSeconds(secs) {
    const s = Math.floor(secs);
    const m = Math.floor(s / 60);
    const r = s % 60;
    return (m < 10 ? '0' + m : m) + ':' + (r < 10 ? '0' + r : r);
}

function toggleCallJourney(id) {
    const row = document.getElementById('journey-row-' + id);
    const btn = document.getElementById('journey-btn-' + id);
    if (!row) return;
    const isHidden = (row.style.display === 'none' || !row.style.display);
    row.style.display = isHidden ? 'table-row' : 'none';
    if (btn) {
        const icon = btn.querySelector('.journey-icon');
        if (icon) {
            icon.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
        }
        if (isHidden) {
            btn.classList.add('active');
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-primary');
        } else {
            btn.classList.remove('active');
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-outline-primary');
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('cdrAudioModal');
    if (modalEl) {
        modalEl.addEventListener('click', function (e) {
            if (e.target === this) {
                closeCdrAudioModal();
            }
        });
    }
});
document.addEventListener('spa:pageLoaded', function() {
    const modalEl = document.getElementById('cdrAudioModal');
    // Bu dinleyici document'a bağlı olduğu için cdr_reports.php'den başka bir
    // sayfaya SPA ile geçildikten SONRA da tetiklenmeye devam ediyor. Modal
    // artık DOM'da yoksa bu sayfada değiliz demektir — hâlâ çalıyor olabilecek
    // wavesurferCdr örneği durdurulup temizleniyor, aksi halde ses arka planda
    // çalmaya devam edip kullanıcının durduracak hiçbir arayüzü kalmıyordu
    // (2026-08-21 denetiminde bulundu).
    if (!modalEl) {
        if (window.wavesurferCdr) {
            try { window.wavesurferCdr.destroy(); } catch (e) {}
            window.wavesurferCdr = null;
        }
        return;
    }
    if (modalEl) {
        modalEl.addEventListener('click', function (e) {
            if (e.target === this) {
                closeCdrAudioModal();
            }
        });
    }
});
