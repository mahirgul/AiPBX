<?php

declare(strict_types=1);

/**
 * Turns the AMI event stream into call events for webhooks, plugins and CRMs. One call is
 * everything with the same Linkedid (the caller, IVR, queue, Local channels, the agents):
 *
 *   call.ringing    a call started: an outside caller reached the PBX, or an extension dialled
 *   call.offered    an extension rings for it (queue, ring group or direct): the screen pop moment
 *   call.answered   a person answered (an extension, or the outside party of an outbound call);
 *                   an IVR or queue answering the line is not this
 *   call.completed  the last channel of the call hung up: outcome, wait and talk time
 *
 * No I/O: handle() takes one AMI event and returns the events to send, so the rules are
 * tested without Asterisk. bin/call_events.php feeds it.
 */
final class CallEventTracker
{
    /** A call that never ended (a lost Hangup) is forgotten after this. */
    private const STALE_SECONDS = 43200;

    /** @var array<string, array<string, mixed>> linkedid => state */
    private array $calls = [];

    /** @var array<string, string> channel uniqueid => linkedid (survives transfers changing Linkedid) */
    private array $uidToCall = [];

    /** @var array<string, true> */
    private array $trunks;

    /** @var callable(string): ?array */
    private $lookup;

    /**
     * @param list<string> $trunkEndpoints PJSIP endpoint names of the trunks
     * @param callable(string): ?array $lookup caller number => address book contact or null
     */
    public function __construct(array $trunkEndpoints, callable $lookup)
    {
        $this->trunks = array_fill_keys($trunkEndpoints, true);
        $this->lookup = $lookup;
    }

    /** @param list<string> $trunkEndpoints */
    public function setTrunks(array $trunkEndpoints): void
    {
        $this->trunks = array_fill_keys($trunkEndpoints, true);
    }

    /** Clear all tracked call state (e.g. on AMI reconnect). */
    public function reset(): void
    {
        $this->calls = [];
        $this->uidToCall = [];
    }

    /** Forget calls older than STALE_SECONDS even when AMI is idle. */
    public function purgeStale(?int $now = null): void
    {
        $this->forgetStale($now ?? time());
    }

    /**
     * "PJSIP/19000-webrtc-0000001a" => ['ext', '19000'],
     * "Local/19000@cc-internal-00000001;1" => ['ext', '19000'],
     * a trunk => ['trunk', name], anything else (other Local, a conference) => ['other', ''].
     *
     * @return array{0: string, 1: string}
     */
    public function classify(string $channel): array
    {
        if (preg_match('#^Local/(\d{2,8})@cc-internal(?:-[0-9a-fA-F]+;[12])?$#', $channel, $lm)) {
            return ['ext', $lm[1]];
        }
        if (!preg_match('#^PJSIP/(.+)-[0-9a-f]{8}$#', $channel, $m)) {
            return ['other', ''];
        }
        $endpoint = $m[1];
        if (isset($this->trunks[$endpoint])) {
            return ['trunk', $endpoint];
        }
        if (preg_match('/^(\d{2,8})(?:-(?:sip|webrtc|mob-webrtc))?$/', $endpoint, $e)) {
            return ['ext', $e[1]];
        }
        return ['other', ''];
    }

    /**
     * @param array<string, string> $ev one AMI event (keys as Asterisk sends them)
     * @return list<array{0: string, 1: array<string, mixed>}> event name and data
     */
    public function handle(array $ev, ?int $now = null): array
    {
        $now ??= time();
        $event = (string) ($ev['Event'] ?? '');
        $uid = (string) ($ev['Uniqueid'] ?? '');
        $linked = (string) ($ev['Linkedid'] ?? '');
        if ($linked === '' && $event === 'Hangup' && $uid !== '' && isset($this->uidToCall[$uid])) {
            $linked = $this->uidToCall[$uid];
        }
        if ($linked === '') {
            return [];
        }
        $out = [];
        switch ($event) {
            case 'Newchannel':
                $isNew = !isset($this->calls[$linked]);
                $c = &$this->call($linked, $now);
                if ($uid !== '') {
                    if (isset($this->uidToCall[$uid]) && $this->uidToCall[$uid] !== $linked) {
                        $prev = $this->uidToCall[$uid];
                        unset($this->calls[$prev]['channels'][$uid]);
                    }
                    $c['channels'][$uid] = true;
                    $this->uidToCall[$uid] = $linked;
                }
                if ($isNew && $uid === $linked) {
                    $channel = (string) ($ev['Channel'] ?? '');
                    [$kind, $name] = $this->classify($channel);
                    $c['caller'] = ['number' => (string) ($ev['CallerIDNum'] ?? ''), 'name' => self::cidName((string) ($ev['CallerIDName'] ?? ''))];
                    if ($kind === 'trunk') {
                        $c['direction'] = 'inbound';
                        $c['trunk'] = $name;
                        $c['did'] = (string) ($ev['Exten'] ?? '');
                        $c['caller']['contact'] = ($this->lookup)($c['caller']['number']);
                        $c['rang'] = true;
                        $out[] = ['call.ringing', $this->data($linked)];
                    } elseif ($kind === 'ext') {
                        $c['from_ext'] = $name;
                        $c['dialled'] = (string) ($ev['Exten'] ?? ''); // what the phone dialled
                        if (str_starts_with($channel, 'Local/')) {
                            $c['is_originate'] = true;
                            if ($c['caller']['number'] === '') {
                                $c['caller']['number'] = $name;
                            }
                            if ($c['dialled'] === $name || $c['dialled'] === 's') {
                                $c['dialled'] = '';
                            }
                        }
                    }
                }
                unset($c);
                break;

            case 'QueueCallerJoin':
                if (isset($this->calls[$linked])) {
                    $this->calls[$linked]['queue'] = (string) ($ev['Queue'] ?? '');
                }
                break;

            case 'QueueCallerAbandon':
                if (isset($this->calls[$linked])) {
                    $this->calls[$linked]['abandoned'] = true;
                }
                break;

            case 'DialBegin':
                if (!isset($this->calls[$linked])) {
                    break;
                }
                $c = &$this->calls[$linked];
                [$kind, $name] = $this->classify((string) ($ev['DestChannel'] ?? ''));
                // Click-to-call first rings the originating extension's own phone via Local/<ext>@cc-internal;
                // do not mark the call rang/internal, offer it back to the caller, or fire call.answered early.
                if (!empty($c['is_originate']) && $kind === 'ext' && $name === ($c['from_ext'] ?? '')) {
                    unset($c);
                    break;
                }
                if (empty($c['rang'])) {
                    // A call an extension started: its direction is known at the first dial.
                    $c['direction'] = $kind === 'trunk' ? 'outbound' : 'internal';
                    // The number the phone dialled; a trunk channel's own exten is often just "s".
                    $dialled = (string) ($c['dialled'] ?? '');
                    $evExten = (string) ($ev['Exten'] ?? '');
                    $destExten = (string) ($ev['DestExten'] ?? '');
                    $targetNum = !in_array($dialled, ['', 's'], true)
                        ? $dialled
                        : (!in_array($evExten, ['', 's'], true) ? $evExten : $destExten);
                    $c['callee'] = ['number' => $targetNum];
                    if ($kind === 'trunk') {
                        $c['trunk'] = $name;
                        $c['callee']['contact'] = ($this->lookup)($c['callee']['number']);
                    }
                    $c['rang'] = true;
                    $out[] = ['call.ringing', $this->data($linked)];
                }
                $destUid = (string) ($ev['DestUniqueid'] ?? '');
                if ($kind === 'ext') {
                    // One extension can ring on three endpoints (desk phone, web, app): one offer.
                    $open = $c['ringing'][$name] ?? 0;
                    $c['ringing'][$name] = $open + 1;
                    if ($destUid !== '') {
                        $c['dest'][$destUid] = $name;
                    }
                    if ($open === 0 && ($c['from_ext'] ?? '') !== $name) {
                        $out[] = ['call.offered', $this->data($linked, ['agent_extension' => $name])];
                    }
                } elseif ($kind === 'trunk') {
                    if ($destUid !== '') {
                        $c['dest'][$destUid] = 'trunk:' . $name;
                    }
                }
                unset($c);
                break;

            case 'DialEnd':
                if (!isset($this->calls[$linked])) {
                    break;
                }
                $c = &$this->calls[$linked];
                $destUid = (string) ($ev['DestUniqueid'] ?? '');
                $who = $destUid !== '' ? ($c['dest'][$destUid] ?? null) : null;
                if ($who === null) {
                    unset($c);
                    break;
                }
                unset($c['dest'][$destUid]);
                $isTrunk = str_starts_with($who, 'trunk:');
                if (!$isTrunk) {
                    $c['ringing'][$who] = max(0, ($c['ringing'][$who] ?? 1) - 1);
                }
                if (strtoupper((string) ($ev['DialStatus'] ?? '')) === 'ANSWER') {
                    $agent = $isTrunk ? (string) ($c['from_ext'] ?? '') : $who;
                    if (!isset($c['answered_at'])) {
                        $c['answered_at'] = $now;
                        $c['agent'] = $agent;
                    }
                    // After a transfer the call ends with the last one who answered.
                    $c['last_agent'] = $agent;
                    $key = $isTrunk ? $who : 'ext:' . $who;
                    if (empty($c['answered_by'][$key])) {
                        $c['answered_by'][$key] = true; // a transfer to another extension answers again
                        $out[] = ['call.answered', $this->data($linked, ['agent_extension' => $agent])];
                    }
                }
                unset($c);
                break;

            case 'Hangup':
                $callIds = [];
                if ($uid !== '' && isset($this->uidToCall[$uid])) {
                    $callIds[] = $this->uidToCall[$uid];
                    unset($this->uidToCall[$uid]);
                }
                if (isset($this->calls[$linked]) && !in_array($linked, $callIds, true)) {
                    $callIds[] = $linked;
                }
                foreach ($callIds as $cid) {
                    if (!isset($this->calls[$cid])) {
                        continue;
                    }
                    if ($uid !== '') {
                        unset($this->calls[$cid]['channels'][$uid]);
                        if (isset($this->calls[$cid]['dest'][$uid])) {
                            $destWho = $this->calls[$cid]['dest'][$uid];
                            unset($this->calls[$cid]['dest'][$uid]);
                            if (!str_starts_with($destWho, 'trunk:')) {
                                $this->calls[$cid]['ringing'][$destWho] = max(0, ($this->calls[$cid]['ringing'][$destWho] ?? 1) - 1);
                            }
                        }
                    }
                    if ($this->calls[$cid]['channels'] === []) {
                        if (!empty($this->calls[$cid]['rang'])) {
                            $this->calls[$cid]['ended_at'] = $now;
                            $out[] = ['call.completed', $this->data($cid)];
                        }
                        unset($this->calls[$cid]);
                    }
                }
                break;
        }
        $this->forgetStale($now);
        return $out;
    }

    /** Number of calls being followed (health and tests). */
    public function openCalls(): int
    {
        return count($this->calls);
    }

    /** @return array<string, mixed> */
    private function &call(string $linked, int $now): array
    {
        if (!isset($this->calls[$linked])) {
            $this->calls[$linked] = ['started_at' => $now, 'channels' => [], 'ringing' => [], 'dest' => [], 'answered_by' => []];
        }
        return $this->calls[$linked];
    }

    /**
     * The common fields of every call event.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function data(string $linked, array $extra = []): array
    {
        $c = $this->calls[$linked];
        $fmt = fn($t) => $t === null ? null : date(DATE_ATOM, (int) $t);
        $d = [
            'linkedid' => $linked,
            'direction' => $c['direction'] ?? 'internal',
            'did' => (string) ($c['did'] ?? ''),
            'trunk' => (string) ($c['trunk'] ?? ''),
            'queue' => (string) ($c['queue'] ?? ''),
            'caller' => $c['caller'] ?? ['number' => '', 'name' => ''],
            'callee' => $c['callee'] ?? null,
            'from_extension' => (string) ($c['from_ext'] ?? ''),
            // The one who has the call now (after a transfer: the last to answer); the first
            // stays in first_agent_extension, and the wait and talk times count from the first answer.
            'agent_extension' => (string) ($c['last_agent'] ?? $c['agent'] ?? ''),
            'first_agent_extension' => (string) ($c['agent'] ?? ''),
            'started_at' => $fmt($c['started_at']),
        ];
        if (isset($c['ended_at'])) {
            $answered = $c['answered_at'] ?? null;
            $d['answered_at'] = $fmt($answered);
            $d['ended_at'] = $fmt($c['ended_at']);
            $d['wait_seconds'] = (int) (($answered ?? $c['ended_at']) - $c['started_at']);
            $d['talk_seconds'] = $answered === null ? 0 : (int) ($c['ended_at'] - $answered);
            $d['outcome'] = $answered !== null ? 'answered' : (!empty($c['abandoned']) ? 'abandoned' : 'missed');
        }
        return array_merge($d, $extra);
    }

    private static function cidName(string $name): string
    {
        return in_array(strtolower($name), ['<unknown>', 'unknown', ''], true) ? '' : $name;
    }

    private function forgetStale(int $now): void
    {
        foreach ($this->calls as $id => $c) {
            if ($now - (int) $c['started_at'] > self::STALE_SECONDS) {
                foreach (array_keys($c['channels']) as $chUid) {
                    unset($this->uidToCall[$chUid]);
                }
                unset($this->calls[$id]);
            }
        }
    }
}
