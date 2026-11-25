/**
 * Phonebook: the contact form with any number of labelled numbers.
 */
function phonebookAddNumber(label, number) {
    const list = document.getElementById('pb_numbers');
    const add = document.getElementById('pb_add_number');
    if (!list || list.children.length >= parseInt(add.dataset.max || '12', 10)) return;
    const row = document.getElementById('pb_number_row').content.firstElementChild.cloneNode(true);
    if (label) row.querySelector('select').value = label;
    row.querySelector('input').value = number || '';
    list.appendChild(row);
    add.disabled = list.children.length >= parseInt(add.dataset.max || '12', 10);
}

function phonebookRemoveNumber(btn) {
    const row = btn.closest('.pb-number-row');
    if (row) row.remove();
    document.getElementById('pb_add_number').disabled = false;
    if (!document.getElementById('pb_numbers').children.length) phonebookAddNumber('', '');
}

function openPhonebookModal(c, number) {
    const cfg = window.PHONEBOOK || { fields: [] };
    document.getElementById('pb_id').value = c ? c.id : '';
    cfg.fields.forEach(f => { document.getElementById('pb_' + f).value = c ? (c[f] || '') : ''; });
    document.getElementById('pb_is_vip').checked = Number(c?.is_vip) === 1;
    document.getElementById('pb_numbers').replaceChildren();
    (c && c.numbers && c.numbers.length ? c.numbers : [{ label: 'mobile', number: number || '' }])
        .forEach(n => phonebookAddNumber(n.label, n.number));
    document.getElementById('phonebookModalTitle').lastChild.textContent = ' ' + (c ? __('js.phonebook.edit_contact') : __('js.phonebook.new_contact'));
    UIHelper.openOverlayModal('phonebookModal');
}

(function () {
    const add = (window.PHONEBOOK || {}).add;
    if (add) openPhonebookModal(null, add);
})();
