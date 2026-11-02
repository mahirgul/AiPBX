<?php
// Creates or removes the video meeting scripts/e2e/meeting_call.mjs joins. Run as root on the
// installed machine: php meeting_setup.php create   prints the meeting's token
//                    php meeting_setup.php clean
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$web = getenv('AIPBX_WEB') ?: '/var/www/html';
require $web . '/config.php';
require_once $web . '/src/services/MeetingService.php';

const ROOM = '8899';
$db = getDB();
$db->exec("DELETE FROM pbx_meetings WHERE room_number = '" . ROOM . "'");
$db->exec("DELETE FROM pbx_conferences WHERE room_number = '" . ROOM . "'");
if (($argv[1] ?? '') !== 'create') {
    exit(0);
}
$result = MeetingService::saveMeeting([
    'title' => 'E2E meeting',
    'scheduled_start' => date('Y-m-d H:i:s', time() - 60),
    'scheduled_end' => date('Y-m-d H:i:s', time() + 3600),
    'room_number' => ROOM,
    'allow_guests' => 1,
    'video_mode' => 'sfu',
]);
$row = $db->query("SELECT * FROM pbx_meetings WHERE room_number = '" . ROOM . "'")->fetch(PDO::FETCH_ASSOC);
foreach ($row ?: [] as $value) {
    if (is_string($value) && str_starts_with($value, 'm_')) {
        echo $value, "\n";
        exit(0);
    }
}
fwrite(STDERR, 'The meeting was not created: ' . json_encode($result) . "\n");
exit(1);
