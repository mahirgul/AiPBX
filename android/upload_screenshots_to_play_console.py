#!/usr/bin/env python3
"""
Uploads Android app screenshots from docs/img to Google Play Console Store Listings.
Supports both tr-TR and en-US listings.
"""

import os
import sys
import json
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload
from googleapiclient.errors import HttpError

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(BASE_DIR)
KEY_FILE = os.path.join(BASE_DIR, 'hosting-1-491712-4626c13e8f8b.json')
PACKAGE_NAME = 'com.mhrgl.AiPBX'

SCREENSHOTS = [
    (os.path.join(PROJECT_ROOT, 'docs', 'img', 'app_dialer.jpg'), '1_dialer'),
    (os.path.join(PROJECT_ROOT, 'docs', 'img', 'app_chat.jpg'), '2_chat'),
    (os.path.join(PROJECT_ROOT, 'docs', 'img', 'app_contacts.jpg'), '3_contacts'),
    (os.path.join(PROJECT_ROOT, 'docs', 'img', 'app_history.jpg'), '4_history'),
    (os.path.join(PROJECT_ROOT, 'docs', 'img', 'app_login.jpg'), '5_login'),
]


def upload_screenshots(commit_changes=True):
    if not os.path.exists(KEY_FILE):
        print(f"Hata: Key dosyasi bulunamadi: {KEY_FILE}")
        sys.exit(1)

    for path, name in SCREENSHOTS:
        if not os.path.exists(path):
            print(f"Hata: Ekran goruntusu bulunamadi: {path}")
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
        for lang in ['tr-TR', 'en-US']:
            print(f"\n3. [{lang}] Eski telefon ekran goruntuleri temizleniyor...")
            service.edits().images().deleteall(
                packageName=PACKAGE_NAME,
                editId=edit_id,
                language=lang,
                imageType='phoneScreenshots'
            ).execute()

            print(f"4. [{lang}] 5 yeni ekran goruntusu yukleniyor...")
            for img_path, label in SCREENSHOTS:
                media = MediaFileUpload(img_path, mimetype='image/jpeg')
                res = service.edits().images().upload(
                    packageName=PACKAGE_NAME,
                    editId=edit_id,
                    language=lang,
                    imageType='phoneScreenshots',
                    media_body=media
                ).execute()
                img_id = res.get('image', {}).get('id')
                print(f"   -> [{lang}] {label} yuklendi (ID: {img_id})")

        print("\n5. Degisiklikler dogrulaniyor (validate)...")
        service.edits().validate(packageName=PACKAGE_NAME, editId=edit_id).execute()
        print("   Dogrulama basarili!")

        if commit_changes:
            print("\n6. Degisiklikler Google Play Console'a onaylaniyor (commit)...")
            service.edits().commit(packageName=PACKAGE_NAME, editId=edit_id).execute()
            print("\nTEBRIKLER: Ekran goruntuleri Google Play Console magaza sayfasina basariyla yayinlandi!")
        else:
            print("\nBilgi: commit=False secildigi icin degisiklikler geri aliniyor.")
            service.edits().delete(packageName=PACKAGE_NAME, editId=edit_id).execute()

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
    dry_run = '--dry-run' in sys.argv
    upload_screenshots(commit_changes=not dry_run)
