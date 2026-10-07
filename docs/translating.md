# Translating AiPBX

The web portal speaks English (`en`), Turkish (`tr`) and Croatian (`hr`).
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

# 5. Check all languages: same keys as English, same placeholders
php web/bin/lint_lang.php
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

The Android and iOS apps have their own texts (English and Turkish so far):

- Android: `android/app/src/main/res/values/strings.xml` (English) and
  `values-<code>/strings.xml` per language.
- iOS: `ios/AiPBX/Resources/<code>.lproj/Localizable.strings` and
  `InfoPlist.strings`.

The server sends its messages to the apps in the app's language when the
portal has that language too.

## Phone prompts

The voice prompts callers hear are separate from the interface texts: see
[Sounds & languages](sounds.md). Asterisk publishes official prompt packs for
a few languages (Sounds → Asterisk Sound Packs); for another language put your
own recordings in `/var/lib/asterisk/sounds/<code>/`, using the English file
names, and run `sudo chown -R asterisk:asterisk` on that folder. The language
then appears under PBX Settings → Language.
