<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/RegistrationService.php';

/** The Registrations page reads Asterisk's own output; these are real lines of Asterisk 22. */
final class RegistrationServiceTest extends TestCase
{
    public function testContactsComeWithAddressAgentAndExpiry(): void
    {
        $out = '/registrar/contact/19000-mob-webrtc;@9eb70b5f5a11df99e68f1f4f83b7d566: {"via_addr":"fsadm2789l33.invalid","endpoint":"19000-mob-webrtc","uri":"sip:ijuu517i@127.0.0.1:54606;transport=WS;x-ast-orig-host=fsadm2789l33.invalid:0","user_agent":"JsSIP 3.1.2","expiration_time":"1794692186"}' . "\n"
            . '/registrar/contact/1001-sip;@0123456789abcdef: {"endpoint":"1001-sip","uri":"sip:1001@192.168.1.50:5062;transport=tls","user_agent":"Yealink SIP-T46S 66.86.0.15","expiration_time":"1794692000"}' . "\n"
            . "3 results found.\n";
        $c = RegistrationService::parseRegistrar($out);
        $this->assertSame('WebSocket', $c['19000-mob-webrtc'][0]['address']);
        $this->assertSame('9eb70b5f5a', $c['19000-mob-webrtc'][0]['hash']);
        $this->assertSame('192.168.1.50:5062 (TLS)', $c['1001-sip'][0]['address']);
        $this->assertSame('Yealink SIP-T46S 66.86.0.15', $c['1001-sip'][0]['user_agent']);
        $this->assertSame(1794692000, $c['1001-sip'][0]['expires']);
    }

    public function testContactStatesAndRoundTrip(): void
    {
        $out = "  Contact:  <Aor/ContactUri..............................> <Hash....> <Status> <RTT(ms)..>\n"
            . "==========================================================================================\n\n"
            . "  Contact:  19000-mob-webrtc/sip:ijuu517i@127.0.0.1:54606; 9eb70b5f5a NonQual        -nan\n"
            . "  Contact:  voipms/sip:atlanta.voip.ms:5060                 4f3c2b1a0e Avail         23.456\n"
            . "  Contact:  1001-sip/sip:1001@192.168.1.50:5062;transport= 0123456789 Unavail        0.000\n";
        $s = RegistrationService::parseContactStates($out);
        $this->assertSame(['status' => 'NonQual', 'rtt' => null], $s['19000-mob-webrtc/9eb70b5f5a']);
        $this->assertSame(['status' => 'Avail', 'rtt' => 23.5], $s['voipms/4f3c2b1a0e']);
        $this->assertSame('Unavail', $s['1001-sip/0123456789']['status']);
    }

    public function testOutboundRegistrations(): void
    {
        $out = " <Registration/ServerURI..............................>  <Auth....................>  <Status.......>\n"
            . "==========================================================================================\n\n"
            . " voipms/sip:atlanta.voip.ms:5060                          voipms                      Registered        (exp. 3539s)\n"
            . " backup/sip:sip.example.net                                                           Rejected\n"
            . "\nObjects found: 2\n";
        $r = RegistrationService::parseRegistrations($out);
        $this->assertSame('Registered        (exp. 3539s)', $r['voipms']['status']);
        $this->assertSame('sip:atlanta.voip.ms:5060', $r['voipms']['server']);
        $this->assertSame('Rejected', $r['backup']['status']);
        $this->assertSame([], RegistrationService::parseRegistrations("No objects found.\n"));
    }
}
