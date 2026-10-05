/* Page script of templates/views/certificates/index.php */

// Operations take up to a minute (Let's Encrypt validation, service reloads):
// confirm where needed, then lock the page so nothing is submitted twice.
(function () {
    let submitter = null;
    document.addEventListener('click', e => { const b = e.target.closest('button[type=submit]'); if (b) submitter = b; });
    document.querySelectorAll('form.cert-form').forEach(form => {
        form.addEventListener('submit', e => {
            const msg = (submitter && submitter.form === form && submitter.dataset.confirm) || form.dataset.confirm;
            if (msg && !confirm(msg)) { e.preventDefault(); return; }
            if (submitter && submitter.name) {
                // Disabled buttons are not submitted: carry the clicked action over.
                const h = document.createElement('input');
                h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
                form.appendChild(h);
            }
            document.querySelectorAll('form.cert-form button').forEach(b => b.disabled = true);
            if (submitter) submitter.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + window.CERTIFICATES_WORKING;
        });
    });
})();
