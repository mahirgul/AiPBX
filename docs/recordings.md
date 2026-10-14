# Call recordings

## Where they are

Recordings are written by Asterisk (MixMonitor) to `/var/spool/asterisk/monitor/`:

| Prefix | Recorded when |
|--------|---------------|
| `outbound_…` | an outbound route is used |
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

With an S3-compatible bucket on **Admin → File storage** (AWS S3, Wasabi, Backblaze B2, Cloudflare R2,
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
