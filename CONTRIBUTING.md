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
| `web/` | Web Management Portal &amp; API | PHP 8.x MVC, JavaScript, CSS |
| `asterisk-config/` | PBX Dialplan &amp; PJSIP Configs | Asterisk 22, PJSIP, SpanDSP |
| `chat/` | High-Concurrency Chat Engine | Go (Gorilla WebSocket) |
| `android/` | Mobile Application | Kotlin, WebRTC, Jetpack, FCM |
| `docs/` | Documentation &amp; Landing Page | HTML5, CSS3, Vanilla JS |
| `install.sh` | Turnkey Auto-Installer | Bash (Ubuntu 22/24/26 LTS) |

---

## 🚀 Getting Started with Development

### 1. Fork &amp; Clone
```bash
git clone https://github.com/<your-username>/AiPBX.git
cd AiPBX
git remote add upstream https://github.com/mahirgul/AiPBX.git
```

### 2. Isolated Sandboxed Testing (Recommended: LXC)
To test `install.sh` or full-stack features without polluting your host machine, use an isolated Linux container:
```bash
# Launch a clean Ubuntu container
lxc launch ubuntu:24.04 aipbx-dev
lxc exec aipbx-dev -- bash

# Inside container:
git clone https://github.com/<your-username>/AiPBX.git /opt/aipbx
cd /opt/aipbx
sudo bash install.sh
```

### 3. Android App Development
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

### 3. Security First 🔒
- **Zero Static Passwords**: Never hardcode credentials or tokens anywhere in the codebase.
- **Least Privilege**: Ensure database queries in the web portal respect DML-only permissions (`aipbx_portal`).
- **Input Validation**: Sanitize and validate all user inputs (CSRF, XSS, SQL injection protection).

---

## 🔄 Submitting a Pull Request (PR)

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
