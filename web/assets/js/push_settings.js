/* Page script of templates/views/push_settings/index.php */

function toggleModuleHelp(boxId) {
    var box = document.getElementById(boxId);
    if (box) {
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
    }
}

function togglePushFields() {
    var provider = document.getElementById('pushProviderSelect').value;
    var fcmSection = document.getElementById('fcmConfigSection');
    if (fcmSection) {
        fcmSection.style.display = (provider === 'fcm') ? 'block' : 'none';
    }
}

function sendTestPush() {
    var select = document.getElementById('testTargetSelect');
    var target = select.value;
    var resultBox = document.getElementById('testResultBox');
    var btn = document.getElementById('btnTestPush');

    if (!target) {
        alert(__('js.push.select_target'));
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + __('js.common.sending') + '';
    resultBox.style.display = 'none';

    var formData = new FormData();
    formData.append('csrf_token', window.CSRF_TOKEN);
    formData.append('target', target);
    formData.append('type', 'extension');

    fetch('/push-settings?action=test_push', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + __('js.push.send_test') + '';
        resultBox.style.display = 'block';
        if (data.success) {
            resultBox.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' + (data.message || __('js.common.success')) + '</div>';
        } else {
            resultBox.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> ' + (data.message || __('js.push.send_failed')) + '</div>';
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + __('js.push.send_test') + '';
        resultBox.style.display = 'block';
        resultBox.innerHTML = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' + __('js.common.request_failed_colon') + '' + err + '</div>';
    });
}
