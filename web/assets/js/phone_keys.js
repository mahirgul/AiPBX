/* Page script of templates/views/phone_keys/index.php (data: window.PHONE_KEYS) */

(function () {
    const data = window.PHONE_KEYS || { pages: [], keys: [], types: [], editable: false };
    const root = document.getElementById('phoneKeysPages');
    if (!root) return;

    const byPos = {};
    data.keys.forEach(k => { byPos[k.page + '-' + k.position] = k; });

    data.pages.forEach(p => {
        const section = document.createElement('div');
        section.className = 'phone-keys-page';
        section.innerHTML = '<h4>' + escapeHtml(p.title) + '</h4>';
        const grid = document.createElement('div');
        grid.className = 'phone-keys-grid';
        for (let pos = 1; pos <= p.count; pos++) {
            grid.appendChild(keyCell(p.page, pos, byPos[p.page + '-' + pos] || null));
        }
        section.appendChild(grid);
        root.appendChild(section);
    });

    function keyCell(page, pos, key) {
        const cell = document.createElement('div');
        cell.className = 'phone-key' + (key ? ' is-set' : '');
        cell.dataset.page = page;
        cell.dataset.position = pos;

        let opts = '<option value="">' + escapeHtml(__('js.phones.key_empty')) + '</option>';
        data.types.forEach(t => {
            opts += '<option value="' + t + '"' + (key && key.type === t ? ' selected' : '') + '>' + escapeHtml(__('js.phones.key_' + t)) + '</option>';
        });
        const disabled = data.editable ? '' : ' disabled';
        cell.innerHTML =
            '<span class="phone-key-no">' + pos + '</span>' +
            '<select class="form-control key-type"' + disabled + '>' + opts + '</select>' +
            '<input type="text" class="form-control key-target" maxlength="32" placeholder="' + escapeHtml(__('js.phones.key_target')) + '"' + disabled + '>' +
            '<input type="text" class="form-control key-label" maxlength="64" placeholder="' + escapeHtml(__('js.phones.key_label')) + '"' + disabled + '>';
        cell.querySelector('.key-target').value = key ? key.target : '';
        cell.querySelector('.key-label').value = key ? key.label : '';
        cell.querySelector('.key-type').addEventListener('change', e => {
            cell.classList.toggle('is-set', e.target.value !== '');
            syncTarget(cell);
        });
        syncTarget(cell);
        return cell;
    }

    // DND and line keys have no target.
    function syncTarget(cell) {
        const type = cell.querySelector('.key-type').value;
        const target = cell.querySelector('.key-target');
        const needsTarget = type === 'blf' || type === 'speeddial' || type === 'park';
        target.style.visibility = needsTarget ? 'visible' : 'hidden';
        target.required = needsTarget;
    }
})();

function collectPhoneKeys() {
    const keys = [];
    document.querySelectorAll('#phoneKeysPages .phone-key').forEach(cell => {
        const type = cell.querySelector('.key-type').value;
        if (!type) return;
        keys.push({
            page: parseInt(cell.dataset.page, 10),
            position: parseInt(cell.dataset.position, 10),
            type: type,
            target: cell.querySelector('.key-target').value.trim(),
            label: cell.querySelector('.key-label').value.trim(),
        });
    });
    document.getElementById('phone_keys_json').value = JSON.stringify(keys);
    return true;
}
