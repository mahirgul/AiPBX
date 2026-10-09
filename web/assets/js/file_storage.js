/* Page script of templates/views/file_storage/index.php */

// Shows the bucket fields only when "S3-compatible storage" is selected.
(function () {
    const form = document.getElementById('storageForm');
    if (!form) return;
    const update = () => {
        const checked = form.querySelector('input.storage-backend:checked');
        const s3 = checked !== null && checked.value === 's3';
        form.querySelectorAll('.storage-s3').forEach(el => { el.style.display = s3 ? '' : 'none'; });
    };
    form.querySelectorAll('input.storage-backend').forEach(r => r.addEventListener('change', update));
    update();
})();
