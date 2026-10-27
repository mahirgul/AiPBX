<?php
require_once __DIR__ . '/PhoneTemplate.php';

/**
 * Cisco CallManager-series desk phones running **Cisco's SIP firmware**: the
 * 7900 (7941G/7942G/7945G/7961G/7962G/7965G/7975G) and 8800 (8841/8851/8861/
 * 8865) sets. Their configuration file is `SEP<MAC>.cnf.xml` (uppercase MAC) and
 * its shape is CUCM's, not the flat-profile the SPA models above use.
 *
 * These phones are not SPA sets and are not SCCP: they speak a Cisco-flavoured
 * SIP, and the community project **usecallmanager.nz** documents them and
 * publishes the Asterisk patch that adds what Cisco's firmware expects (BLF,
 * call-forward/DND synchronisation, pickup, parking). docs/cisco-callmanager.md
 * walks through the parts AiPBX cannot do for you: the SIP firmware load, that
 * patch, and the shared files below.
 *
 * What AiPBX writes here, and what it deliberately does not:
 *
 *  - the server, the extension, its SIP password, the display name, NTP, the
 *    SIP port and transport, the messages key, the web admin password (if the
 *    phone model supports it) and the key layout;
 *  - `loadInformation` only when an administrator set `provision_cisco_load`:
 *    the load name is per model *and* per firmware version, so guessing it would
 *    brick a phone's provisioning rather than leave it alone;
 *  - `softKeyFile` / `dialTemplate` only when those files are actually present
 *    in the TFTP/FTP folder, so a phone never asks for a file nobody has;
 *  - nothing for the KEM expansion modules (see PhoneModels) and nothing for a
 *    Do Not Disturb key (the feature id is not one of the documented ones).
 *
 * The SIP password: these phones fetch this file over TFTP, unauthenticated, so
 * treat the TFTP/FTP folder as secret. AiPBX's own TFTP serves it to the
 * *allowed networks* only, and the per-phone file is written there only when
 * "Also write the phones' files into the TFTP/FTP folder" is switched on.
 */
final class CiscoCallManagerTemplate extends PhoneTemplate
{
    /**
     * timeZone names, by UTC offset in minutes. The names are the ones the
     * phone's own documentation lists (usecallmanager.nz, SEPMAC.cnf.xml), not
     * an invented set: an unrecognised name is worse than the nearest one.
     */
    private const TIME_ZONES = [
        -720 => 'Dateline Standard Time',
        -660 => 'Samoa Standard Time',
        -600 => 'Hawaiian Standard Time',
        -540 => 'Alaskan Standard/Daylight Time',
        -480 => 'Pacific Standard/Daylight Time',
        -420 => 'Mountain Standard/Daylight Time',
        -360 => 'Central Standard/Daylight Time',
        -300 => 'Eastern Standard/Daylight Time',
        -240 => 'Atlantic Standard/Daylight Time',
        -210 => 'Newfoundland Standard/Daylight Time',
        -180 => 'E. South America Standard/Daylight Time',
        -120 => 'Mid-Atlantic Standard/Daylight Time',
        -60 => 'Azores Standard/Daylight Time',
        0 => 'GMT Standard/Daylight Time',
        60 => 'W. Europe Standard/Daylight Time',
        120 => 'Central Europe Standard/Daylight Time',
        180 => 'Russian Standard/Daylight Time',
        210 => 'Iran Standard/Daylight Time',
        240 => 'Arabian Standard Time',
        270 => 'Afghanistan Standard Time',
        300 => 'West Asia Standard Time',
        330 => 'India Standard Time',
        360 => 'Central Asia Standard Time',
        420 => 'SE Asia Standard Time',
        480 => 'China Standard/Daylight Time',
        540 => 'Tokyo Standard Time',
        570 => 'Cen. Australia Standard/Daylight Time',
        600 => 'E. Australia Standard Time',
        660 => 'Central Pacific Standard Time',
        720 => 'New Zealand Standard/Daylight Time',
    ];

    /** featureID of a phone line, a speed dial and a BLF speed dial (usecallmanager.nz, Line Keys). */
    private const FEATURE_LINE = 9;
    private const FEATURE_SPEED_DIAL = 2;
    private const FEATURE_BLF = 21;

    /** Highest calls per line; the phone rejects a higher number. */
    private const MAX_CALLS = ['7900' => 50, '8800' => 200];

    public function vendor(): string
    {
        return 'cisco-cm';
    }

    public function macFromFile(string $file): string
    {
        return preg_match('/^SEP([0-9a-f]{12})\.cnf\.xml$/i', $file, $m)
            ? PhoneModels::normalizeMac($m[1])
            : '';
    }

    public function deviceFile(string $mac): string
    {
        return 'SEP' . strtoupper($mac) . '.cnf.xml';
    }

    public function contentType(): string
    {
        return 'application/xml; charset=utf-8';
    }

    /** Cisco's own NOTIFY event (see asterisk-config/pjsip_notify.conf). */
    public function notifyResync(): string
    {
        return 'cisco-check-cfg';
    }

    /**
     * These phones have no NOTIFY that reboots them; they restart by themselves
     * when the configuration needs it. The page hides the Reboot button.
     */
    public function notifyReboot(): string
    {
        return '';
    }

    /** The TFTP/FTP folder the shared files are read from (see Network services). */
    private static function tftpDir(): string
    {
        return getenv('AIPBX_TFTP_DIR') ?: '/var/lib/aipbx/tftp';
    }

    private static function sharedFile(string $name): string
    {
        return is_file(self::tftpDir() . '/' . $name) ? $name : '';
    }

    public function render(array $ctx): string
    {
        $model = PhoneModels::get((string) $ctx['model']) ?? ['keys' => 0];
        $tls = $ctx['transport'] === 'tls';
        $port = (int) $ctx['port'];
        $series = str_starts_with((string) $ctx['model'], 'cisco-cm-8') ? '8800' : '7900';
        // 7900 series: 3 = enabled and the user may change it; 8800: 1.
        $callWaiting = $series === '8800' ? 1 : 3;
        $maxCalls = self::MAX_CALLS[$series];
        $load = trim((string) ($ctx['cisco_load'] ?? ''));
        $softKeys = self::sharedFile('Softkeys.xml');
        $dialTemplate = self::sharedFile('DialTemplate.xml');
        $lines = $this->lines($ctx, (int) $model['keys'], $port, $callWaiting, $maxCalls);

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<!-- Generated by AiPBX for " . self::xml(PhoneModels::formatMac((string) $ctx['mac']))
            . "; changes made on the phone are overwritten at the next provisioning. -->\n";
        $xml .= "<device>\n";
        $xml .= "  <fullConfig>true</fullConfig>\n";
        $xml .= "  <deviceProtocol>SIP</deviceProtocol>\n";
        $xml .= "  <devicePool>\n";
        $xml .= "    <dateTimeSetting>\n";
        $xml .= "      <dateTemplate>D/M/Y</dateTemplate>\n";
        $xml .= '      <timeZone>' . self::xml(self::timeZone((int) $ctx['utc_offset']), 60) . "</timeZone>\n";
        $xml .= "      <ntps>\n        <ntp>\n";
        $xml .= '          <name>' . self::xml($ctx['ntp'], 50) . "</name>\n";
        $xml .= "          <ntpMode>unicast</ntpMode>\n";
        $xml .= "        </ntp>\n      </ntps>\n";
        $xml .= "    </dateTimeSetting>\n";
        $xml .= "    <callManagerGroup>\n      <members>\n        <member priority=\"0\">\n          <callManager>\n";
        $xml .= "            <ports>\n";
        $xml .= "              <sipPort>{$port}</sipPort>\n";
        $xml .= "              <securedSipPort>5061</securedSipPort>\n";
        $xml .= "            </ports>\n";
        $xml .= '            <processNodeName>' . self::xml($ctx['server'], 50) . "</processNodeName>\n";
        $xml .= "          </callManager>\n        </member>\n      </members>\n    </callManagerGroup>\n";
        $xml .= "  </devicePool>\n";
        $xml .= "  <sipProfile>\n";
        $xml .= "    <sipProxies>\n      <registerWithProxy>true</registerWithProxy>\n";
        if ($ctx['proxy'] !== '') {
            $xml .= '      <outboundProxy>' . self::xml($ctx['proxy'], 50) . "</outboundProxy>\n";
            $xml .= "      <outboundProxyPort>{$port}</outboundProxyPort>\n";
        }
        $xml .= "    </sipProxies>\n";
        $xml .= "    <sipCallFeatures>\n";
        $xml .= "      <cnfJoinEnabled>true</cnfJoinEnabled>\n";
        $xml .= "      <callForwardURI>x-cisco-serviceuri-cfwdall</callForwardURI>\n";
        $xml .= "      <callPickupURI>x-cisco-serviceuri-pickup</callPickupURI>\n";
        $xml .= "      <callPickupListURI>x-cisco-serviceuri-opickup</callPickupListURI>\n";
        $xml .= "      <callPickupGroupURI>x-cisco-serviceuri-gpickup</callPickupGroupURI>\n";
        $xml .= "      <meetMeServiceURI>x-cisco-serviceuri-meetme</meetMeServiceURI>\n";
        $xml .= "      <abbreviatedDialURI>x-cisco-serviceuri-abbrdial</abbreviatedDialURI>\n";
        $xml .= "      <rfc2543Hold>false</rfc2543Hold>\n";
        $xml .= "      <callHoldRingback>1</callHoldRingback>\n";
        $xml .= "      <localCfwdEnable>true</localCfwdEnable>\n";
        $xml .= "      <semiAttendedTransfer>true</semiAttendedTransfer>\n";
        $xml .= "      <remoteCcEnable>true</remoteCcEnable>\n";
        $xml .= "      <dndControl>0</dndControl>\n";
        $xml .= "    </sipCallFeatures>\n";
        $xml .= "    <sipStack>\n";
        $xml .= "      <sipInviteRetx>6</sipInviteRetx>\n";
        $xml .= "      <sipRetx>10</sipRetx>\n";
        $xml .= "      <timerInviteExpires>180</timerInviteExpires>\n";
        $xml .= "      <timerRegisterExpires>3600</timerRegisterExpires>\n";
        $xml .= "      <timerRegisterDelta>5</timerRegisterDelta>\n";
        $xml .= "      <timerKeepAliveExpires>120</timerKeepAliveExpires>\n";
        $xml .= "      <timerSubscribeExpires>120</timerSubscribeExpires>\n";
        $xml .= "      <timerSubscribeDelta>5</timerSubscribeDelta>\n";
        $xml .= "      <timerT1>500</timerT1>\n";
        $xml .= "      <timerT2>4000</timerT2>\n";
        $xml .= "      <maxRedirects>70</maxRedirects>\n";
        // The phone updates the connected line from ${CONNECTEDLINE()} when this is on.
        $xml .= "      <remotePartyID>true</remotePartyID>\n";
        $xml .= "      <userInfo>Phone</userInfo>\n";
        $xml .= "    </sipStack>\n";
        $xml .= "    <autoAnswerTimer>1</autoAnswerTimer>\n";
        $xml .= "    <autoAnswerAltBehavior>false</autoAnswerAltBehavior>\n";
        $xml .= "    <autoAnswerOverride>true</autoAnswerOverride>\n";
        $xml .= "    <transferOnhookEnabled>false</transferOnhookEnabled>\n";
        $xml .= "    <enableVad>false</enableVad>\n";
        // Codecs: the phone is told only whether G.722 is on; the rest is its own order.
        $xml .= '    <preferredCodec>' . (in_array('g722', (array) $ctx['codecs'], true) ? 'g722' : 'none') . "</preferredCodec>\n";
        $xml .= "    <dtmfAvtPayload>101</dtmfAvtPayload>\n";
        $xml .= "    <dtmfDbLevel>3</dtmfDbLevel>\n";
        $xml .= "    <dtmfOutofBand>avt</dtmfOutofBand>\n";
        $xml .= "    <alwaysUsePrimeLine>false</alwaysUsePrimeLine>\n";
        $xml .= "    <alwaysUsePrimeLineVoiceMail>false</alwaysUsePrimeLineVoiceMail>\n";
        $xml .= "    <kpml>0</kpml>\n";
        // No spaces: the 7900 series drops a label that has them.
        $xml .= '    <phoneLabel>' . self::xml(str_replace(' ', '', (string) $ctx['display_name']), 12) . "</phoneLabel>\n";
        $xml .= "    <stutterMsgWaiting>0</stutterMsgWaiting>\n";
        $xml .= "    <callStats>true</callStats>\n";
        $xml .= "    <offhookToFirstDigitTimer>15000</offhookToFirstDigitTimer>\n";
        $xml .= "    <silentPeriodBetweenCallWaitingBursts>10</silentPeriodBetweenCallWaitingBursts>\n";
        $xml .= "    <startMediaPort>16384</startMediaPort>\n";
        $xml .= "    <stopMediaPort>32766</stopMediaPort>\n";
        $xml .= "    <natEnabled>false</natEnabled>\n";
        $xml .= $lines;
        $xml .= "    <dscpForAudio>184</dscpForAudio>\n";
        $xml .= "    <ringSettingBusyStationPolicy>0</ringSettingBusyStationPolicy>\n";
        if ($dialTemplate !== '') {
            $xml .= '    <dialTemplate>' . self::xml($dialTemplate, 40) . "</dialTemplate>\n";
        }
        if ($softKeys !== '') {
            $xml .= '    <softKeyFile>' . self::xml($softKeys, 40) . "</softKeyFile>\n";
        }
        $xml .= "  </sipProfile>\n";
        $xml .= "  <commonProfile>\n";
        $xml .= "    <phonePassword></phonePassword>\n";
        $xml .= "    <backgroundImageAccess>true</backgroundImageAccess>\n";
        // 7900 series: 2 = phone history greyed; 8800: 1.
        $xml .= '    <callLogBlfEnabled>' . ($series === '8800' ? 1 : 2) . "</callLogBlfEnabled>\n";
        $xml .= "  </commonProfile>\n";
        $xml .= "  <vendorConfig>\n";
        $xml .= "    <disableSpeaker>false</disableSpeaker>\n";
        $xml .= "    <disableSpeakerAndHeadset>false</disableSpeakerAndHeadset>\n";
        $xml .= "    <enableGroupListen>true</enableGroupListen>\n";
        $xml .= "    <settingsAccess>1</settingsAccess>\n";
        $xml .= "    <g722CodecSupport>" . (in_array('g722', (array) $ctx['codecs'], true) ? 2 : 1) . "</g722CodecSupport>\n";
        $xml .= "    <handsetWidebandEnable>2</handsetWidebandEnable>\n";
        $xml .= "    <headsetWidebandEnable>2</headsetWidebandEnable>\n";
        $xml .= "    <handsetWidebandUIControl>1</handsetWidebandUIControl>\n";
        $xml .= "    <headsetWidebandUIControl>1</headsetWidebandUIControl>\n";
        $xml .= "    <webAccess>0</webAccess>\n";
        $xml .= "    <sshAccess>0</sshAccess>\n";
        $xml .= "    <peerFirmwareSharing>0</peerFirmwareSharing>\n";
        $xml .= "  </vendorConfig>\n";
        if ($load !== '') {
            $xml .= '  <loadInformation>' . self::xml($load, 40) . "</loadInformation>\n";
        }
        $xml .= $this->services();
        $xml .= "  <userLocale>\n";
        $xml .= "    <name>English_United_States</name>\n";
        $xml .= "    <uid>1</uid>\n";
        $xml .= "    <langCode>en_US</langCode>\n";
        $xml .= "    <version>1.0.0.0-1</version>\n";
        $xml .= "    <winCharSet>utf-8</winCharSet>\n";
        $xml .= "  </userLocale>\n";
        $xml .= "  <networkLocale>United_States</networkLocale>\n";
        $xml .= "  <networkLocaleInfo>\n";
        $xml .= "    <name>United_States</name>\n";
        $xml .= "    <version>6.0.0.0-1</version>\n";
        $xml .= "  </networkLocaleInfo>\n";
        $xml .= "  <idleTimeout>0</idleTimeout>\n";
        $xml .= "  <directoryURL></directoryURL>\n";
        $xml .= "  <idleURL></idleURL>\n";
        $xml .= "  <informationURL></informationURL>\n";
        $xml .= "  <messagesURL></messagesURL>\n";
        $xml .= "  <servicesURL></servicesURL>\n";
        // 1 = UDP, 2 = TCP/TLS.
        $xml .= '  <transportLayerProtocol>' . ($tls ? 2 : 1) . "</transportLayerProtocol>\n";
        $xml .= "  <autoCallPickupEnable>false</autoCallPickupEnable>\n";
        $xml .= "  <advertiseG722Codec>" . (in_array('g722', (array) $ctx['codecs'], true) ? 1 : 0) . "</advertiseG722Codec>\n";
        $xml .= "  <dndCallAlert>5</dndCallAlert>\n";
        return $xml . "</device>\n";
    }

    /**
     * <sipLines>: one <line> per programmable key, in button order.
     *
     * Button 1 is the phone's line when no layout was drawn, which is what a
     * phone needs to register at all; a layout the administrator drew is written
     * exactly as drawn (see Line Keys in docs/phones.md).
     */
    private function lines(array $ctx, int $keys, int $port, int $callWaiting, int $maxCalls): string
    {
        $xml = "    <sipLines>\n";
        if (empty($ctx['keys'])) {
            return $xml . $this->line(1, 1, self::FEATURE_LINE, '', (string) $ctx['display_name'], '', '', $port, $callWaiting, $maxCalls, (string) $ctx['voicemail'], (string) $ctx['sip_password'], (string) $ctx['extension']) . "    </sipLines>\n";
        }
        $lineIndex = 0;
        foreach (self::keysByPage((array) $ctx['keys'], 0) as $position => $key) {
            $position = (int) $position;
            if ($keys > 0 && $position > $keys) {
                continue;   // the layout has a key this model does not have
            }
            $type = (string) $key['type'];
            if ($type === 'line') {
                $lineIndex++;
                $xml .= $this->line($position, $lineIndex, self::FEATURE_LINE, (string) $key['label'],
                    (string) $ctx['display_name'], '', '', $port, $callWaiting, $maxCalls,
                    (string) $ctx['voicemail'], (string) $ctx['sip_password'], (string) $ctx['extension']);
                continue;
            }
            // A park key dials its slot (the same thing the phone would send when
            // the user picks a parked call up), so it is a speed dial here.
            $feature = match ($type) {
                'blf' => self::FEATURE_BLF,
                'speeddial', 'park' => self::FEATURE_SPEED_DIAL,
                default => 0,   // dnd and anything new: not written for these phones
            };
            if ($feature === 0) {
                continue;
            }
            $target = self::xml((string) $key['target']);
            $xml .= $this->line($position, 0, $feature, (string) $key['label'], '', $target, $target, $port,
                $callWaiting, $maxCalls, '', '', '');
        }
        return $xml . "    </sipLines>\n";
    }

    /** One key definition. Values differ by feature: only a line registers. */
    private function line(int $button, int $index, int $feature, string $label, string $displayName,
                          string $speedDial, string $contact, int $port, int $callWaiting, int $maxCalls,
                          string $voicemail, string $password, string $extension): string
    {
        $attrs = 'button="' . $button . '"' . ($index > 0 ? ' lineIndex="' . $index . '"' : '');
        $xml = "      <line {$attrs}>\n";
        $xml .= "        <featureID>{$feature}</featureID>\n";
        $xml .= '        <featureLabel>' . self::xml($label, 30) . "</featureLabel>\n";
        if ($speedDial !== '') {
            $xml .= "        <speedDialNumber>{$speedDial}</speedDialNumber>\n";
        }
        // The keyword tells the phone to use the CallManager member above.
        $xml .= "        <proxy>USECALLMANAGER</proxy>\n";
        $xml .= "        <port>{$port}</port>\n";
        if ($feature === self::FEATURE_LINE) {
            $xml .= '        <name>' . self::xml($extension, 30) . "</name>\n";
            $xml .= '        <displayName>' . self::xml($displayName, 30) . "</displayName>\n";
            $xml .= "        <autoAnswer>\n          <autoAnswerEnabled>0</autoAnswerEnabled>\n        </autoAnswer>\n";
            $xml .= '        <authName>' . self::xml($extension, 30) . "</authName>\n";
            $xml .= '        <authPassword>' . self::xml($password, 128) . "</authPassword>\n";
            $xml .= '        <contact>' . self::xml($extension, 30) . "</contact>\n";
        }
        if ($contact !== '' && $feature === self::FEATURE_BLF) {
            $xml .= "        <contact>{$contact}</contact>\n";
        }
        $xml .= "        <sharedLine>false</sharedLine>\n";
        if ($feature === self::FEATURE_LINE) {
            $xml .= "        <messageWaitingLampPolicy>3</messageWaitingLampPolicy>\n";
            $xml .= "        <messageWaitingAMWI>0</messageWaitingAMWI>\n";
            $xml .= '        <messagesNumber>' . self::xml($voicemail, 30) . "</messagesNumber>\n";
            $xml .= "        <ringSettingIdle>4</ringSettingIdle>\n";
            $xml .= "        <ringSettingActive>5</ringSettingActive>\n";
            $xml .= "        <callWaiting>{$callWaiting}</callWaiting>\n";
            $xml .= "        <maxNumCalls>{$maxCalls}</maxNumCalls>\n";
            $xml .= "        <busyTrigger>4</busyTrigger>\n";
        }
        return $xml . "      </line>\n";
    }

    /** The phone's built-in directories, which need no server of ours. */
    private function services(): string
    {
        $xml = "  <phoneServices useHTTPS=\"false\">\n";
        $xml .= "    <provisioning>2</provisioning>\n";
        foreach ([['Missed Calls', 'Application:Cisco/MissedCalls', 1], ['Received Calls', 'Application:Cisco/ReceivedCalls', 1],
                  ['Placed Calls', 'Application:Cisco/PlacedCalls', 1], ['Voicemail', 'Application:Cisco/Voicemail', 2]] as [$name, $url, $type]) {
            $xml .= "    <phoneService type=\"{$type}\" category=\"0\" name=\"{$name}\">\n";
            $xml .= "      <name>{$name}</name>\n";
            $xml .= "      <url>{$url}</url>\n";
            $xml .= "      <vendor></vendor>\n";
            $xml .= "      <version></version>\n";
            $xml .= "    </phoneService>\n";
        }
        return $xml . "  </phoneServices>\n";
    }

    /** The nearest documented name for an offset in minutes east of UTC. */
    private static function timeZone(int $minutes): string
    {
        if (isset(self::TIME_ZONES[$minutes])) {
            return self::TIME_ZONES[$minutes];
        }
        // A half-hour zone we have no name for: the closest whole hour, so the
        // clock is at most 30 minutes out instead of the phone refusing the file.
        $nearest = 0;
        foreach (array_keys(self::TIME_ZONES) as $offset) {
            if (abs($offset - $minutes) < abs($nearest - $minutes)) {
                $nearest = $offset;
            }
        }
        return self::TIME_ZONES[$nearest];
    }
}
