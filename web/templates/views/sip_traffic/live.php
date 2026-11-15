<?php
/** @var array $settings */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-satellite-dish', t('siptrace.live_title')); ?>
<?php echo uiPageHeaderActions(); ?>
        <a href="/sip-traffic" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> <?php echo t('siptrace.back_files'); ?></a>
        <a href="/sip-capture-settings" class="btn btn-secondary btn-sm"><i class="fas fa-sliders"></i> <?php echo t('sidebar.item_sip_capture_settings'); ?></a>
<?php echo uiPageHeaderClose(); ?>

    <?php if (!$settings['capture'] || !$settings['files']): ?>
        <div class="alert alert-warning"><i class="fas fa-info-circle"></i> <?php echo t('siptrace.off_notice'); ?>
            <a href="/sip-capture-settings"><?php echo t('sidebar.item_sip_capture_settings'); ?></a></div>
    <?php endif; ?>

    <div class="u-d-flex u-wrap u-flex-center u-gap-8 u-mb-12">
        <button type="button" id="sipLiveToggle" class="btn btn-secondary btn-sm">
            <i class="fas fa-pause"></i> <span><?php echo t('siptrace.live_pause'); ?></span>
        </button>
        <button type="button" id="sipLiveClear" class="btn btn-secondary btn-sm">
            <i class="fas fa-eraser"></i> <?php echo t('siptrace.live_clear'); ?>
        </button>
        <input type="text" id="sipLiveFilter" class="form-control form-control-sm u-maxw-320" placeholder="<?php echo t('siptrace.filter_ph'); ?>">
    </div>

    <?php echo uiTableOpen([
        t('siptrace.col_time'),
        t('siptrace.col_method'),
        t('siptrace.col_caller'),
        t('siptrace.col_called'),
        ['label' => t('siptrace.col_src'), 'class' => 'col-hide-mobile'],
        ['label' => t('siptrace.col_dst'), 'class' => 'col-hide-mobile'],
    ], ['id' => 'sipLiveTable', 'body_id' => 'sipLiveBody', 'search' => false, 'frame' => 'u-maxh-360 u-of-auto']); ?>
        <tr id="sipLiveEmpty"><td colspan="6" class="text-muted text-center u-fs-12"><?php echo t('siptrace.live_waiting'); ?></td></tr>
    <?php echo uiTableClose(); ?>

    <div class="u-mt-10">
        <div class="u-fs-12 u-muted" id="sipLiveHead"><?php echo t('siptrace.click_message'); ?></div>
        <pre id="sipLiveText" class="u-mono u-fs-12" style="max-height: 420px; overflow: auto; white-space: pre-wrap; background: var(--bg-hover, #f1f5f9); padding: 12px; border-radius: 6px;"></pre>
    </div>
</div>

<script>
(function () {
    const tbody = document.getElementById('sipLiveBody');
    const emptyRow = document.getElementById('sipLiveEmpty');
    const toggleBtn = document.getElementById('sipLiveToggle');
    const clearBtn = document.getElementById('sipLiveClear');
    const filterInput = document.getElementById('sipLiveFilter');
    const headEl = document.getElementById('sipLiveHead');
    const textEl = document.getElementById('sipLiveText');

    const lblPause = <?php echo json_encode(t('siptrace.live_pause'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const lblResume = <?php echo json_encode(t('siptrace.live_resume'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const lblClick = <?php echo json_encode(t('siptrace.click_message'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    let file = '';
    let offset = 0;
    let paused = false;
    let inFlight = false;
    const rows = [];
    const MAX_ROWS = 500;

    function fmtTs(ts) {
        const d = new Date((Number(ts) || 0) * 1000);
        const pad = (n, w = 2) => String(n).padStart(w, '0');
        return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + '.' + pad(d.getMilliseconds(), 3);
    }

    function matches(m, q) {
        if (!q) return true;
        const hay = [m.label, m.src, m.dst, m.from, m.to, m.call_id].join(' ').toLowerCase();
        return hay.includes(q);
    }

    function render() {
        const q = (filterInput.value || '').trim().toLowerCase();
        while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
        let shown = 0;
        for (const m of rows) {
            if (!matches(m, q)) continue;
            shown++;
            const tr = document.createElement('tr');
            tr.className = 'u-pointer';
            const cells = [
                [fmtTs(m.ts), 'u-mono u-fs-12 u-nowrap'],
                [m.label || '', 'u-fw-600'],
                [m.from || '', 'u-mono'],
                [m.to || '', 'u-mono'],
                [m.src || '', 'col-hide-mobile u-mono u-fs-11'],
                [m.dst || '', 'col-hide-mobile u-mono u-fs-11']
            ];
            for (const [val, cls] of cells) {
                const td = document.createElement('td');
                td.className = cls;
                td.textContent = val;
                tr.appendChild(td);
            }
            tr.addEventListener('click', () => {
                headEl.textContent = fmtTs(m.ts) + '  ' + (m.src || '') + ' → ' + (m.dst || '') + (m.call_id ? '  [' + m.call_id + ']' : '');
                textEl.textContent = m.text || '';
            });
            tbody.appendChild(tr);
        }
        if (shown === 0 && emptyRow) {
            tbody.appendChild(emptyRow);
        }
    }

    async function poll() {
        if (!document.body.contains(tbody)) {
            clearInterval(timer);
            return;
        }
        if (paused || document.hidden || inFlight) return;
        inFlight = true;
        try {
            const url = '/sip-live?poll=1&file=' + encodeURIComponent(file) + '&offset=' + encodeURIComponent(String(offset));
            const res = await fetch(url, { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            if (typeof data.file === 'string') file = data.file;
            if (typeof data.offset === 'number') offset = data.offset;
            if (Array.isArray(data.messages) && data.messages.length > 0) {
                for (const m of data.messages) {
                    rows.push(m);
                }
                if (rows.length > MAX_ROWS) {
                    rows.splice(0, rows.length - MAX_ROWS);
                }
                render();
            }
        } catch (_) {
            // ignore transient network errors
        } finally {
            inFlight = false;
        }
    }

    toggleBtn.addEventListener('click', () => {
        paused = !paused;
        toggleBtn.querySelector('i').className = paused ? 'fas fa-play' : 'fas fa-pause';
        toggleBtn.querySelector('span').textContent = paused ? lblResume : lblPause;
    });

    clearBtn.addEventListener('click', () => {
        rows.length = 0;
        headEl.textContent = lblClick;
        textEl.textContent = '';
        render();
    });

    filterInput.addEventListener('input', render);

    const timer = setInterval(poll, 1500);
    document.addEventListener('visibilitychange', () => {
        if (!document.body.contains(tbody)) {
            clearInterval(timer);
            return;
        }
        if (!document.hidden && !paused) poll();
    });
    poll();
})();
</script>
