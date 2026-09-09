import os
import json
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload
from googleapiclient.errors import HttpError

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
KEY_FILE = os.path.join(BASE_DIR, 'hosting-1-491712-4626c13e8f8b.json')
PACKAGE_NAME = 'com.mhrgl.AiPBX'
GRAPHIC_PATH = os.path.join(BASE_DIR, 'playstore_feature_graphic_1024x500.png')

def upload_feature():
    if not os.path.exists(KEY_FILE) or not os.path.exists(GRAPHIC_PATH):
        print("Dosya eksik.")
        return

    creds = service_account.Credentials.from_service_account_file(
        KEY_FILE,
        scopes=['https://www.googleapis.com/auth/androidpublisher']
    )
    service = build('androidpublisher', 'v3', credentials=creds)

    try:
        edit = service.edits().insert(packageName=PACKAGE_NAME, body={}).execute()
        edit_id = edit['id']
        print(f"Edit ID: {edit_id}")

        # Check existing listings to find languages
        listings = service.edits().listings().list(packageName=PACKAGE_NAME, editId=edit_id).execute()
        languages = [item['language'] for item in listings.get('listings', [])]
        print(f"Mevcut Magaza Dilleri: {languages}")

        if not languages:
            languages = ['tr-TR']

        for lang in languages:
            print(f"'{lang}' dili icin Feature Graphic yukleniyor...")
            # First delete existing if any
            try:
                service.edits().images().deleteall(
                    packageName=PACKAGE_NAME,
                    editId=edit_id,
                    language=lang,
                    imageType='featureGraphic'
                ).execute()
            except Exception as del_err:
                print(f"   (Eski resim silme uyarisi: {del_err})")

            media = MediaFileUpload(GRAPHIC_PATH, mimetype='image/png')
            res = service.edits().images().upload(
                packageName=PACKAGE_NAME,
                editId=edit_id,
                language=lang,
                imageType='featureGraphic',
                media_body=media
            ).execute()
            print(f"   Basarili! Resim ID: {res['image']['id']}")

        service.edits().commit(packageName=PACKAGE_NAME, editId=edit_id).execute()
        print("Tebrikler! Feature Graphic basariyla kaydedildi.")

    except HttpError as e:
        print(f"HTTP Hatasi: {e.resp.status}")
        try:
            print(json.loads(e.content.decode('utf-8')))
        except Exception:
            print(e.content)
    except Exception as ex:
        print(f"Hata: {ex}")

if __name__ == '__main__':
    upload_feature()
