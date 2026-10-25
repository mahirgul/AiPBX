import os
import sys
import json
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload
from googleapiclient.errors import HttpError

BASE_DIR = os.path.dirname(os.path.abspath(__file__))


KEY_FILE = os.environ.get('PLAY_STORE_JSON_KEY_FILE') or os.path.join(BASE_DIR, 'hosting-1-491712-4626c13e8f8b.json')
if not os.path.exists(KEY_FILE):
    import glob
    candidates = (
        glob.glob(os.path.join(BASE_DIR, 'hosting-*.json')) +
        glob.glob(os.path.join(BASE_DIR, '*service-account*.json')) +
        glob.glob(os.path.join(BASE_DIR, 'play-*.json'))
    )
    if candidates:
        KEY_FILE = candidates[0]

PACKAGE_NAME = 'com.mhrgl.AiPBX'
AAB_PATH = os.path.join(BASE_DIR, 'app', 'build', 'outputs', 'bundle', 'release', 'app-release.aab')
TRACK = sys.argv[1] if len(sys.argv) > 1 else 'production'
DESIRED_STATUS = sys.argv[2] if len(sys.argv) > 2 else 'completed'


def version_name():
    """The app's versionName: the repo VERSION file (build.gradle.kts reads it too).

    Previously both the release name ('1.0.10') and the final success message ('1.0.9')
    were typed into the script BY HAND and did not match; they were also
    forgotten when the version was raised (2026-09-05).
    """
    try:
        with open(os.path.join(BASE_DIR, '..', 'VERSION'), encoding='utf-8') as f:
            return f.read().strip() or '?'
    except OSError:
        return '?'


def play_release_notes():
    """'What's new' for this version from play-release-notes.json.

    The text used to be written into this script and was never changed, so
    every release showed the same old notes. The file names the version it
    belongs to; for any other versionName a neutral text is sent instead of
    stale notes.
    """
    with open(os.path.join(BASE_DIR, 'play-release-notes.json'), encoding='utf-8') as f:
        data = json.load(f)
    version = version_name()
    notes = data.get('notes', {})
    if data.get('version') != version:
        print(f"    WARNING: play-release-notes.json is for {data.get('version')}, the app is {version}: sending the generic text.")
        notes = data.get('fallback', {})
    return [{'language': lang, 'text': text[:500]} for lang, text in notes.items()]


def upload_bundle():
    if not os.path.exists(KEY_FILE):
        print(f"Error: key file not found: {KEY_FILE}")
        sys.exit(1)
    if not os.path.exists(AAB_PATH):
        print(f"Error: AAB file not found: {AAB_PATH}")
        sys.exit(1)

    print("1. Connecting to the Google Play Developer API...")
    creds = service_account.Credentials.from_service_account_file(
        KEY_FILE,
        scopes=['https://www.googleapis.com/auth/androidpublisher']
    )
    service = build('androidpublisher', 'v3', credentials=creds)

    print(f"2. '{PACKAGE_NAME}' creating a new edit for the package...")
    edit = service.edits().insert(packageName=PACKAGE_NAME, body={}).execute()
    edit_id = edit['id']
    print(f"   Edit ID: {edit_id}")

    try:
        # --- AAB UPLOAD ---
        print(f"3. Uploading the AAB ({AAB_PATH})...")
        version_code = None
        try:
            media = MediaFileUpload(AAB_PATH, mimetype='application/octet-stream', resumable=True)
            bundle_response = service.edits().bundles().upload(
                packageName=PACKAGE_NAME,
                editId=edit_id,
                media_body=media
            ).execute()
            version_code = bundle_response['versionCode']
            print(f"   AAB uploaded! Version code: {version_code}")
        except HttpError as upload_err:
            if "already been used" in str(upload_err):
                print(f"   Info: this version code is already on Google Play, listing the existing bundle...")
                bundles = service.edits().bundles().list(packageName=PACKAGE_NAME, editId=edit_id).execute().get('bundles', [])
                if bundles:
                    version_code = max(b['versionCode'] for b in bundles)
                    print(f"   Existing bundle found. Version code: {version_code}")
                else:
                    raise upload_err
            else:
                raise upload_err

        # --- PROGUARD MAPPING UPLOAD ---
        mapping_path = os.path.join(BASE_DIR, 'app', 'build', 'outputs', 'mapping', 'release', 'mapping.txt')
        if os.path.exists(mapping_path) and version_code:
            try:
                print(f"3b. Uploading the ProGuard mapping file ({mapping_path})...")
                mapping_media = MediaFileUpload(mapping_path, mimetype='application/octet-stream')
                service.edits().deobfuscationfiles().upload(
                    packageName=PACKAGE_NAME,
                    editId=edit_id,
                    deobfuscationFileType='proguard',
                    apkVersionCode=version_code,
                    media_body=mapping_media
                ).execute()
                print("    Mapping file uploaded.")
            except Exception as map_err:
                print(f"    Mapping upload warning (optional): {map_err}")

        # --- TRACK ATAMA ---
        track_label = "Uretim / Production" if TRACK == "production" else ("Kapali Test / Closed Testing" if TRACK == "alpha" else TRACK)
        print(f"4. Release -> '{TRACK}' ({track_label}) is being assigned to the track (status: {DESIRED_STATUS})...")
        release_notes = play_release_notes()

        track_body = {
            'track': TRACK,
            'releases': [{
                'name': f'{version_name()} (Build {version_code})',
                'versionCodes': [str(version_code)],
                'status': DESIRED_STATUS,
                'releaseNotes': release_notes
            }]
        }

        try:
            service.edits().tracks().update(
                packageName=PACKAGE_NAME,
                editId=edit_id,
                track=TRACK,
                body=track_body
            ).execute()
            print(f"   Release assigned to track '{TRACK}' ({DESIRED_STATUS}).")
        except HttpError as track_err:
            print(f"   Warning: status {DESIRED_STATUS} could not be set ({track_err.resp.status}), trying 'draft'...")
            track_body['releases'][0]['status'] = 'draft'
            service.edits().tracks().update(
                packageName=PACKAGE_NAME,
                editId=edit_id,
                track=TRACK,
                body=track_body
            ).execute()
            print(f"   Release assigned to track '{TRACK}' as 'draft'.")

        # --- COMMIT ---
        print(f"5. Edit ({edit_id}) is being committed to Google Play...")
        service.edits().commit(packageName=PACKAGE_NAME, editId=edit_id).execute()
        print(f"\nSUCCESS: version {version_name()} (build {version_code}) uploaded to Google Play Console track '{TRACK}'!")

    except HttpError as e:
        print(f"\n[HTTP error] Code: {e.resp.status}")
        try:
            err = json.loads(e.content.decode('utf-8'))
            print("Details:", json.dumps(err, indent=2, ensure_ascii=False))
        except Exception:
            print("Icerik:", e.content)
        try:
            service.edits().delete(packageName=PACKAGE_NAME, editId=edit_id).execute()
        except Exception:
            pass
        sys.exit(1)
    except Exception as ex:
        print(f"\nError: {ex}")
        try:
            service.edits().delete(packageName=PACKAGE_NAME, editId=edit_id).execute()
        except Exception:
            pass
        sys.exit(1)

if __name__ == '__main__':
    upload_bundle()
