<?php
/**
 * Is a Cisco firmware load actually on this server?
 *
 * A 7900/8800 phone told to run a load it cannot download does not fail
 * politely: it sits at "Upgrading" until somebody fixes the file server. So
 * before AiPBX points a phone at a load, it reads that load's manifest
 * (`<load>.loads`) and checks that every file the manifest names is in the
 * folder phones fetch from. Nothing here talks to a phone; it only reads.
 *
 * What a `.loads` file is (verified: Cisco's 7942/7962 9.4(2)SR3 readme and the
 * published package): a signed descriptor, 666 bytes in that release, whose
 * readable part lists the component images the release is made of, e.g.
 *
 *     jar42sip.9-4-2ES26.sbn cnu42.9-4-2ES26.sbn apps42.9-4-2ES26.sbn
 *     dsp42.9-4-2ES26.sbn    cvm42sip.9-4-2ES26.sbn
 *
 * The signature cannot be verified here and is not this class's job: the phone
 * checks it and rejects a load that fails. What is checked is the mundane
 * failure that is actually common — a package half copied into the folder.
 */
final class CiscoFirmware
{
    /** Bigger than any real manifest (the 9.4(2)SR3 one is under a kilobyte). */
    public const MAX_MANIFEST_BYTES = 65536;

    /** A component file name inside a manifest. No slashes, so no paths. */
    private const COMPONENT_RE = '/[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.(?:sbn|bin|txt|jar)/';

    /** A load name: the manifest's file name without `.loads`. */
    public static function validLoad(string $load): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $load);
    }

    /** The folder phones fetch from (Network services → TFTP/FTP folder). */
    public static function dir(): string
    {
        return getenv('AIPBX_TFTP_DIR') ?: '/var/lib/aipbx/tftp';
    }

    /**
     * The state of one load in $dir.
     *
     * state: 'ready'      every file the manifest names is there;
     *        'missing'    one or more are not, and `missing` names them;
     *        'unreadable' the manifest is absent, unreadable or names nothing
     *                     this class can check, so the set cannot be verified
     *                     (the phone may still get it from another server).
     *
     * @return array{load: string, state: string, missing: list<string>, components: int, dir: string}
     */
    public static function check(string $load, ?string $dir = null): array
    {
        $dir = $dir ?? self::dir();
        $out = ['load' => $load, 'state' => 'unreadable', 'missing' => [], 'components' => 0, 'dir' => $dir];
        if (!self::validLoad($load)) {
            return $out;
        }
        $manifest = $dir . '/' . $load . '.loads';
        if (!is_file($manifest) || !is_readable($manifest)) {
            $out['state'] = 'missing';
            $out['missing'] = [$load . '.loads'];
            return $out;
        }
        $handle = @fopen($manifest, 'rb');
        if ($handle === false) {
            return $out;
        }
        $bytes = (string) @fread($handle, self::MAX_MANIFEST_BYTES);
        fclose($handle);
        if ($bytes === '') {
            return $out;
        }

        preg_match_all(self::COMPONENT_RE, $bytes, $m);
        $components = [];
        foreach ($m[0] as $name) {
            if ($name !== $load . '.loads') {
                $components[$name] = true;   // a manifest may repeat a name
            }
        }
        $components = array_keys($components);
        $out['components'] = count($components);
        if ($components === []) {
            return $out;   // nothing readable: do not claim the set is fine
        }
        foreach ($components as $name) {
            if (!is_file($dir . '/' . $name)) {
                $out['missing'][] = $name;
            }
        }
        $out['state'] = $out['missing'] === [] ? 'ready' : 'missing';
        return $out;
    }
}
