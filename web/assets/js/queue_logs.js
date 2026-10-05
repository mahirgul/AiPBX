/* Page script of templates/views/queue_logs/index.php */

function toggleQueueJourney(id) {
    const row = document.getElementById('journey-row-' + id);
    const btn = document.getElementById('journey-btn-' + id);
    if (!row) return;
    const isHidden = (row.style.display === 'none' || !row.style.display);
    row.style.display = isHidden ? 'table-row' : 'none';
    if (btn) {
        const icon = btn.querySelector('.queue-journey-icon');
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

function toggleAllQueueJourneys() {
    const rows = document.querySelectorAll('.queue-journey-row');
    const btns = document.querySelectorAll('.queue-journey-btn');
    const textAll = document.getElementById('toggleAllJourneysText');
    if (!rows.length) return;

    let anyHidden = false;
    rows.forEach(function(r) {
        if (r.style.display === 'none' || !r.style.display) anyHidden = true;
    });

    rows.forEach(function(r) {
        r.style.display = anyHidden ? 'table-row' : 'none';
    });

    btns.forEach(function(b) {
        const icon = b.querySelector('.queue-journey-icon');
        if (icon) icon.style.transform = anyHidden ? 'rotate(180deg)' : 'rotate(0deg)';
        if (anyHidden) {
            b.classList.add('active', 'btn-primary');
            b.classList.remove('btn-outline-primary');
        } else {
            b.classList.remove('active', 'btn-primary');
            b.classList.add('btn-outline-primary');
        }
    });

    if (textAll) {
        textAll.innerText = anyHidden ? window.QUEUE_LOGS_I18N.collapse_all : window.QUEUE_LOGS_I18N.expand_all;
    }
}
