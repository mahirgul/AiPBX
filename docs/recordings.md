# Call recordings

## Where they are

Recordings are written by Asterisk (MixMonitor) to `/var/spool/asterisk/monitor/`:

| Prefix | Recorded when |
|--------|---------------|
| `outbound_…` | every call that leaves through a trunk (a fax excepted) while **Privacy → Record outbound calls** is on (the default); not when the caller opted out of recording (an inbound call forwarded out keeps that choice). The recording beep applies as for inbound calls |
| `inbound_…` | the inbound route has *record call* enabled |
| `rg_…` | a ring group records |
| queue recordings | the queue records |

The CDR stores the file path; the portal (CDR reports, *My Phone*, queue reports, mobile apps) plays and
downloads recordings through it.

## Automatic MP3 conversion

WAV recordings take about **1 MB per minute**. Every 5 minutes a cron job
(`/usr/local/bin/recordings_to_mp3.php`) converts recordings that are no longer being written to
**mono 16 kbps MP3** (about **0.12 MB per minute**, ~8× smaller):

- only files untouched for 3 minutes are converted, so running calls are never touched;
- owner, permissions and modification time are kept;
- the CDR path is updated to the `.mp3` file, then the WAV is deleted;
- header-only WAVs (calls that were never answered) are removed and unlinked from the CDR.

Existing recordings are converted on the first runs after installing or updating (21 GB of recordings
became 2.7 GB on one system). The encoder is `lame`, installed by `install.sh`.

For telephone audio 16 kbps is normally enough. If recordings sound muffled, raise the bitrate in
`web/bin/recordings_to_mp3.php` (`-b 16` → `-b 24` or `-b 32`); it only affects new conversions.

## In an S3 bucket

With an S3-compatible bucket on **System → File storage** (AWS S3, Wasabi, Backblaze B2, Cloudflare R2,
MinIO …), tick **Call recordings in the bucket too**. Then:

- every finished MP3 is uploaded by the same 5-minute job and removed from this server's disk; the
  CDR points at `s3:recordings/<path>`;
- recordings from before the switch follow, 200 per run;
- playing and downloading work as before, with the same access rules: the portal fetches the file
  through the chat service, so the bucket stays **private** (no public access needed);
- deleting a call record deletes its recording in the bucket too;
- when the bucket cannot be reached, recordings stay on the disk and are uploaded on a later run;
  nothing is lost.

Switching File storage back to the local disk stops the uploads; recordings already in the bucket
stay playable as long as the bucket settings are kept.

## Access

Admins and users with **can listen recordings** hear every recording; other users only recordings of
their own calls.

## Transcript and summary

In the player of a recording (Reports → CDR), **Transcribe & Summarize** writes the call down with
the speech-to-text engine of the language ([Cloud AI services](cloud-ai.md#engine-for-each-job), or
a local Whisper/Vosk model) and, when a cloud language model is set, adds a one- or two-sentence
summary. Only on request, one recording at a time; **Re-transcribe** makes it again. Who may do it
follows the rules of *Access* above, and each one is written to the audit log (who, which call,
which engine) — with a cloud engine the audio goes to that provider.

The text is kept in `cdr_ai` and the CDR search finds calls by what was said. Masking of card
numbers and ids applies when a privacy preset asks for it ([Privacy](privacy.md)). A transcript is
deleted with its recording: by the recording retention, the deletion of the call record and the
right to erasure.

## Quality review

With **criteria** written under *AI → Cloud services → Call quality review* (for example "greets with
the company name; asks for the customer number; offers a solution or a callback time"), every
transcript is also scored by the cloud language model of the language: **0–100** against the
criteria, the caller's **mood** (positive, neutral, negative), **resolved** or not, and one sentence
why. The score shows as a badge in the call list (green from 80, amber from 60) and in the player.
The call-center board (`/cc-board`) adds **Call quality by agent** for the chosen queue and period:
reviewed calls, average score, the share resolved and the unhappy callers of each agent.

**Review new recorded calls automatically** (off by default) has `cdr_qa.php` (every 10 minutes)
transcribe and score the answered, recorded calls of the last day that have no transcript yet,
newest first, up to **At most (calls a day)**. A call that cannot be transcribed is tried once.
Everything here goes to the cloud providers set for speech to text and the language model, and
counts against their [monthly limits](cloud-ai.md#monthly-limits); the cross-border lock of
[Privacy](privacy.md) stops it.
