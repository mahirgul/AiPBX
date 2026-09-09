/**
 * Universal Dynamic PBX Destination Helper
 */
function loadDestinationOptions(typeSelectId, idSelectId, selectedValue = '') {
    const typeEl = document.getElementById(typeSelectId);
    const idEl = document.getElementById(idSelectId);
    if (!typeEl || !idEl) return;

    const moduleKey = typeEl.value;
    idEl.innerHTML = '<option value="">Yükleniyor...</option>';

    if (!moduleKey) {
        idEl.innerHTML = '<option value="">-- Hedef Yok --</option>';
        return;
    }

    fetch('/api/destinations.php?module=' + encodeURIComponent(moduleKey))
        .then(response => response.json())
        .then(data => {
            if (data.success && Array.isArray(data.options)) {
                idEl.innerHTML = '';
                if (data.options.length === 0) {
                    idEl.innerHTML = '<option value="">(Tanımlı Seçenek Yok)</option>';
                } else {
                    data.options.forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item.id;
                        opt.textContent = item.name;
                        if (String(item.id) === String(selectedValue)) {
                            opt.selected = true;
                        }
                        idEl.appendChild(opt);
                    });
                }
            } else {
                idEl.innerHTML = '<option value="">Yükleme Hatası</option>';
            }
        })
        .catch(err => {
            console.error('Destination load error:', err);
            idEl.innerHTML = '<option value="">Baglanti Hatasi</option>';
        });
}

function bindDestinationSelector(typeSelectId, idSelectId) {
    const typeEl = document.getElementById(typeSelectId);
    if (typeEl) {
        if (typeEl.dataset.destinationBound === 'true') return;
        typeEl.dataset.destinationBound = 'true';
        typeEl.addEventListener('change', function() {
            loadDestinationOptions(typeSelectId, idSelectId);
        });
    }
}
