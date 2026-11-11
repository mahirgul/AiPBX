#!/usr/bin/env python3
"""Native Asterisk call checks. Run as root on an isolated test install only.

Uses Local channels (no real carrier or external call) to exercise the generated queue's
DTMF exit, actual Dial answer/hangup handlers, and request persistence after no answer.
"""
import os
from pathlib import Path
import subprocess
import time
import wave

ROOT = Path(os.environ.get('AIPBX_INSTALL_DIR', '/opt/aipbx'))
FIXTURE = Path('/etc/asterisk/pbx/extensions_callback_e2e.conf')
QUEUE = 'callback_e2e_review'
PHONE = '03125550100'


def php(body):
    return subprocess.check_output(['php'], input=("<?php\nrequire '" + str(ROOT / 'web/config.php')
        + "';\nrequire_once '" + str(ROOT / 'web/src/services/QueueCallbackService.php')
        + "';\n" + body), text=True).strip()


def cli(command):
    return subprocess.check_output(['asterisk', '-rx', command], text=True)


def count():
    return int(php("echo getDB()->query(\"SELECT COUNT(*) FROM cc_callbacks WHERE queue_id = " + str(qid) + "\")->fetchColumn();"))


def wait_for(expected):
    for _ in range(40):
        if count() == expected:
            return
        time.sleep(.25)
    raise AssertionError(f'Expected {expected} pending requests, got {count()}')


assert not FIXTURE.exists(), 'Test fixture already exists; refusing to overwrite it'
qid = None
sound = Path(php('echo SOUNDS_CUSTOM_DIR;')) / 'callback-e2e-confirm.wav'
assert not sound.exists(), 'Test sound already exists; refusing to overwrite it'
try:
    with wave.open(str(sound), 'wb') as audio:
        audio.setnchannels(1)
        audio.setsampwidth(2)
        audio.setframerate(8000)
        audio.writeframes(b'\0\0' * 16000)
    qid = int(php("""getDB()->prepare('INSERT INTO pbx_queues (queue_name, title, callback_enabled, is_active, members_json, joinempty, leavewhenempty)
        VALUES (?, ?, 1, 1, ?, ?, ?)')->execute(['callback_e2e_review', 'Callback E2E', '["98761"]', 'yes', 'no']);
        echo getDB()->lastInsertId();"""))
    php("getDB()->prepare('UPDATE pbx_queues SET callback_announce = ? WHERE id = ?')->execute(['custom/callback-e2e-confirm.wav', " + str(qid) + "]);")
    FIXTURE.write_text(f'''[callback-e2e]
exten => request,1,Set(CALLERID(num)={PHONE})
 same => n,Answer()
 same => n,Queue({QUEUE},tT,,,10)
 same => n,Hangup()
exten => invalid,1,Set(CALLERID(num)=anonymous)
 same => n,Answer()
 same => n,Queue({QUEUE},tT,,,10)
 same => n,Hangup()
exten => press,1,Wait(1)
 same => n,SendDTMF(1)
 same => n,Wait(3)
 same => n,Hangup()
exten => talk,1,Set(CALLERID(num)={PHONE})
 same => n,Dial(Local/agent@callback-e2e,3,U(aipbx-callback-answered^{PHONE}^98761))
 same => n,Hangup()
exten => agent,1,Answer()
 same => n,Wait(2)
 same => n,Hangup()
exten => noanswer,1,Set(CALLERID(num)={PHONE})
 same => n,Dial(Local/never@callback-e2e,1,U(aipbx-callback-answered^{PHONE}^98761))
 same => n,Hangup()
exten => never,1,Wait(5)
 same => n,Hangup()
''')
    php("require_once '" + str(ROOT / 'web/src/asterisk_sync.php') + "'; syncAllQueues(); syncGeneralDialplan();")
    assert 'aipbx-callback-' + str(qid) in cli('queue show ' + QUEUE) or 'callback' in cli('dialplan show aipbx-callback-' + str(qid))
    cli('channel originate Local/request@callback-e2e extension press@callback-e2e')
    wait_for(1)
    print('PASS: pressing 1 in a real queue call saves the caller number')
    snapshots = []
    for _ in range(40):
        snapshot = cli('core show channels concise')
        snapshots.append(snapshot)
        if 'custom/callback-e2e-confirm' in snapshot:
            break
        time.sleep(.05)
    else:
        for line in cli('core show channels concise').splitlines():
            if line.startswith('Local/request@'):
                channel = line.split('!', 1)[0]
                print('\n'.join(v for v in cli('core show channel ' + channel).splitlines() if 'CALLBACK_RESULT' in v))
        raise AssertionError('Saved request did not play the selected confirmation: ' + repr(snapshots[:3]))
    print('PASS: selected confirmation plays after save, before hangup')
    time.sleep(4)
    cli('channel originate Local/request@callback-e2e extension press@callback-e2e')
    time.sleep(4)
    assert count() == 1
    print('PASS: requesting twice leaves one pending entry')
    cli('channel originate Local/noanswer@callback-e2e application Wait 3')
    time.sleep(3)
    assert count() == 1
    print('PASS: unanswered Dial does not remove a request')
    cli('channel originate Local/talk@callback-e2e application Wait 5')
    wait_for(0)
    print('PASS: answered agent conversation removes the request on hangup')
    cli('channel originate Local/invalid@callback-e2e extension press@callback-e2e')
    for _ in range(30):
        assert 'custom/callback-e2e-confirm' not in cli('core show channels concise'), 'Failed request played a success prompt'
        time.sleep(.1)
    assert count() == 0
    print('PASS: invalid caller cannot save a request or hear the success confirmation')
finally:
    if qid is not None:
        php('getDB()->exec("DELETE FROM cc_callbacks WHERE queue_id = ' + str(qid) + '"); getDB()->exec("DELETE FROM pbx_queues WHERE id = ' + str(qid) + '");'
            + ' getDB()->exec("DELETE FROM queues_details WHERE id = \'callback_e2e_review\'");')
    if FIXTURE.exists():
        FIXTURE.unlink()
    if sound.exists():
        sound.unlink()
    php("require_once '" + str(ROOT / 'web/src/asterisk_sync.php') + "'; syncAllQueues();")
