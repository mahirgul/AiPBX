<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/SystemServicesService.php';

final class SystemServicesTest extends TestCase
{
    public function testStatesAndMissingServices(): void
    {
        $show = "LoadState=loaded\nActiveState=active\nId=asterisk.service\n\n"
            . "LoadState=not-found\nActiveState=inactive\nId=ollama.service\n\n"
            . "LoadState=loaded\nActiveState=failed\nId=coturn.service\n\n"
            . "LoadState=loaded\nActiveState=inactive\nId=aipbx-vsftpd.service\n\n"
            . "LoadState=loaded\nActiveState=active\nId=aipbx-vpn@t1.service";
        $out = SystemServicesService::parseShow($show, ['asterisk', 'ollama', 'coturn', 'aipbx-vsftpd', 'aipbx-vpn@t1']);
        $this->assertSame(
            ['asterisk' => 'on', 'coturn' => 'failed', 'aipbx-vsftpd' => 'off', 'aipbx-vpn@t1' => 'on'],
            array_column($out, 'state', 'unit')
        );
    }

    public function testOnlyKnownServicesMayBeRestarted(): void
    {
        foreach (['asterisk', 'nginx', 'aipbx-chat', 'aipbx-vpn@t1'] as $ok) {
            $this->assertTrue(SystemServicesService::isAllowed($ok), $ok);
        }
        foreach (['', 'sshd.socket', 'aipbx-vpn@', 'aipbx-vpn@a b', 'dbus', 'asterisk;id'] as $bad) {
            $this->assertFalse(SystemServicesService::isAllowed($bad), $bad);
        }
    }
}
