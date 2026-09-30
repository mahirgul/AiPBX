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

## Access

Admins and users with **can listen recordings** hear every recording; other users only recordings of
their own calls.
