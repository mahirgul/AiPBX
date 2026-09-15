# AI-PBX Chat — Grup Sohbeti Özelliği (Uygulama Planı)

**Durum:** Plan — kod değişikliği yapılmadı
**Hazırlayan:** Claude Code (analiz), **Uygulayacak:** Antigravity
**Tarih:** 2026-09-15
**Hedef sürüm:** Ubuntu 26.04 LTS üzerinde çalışan AiPBX (bkz. `INSTALL.md`)

> Bu belge, mevcut kod tabanının gerçek durumu okunarak hazırlandı. Dosya/satır referansları
> `00f470c` commit'i itibarıyladır. Uygulamadan önce ilgili dosyaları tekrar okuyun.

---

## 0. Mevcut Durum (Analiz Özeti)

Chat üç katmanda yaşıyor:

| Katman | Yol | Rol |
|---|---|---|
| Go servisi | `chat/` (`main.go`, `handlers.go`, `db.go`, `hub.go`, `auth.go`, `fcm.go`) | REST + WebSocket hub, medya yükleme, FCM tetikleme |
| Web portal | `web/src/controllers/ChatController.php`, `web/templates/views/chat/index.php` (~45 KB tek dosya) | Token üretimi + tüm sohbet arayüzü (vanilla JS) |
| Android | `android/app/src/main/java/com/mhrgl/aipbx/` (`ui/Chat*.kt`, `data/ApiClient.kt`, `data/ChatWebSocketManager.kt`) | Yerel istemci |

**Kritik tespit — şema zaten gruba hazır:** `20260906170000_create_chat_tables.php` içinde
`chat_conversations.type` (`direct` varsayılan), `title` ve `created_by` alanları mevcut;
`chat_participants` zaten N katılımcıyı destekliyor. Mesajlaşma, okundu bilgisi, FCM dağıtımı
ve WS yayını **katılımcı listesi üzerinden** çalışıyor (`GetParticipants` → döngü), yani
mesaj akışı grupta kendiliğinden çalışır. Eksik olan: grup oluşturma/yönetme API'si,
grup odaklı listeleme/görüntüleme ve arayüzler.

Bu yüzden iş, "sıfırdan grup altyapısı" değil, **var olan çok-katılımcılı altyapının
üstüne yönetim + arayüz eklemek**. Aşağıdaki fazlar buna göre sıralandı.

---

## 1. Veri Modeli (Faz 1)

Yeni Phinx migration: `web/db/migrations/20260916090000_add_chat_group_support.php`
(mevcut dosyaların idempotent stilini birebir izleyin: `hasTable` / `hasColumn` kontrolü,
`up()` + `down()`).

### 1.1 `chat_conversations`
| Kolon | Tip | Not |
|---|---|---|
| `avatar_url` | `string(255)`, null | Grup görseli; `/chat/media/avatars/...` |
| `description` | `string(255)`, null | Opsiyonel grup açıklaması |
| `is_deleted` | `tinyint(1)`, default 0 | Grup silme = soft delete (geçmiş korunur) |

`type` alanı `'group'` değerini alacak. `direct_key` grupta **NULL** kalır —
unique index MySQL'de çoklu NULL'a izin verdiği için sorun yok, doğrulayın.

### 1.2 `chat_participants`
| Kolon | Tip | Not |
|---|---|---|
| `role` | `string(16)`, default `'member'` | `'admin'` \| `'member'` |
| `added_by` | `string(20)`, null | Kimin eklediği (sistem mesajı için) |
| `left_at` | `datetime`, null | Ayrılma/çıkarılma zamanı (soft) |

**Karar gerektiren nokta — çıkarılan üyenin geçmişi:**
`IsParticipant()` satır varlığına bakıyor. İki seçenek:
- **(A) Önerilen:** satırı bırak, `left_at` doldur; `IsParticipant` sorgusuna
  `AND left_at IS NULL` ekle → üye çıkınca geçmişi de kaybeder, ama tekrar
  eklenince `(conversation_id, extension)` unique index'i çakışmaz (satır güncellenir).
- (B) Satırı sil → tekrar ekleme temiz, ama `added_by`/geçmiş kaydı kaybolur.

(A) seçilirse `left_at IS NULL` filtresi **`GetParticipants`, `IsParticipant`,
`GetConversations`, `GetContacts` içindeki alt sorgulara da** eklenmeli. Bu, planın
en kolay atlanan maddesi — kontrol listesine alın.

### 1.3 `chat_messages`
| Kolon | Tip | Not |
|---|---|---|
| `system_event` | `string(32)`, null | `member_added`, `member_removed`, `member_left`, `group_renamed`, `group_created`, `avatar_changed` |
| `system_meta` | `varchar(255)`, null | JSON: `{"actor":"101","target":"102","value":"Yeni Ad"}` |

`msg_type` sütunu `limit 16` — `'system'` değeri sığıyor, genişletmeye gerek yok.
Sistem mesajları normal mesaj akışına girer (sıralama/okundu tutarlılığı bedava gelir).

### 1.4 RBAC
`sys_role_permissions` içinde `chat` modülü zaten var
(`20260915031500_add_my_phone_and_chat_to_role_permissions.php`).
**Grup için ayrı modül anahtarı açmayın.** Kural:
- Grup oluşturma/katılma: `chat.access` (tüm sohbet kullanıcıları)
- Grup yönetimi (üye ekle/çıkar, yeniden adlandır, sil): **grup içi `role='admin'`**,
  portal rolünden bağımsız. Oluşturan otomatik admin.
- İsteğe bağlı: `read_only_admin` rolü grup oluşturamasın istenirse `chat.edit` kontrolü
  eklenebilir — v1'de gerek yok, not düşüldü.

---

## 2. Go Servisi (Faz 2)

### 2.1 `db.go` — yeni tipler ve fonksiyonlar

`Conversation` struct'ına eklenecek JSON alanları:
```go
AvatarURL    string       `json:"avatar_url,omitempty"`
Description  string       `json:"description,omitempty"`
MemberCount  int          `json:"member_count,omitempty"`
OnlineCount  int          `json:"online_count,omitempty"`  // hub'dan doldurulur
MyRole       string       `json:"my_role,omitempty"`       // admin|member
Participants []Participant `json:"participants,omitempty"` // yalnız detay endpoint'inde
```

Yeni tip:
```go
type Participant struct {
    Extension string `json:"extension"`
    FullName  string `json:"full_name"`
    Role      string `json:"role"`
    IsOnline  bool   `json:"is_online"`
    JoinedAt  string `json:"joined_at"`
}
```

Yeni fonksiyonlar (hepsi `db.go`, mevcut stil: `database/sql`, parametreli sorgu,
`tx` ile çok adımlı yazma):
- `CreateGroupConversation(title, creatorExt, avatarURL string, members []string) (*Conversation, error)`
  — tek transaction: conversation INSERT → participants bulk INSERT (creator `role='admin'`)
  → `group_created` sistem mesajı.
- `GetGroupDetails(convID int, requesterExt string) (*Conversation, error)` — katılımcı listesiyle.
- `AddGroupMembers(convID int, actorExt string, exts []string) ([]string, error)` — eklenenleri döner.
- `RemoveGroupMember(convID int, actorExt, targetExt string) error`
- `LeaveGroup(convID int, ext string) error` — son admin ayrılırsa: en eski üyeyi admin yap
  (grup adminsiz kalmasın); son üye ayrılırsa grubu `is_deleted=1` yap.
- `UpdateGroupInfo(convID int, actorExt, title, avatarURL, description string) error`
- `DeleteGroup(convID int, actorExt string) error` — soft delete.
- `IsGroupAdmin(convID int, ext string) (bool, error)`
- `SaveSystemMessage(convID int, event, actorExt, targetExt, value string) (*Message, error)`
- `GetGroupMemberExtensions(convID int) ([]string, error)`

### 2.2 `db.go` — mevcut sorgularda **zorunlu** düzeltmeler

Bunlar atlanırsa grup listede bozuk görünür:

1. **`GetConversations` (db.go:~140)** — `target_ext` / `target_name` alt sorguları
   "benden farklı ilk katılımcı"yı seçiyor. Grupta bu rastgele bir üye döner.
   `c.type` üzerinden dallandırın: grup satırlarında `target_ext`/`target_name` boş
   dönsün, bunun yerine `member_count` alt sorgusu (`SELECT COUNT(*) FROM chat_participants
   WHERE conversation_id = c.id`) ve `p.role AS my_role` eklensin.
   `WHERE` koşuluna `AND c.is_deleted = 0` ekleyin.
2. **`GetContacts` (db.go:~120)** — okunmamış alt sorgusu zaten `c.type = 'direct'`
   ile sınırlı; **değiştirmeyin**, doğru davranış bu.
3. **`GetConversationIDForAttachment` (db.go sonu)** — `LIKE '%dosyaadı%'` ile
   `chat_messages` içinde arıyor. **Grup avatarlarının `chat_messages` kaydı yok**,
   dolayısıyla avatar isteği 403 alır. Bkz. §2.4 CH-G5.
4. `IsParticipant` / `GetParticipants` — §1.2(A) seçilirse `left_at IS NULL` ekleyin.

### 2.3 `handlers.go` + `main.go` — yeni uçlar

`main.go` içindeki `registerRoutes(prefix)` kapanışına ekleyin; fonksiyon zaten
`""` ve `"/chat"` önekleriyle iki kez çağrıldığı için ters proxy uyumu bedava gelir.
Mevcut stile uyun: `server.authMiddleware(...)` sarmalayıcısı, metot kontrolü
handler içinde, hata mesajları Türkçe, `writeJSON`/`writeJSONError` kullanımı.

| Metot | Yol | Yetki | Açıklama |
|---|---|---|---|
| POST | `/api/conversations/group` | chat kullanıcısı | Oluştur `{title, members[], avatar_url?, description?}` |
| GET | `/api/conversations/group?conversation_id=` | katılımcı | Detay + üye listesi |
| POST | `/api/conversations/group/update` | grup admini | `{conversation_id, title?, avatar_url?, description?}` |
| POST | `/api/conversations/group/members/add` | grup admini | `{conversation_id, extensions[]}` |
| POST | `/api/conversations/group/members/remove` | grup admini | `{conversation_id, extension}` |
| POST | `/api/conversations/group/members/role` | grup admini | `{conversation_id, extension, role}` |
| POST | `/api/conversations/group/leave` | katılımcı | `{conversation_id}` |
| POST | `/api/conversations/group/delete` | grup admini | `{conversation_id}` (soft) |

Her uç, işlem sonrası: (a) sistem mesajı kaydeder, (b) tüm üyelere WS olayı yayar,
(c) yeni eklenen üyelere FCM push gönderir.

`HandleSendMessage` ve `HandleGetMessages` **değişmiyor** — `IsParticipant` kontrolü
grupta da doğru çalışıyor. Yalnızca FCM başlığı §2.5'e göre güncellenecek.

### 2.4 `hub.go` — WS olayları

`BroadcastToConversation(convID, msg, senderExt)` zaten yazılmış ama **hiç çağrılmıyor**;
grup olaylarını yaymak için tam olarak bu kullanılmalı (kullanılmayan `senderExt`
parametresini ya değerlendirin ya da kaldırın).

Yeni giden olaylar (`event` alanı):
- `group_created` → `{conversation}` (yeni üyelere)
- `group_updated` → `{conversation_id, title, avatar_url}`
- `group_member_added` → `{conversation_id, members[], actor}`
- `group_member_removed` → `{conversation_id, extension, actor}`
- `group_deleted` → `{conversation_id}`
- Sistem mesajları normal `new_message` olayı olarak akar (`msg_type:"system"`).

Çıkarılan üyeye `group_member_removed` gönderildikten sonra istemci o sohbeti listeden
düşürür. `InMessage` struct'ına yeni action **eklemeyin** — grup yönetimi REST üzerinden.

### 2.5 `fcm.go` / `web/bin/send_chat_push.php`

`TriggerFcmPush(toExt, title, body, action, extra)` imzası korunur. Grupta:
- `title` = grup adı (kişi adı değil)
- `body` = `"Ahmet: merhaba"` (gönderen adı önekli)
- `extra` içine `"conversation_type":"group"`, `"group_title"` ekleyin ki Android
  bildirimi doğru gruplayabilsin.

Sistem mesajları için push **gönderilmesin** (gürültü); yalnızca "gruba eklendiniz"
olayında yeni üyeye tek bir push atılsın.

---

## 3. Web Arayüzü (Faz 3)

Tek dosya: `web/templates/views/chat/index.php`. Mevcut fonksiyon isimleri
(`loadConversations`, `renderConversationsList`, `renderContactsList`, `openConversation`,
`appendMessageToUI`, `handleWsEvent`, `sendMessage`, `switchChatTab`) korunarak genişletilecek.

1. **Yeni Grup girişi:** sekme çubuğuna (`tab-btn-convs` / `tab-btn-contacts` yanına)
   "＋ Yeni Grup" butonu. Modal: grup adı + kişi listesinden çoklu seçim (checkbox) +
   opsiyonel avatar yükleme (mevcut `/chat/api/upload` ucu kullanılır).
2. **`renderConversationsList`:** `c.type === 'group'` dalı — avatar yoksa baş harf yerine
   grup ikonu, presence noktası yerine `"N üye"` metni, `target_online` kullanılmaz.
3. **`openConversation`:** başlıkta grup adı + üye sayısı; başlığa tıklayınca
   **Grup Bilgisi paneli** (sağ drawer): üye listesi (online noktalarıyla), admin rozeti,
   admin ise "Üye Ekle" / "Çıkar" / "Adı Değiştir" / "Grubu Sil", herkes için "Gruptan Ayrıl".
4. **`appendMessageToUI`:** grupta karşı taraf balonlarının üstüne **gönderen adı**
   (renk, `sender_ext`'ten türetilen sabit palet). `msg_type === 'system'` ise ortalanmış,
   soluk, balonsuz satır.
5. **`handleWsEvent`:** yeni olay tipleri → liste/panel yenileme.
6. **XSS:** grup adı ve üye adları mutlaka mevcut `escapeHtml()` ile basılmalı;
   `innerHTML` ile ham birleştirme yapılmasın (dosyada hâlihazırda `escapeHtml` var, kullanın).

---

## 4. Android (Faz 4)

| Dosya | Değişiklik |
|---|---|
| `data/ApiClient.kt` | `createGroupChat`, `getGroupDetails`, `addGroupMembers`, `removeGroupMember`, `leaveGroup`, `updateGroup`, `deleteGroup` — mevcut `Result<T>` + OkHttp + Gson kalıbını izleyin (bkz. satır ~355-470) |
| `data/ApiClient.kt` (modeller) | `ChatConversation`'a `type`, `title`, `avatarUrl`, `memberCount`, `myRole`; yeni `ChatParticipant`, `GroupDetailsResponse` |
| `ui/ChatListActivity.kt` | `showNewChatDialog()` (satır ~206) yanına `showNewGroupDialog()`; FAB'a "Yeni Sohbet / Yeni Grup" seçimi |
| `ui/ChatConversationAdapter.kt` | Grup satırı: grup ikonu/avatar, alt satırda üye sayısı, presence noktası gizli |
| `ui/ChatActivity.kt` | Başlık grup adı + üye sayısı; başlığa tıklama → grup bilgisi; toolbar menüsünde Ayrıl/Sil |
| `ui/ChatMessageAdapter.kt` | Karşı balonlarda gönderen adı (yalnız grupta); `system` tipi için ortalanmış satır |
| `data/ChatWebSocketManager.kt` | Yeni `group_*` olaylarının ayrıştırılması ve callback'leri |
| Yeni layout | `dialog_new_group.xml`, `item_contact_checkbox.xml`, `activity_group_info.xml`, `item_group_member.xml`, `item_chat_message_system.xml` |
| FCM alıcısı | Grup bildirimlerini `conversation_id` ile grupla (NotificationCompat group key) |

`ChatContactPickerAdapter.kt` çoklu seçim modunu destekleyecek şekilde genişletilebilir —
ayrı adapter yazmak yerine `selectionMode: Boolean` parametresi tercih edilsin.

---

## 5. Güvenlik Kontrol Listesi (Faz 5)

Depo mevcut güvenlik maddelerini `CH-n` koduyla işaretliyor (bkz. `handlers.go` yorumları,
`web/tests/unit/ChatSecurityTest.php`). Grup maddeleri **CH-G** öneki ile devam etsin:

- **CH-G1** Her grup ucu `IsParticipant`; yönetim uçları ek olarak `IsGroupAdmin`.
  Fail-closed: hata durumunda 403.
- **CH-G2** Üye ekleme yalnızca `sys_users` içinde `is_active=1`, `extension` dolu ve
  `role != 'fax_user'` olan kayıtlara (aynı filtre `GetContacts`'ta var — tekrar kullanın).
  İstemciden gelen rastgele dahili string'i doğrudan INSERT etmeyin.
- **CH-G3** Grup üst sınırı (öneri: **256 üye**) ve grup adı uzunluğu (100 — kolon limiti).
  Sınırsız grup, her mesajda N× FCM `exec` süreci demek (`fcm.go` her push için
  `php` süreci fork ediyor) — DoS riski burada gerçek.
- **CH-G4** `title`/`description` ham saklanır, **çıktıda** escape edilir (web: `escapeHtml`,
  Android: TextView zaten güvenli). HTML/JS enjeksiyonu için ayrıca sunucuda kontrol şart değil,
  ama `\n` ve kontrol karakterleri temizlensin.
- **CH-G5** **Avatar medya erişimi — bilinen tuzak.** `HandleMedia` (handlers.go sonu)
  dosyayı `GetConversationIDForAttachment` ile `chat_messages` üzerinden doğruluyor.
  Grup avatarının mesaj kaydı olmadığı için erişim 403 döner. Çözüm: avatarları
  `avatars/` alt dizinine yazın ve `HandleMedia` içine dal ekleyin —
  `chat_conversations.avatar_url` eşleşmesi + istekte bulunanın o grubun katılımcısı olması.
  **Dizin listeleme/`..` kontrolü mevcut haliyle korunmalı.**
- **CH-G6** Çıkarılan üye geçmişi göremez (§1.2(A) ile `left_at IS NULL` filtresi).
  Bu bilinçli bir üründür — karar dokümante edilsin.
- **CH-G7** Grup silme soft delete; mesajlar ve medya diskte kalır. KVKK/GDPR temizliği
  için ayrı bir bakım görevi gerekir (kapsam dışı, §8'e not düşüldü).

---

## 6. Testler (Faz 6)

**Go — `chat/security_test.go`** (mevcut dosyaya ekleme):
- Katılımcı olmayan grubun mesajlarını okuyamaz (403)
- Admin olmayan üye üye ekleyemez/çıkaramaz (403)
- Gruptan çıkarılan üye sonrasında mesaj/medya alamaz
- Üye sınırı aşıldığında hata
- Son admin ayrılınca grubun adminsiz kalmadığı

**PHP — `web/tests/unit/ChatSecurityTest.php`** (canlı API testleri stili mevcut):
- `testLiveApiNonMemberCannotReadGroupMessages`
- `testLiveApiNonAdminCannotRenameGroup`
- `testLiveApiGroupAvatarNotAccessibleByNonMember`

**Manuel kabul senaryosu:** 3 dahili ile grup kur → web'den mesaj at, Android'de anlık
gelsin → üye ekle/çıkar sistem mesajlarını iki istemcide de doğrula → uygulama kapalıyken
FCM bildirimi grup adıyla gelsin → gruptan ayrıl, sohbet listeden düşsün.

---

## 7. Dağıtım (Faz 7)

```bash
# 1) Migration
cd /home/pbx/web && vendor/bin/phinx migrate -c db/phinx.php

# 2) Go servisini derle ve yeniden başlat
cd /home/pbx/chat && CGO_ENABLED=0 go build -o aipbx-chat .
systemctl restart aipbx-chat && systemctl status aipbx-chat --no-pager

# 3) Android APK
cd /home/pbx/android && ./gradlew assembleRelease
```

- `install.sh` STEP 10 zaten `go build` yapıyor, **değişiklik gerekmiyor**.
- Apache proxy kuralları (`install.sh:432-438`) `/chat/api/` tamamını iletiyor,
  yeni uçlar için **ek kural gerekmiyor**.
- **Dikkat — doküman tutarsızlığı:** `ARCHITECTURE.md` chat portunu `9090` olarak yazıyor
  (satır 44, 123), gerçek varsayılan `config.go` ve `install.sh` içinde **8086**.
  Bu iş sırasında `ARCHITECTURE.md` düzeltilsin.
- `README.md` + `ARCHITECTURE.md` özellik listesine grup sohbeti eklensin.

---

## 8. Kapsam Dışı (v2 adayları)

Bilinçli olarak dışarıda bırakıldı — istenirse ayrı iş kalemi:
- Grup içinde mesaj başına "kimler okudu" listesi (v1: yalnız okundu sayacı)
- @mention ve mention bildirimi
- Mesaj yanıtlama (reply/quote) ve iletme
- Grup davet linki / dahili olmayan katılımcı
- Grup medyası için saklama süresi ve disk temizliği (CH-G7)
- Sohbet arşivleme / sabitleme

---

## 9. Uygulama Sırası (Antigravity için önerilen akış)

1. Faz 1 migration → `phinx migrate` ile doğrula, `phinx rollback` ile geri alınabilirliği test et
2. Faz 2.1–2.2 (`db.go`) → **önce mevcut sorgu düzeltmeleri**, direct sohbetlerin bozulmadığını doğrula
3. Faz 2.3–2.5 (handlers/hub/fcm) → `curl` ile uçları tek tek dene
4. Faz 3 web arayüzü → uçtan uca iki tarayıcı oturumuyla test
5. Faz 4 Android
6. Faz 5–6 güvenlik kontrolleri + testler
7. Faz 7 doküman ve dağıtım

**Her fazda:** mevcut kod stiline uyun (Türkçe hata mesajları, `CH-n` güvenlik yorumları,
idempotent migration, `Result<T>` Kotlin kalıbı). Direct sohbet davranışı **hiçbir fazda
bozulmamalı** — her adımdan sonra birebir sohbeti duman testinden geçirin.
