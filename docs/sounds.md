# Sounds & languages

## Where sounds live

| Path | Content |
|------|---------|
| `/var/lib/asterisk/sounds/custom/` | sounds uploaded on **Sounds** (IVR, announcements, queues) |
| `/var/lib/asterisk/sounds/tr/` | Turkish system prompts shipped in `sounds/tr/` of the repository |
| `/var/lib/asterisk/moh/` | music on hold |
| `/usr/share/asterisk/sounds/en/` | English prompts (Debian package) |

Debian's Asterisk searches prompts under `/usr/share/asterisk/sounds`. The installer links
`/usr/local/share/asterisk/sounds` (Debian's `custom` directory) to `/var/lib/asterisk/sounds/custom`
and `/usr/share/asterisk/sounds/tr` to `/var/lib/asterisk/sounds/tr`. Without these links IVRs,
announcements and Turkish prompts fail with *"File … does not exist in any format"* and the call is hung
up. The links live outside dpkg-owned files, so package upgrades keep them.

Sounds should be 8 kHz mono WAV (16-bit PCM). Uploaded files with the same name as a shipped file are
never overwritten by updates.

## Languages

A call uses the language set on its inbound route. When a prompt is missing in that
language Asterisk plays the English one, so a partly translated set gives mixed-language prompts.

## Turkish voicemail prompts

The Turkish set came from another PBX with different file names. Prompts with a clear equivalent were
renamed to Asterisk's names: `vm-INBOX`, `vm-Old`, `vm-password`, `vm-theperson`, `vm-isunavail`,
`vm-isonphone`, `vm-intro`, `vm-leavemsg`, `vm-reachoper`, `vm-rec-name`, `vm-msgsaved`,
`vm-msgforwarded` (plus the ones that already matched). Menu prompts of the other system that name keys
were not reused — its key layout differs from Asterisk's.

Still to be recorded (Asterisk key layout; save as `sounds/tr/<name>.wav`):

| File | Text |
|------|------|
| `vm-login` | Sesli posta. Posta kutusu numarası? |
| `vm-incorrect-mailbox` | Giriş hatalı. Posta kutusu numarası? |
| `vm-onefor` | …dinlemek için 1'e basın |
| `vm-opts` | Klasör değiştirmek için 2'ye, gelişmiş seçenekler için 3'e, posta kutusu ayarları için 0'a basın. |
| `vm-msginstruct` | Sonraki mesaj için 6'ya, tekrar dinlemek için 5'e, önceki mesaj için 4'e, silmek için 7'ye, iletmek için 8'e, kaydetmek için 9'a basın. |
| `vm-next` | Sonraki mesaj için 6'ya basın. |
| `vm-prev` | Önceki mesaj için 4'e basın. |
| `vm-repeat` | Mesajı tekrar dinlemek için 5'e basın. |
| `vm-delete` | Bu mesajı silmek için 7'ye basın. |
| `vm-undelete` | Silmeyi geri almak için 7'ye basın. |
| `vm-undeleted` | Mesaj geri alındı. |
| `vm-toforward` | Mesajı başka bir kullanıcıya iletmek için 8'e basın. |
| `vm-savemessage` | veya kaydetmek için 9'a basın |
| `vm-options` | Ulaşılamıyor mesajı kaydetmek için 1'e, meşgul mesajı için 2'ye, adınızı kaydetmek için 3'e, geçici karşılama için 4'e, şifre değiştirmek için 5'e, ana menü için yıldıza basın. |
| `vm-rec-unv` | Sinyalden sonra ulaşılamıyor mesajınızı söyleyin, ardından kareye basın. |
| `vm-rec-busy` | Sinyalden sonra meşgul mesajınızı söyleyin, ardından kareye basın. |
| `vm-rec-temp` | Sinyalden sonra geçici mesajınızı söyleyin, ardından kareye basın. |
| `vm-review` | Kaydı onaylamak için 1'e, dinlemek için 2'ye, yeniden kaydetmek için 3'e basın. |
| `vm-nobodyavail` | Şu anda çağrınızı yanıtlayabilecek kimse yok. |
| `vm-mailboxfull` | Üzgünüz, kullanıcının posta kutusu dolu. |
| `vm-tooshort` | Mesajınız çok kısa. |
| `vm-newuser` | Sesli postaya hoş geldiniz. Önce kısa bir kurulum yapacağız. |
| `vm-invalidpassword` | Geçersiz şifre. Lütfen tekrar deneyin. |
| `vm-invalid-password` | Bu şifre gereksinimleri karşılamıyor. Lütfen tekrar deneyin. |
| `vm-whichbox` | Mesaj bırakmak için posta kutusu numarasını girin. |
| `vm-helpexit` | Yardım için yıldıza, çıkmak için kareye basın. |
| `vm-instructions` | Mesajlarınızı dinlemek için 1'e basın. Kare tuşuyla istediğiniz zaman çıkabilirsiniz. |
| `vm-starmain` | Ana menüye dönmek için yıldıza basın. |
| `vm-star-cancel` | İptal için yıldıza basın. |
| `vm-tocancel` | veya iptal için kareye basın. |
| `vm-then-pound` | ardından kareye basın |
| `vm-advopts` | Gelişmiş seçenekler için 3'e basın. |
| `vm-tohearenv` | Mesaj bilgilerini dinlemek için 3'e basın. |
| `vm-toreply` | Yanıt göndermek için 1'e basın. |
| `vm-tocallback` | Mesajı bırakan kişiyi aramak için 2'ye basın. |
| `vm-tomakecall` | Dış arama yapmak için 4'e basın. |
| `vm-tocallnum` | Bu numarayı aramak için 1'e basın. |
| `vm-toenternumber` | Numara girmek için 1'e basın. |
| `vm-calldiffnum` | Farklı bir numara girmek için 2'ye basın. |
| `vm-enter-num-to-call` | Aramak istediğiniz numarayı girin. |
| `vm-dialout` | Lütfen bekleyin, bağlıyorum. |
| `vm-num-i-have` | Kayıtlı numara |
| `vm-nonumber` | Mesajı kimin gönderdiğini bilmiyorum. |
| `vm-unknown-caller` | bilinmeyen bir arayandan |
| `vm-nobox` | Gönderenin posta kutusu olmadığı için yanıt veremezsiniz. |
| `vm-forward` | Dahili girmek için 1'e, rehberi kullanmak için 2'ye basın. |
| `vm-forward-multiple` | Mesajı göndermek için 1'e, başka alıcı eklemek için 2'ye basın. |
| `vm-forwardoptions` | Mesajın başına not eklemek için 1'e, olduğu gibi iletmek için 2'ye basın. |
| `vm-record-prepend` | Sinyalden sonra iletilecek mesaja bir giriş kaydedin, bitince kareye basın. |
| `vm-torerecord` | Mesajınızı yeniden kaydetmek için 3'e basın. |
| `vm-tocancelmsg` | Bu mesajı iptal etmek için yıldıza basın. |
| `vm-saveoper` | Kaydı onaylamak için 1'e basın, aksi halde lütfen hatta kalın. |
| `vm-review-urgent` | Mesajı acil olarak işaretlemek için 4'e basın. |
| `vm-review-nonurgent` | Acil işaretini kaldırmak için 4'e basın. |
| `vm-marked-urgent` | Mesaj acil olarak işaretlendi. |
| `vm-marked-nonurgent` | Acil işareti kaldırıldı. |
| `vm-tempgreeting` | Geçici karşılamanızı kaydetmek için 1'e basın. |
| `vm-tempgreeting2` | Geçici karşılamayı kaydetmek için 1'e, silmek için 2'ye basın. |
| `vm-tempgreetactive` | Geçici karşılamanız şu anda etkin. |
| `vm-tempremoved` | Geçici karşılamanız kaldırıldı. |
| `vm-tmpexists` | Normal karşılamalarınızın yerine geçen bir geçici karşılama var. |
| `vm-changeto` | Hangi klasöre geçilsin? |
| `vm-savefolder` | Mesaj hangi klasöre kaydedilsin? |
| `vm-saved` | kaydedildi |
| `vm-savedto` | şuraya kaydedildi |
| `vm-Work` | iş |
| `vm-Family` | aile |
| `vm-Friends` | arkadaşlar |
| `vm-Urgent` | acil |
| `vm-Cust1` … `vm-Cust5` | klasör 5 … klasör 9 |
| `vm-for` | için |
| `vm-from` | kimden |
| `vm-press` | basın |
| `vm-last` | son |
| `vm-opts-full` | Diğer klasörlerdeki mesajlar için 2'ye, başka bir posta kutusuna mesaj bırakmak için 3'e, karşılama ve şifre ayarları için 0'a basın. |
| `vm-onefor-full` | Dinlemek için 1'e basın… |
