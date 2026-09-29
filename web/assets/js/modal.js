/**
 * Global Modal Dialog Module (Accessibility, Keyboard & Backdrop Handling)
 */

// Edit dialogs (anything with a form) close only through their own close /
// cancel buttons: a stray backdrop click or Escape used to discard half-done work.
function isEditModal(modal) {
    return !!modal.querySelector('form');
}

// Global Keyboard ESC and Backdrop Click Listeners
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay').forEach(function(modal) {
                if (isEditModal(modal)) return;
                modal.style.display = 'none';
                modal.classList.remove('active');
            });
            const pdfFrame = document.getElementById('pdfFrame');
            if (pdfFrame) pdfFrame.src = '';
        }
    });

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay') && !isEditModal(e.target)) {
            e.target.style.display = 'none';
            e.target.classList.remove('active');
            const pdfFrame = document.getElementById('pdfFrame');
            if (pdfFrame) pdfFrame.src = '';
        }
    });
});

// PDF Viewer Modal
function viewPdf(url) {
    const frame = document.getElementById('pdfFrame');
    const modal = document.getElementById('pdfModal');
    if (frame && modal) {
        frame.src = url;
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closePdfModal() {
    const frame = document.getElementById('pdfFrame');
    UIHelper.closeOverlayModal('pdfModal');
    if (frame) frame.src = '';
}

// Collapsible Module Help & Guide Box Toggle
function toggleModuleHelp(boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;

    if (box.style.display === 'block') {
        box.style.display = 'none';
    } else {
        box.style.display = 'block';
    }
}
