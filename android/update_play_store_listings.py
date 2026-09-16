#!/usr/bin/env python3
"""
Updates Google Play Console Store Listing texts (Title, Short Description, Full Description)
for both English (en-US) and Turkish (tr-TR) localizations.
"""

import os
import sys
import json
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
KEY_FILE = os.path.join(BASE_DIR, 'hosting-1-491712-4626c13e8f8b.json')
PACKAGE_NAME = 'com.mhrgl.AiPBX'

LISTINGS = {
    'en-US': {
        'title': 'AiPBX',
        'shortDescription': 'Enterprise IP PBX client with HD voice, team messaging & live directory',
        'fullDescription': (
            "AiPBX is a next-generation enterprise IP PBX client and unified communications softphone "
            "designed to seamlessly connect to your organization's Asterisk PBX infrastructure. "
            "Experience crystal-clear HD audio, real-time presence, team collaboration, and reliable call handling from anywhere.\n\n"
            "Key Features:\n\n"
            "📞 Enterprise Voice Communications\n"
            "• High-definition Opus & G.711 audio powered by dual WebRTC and SIP engines\n"
            "• End-to-end SRTP / DTLS-SRTP encryption for maximum security\n"
            "• Instant push notifications (FCM) to wake up the app for incoming calls, even when locked or in deep sleep\n"
            "• Flexible call control: In-call transfer, hold, mute, speakerphone, and DTMF dialpad\n"
            "• PBX features: Do Not Disturb (DND), Call Forwarding (*72), and Asterisk feature codes\n\n"
            "💬 Real-Time Team Messaging & Group Chat\n"
            "• 1-to-1 direct messaging and multi-user group chat rooms\n"
            "• High-performance WebSocket chat engine with real-time delivery and read receipts\n"
            "• Easy group management: Create groups, assign admin roles, and invite colleagues\n"
            "• Image and file sharing with automated compression and secure delivery\n"
            "• Smart conversation filtering (All, Direct, Groups) and instant search\n\n"
            "👥 Corporate Directory & Live Presence\n"
            "• Synchronized corporate directory with live extension presence (online, offline, busy)\n"
            "• One-tap dialing and messaging to company extensions\n"
            "• Native device contacts integration\n\n"
            "📊 Call History & In-App Diagnostics\n"
            "• Comprehensive call logs (incoming, outgoing, missed) with duration and timestamps\n"
            "• Built-in real-time diagnostic log viewer for network and SIP troubleshooting\n\n"
            "🔒 Secure & Self-Hosted\n"
            "• Connects directly to your own self-hosted AiPBX / Asterisk server\n"
            "• Zero third-party cloud dependence or recurring per-user fees\n"
            "• Full compliance with enterprise privacy and data retention policies\n\n"
            "Requires an active AiPBX or Asterisk server connection. Contact your system administrator for setup credentials."
        )
    },
    'tr-TR': {
        'title': 'AiPBX',
        'shortDescription': 'Kurumsal IP Santral, HD Sesli Arama, Ekip Sohbeti ve Dahili Rehber Uygulaması',
        'fullDescription': (
            "AiPBX, kurumunuzun Asterisk tabanlı santral altyapısına doğrudan bağlanan, kristal netliğinde ses kalitesi "
            "ve güvenli kurumsal iletişim sağlayan yeni nesil mobil santral ve tümleşik iletişim uygulamasıdır.\n\n"
            "Temel Özellikler:\n\n"
            "📞 Kurumsal Sesli İletişim\n"
            "• WebRTC ve SIP motoruyla Opus ve G.711 formatlarında yüksek kaliteli (HD) ses iletimi\n"
            "• Uçtan uca DTLS-SRTP şifreleme ile üst düzey çağrı güvenliği\n"
            "• Ekran kapalı veya kilitliyken FCM push bildirimleri ile anında çağrı uyandırma ve yanıtlama\n"
            "• Çağrı yönetimi: Aktarma (transfer), bekletme, sessize alma ve hoparlör\n"
            "• Santral özellikleri: Rahatsız Etmeyin (DND), Çağrı Yönlendirme (*72) ve özel Asterisk kodları\n\n"
            "💬 Anlık Mesajlaşma ve Grup Sohbeti\n"
            "• Bire bir doğrudan mesajlaşma ve çok katılımcılı ekip grup sohbet odaları\n"
            "• Yüksek performanslı WebSocket mimarisi, anlık teslimat ve okundu bildirimleri\n"
            "• Zengin grup yönetimi: Yeni grup kurma, yönetici atama ve üye davet etme\n"
            "• Görsel ve dosya paylaşımı, otomatik sıkıştırma desteği\n"
            "• Akıllı sohbet filtreleme (Tümü, Bireysel, Gruplar) ve hızlı arama\n\n"
            "👥 Kurumsal Rehber ve Canlı Durum (Presence)\n"
            "• Canlı dahili durum göstergeleri (çevrimiçi, meşgul, çevrimdışı)\n"
            "• Tek dokunuşla dahili arama ve mesajlaşma\n"
            "• Telefonun yerel rehberiyle kusursuz entegrasyon\n\n"
            "📊 Detaylı Çağrı Geçmişi ve Teşhis\n"
            "• Gelen, giden ve cevapsız çağrıların ayrıntılı listesi\n"
            "• Ağ ve bağlantı sorunlarını anında tespit edebileceğiniz dahili log görüntüleyici\n\n"
            "🔒 Güvenli ve Kendi Sunucunuzda (On-Premise)\n"
            "• Doğrudan kendi AiPBX / Asterisk sunucunuza bağlanır\n"
            "• Üçüncü parti bulut bağımlılığı yoktur, verileriniz tamamen kurumunuzda kalır\n\n"
            "Not: Bu uygulamanın kullanılabilmesi için kurumunuza ait bir AiPBX veya Asterisk sunucusu gereklidir. Giriş bilgileri için sistem yöneticinizle iletişime geçiniz."
        )
    }
}


def update_listings(commit_changes=True):
    if not os.path.exists(KEY_FILE):
        print(f"Hata: Key dosyasi bulunamadi: {KEY_FILE}")
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
        for lang, data in LISTINGS.items():
            print(f"3. [{lang}] Magaza metinleri guncelleniyor...")
            print(f"   Baslik: {data['title']}")
            print(f"   Kisa Aciklama ({len(data['shortDescription'])} kr): {data['shortDescription']}")
            print(f"   Tam Aciklama ({len(data['fullDescription'])} kr)")
            service.edits().listings().update(
                packageName=PACKAGE_NAME,
                editId=edit_id,
                language=lang,
                body=data
            ).execute()

        print("\n4. Degisiklikler dogrulaniyor (validate)...")
        service.edits().validate(packageName=PACKAGE_NAME, editId=edit_id).execute()
        print("   Dogrulama basarili!")

        if commit_changes:
            print("\n5. Degisiklikler Google Play Console'a onaylaniyor (commit)...")
            service.edits().commit(packageName=PACKAGE_NAME, editId=edit_id).execute()
            print("\nTEBRIKLER: Magaza aciklamalari Google Play Console'a basariyla kaydedildi!")
        else:
            print("\nBilgi: commit=False secildigi icin iptal edildi.")
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
    update_listings(commit_changes=not dry_run)
