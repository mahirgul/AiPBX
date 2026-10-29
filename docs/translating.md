# Translating AiPBX

The web portal speaks English (`en`), Turkish (`tr`), Azerbaijani (`az`), Bulgarian (`bg`),
Czech (`cs`), Danish (`da`), German (`de`), Greek (`el`), Spanish (`es`), Finnish (`fi`),
French (`fr`), Croatian (`hr`), Hungarian (`hu`), Italian (`it`), Norwegian Bokmål (`nb`),
Dutch (`nl`), Polish (`pl`), Portuguese (`pt`), Romanian (`ro`), Russian (`ru`),
Serbian in Latin script (`sr`), Swedish (`sv`) and Ukrainian (`uk`).
Every user picks a language from the **language dropdown**: on the sign-in
page, and in the user menu (bottom right) after signing in. Corrections and
new languages are welcome as pull requests.

## How the portal finds its texts

- `web/lang/<code>.php` returns one array: `'key' => 'text'`. English
  (`en.php`) is the reference; every other file uses the same keys.
- `t('key')` in PHP and `__('js.key')` in the page scripts look the text up
  in the user's language. **A key missing from a language shows the English
  text**, so a partly translated language already works.
- A language appears in the dropdowns once it is listed in `UI_LANGUAGES` in
  `web/config.php`, with its own name for itself (`'de' => 'Deutsch'`).

## Rules for a translation

1. **Keep every placeholder**: `%s`, `%d`, and positional ones such as
   `%1$s` / `%2$s`. The count must match English. If your language needs a
   different word order, switch to positional placeholders (`%2$s … %1$s`).
2. **Keep HTML tags** (`<strong>`, `<code>`, `<a href="…">`, `<br>`) and
   entities (`&amp;`, `&rarr;`) exactly; translate only the text between
   them. Do not translate what is inside `<code>`.
3. **Keep line breaks**: some texts contain `\n` (written as `\\n` in the
   chunk files, because those are JSON strings). Keep them in the same places.
4. Leave product and protocol names as they are: AiPBX, Asterisk, PJSIP,
   WebRTC, SIP, DID, IVR, T.38, FCM, Let's Encrypt, Microsoft Teams…
5. Keys starting with `js.` are used in the browser; keys starting with
   `srv_`, `api` or `sync.` are messages from the server. Translate them all
   the same way.
6. Short labels (buttons, table headers, menu items) should stay short; the
   layout has little room for them.

## Terms that must not vary

Four words carry most of AiPBX's phone vocabulary, and each of them drifts inside a
single language: the same concept translated once from its label and then again from
the sentence around it. In the current files `provisioning` sits as a loanword in
sixteen German occurrences and twenty Italian ones while the label above them reads
`Bereitgestellt` and `Configurato`; Greek has παραμετροποίηση in the badge and
ρύθμιση in sixteen other occurrences; Turkish says "Dış Hat" where the label is and plain
"hat" in most other strings.

**One word per concept per language.** Decline it, compound it, put it in another
case — but do not reach for a synonym because one sentence reads better with it. A
reader who meets two words for the same button has to work out whether they are two
things.

| Term | In AiPBX it means | Not to be confused with | The word is fixed by |
| --- | --- | --- | --- |
| **provisioning** | Writing a phone's configuration file — and the address it fetches that file from — before the phone is used. | Changing settings in the portal, or installing AiPBX. | `phonedisc.state_provisioned`, `phones.settings_title`, `phones.col_url` |
| **discovery** | Finding phones that are on the network but not in AiPBX yet. | Scanning ports for its own sake; searching the phone list you already have. | `phonedisc.settings_title`, `phonedisc.settings_btn`, `phonedisc.title` |
| **extension** | A person's internal number and the SIP account behind it, e.g. 3010. Reachable without a trunk. | A trunk; a DID; a queue or ring group. | `extensions.col_extension`, `phones.col_extension` |
| **trunk** | The connection off the box: a provider, a gateway, a SIP peer. | An extension; an outbound route or rule. | `trunks.col_trunk`, `sidebar.item_trunks`, `outbound.add_trunk` |

### The word each language uses

This table is the decision, and the label keys named above are where it is read
from: the provisioning column is the participle of the badge (`Bereitgestellt`,
`Provisionado` as the labels have it), and its verb and noun forms follow from it.
Where a label below disagrees with its row, the label changes — see the three
corrections and the Greek split under the table.

| Language | provisioning | discovery | extension | trunk |
| --- | --- | --- | --- | --- |
| en | Provisioned | Discovery | Extension | Trunk |
| az | Tanıdılıb | Aşkarlama | Daxili nömrə | Trank |
| bg | Провизиран | Откриване | Вътрешен номер | Транк |
| cs | Nakonfigurován | Zjišťování | Pobočka | Trunk |
| da | Provisioneret | Registrering | Lokalnummer | Trunk |
| de | Bereitgestellt | Erkennung | Nebenstelle | Trunk |
| el | Παραμετροποιήθηκε ‡ | Ανακάλυψη | Εσωτερικό | Trunk |
| es | Aprovisionado | Descubrimiento | Extensión | Troncal |
| fi | Käyttöönotettu | Etsintä | Alanumero | Runkolinja |
| fr | Provisionné | Découverte | Poste | Trunk |
| hr | Konfigurirano | Otkrivanje | Lokal | Trunk |
| hu | Kiépítve † | Felderítés | Mellék | Trönk |
| it | Configurato | Rilevamento | Interno | Trunk |
| nb | Klargjort | Oppdagelse | Internnummer | Trunk |
| nl | Geprovisioneerd | Detectie | Toestel | Trunk |
| pl | Skonfigurowany | Wykrywanie | Numer wewn. | Trunk |
| pt | Provisionado | Descoberta | Extensão | Tronco |
| ro | Provizionat | Descoperire | Interior | Trunchi |
| ru | Подготовлен † | Обнаружение | Внутр. номер | Транк |
| sr | Konfigurisano | Otkrivanje | Lokal | Trank |
| sv | Provisionerad | Identifiering | Anknytning | Trunk |
| tr | Tanımlandı | Keşif | Dahili | Dış Hat |
| uk | Підготовлений † | Виявлення | Внутрішній номер | Транк |

† **Three of those cells are corrections, not a description of today's labels.**
Hungarian `Beállítva`, Russian `Настроен` and Ukrainian `Налаштований` are all
built on the word those languages already use for **settings** — `beállítások`,
`настройки`, `налаштування`, the `phones.settings_title` labels — so the badge
reads "set up" and a reader cannot tell provisioning from changing a setting in
the portal. Use the commissioning sense instead, the one German
`Bereitgestellt` and Finnish `Käyttöönotettu` carry: `Kiépítve`, `Подготовлен`,
`Підготовлений`. `phonedisc.state_provisioned` and the strings beside it
(`phones.col_url`, still `Provisioning URL` in hu, `URL автонастройки` in ru,
`URL автоналаштування` in uk) change with the badge, in one commit.

‡ **Greek is split, and is listed as it is for now.** The badge says
`Παραμετροποιήθηκε` while sixteen other occurrences say `ρύθμιση` — the settings
word, as in `phones.settings_title` = `Ρυθμίσεις αυτόματης ρύθμισης`. One of the
two has to go. Which one survives is open upstream together with az, so until it
is answered follow the badge and do not introduce a third word.

Two families, and the choice is per language rather than per string: some take the
loanword (bg провизиран, fr provisionné, nl geprovisioneerd, pt provisionado, ro
provizionat, sv provisionerad) and others a native word for commissioning (cs
nakonfigurován, it configurato, pl skonfigurowany, de bereitgestellt, el
παραμετροποιήθηκε, fi käyttöönotettu, hu kiépítve, nb klargjort, ru подготовлен,
tr tanımlandı, uk підготовлений). Either is fine; using both in one language is not.

A word meaning "configure" is only usable where the language's settings word is a
*different* word (`cs nakonfigurován` beside `nastavení`, `it configurato` beside
`impostazioni`, `pl skonfigurowany` beside `ustawienia`); where it is the same word
it is a settings label wearing a provisioning badge, which is what † fixes.

If a word in that table is wrong, change the label key and the table in the same
commit — one decision for the language, never one string at a time. The az and el
wording for provisioning is still open upstream; until it is settled, follow the
label (for el, the badge — see ‡).

## Workflow with `scripts/i18n.py`

The tool cuts English into numbered chunks, checks every translated line
(placeholders and HTML tags must match English) and writes the language file.
Work files go to `.i18n-work/` (not committed).

```bash
# 1. Strings the language does not have yet, 300 per file
python3 scripts/i18n.py export-missing de 300
#    -> .i18n-work/de_missing_00.txt, de_missing_01.txt, ...
#    each line:  <index> TAB <key> TAB <English text as a JSON string>

# 2. Translate each file into a new file with one line per string:
#       <index> TAB <translated text as a JSON string>
#    (the key column may be kept or dropped), then import it:
python3 scripts/i18n.py import de .i18n-work/de_00.txt
#    -> "imported 300, rejected 0"; rejected lines are listed with the reason

# 3. Progress
python3 scripts/i18n.py status de

# 4. Write web/lang/de.php (English key order; works with a partial set)
python3 scripts/i18n.py build de "German (Deutsch)"

# 5. Check all languages: same keys as English, same placeholders and HTML tags
php web/bin/lint_lang.php
python3 scripts/i18n.py check        # or: check de
```

Then add the language to `UI_LANGUAGES` in `web/config.php` and open the
portal: pick it in the dropdown on the sign-in page and click through a few
pages (long words can break narrow buttons).

**Updating an existing language** after new English texts were added works
the same way: `export-missing` finds only the new keys, because the tool
starts from the existing `web/lang/<code>.php`.

### Translating with an AI assistant

The chunk format is made for it. Give the assistant this page and one chunk
at a time, and ask for one output line per input line in the
`<index> TAB <JSON string>` form. Always run `import` (it rejects broken
placeholders or tags) and `lint_lang.php`, and have a native speaker read the
result before it is merged.

## Mobile apps

The Android and iOS apps have their own texts, in the same languages as the portal;
a new app language is also added to `L10n.kt` (Android),
`locales_config.xml` and `AppLanguage` in `Localization.swift` (iOS), and the iOS
Mobile clients (Android and iOS) are maintained in their respective standalone repositories:
- Android: [https://github.com/mahirgul/AiPBX-Android](https://github.com/mahirgul/AiPBX-Android)
- iOS: [https://github.com/mahirgul/AiPBX-iOS](https://github.com/mahirgul/AiPBX-iOS)

The server sends its messages to the apps in the app's language when the
portal has that language too.

## Phone prompts

The voice prompts callers hear are separate from the interface texts: see
[Sounds & languages](sounds.md). Asterisk publishes official prompt packs for
a few languages (Sounds → Asterisk Sound Packs); for another language put your
own recordings in `/var/lib/asterisk/sounds/<code>/`, using the English file
names, and run `sudo chown -R asterisk:asterisk` on that folder. The language
then appears under PBX Settings → Language.
