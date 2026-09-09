import os
import sys
import json
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload
from googleapiclient.errors import HttpError

import re

BASE_DIR = os.path.dirname(os.path.abspath(__file__))


KEY_FILE = os.path.join(BASE_DIR, 'hosting-1-491712-4626c13e8f8b.json')
PACKAGE_NAME = 'com.mhrgl.AiPBX'
AAB_PATH = os.path.join(BASE_DIR, 'app', 'build', 'outputs', 'bundle', 'release', 'app-release.aab')
TRACK = sys.argv[1] if len(sys.argv) > 1 else 'alpha'
DESIRED_STATUS = sys.argv[2] if len(sys.argv) > 2 else 'completed'


def surum_adi():
    """versionName'i app/build.gradle.kts'ten okur.

    Onceden hem release adi ('1.0.10') hem de son basari mesaji ('1.0.9')
    betige ELLE yazilmisti ve ikisi birbirini tutmuyordu; surum yukseltilince
    guncellenmesi de unutuluyordu (2026-09-05).
    """
    try:
        with open(os.path.join(BASE_DIR, 'app', 'build.gradle.kts'), encoding='utf-8') as f:
            m = re.search(r'versionName\s*=\s*"([^"]+)"', f.read())
            if m:
                return m.group(1)
    except OSError:
        pass
    return '?'


def upload_bundle():
    if not os.path.exists(KEY_FILE):
        print(f"Hata: Key dosyasi bulunamadi: {KEY_FILE}")
        sys.exit(1)
    if not os.path.exists(AAB_PATH):
        print(f"Hata: AAB dosyasi bulunamadi: {AAB_PATH}")
        sys.exit(1)

    print("1. Google Play Developer API baglantisi kuruluyor...")
    creds = service_account.Credentials.from_service_account_file(
        KEY_FILE,
        scopes=['https://www.googleapis.com/auth/androidpublisher']
    )
    service = build('androidpublisher', 'v3', credentials=creds)

    print(f"2. '{PACKAGE_NAME}' paketi icin yeni bir edit olusturuluyor...")
    edit = service.edits().insert(packageName=PACKAGE_NAME, body={}).execute()
    edit_id = edit['id']
    print(f"   Edit ID: {edit_id}")

    try:
        # --- AAB YUKLEME ---
        print(f"3. AAB paketi yukleniyor ({AAB_PATH})...")
        version_code = None
        try:
            media = MediaFileUpload(AAB_PATH, mimetype='application/octet-stream', resumable=True)
            bundle_response = service.edits().bundles().upload(
                packageName=PACKAGE_NAME,
                editId=edit_id,
                media_body=media
            ).execute()
            version_code = bundle_response['versionCode']
            print(f"   AAB basariyla yuklendi! Version Code: {version_code}")
        except HttpError as upload_err:
            if "already been used" in str(upload_err):
                print(f"   Bilgi: Bu surum kodu zaten Google Play'e yuklenmis, mevcut paket listeleniyor...")
                bundles = service.edits().bundles().list(packageName=PACKAGE_NAME, editId=edit_id).execute().get('bundles', [])
                if bundles:
                    version_code = max(b['versionCode'] for b in bundles)
                    print(f"   Mevcut paket bulundu. Version Code: {version_code}")
                else:
                    raise upload_err
            else:
                raise upload_err

        # --- TRACK ATAMA ---
        track_label = "Kapali Test / Closed Testing" if TRACK == "alpha" else TRACK
        print(f"4. Surum '{TRACK}' ({track_label}) kanalina ataniyor (status: {DESIRED_STATUS})...")
        release_notes = [
            {
                'language': 'tr-TR',
                'text': 'Sohbet ve mesajlaşma butonu alt navigasyon çubuğuna (Rehber yanına) taşındı. Arayüz ve erişilebilirlik iyileştirmeleri yapıldı.'
            },
            {
                'language': 'en-US',
                'text': 'Moved chat button to the bottom navigation bar next to contacts. UI and accessibility enhancements.'
            }
        ]

        track_body = {
            'track': TRACK,
            'releases': [{
                'name': f'{surum_adi()} (Build {version_code})',
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
            print(f"   Surum '{TRACK}' kanalina atandi ({DESIRED_STATUS}).")
        except HttpError as track_err:
            print(f"   Uyari: {DESIRED_STATUS} olarak atanamadi ({track_err.resp.status}), 'draft' olarak deneniyor...")
            track_body['releases'][0]['status'] = 'draft'
            service.edits().tracks().update(
                packageName=PACKAGE_NAME,
                editId=edit_id,
                track=TRACK,
                body=track_body
            ).execute()
            print(f"   Surum '{TRACK}' kanalina 'draft' olarak atandi.")

        # --- COMMIT ---
        print(f"5. Edit ({edit_id}) Google Play'e onaylaniyor (commit)...")
        service.edits().commit(packageName=PACKAGE_NAME, editId=edit_id).execute()
        print(f"\nBASARILI: Sürüm {surum_adi()} (Build {version_code}) Google Play Console '{TRACK}' kanalina basariyla yuklendi!")

    except HttpError as e:
        print(f"\n[HTTP Hatasi] Kod: {e.resp.status}")
        try:
            err = json.loads(e.content.decode('utf-8'))
            print("Detay:", json.dumps(err, indent=2, ensure_ascii=False))
        except Exception:
            print("Icerik:", e.content)
        try:
            service.edits().delete(packageName=PACKAGE_NAME, editId=edit_id).execute()
        except Exception:
            pass
        sys.exit(1)
    except Exception as ex:
        print(f"\nGenel Hata: {ex}")
        try:
            service.edits().delete(packageName=PACKAGE_NAME, editId=edit_id).execute()
        except Exception:
            pass
        sys.exit(1)

if __name__ == '__main__':
    upload_bundle()
