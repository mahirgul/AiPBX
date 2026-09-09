import os
import sys
import json
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload
from googleapiclient.errors import HttpError

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
KEY_FILE = os.path.join(BASE_DIR, 'hosting-1-491712-4626c13e8f8b.json')
PACKAGE_NAME = 'com.mhrgl.AiPBX'
AAB_PATH = os.path.join(BASE_DIR, 'app', 'build', 'outputs', 'bundle', 'release', 'app-release.aab')
TRACK = sys.argv[1] if len(sys.argv) > 1 else 'alpha'
STATUS = 'draft' if TRACK in ('alpha', 'beta', 'production') else 'completed'

SCREENSHOTS = [
    os.path.join(BASE_DIR, 'screenshot_1_dialer.png'),
    os.path.join(BASE_DIR, 'screenshot_2_active_call.png'),
    os.path.join(BASE_DIR, 'screenshot_3_dtmf.png'),
    os.path.join(BASE_DIR, 'screenshot_4_contacts.png')
]

def upload_all():
    if not os.path.exists(KEY_FILE):
        print(f"Hata: Key dosyasi bulunamadi: {KEY_FILE}")
        return
    if not os.path.exists(AAB_PATH):
        print(f"Hata: AAB dosyasi bulunamadi: {AAB_PATH}")
        return

    print("1. Google Play Developer API baglantisi kuruluyor...")
    creds = service_account.Credentials.from_service_account_file(
        KEY_FILE,
        scopes=['https://www.googleapis.com/auth/androidpublisher']
    )
    service = build('androidpublisher', 'v3', credentials=creds)

    try:
        print(f"2. '{PACKAGE_NAME}' paketi icin yeni bir duzenleme (edit) olusturuluyor...")
        edit = service.edits().insert(packageName=PACKAGE_NAME, body={}).execute()
        edit_id = edit['id']
        print(f"   Duzenleme ID: {edit_id}")

        # --- AAB YUKLEME ---
        print(f"3. AAB paketi yukleniyor (v1.0.5, build 6)...")
        media = MediaFileUpload(AAB_PATH, mimetype='application/octet-stream', resumable=True)
        bundle_response = service.edits().bundles().upload(
            packageName=PACKAGE_NAME,
            editId=edit_id,
            media_body=media
        ).execute()

        version_code = bundle_response['versionCode']
        print(f"   AAB yuklendi! Version Code: {version_code}")

        # --- TRACK ATAMA ---
        print(f"4. Surum '{TRACK}' kanalina ataniyor...")
        track_body = {
            'track': TRACK,
            'releases': [{
                'name': f'1.0.8 (Build {version_code})',
                'versionCodes': [str(version_code)],
                'status': STATUS,
                'releaseNotes': [
                    {
                        'language': 'tr-TR',
                        'text': 'Arama gecmisi filtreleme, cevapsiz ve giden cagri kayitlari duzeltildi. WebRTC ve ses motoru kararliligi artirildi.'
                    },
                    {
                        'language': 'en-US',
                        'text': 'Fixed call history filtering, missed and outgoing call records. Improved WebRTC audio stability.'
                    }
                ]
            }]
        }
        service.edits().tracks().update(
            packageName=PACKAGE_NAME,
            editId=edit_id,
            track=TRACK,
            body=track_body
        ).execute()
        print(f"   Surum '{TRACK}' kanalina atandi.")

        # --- SCREENSHOTS YUKLEME ---
        print("5. Magaza ekran goruntuleri (Phone Screenshots) yukleniyor...")
        listings = service.edits().listings().list(packageName=PACKAGE_NAME, editId=edit_id).execute()
        languages = [item['language'] for item in listings.get('listings', [])]
        if not languages:
            languages = ['tr-TR', 'en-US']
        print(f"   Diller: {languages}")

        for lang in languages:
            print(f"   [{lang}] Dili icin ekran goruntuleri guncelleniyor...")
            try:
                service.edits().images().deleteall(
                    packageName=PACKAGE_NAME,
                    editId=edit_id,
                    language=lang,
                    imageType='phoneScreenshots'
                ).execute()
            except Exception as e:
                print(f"   (Eski screenshot silme notu: {e})")

            for idx, sc_path in enumerate(SCREENSHOTS, 1):
                if os.path.exists(sc_path):
                    sc_media = MediaFileUpload(sc_path, mimetype='image/png')
                    res = service.edits().images().upload(
                        packageName=PACKAGE_NAME,
                        editId=edit_id,
                        language=lang,
                        imageType='phoneScreenshots',
                        media_body=sc_media
                    ).execute()
                    print(f"      Screenshot {idx} yuklendi (ID: {res['image']['id']})")

        # --- COMMIT ---
        print("6. Tum degisiklikler onaylanip yayinlaniyor (commit)...")
        service.edits().commit(packageName=PACKAGE_NAME, editId=edit_id).execute()
        print("   Tebrikler! Hem yeni surum (AAB v1.0.5) hem de ekran goruntuleri Google Play Console'a basariyla kaydedildi.")

    except HttpError as e:
        print(f"\n[HTTP Hatasi] Kod: {e.resp.status}")
        try:
            err = json.loads(e.content.decode('utf-8'))
            print("Detay:", json.dumps(err, indent=2, ensure_ascii=False))
        except Exception:
            print("Icerik:", e.content)
    except Exception as ex:
        print(f"\nGenel Hata: {ex}")

if __name__ == '__main__':
    upload_all()
