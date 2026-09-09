/**
 * Faks Gönder - "PDF Yükle" / "Metin Yaz" Sekme Anahtarlama + WYSIWYG Editör
 */
(function () {
    var editorEl = document.getElementById('fax-text-editor');
    var quill = null;

    if (editorEl && typeof Quill !== 'undefined') {
        // Font/boyut sınıf tabanlı (ql-font-x) değil STYLE tabanlı attributor'a
        // geçiriliyor ki editör.root.innerHTML çıktısı kendi içinde taşınabilir
        // olsun (Quill'in kendi CSS'ine bağımlı kalmadan, sunucudaki
        // TextFaxHelper/mPDF doğrudan inline style'ları okuyor).
        var Font = Quill.import('attributors/style/font');
        Font.whitelist = ['dejavusans', 'dejavuserif', 'dejavusansmono'];
        Quill.register(Font, true);

        var Size = Quill.import('attributors/style/size');
        Size.whitelist = ['10px', '12px', '14px', '16px', '18px', '20px', '24px'];
        Quill.register(Size, true);

        var Align = Quill.import('attributors/style/align');
        Quill.register(Align, true);

        quill = new Quill('#fax-text-editor', {
            theme: 'snow',
            placeholder: editorEl.getAttribute('data-placeholder') || '',
            modules: { toolbar: '#fax-text-toolbar' },
            formats: ['font', 'size', 'bold', 'italic', 'underline', 'align', 'list']
        });
        quill.format('font', 'dejavusans');
        quill.format('size', '12px');
    }

    window.switchFaxComposeMode = function (mode) {
        var isText = mode === 'text';

        document.querySelectorAll('.fax-compose-tab-btn').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
        });

        var pdfPanel = document.getElementById('fax-compose-pdf');
        var textPanel = document.getElementById('fax-compose-text');
        if (pdfPanel) pdfPanel.style.display = isText ? 'none' : '';
        if (textPanel) textPanel.style.display = isText ? '' : 'none';

        var modeInput = document.getElementById('fax_compose_mode_input');
        if (modeInput) modeInput.value = mode;
    };

    var form = document.getElementById('faxSendForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var modeInput = document.getElementById('fax_compose_mode_input');
            var mode = modeInput ? modeInput.value : 'pdf';

            if (mode === 'text') {
                if (quill && quill.getText().trim() === '') {
                    e.preventDefault();
                    if (typeof showFooterToast === 'function') {
                        showFooterToast('Lütfen gönderilecek metni yazın!', 'warning');
                    } else {
                        alert('Lütfen gönderilecek metni yazın!');
                    }
                    return;
                }
                var contentInput = document.getElementById('fax_text_content_input');
                if (contentInput && quill) contentInput.value = quill.root.innerHTML;
            } else {
                var fileInput = document.getElementById('fax_pdf_file_input');
                if (fileInput && fileInput.files.length === 0) {
                    e.preventDefault();
                    if (typeof showFooterToast === 'function') {
                        showFooterToast('Lütfen bir PDF dosyası seçin!', 'warning');
                    } else {
                        alert('Lütfen bir PDF dosyası seçin!');
                    }
                }
            }
        });
    }
})();
