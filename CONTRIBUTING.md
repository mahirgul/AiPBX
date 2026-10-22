# Contributing to AI PBX

Thank you for your interest in contributing to **AI PBX**! 🎉

AI PBX is an open-source enterprise IP PBX and Unified Communications platform created by [Mahir Gül](https://mhrgl.com). We welcome contributions from developers, VoIP engineers, sysadmins, and technical writers worldwide.

---

## 🧭 Code of Conduct

By participating in this project, you agree to:
- Be respectful, constructive, and collaborative.
- Focus on technical excellence, reliability, and security.
- Help others learn and contribute positively to the ecosystem.

---

## 🛠 Project Structure

AI PBX is organized as a unified monorepo:

| Directory | Component | Tech Stack |
|---|---|---|
| `web/` | Web Management Portal, REST API v1 &amp; Built-in Privacy Engine | PHP 8.x MVC, JavaScript, CSS |
| `plugins/` | Official First-Class Modular Plugins | PHP 8.x, JSON Manifests |
| `asterisk-config/` | PBX Dialplan &amp; PJSIP Configs | Asterisk 22, PJSIP, SpanDSP |
| `chat/` | High-Concurrency Chat Engine | Go (Gorilla WebSocket) |
| `ai/` | Local &amp; Cloud AI Runtime (`aipbx-ai`) | Python |
| `signaling/` | Local Loopback HEPv3 SIP Signaling Capture (`aipbx-signaling`) | Python |
| `android/` | Android Mobile Application | Kotlin, WebRTC, Jetpack, FCM |
| `ios/` | iOS Mobile Application | SwiftUI, CallKit, WebRTC, PushKit |
| `docs/` | Built-in Offline System Guide (`/help`) &amp; Documentation | Markdown, Mermaid diagrams (SVG), PNG, JPG |
| `sounds/` | Bundled Sounds &amp; Turkish Prompt Packages | `scripts/build_tr_sounds.sh` |
| `install.sh` | Turnkey Auto-Installer &amp; Upgrader | Bash (Ubuntu 26.04 LTS) |

---

## 🚀 Getting Started with Development

### 1. Fork &amp; Clone
```bash
git clone https://github.com/<your-username>/AiPBX.git
cd AiPBX
git remote add upstream https://github.com/mahirgul/AiPBX.git
```

### 2. Testing

Before every push (CI runs the same checks):

```bash
cd web
php bin/lint_lang.php                    # 23-language key parity check
php bin/smoke.php                        # every page renders, RBAC, conventions
php vendor/bin/phinx migrate -e testing  # test database (tests/.env.test)
set -a; . tests/.env.test; set +a; php vendor/bin/phpunit
php -d memory_limit=2G vendor/bin/phpstan analyse
(cd ../signaling && python3 -m unittest discover -s tests -v)
(cd ../chat && gofmt -l . && go vet ./... && go test ./...)
```

Unit tests never touch the live system: generated configs go to a temporary
directory (`ASTERISK_PBX_DIR`, `ASTERISK_CONF_DIR`) and no command reaches Asterisk.

### 3. End-to-end tests on Ubuntu 26.04 (LXD)

`scripts/e2e/run.sh` installs the current commit in an LXD container and uses it
like an administrator: portal login, saving and applying a trunk (from inside
Apache's systemd sandbox), the root helper, custom and Turkish sounds, firewall,
backups, the MP3 converter and migrations.

```bash
# once: a clean Ubuntu 26.04 container with two snapshots
lxc launch ubuntu:26.04 aipbx-test
lxc snapshot aipbx-test pristine
lxc exec aipbx-test -- bash -c 'curl -fsSL https://raw.githubusercontent.com/mahirgul/AiPBX/main/install.sh | AIPBX_REF=v1.0.0 bash'
lxc snapshot aipbx-test installed-200

scripts/e2e/run.sh fresh     # install from scratch
scripts/e2e/run.sh upgrade   # upgrade the older install in snapshot installed-200
scripts/e2e/run.sh           # both
```

The container is reset to a snapshot on every run. Run it before a release and
after changes to `install.sh`, migrations, seed data or system integration.

### 4. Android App Development
- Open the `android/` directory in **Android Studio**.
- Build the release or debug APK:
  ```bash
  cd android
  ./gradlew assembleDebug
  ```
- Android logs can be inspected directly on-device using the built-in **Log Viewer** (`LogViewerActivity`).

---

## 📋 Contribution Guidelines

### 1. Branch Naming
Create a feature branch from `main`:
- `feat/feature-name` (e.g., `feat/webrtc-screen-sharing`)
- `fix/bug-description` (e.g., `fix/pjsip-nat-contact`)
- `docs/documentation-update` (e.g., `docs/install-guide`)

### 2. Conventional Commits
Please adhere to the [Conventional Commits](https://www.conventionalcommits.org/) specification:
- `feat(...)`: A new feature
- `fix(...)`: A bug fix
- `docs(...)`: Documentation changes
- `chore(...)`: Maintenance, version bumps, or dependency updates
- `refactor(...)`: Code refactoring without changing functionality
- `test(...)`: Adding or updating tests

*Example:* `feat(android): add dark mode toggle in dialer settings`

### 3. Code Conventions
- **English** for code comments, CLI output, commit messages and documentation (the portal UI stays
  bilingual through `web/lang/tr.php` and `en.php`).
- **Controllers** handle form buttons with `BaseController::handlePost()` and pass the result to the
  layout as notices — views do not print `$message` / `$error` themselves.
- **Page scripts and styles** live in `web/assets/js/<page>.js` and `web/assets/css/pages/<page>.css`;
  PHP values reach them through a small data block (`window.X = {...}`), not PHP inside the script.
- **Sidebar** entries are data in `web/templates/sidebar_menu.php`.
- **Asterisk restarts on update:** `aipbx-update` restarts Asterisk only when the update needs it
  (`scripts/asterisk-restart-needed.sh`): when a file in `asterisk-config/` (outside `pbx/`) changed,
  or `ASTERISK_RESTART_EPOCH` at the top of `install.sh` was raised. Raise it in a change that needs
  a restart to take effect — a new Asterisk package or module, the service unit, anything read only
  at start. Everything else is reloaded and calls stay up.

### 4. Security First 🔒
- **Zero Static Passwords**: Never hardcode credentials or tokens anywhere in the codebase.
- **Least Privilege**: Ensure database queries in the web portal respect DML-only permissions (`aipbx_portal`).
- **Input Validation**: Sanitize and validate all user inputs (CSRF, XSS, SQL injection protection).

---

## 📐 Diagrams in the documentation

Draw diagrams with [Mermaid](https://mermaid.js.org/), not with ASCII arrows: the source goes to
`docs/diagrams/<name>.mmd` (start it with `%% @classes` for the shared colours), then

```bash
scripts/render-diagrams.sh <name>   # writes docs/img/<name>.svg (needs Node and Chrome/Chromium)
```

and put `![What it shows](img/<name>.svg)` on a line of its own in the document. Commit the `.mmd`
and the `.svg` together. The SVG is shown as it is on GitHub, in the portal's Help page (offline)
and on aipbx.bid/docs; no JavaScript is needed to read it.

## 🌍 Translations

The portal is available in 23 languages (see [docs/translating.md](docs/translating.md)), and new languages
or corrections are very welcome — no PHP knowledge needed. The texts live in
`web/lang/<code>.php`; `scripts/i18n.py` exports the missing strings, checks
your translation (placeholders, HTML tags) and writes the file. Step-by-step
guide: [docs/translating.md](docs/translating.md).

## 🔄 Submitting a Pull Request (PR)

### One topic per pull request

Every PR must be about **one** bug fix or **one** feature. Do not mix in
unrelated fixes, refactors, renames, reformatting or translations — send each
of them as its own PR. A small, focused PR can be reviewed and tested on a real
PBX in minutes; a PR that mixes many changes waits a long time and is usually
asked to be split.

- If you found three bugs, open three PRs (or one issue per bug).
- Keep the diff to what the change needs: no editor-wide reformatting.
- A PR that touches many parts of the project or is very large (roughly more than
  ~1000 changed lines or ~40 files) is labelled `needs-split` automatically.
  If it really is a single change, say so in a comment.

### Steps

1. Ensure your code is formatted and tested.
2. Push your branch to your GitHub fork:
   ```bash
   git push origin feat/your-feature
   ```
3. Open a Pull Request against the `main` branch of [mahirgul/AiPBX](https://github.com/mahirgul/AiPBX).
4. Provide a clear description of:
   - What problem this PR solves
   - How it was tested (include screenshots or CLI output if applicable)
   - Any architectural considerations or backwards-incompatible changes

---

## 📬 Contact & Community

- **Project Creator & Lead**: Mahir Gül ([mhrgl.com](https://mhrgl.com))
- **Privacy Policy**: [https://www.mhrgl.com/privacy](https://www.mhrgl.com/privacy)
- **GitHub Issues**: [https://github.com/mahirgul/AiPBX/issues](https://github.com/mahirgul/AiPBX/issues)
- **GitHub Discussions**: [https://github.com/mahirgul/AiPBX/discussions](https://github.com/mahirgul/AiPBX/discussions)

Thank you for building the future of open-source enterprise telephony with us!
