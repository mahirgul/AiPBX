/* Page script of templates/views/meetings/index.php */

function openCreateMeetingModal() {
    document.getElementById('modal_meeting_id').value = '0';
    document.getElementById('modal_meeting_title').value = '';
    document.getElementById('modal_meeting_desc').value = '';

    // Calculate next round hour
    const now = new Date();
    now.setHours(now.getHours() + 1, 0, 0, 0);
    const startStr = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

    const end = new Date(now.getTime() + 60 * 60 * 1000);
    const endStr = new Date(end.getTime() - end.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

    document.getElementById('modal_meeting_starts_at').value = startStr;
    document.getElementById('modal_meeting_ends_at').value = endStr;
    document.getElementById('modal_meeting_video_mode').value = 'sfu';
    document.getElementById('modal_meeting_room').value = '';
    document.getElementById('modal_meeting_user_pin').value = '';
    document.getElementById('modal_meeting_host_pin').value = '';
    document.getElementById('modal_meeting_invites').value = '';

    UIHelper.openOverlayModal('meetingModal');
}

function openInviteModal(meeting) {
    document.getElementById('invite_meeting_id').value = meeting.id;
    document.getElementById('invite_guest_email').value = '';
    document.getElementById('invite_guest_name').value = '';
    UIHelper.openOverlayModal('inviteModal');
}

function copyGuestLink(url) {
    if (!navigator.clipboard) {
        prompt(__('js.meetings.copy_prompt', 'Copy meeting link:'), url);
        return;
    }
    navigator.clipboard.writeText(url).then(() => {
        if (window.showFooterToast) {
            window.showFooterToast(__('js.meetings.link_copied', 'Meeting link copied to clipboard!'), 'success');
        } else {
            alert(__('js.meetings.link_copied', 'Meeting link copied to clipboard!'));
        }
    }).catch(() => {
        prompt(__('js.meetings.copy_prompt', 'Copy meeting link:'), url);
    });
}
