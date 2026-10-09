# File storage

**Admin → File storage** (admin only) decides where the chat service keeps chat files: message
attachments, their thumbnails and group pictures.

| Storage | Where |
|---------|-------|
| **Local disk** (default) | `/var/lib/aipbx/chat_files` on the PBX (`CHAT_UPLOAD_DIR` in `/etc/ai-pbx.env`) |
| **S3-compatible storage** | a bucket at AWS S3, MinIO, Wasabi, Backblaze B2, Cloudflare R2 or any other S3-compatible service |

With a bucket, large files stay off the PBX's disk and out of its backups.

Call recordings, voicemail and faxes are not affected; they stay on the local disk
(see [Call recordings](recordings.md)).

## Access stays the same

Files are always downloaded through the chat service (`/chat/media/…`), which checks that the
person is a participant of a chat that contains the file. The bucket is never shown to browsers or
apps, so it can, and should, stay **private**: no public access and no bucket policy for anonymous
reads.

## Setting up a bucket

1. Create a bucket at your provider, plus a key that may only use that bucket. It needs
   `s3:PutObject`, `s3:GetObject` and `s3:DeleteObject` on the bucket's objects. For example, on AWS:

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [{
       "Effect": "Allow",
       "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
       "Resource": "arn:aws:s3:::aipbx-chat/*"
     }]
   }
   ```

2. On **Admin → File storage** choose *S3-compatible storage* and fill in:

   | Field | Value |
   |-------|-------|
   | Endpoint URL | empty for AWS S3; otherwise the provider's S3 address, e.g. `https://s3.eu-central-003.backblazeb2.com`, `https://s3.wasabisys.com`, `https://<account>.r2.cloudflarestorage.com` or `https://minio.example.com:9000` |
   | Region | the bucket's region, e.g. `eu-central-1` (`auto` for Cloudflare R2; any value for MinIO, usually `us-east-1`) |
   | Bucket | the bucket's name |
   | Folder in the bucket | optional; lets several PBXs share one bucket (`pbx1`, `pbx2` …) |
   | Access key / Secret key | the key from step 1 |
   | Path-style addresses | on for MinIO and most self-hosted servers, off for AWS S3 |

3. *Test connection* writes a small file, reads it back and deletes it, and shows the provider's
   error when something is wrong (for example `AccessDenied` or `NoSuchBucket`). *Save* runs the
   same test first, so the PBX never switches to a bucket it cannot write to.

The secret key is **encrypted** in the database with the same key as the [Cloud TTS](cloud-tts.md)
credentials (`AIPBX_SETTINGS_KEY` or `/var/lib/aipbx/settings.key`) and is never sent back to the
browser. Leave the field empty to keep the stored secret.

The chat service picks up a change within about ten seconds; no restart is needed.

## Files from before the switch

Files that were on the local disk before the switch are still served from there. Once S3 is in
use, the page shows how many files are left on the disk, and **Move files to the bucket** moves
them in the background. Each file is removed from the disk only after the bucket holds it with the
same size. A file that fails stays on the disk, the page shows the last error, and the move can be
run again.

Switching back to the local disk is possible at any time: new files are written to the disk again,
and files already in the bucket stay readable as long as the bucket settings are kept.

## Troubleshooting

- **"the chat service did not answer"**: check `systemctl status aipbx-chat` and
  `journalctl -u aipbx-chat`.
- **Uploads fail after saving**: the key may have lost its rights; *Test connection* shows the
  provider's answer. The chat service's log names the failing file.
- **`SignatureDoesNotMatch`**: usually a wrong secret key or region.
- **TLS errors with a bucket name that contains dots**: turn on *Path-style addresses*.
