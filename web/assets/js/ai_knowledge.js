(function () {
    function open(c) {
        document.getElementById('kb_col_id').value = c ? String(c.id) : '0';
        document.getElementById('kb_col_name').value = c ? c.name || '' : '';
        document.getElementById('kb_col_description').value = c ? c.description || '' : '';
        document.getElementById('kb_col_embedding').value = c ? c.embedding_binding || '' : '';
        document.getElementById('kb_col_text').value = c ? c.content_text || '' : '';
        UIHelper.openOverlayModal('kbCollectionModal');
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-kb-edit]');
        if (b) { open(b.dataset.kbEdit ? JSON.parse(b.dataset.kbEdit) : null); }
        if (e.target.closest('[data-kb-close]')) { UIHelper.closeOverlayModal('kbCollectionModal'); }
    });
    document.addEventListener('submit', function (e) {
        var msg = e.target.dataset && e.target.dataset.confirm;
        if (msg && !window.confirm(msg)) { e.preventDefault(); }
    });
})();
