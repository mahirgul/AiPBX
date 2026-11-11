# Plugin System & Developer Guide

AiPBX includes a modular **Plugin Catalog & Custom `.zip` Plugin Engine** (`PluginManager`) integrated with `/api/v1/*` and Outbound Webhooks.

- 📦 **Bundled catalog (`plugins/<slug>/`)**: Ships in the repository. Official plugins: `google-auth`, `oidc-auth`, `saml-auth`, `ms-teams`, `web-widgets`, `ai-cloud`, `ai-tts`, `ai-apps`, `ai-models`. Fresh installations start lean; administrators enable only the plugins they need under **Integration → Plugin Catalog** (`/plugins`). Existing installations automatically keep previously configured integrations enabled on upgrade.
- 🌐 **Community plugins**: published in [AiPBX-plugins](https://github.com/mahirgul/AiPBX-plugins) (`plugins/community/<slug>/`) and installed as an uploaded `.zip`. Bundled plugins are maintained in the main repository; community plugins are maintained in the separate plugin repository.
- 📤 **Custom Uploaded Plugins (`/var/lib/aipbx/plugins/<slug>/`)**: Any developer or administrator can package a custom integration into a `.zip` archive and upload it directly in **Integration → Plugin Catalog → Upload Custom Plugin (`.zip`)**.

**A working example of every hook:** [`examples/sample-plugin`](https://github.com/mahirgul/AiPBX-plugins/tree/main/examples/sample-plugin)
in AiPBX-plugins. It changes nothing on the PBX; copy it to start a new plugin.

---

## 📦 1. Package Layout & `plg_*` Database Tables

A plugin is a folder (or `.zip` archive) containing `plugin.json` at its root, an optional `schema.sql` for creating dedicated `plg_*` MySQL tables, plus optional hook files:

```
my-crm-bridge/
├── plugin.json          # 📋 Required: manifest, tables, hooks, scopes, settings_schema
├── schema.sql           # 🗄️ Optional: CREATE TABLE IF NOT EXISTS plg_<name> (...) statements
├── events/
│   └── handler.php      # ⚡ Optional: async event hook handler (call.*, voicemail.*, fax.*)
├── worker/
│   └── tick.php         # ⏱️ Optional: background worker hook run by aipbx-webhook-worker
├── public/
│   └── handler.php      # 🌐 Optional: endpoint served at /api/plugins/<slug>/<subPath>
├── sync/
│   └── Sync.php         # ☎️ Optional: Asterisk config generator hook
└── admin/
    ├── action.php       # 🔄 Optional: pre-render POST / CSV export handler
    └── page.php         # 🖥️ Optional: custom admin UI rendered on /plugins/<slug>
```

### 🗄️ Dedicated Plugin MySQL Tables (`plg_` Prefix Rule)
When a plugin needs relational tables (such as campaigns, call queues, or logs beyond the 500-item `sys_plugin_kv` store):
1. 📄 Place a `schema.sql` file in the plugin root and list the table names under `"tables": ["plg_my_table"]` in `plugin.json`.
2. 🛡️ **Strict Prefix Sandbox**: Every table in `schema.sql` **must** start with `plg_` (`CREATE TABLE IF NOT EXISTS plg_<name> (...)`). `PluginManager::parseAndValidatePluginSchemaSql()` rejects any statement that touches core tables (`sys_*`, `pbx_*`, `cdrs`) or contains destructive DDL (`DROP`, `ALTER`, `TRUNCATE`).
3. 🔄 **Lifecycle**: Tables are automatically created when the plugin is enabled, preserved when disabled, and dropped when uninstalled.

---

## 2. `plugin.json` Manifest Reference

```json
{
    "slug": "my-crm-bridge",
    "name": "My CRM Bridge",
    "name_i18n": {
        "tr": "Özel CRM Köprüsü",
        "en": "My CRM Bridge"
    },
    "version": "1.0.0",
    "author": "Your Company",
    "author_url": "https://example.com",
    "type": "community",
    "category": "integration",
    "icon": "fas fa-address-book",
    "icon_style": "color: #10b981;",
    "description": "Push call events to CRM and display recent synced calls.",
    "description_i18n": {
        "tr": "Çağrı olaylarını CRM sistemine iletir ve son çağrıları gösterir.",
        "en": "Push call events to CRM and display recent synced calls."
    },
    "scopes": ["calls:read", "cdr:read"],
    "hooks": {
        "events": ["call.started", "call.ended", "voicemail.created"],
        "sync_domain": "general_dialplan",
        "admin_page": {
            "group": "integrations"
        }
    },
    "settings_schema": [
        {
            "key": "enabled_push",
            "type": "boolean",
            "default": "1",
            "label": "Enable CRM Push",
            "label_i18n": { "tr": "CRM Gönderimini Etkinleştir" }
        },
        {
            "key": "crm_url",
            "type": "text",
            "default": "https://crm.example.com/api/calls",
            "placeholder": "https://crm.example.com/api/calls",
            "label": "CRM Webhook URL",
            "label_i18n": { "tr": "CRM Webhook Adresi" }
        },
        {
            "key": "crm_api_key",
            "type": "password",
            "secret": true,
            "default": "",
            "label": "CRM API Key",
            "label_i18n": { "tr": "CRM API Anahtarı" }
        }
    ]
}
```

### Automatic Settings Form & Encryption
- You do **not** need to write HTML forms for your plugin's settings: `settings_schema` is automatically rendered on `/plugins/<slug>`.
- Supported field types: `text`, `number`, `password`, `boolean` (`checkbox`), `select` (`"options": {"k": "Label"}`), and `textarea`.
- Any field with `"secret": true` is automatically encrypted at rest using `SecretBox` (AES-256-GCM / Sodium) in `sys_plugin_settings` and masked in the UI.
- When `"scopes"` are declared, `PluginManager::enable()` automatically issues a dedicated loopback Bearer API key (`_worker_api_token`) and revokes it when the plugin is disabled or uninstalled.

---

## 3. Hook Points

### A. Event Hook (`events/handler.php`)
Triggered asynchronously by `aipbx-webhook-worker.service` whenever an event listed in `hooks.events` occurs (`call.started`, `call.answered`, `call.ended`, `call.missed`, `voicemail.created`, `fax.received`, `fax.sent`, `queue.CallEntered`, `queue.AgentConnect`, `queue.Abandon`, `config.sync_applied`).

Return a callable receiving `(string $eventName, array $envelope, array $settings)`:

```php
<?php
return static function (string $eventName, array $envelope, array $settings): void {
    if (($settings['enabled_push'] ?? '0') !== '1') {
        return;
    }

    // Store in the built-in plugin Key-Value store (sys_plugin_kv)
    $eventId = (string)($envelope['id'] ?? uniqid('ev_', true));
    PluginManager::kvSet('my-crm-bridge', 'recent_calls', $eventId, [
        'event' => $eventName,
        'data'  => $envelope['data'] ?? [],
        'time'  => $envelope['occurred_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
    ]);
};
```

### B. Asterisk Configuration Hook (`sync/Sync.php`)
Triggered when Asterisk configuration changes are applied (`general_dialplan` or full sync).

Return a callable receiving `(string $slug, array $settings)`:

```php
<?php
return static function (string $slug, array $settings): void {
    $content = "; Auto-generated by plugin {$slug}\n";
    writePBXConf("extensions_plugin_{$slug}.conf", $content);
};
```
Files named `pjsip_plugin_<slug>.conf`, `extensions_plugin_<slug>.conf`, or `hep_plugin_<slug>.conf` inside `/etc/asterisk/pbx/` are automatically removed when the plugin is disabled or uninstalled.

### C. Custom Admin Page (`admin/page.php`)
Rendered below the automatic settings card on `/plugins/<slug>`. Runs inside an output-buffered error sandbox so any UI exception is caught cleanly without breaking the portal:

```php
<?php
$recent = PluginManager::kvList('my-crm-bridge', 'recent_calls', 20);
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-list"></i> Recent Synced Calls (<?php echo count($recent); ?>)</div>
    </div>
    <div style="padding: 16px;">
        <pre><?php echo htmlspecialchars(json_encode($recent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
    </div>
</div>
```

### D. Other hooks

- **`worker/tick.php`** (`"worker": true`): returns `function (string $slug, array $settings)`, called by
  `aipbx-webhook-worker` every ~2 s. Keep your own clock (e.g. in `sys_plugin_kv`) for slower jobs and
  keep each call short.
- **`hooks.reload`**: Asterisk configuration reload command executed after sync or uninstall. Under AiPBX 1.73+ CLI privilege isolation (`conf/asterisk/cli_permissions.conf`), `www-data` is strictly restricted to safe operational reloads (`dialplan reload`, `pjsip reload`, `queue reload`, `moh reload`, `voicemail reload`, `module reload res_*.so`). Arbitrary commands or destructive operations (`core stop`, `module load`) are rejected by the Asterisk control socket.
- **`public/handler.php`** (`"public_endpoints": true`): returns `function (string $subPath, array $settings)`
  for `/api/plugins/<slug>/<subPath>`. **There is no sign-in on this URL**; check the caller yourself.
  Plain HTTP is accepted only from `127.0.0.1` (Asterisk `CURL()`), everyone else is redirected to HTTPS.
- **`admin/action.php`**: returns `function (string $slug, array $settings): array`, run on every request
  to `/plugins/<slug>` before the page is drawn. Check CSRF (`verifyCSRFToken($_POST['csrf_token'])`) for
  your own forms; return `['message' => ...]`, `['error' => ...]` or `[]`.
- **`user_page`** (AiPBX 1.71+): a page for signed-in users, not only administrators, at `/apps/<slug>`,
  with a line in the sidebar under the dashboard group. Declare it in `hooks`:
  `"user_page": {"label": "Messages", "label_i18n": {"tr": "Mesajlar"}, "icon": "fas fa-comment-sms"}`.
  `user/page.php` draws it with `$slug`, `$settings`, `$user` (the signed-in user's row) and `$csrf_token`.
  `user/visible.php` (optional) returns `function (array $user, array $settings): bool` and decides who
  sees the line and may open the page; without it every signed-in user does. On POST the core checks the
  CSRF token and runs `user/action.php`, `function (string $slug, array $settings, array $user): array`;
  return `['message' => ...]` or `['error' => ...]` for a form, or `['json' => [...]]` to answer a script.
  Check what the user may touch yourself: the page is open to every signed-in user `visible.php` lets in.
- **Alerts in the bell** (AiPBX 1.50.8+): `NoticeService::raise($key, 'warn'|'fail', $title, $message, $link, $slug)`
  opens or updates one problem, `NoticeService::resolve($key)` ends it. Raise the same key on every pass
  while the problem lasts and resolve it when it is gone; the bell shows it to administrators until then,
  and the health check *Other alerts* e-mails it to the health recipients.
  Prefix keys with your slug, pass your slug as the last argument (a disabled plugin's alerts are hidden),
  and check `class_exists('NoticeService')` for older AiPBX versions.

---

### `requires_aipbx` (AiPBX 1.72+)
`"requires_aipbx": "1.71.0"` in `plugin.json`: an older AiPBX refuses to install the plugin and says which
version it needs, instead of the plugin failing at its first missing hook.

## 4. Packaging and Uploading Your Custom `.zip` Plugin

1. Package your plugin folder into a `.zip` file:
   ```bash
   zip -r my-crm-bridge.zip my-crm-bridge/
   ```
2. In the AiPBX portal, go to **Integration → Plugin Catalog** (`/plugins`).
3. Click **Upload Custom Plugin (`.zip`)** and select `my-crm-bridge.zip`.
4. AiPBX validates the archive (blocks path traversal, checks `plugin.json`, and runs `php -l` syntax checks on every `.php` file), extracts it to `/var/lib/aipbx/plugins/my-crm-bridge/`, enables it, and adds it to the sidebar menu (`Integrations` or `AI`).

---

### From the community catalog (AiPBX 1.72+)
Community plugins live in [mahirgul/aipbx-plugins](https://github.com/mahirgul/aipbx-plugins) under
`plugins/community/<slug>/`. Its CI builds each one as `<slug>.zip` (the `tests/` folder left out) with
`catalog.json` (slug, name, version, SHA-256) on the release `catalog`. On **Integration → Plugin Catalog**,
**Show community plugins from the catalog** (off by default) lists them with **Install & Enable**, and
**Update to vX** when the catalog has a newer version of an installed one. The download is checked
against the catalog's SHA-256 and then installed exactly like an uploaded `.zip`.

**Signed catalog (AiPBX 1.74+).** A checksum proves that a download is the file the list names, not
that the list is genuine. `catalog.json` therefore carries a detached Ed25519 signature,
`catalog.json.sig`, made by the catalog's CI with a key that is in no repository; AiPBX holds the
public key and uses no list without a valid signature. A list signed earlier than the one in use is
refused too, so an old plugin version cannot be offered again. A refused list is written to the audit
log and the last good one stays in use. The signature says the list came from the catalog's
maintainer; it is not a review of what a plugin does: read a community plugin before you enable it.

## 5. Safety & Fault Isolation ("Safe Mode")

- Every plugin hook (`events/handler.php`, `sync/Sync.php`, `admin/page.php`) is wrapped in an isolated `try / catch (\Throwable)` block.
- If a plugin throws **5 consecutive runtime errors**, `PluginManager` automatically disables the plugin (**Safe Mode**), removes its generated Asterisk `.conf` files, and records a `plugin_safe_mode` entry in **Security → Audit Log** (`/audit-log`).


## 6. Development Requirements and Release Checklist

Apply these requirements to new plugins and changes to existing plugins. The checks below are review
and test requirements; the plugin loader does not enforce all of them. Safe Mode catches runtime
errors, but it is not a security boundary for PHP code, network access, or database queries.

### Access control and secrets

- Treat every `public/handler.php` route as anonymous until the handler authenticates it. A plugin's
  enabled state, declared scopes, and HTTPS do not authenticate callers. Validate the path, HTTP method,
  input types, lengths and required fields before any database write, notification or paid API request.
- For Asterisk-only callbacks, require an exact loopback `REMOTE_ADDR` (`127.0.0.1` or `::1`) and a
  per-installation random secret. Do not accept `X-Forwarded-For` as proof of loopback access. Send the
  secret through authenticated loopback requests, compare it with `hash_equals`, and reject missing/empty
  secrets. With Asterisk, use scalar `CURLOPT(userpwd)` credentials and clear them after the request.
  Avoid adding secret `httpheader` values: repeated calls accumulate headers, and setting an empty
  header does not reliably remove previous values.
- For provider webhooks, verify the provider's documented signature against the original body/URL,
  including timestamp/replay checks where supported. If signatures are unavailable, require a strong
  random webhook secret. Keep secret URLs out of logs. Authentication does not make media URLs safe.
- Enforce per-user ownership on every read, send, download, export and update. Hiding a menu item or
  checking that a user is signed in does not authorize access to a supplied number or record ID.
  Validate CSRF tokens on session-authenticated mutations; use POST for those mutations.
- Store credentials with secret settings (`secret: true` or `PluginManager::setSetting(..., true)`).
  Keys beginning with `_` are omitted from `getAllSettings()` and hook `$settings`; retrieve a private
  token explicitly with `PluginManager::getSetting($slug, '_lookup_token')`. Generate tokens once,
  preserve them across normal syncs, and document rotation and revocation.
- Escape dynamic HTML and keep credentials, message bodies and personal data out of error responses
  and routine logs. Bound request size and rate, particularly for routes that can incur provider costs.

### Outbound HTTP and media downloads

- Keep provider API destinations fixed or explicitly configured by an administrator. Treat URLs from
  webhook bodies, messages and remote responses as untrusted, even when the webhook signature is valid.
- For untrusted URLs, allow only the required scheme (normally HTTPS) and ports. Reject URL credentials,
  loopback, private, link-local, reserved and other non-public IPv4/IPv6 destinations. Validate DNS
  results and pin the connection to a validated address to prevent DNS rebinding; retain TLS hostname
  and certificate verification. An intentionally configured internal service needs a separate,
  explicit destination policy, not a general bypass for incoming media links.
- Disable redirects unless needed. If enabled, validate every redirect destination under the same
  policy before connecting. Never forward provider credentials to an unrelated host.
- Set connection and total timeouts. Enforce download size while streaming, including responses without
  `Content-Length`; a size check after buffering the entire body is insufficient. Limit attachment
  count, check content types, and serve executable/unknown formats as downloads with `nosniff`.
- Handle transport errors, non-success HTTP status, malformed JSON and provider-specific error fields.
  An HTTP 200 alone does not prove a successful operation. Do not report a send as successful merely
  because an ID is missing and a local replacement was generated.

### Database, events and duplicate processing

- Verify table and column names against actual core migrations and the minimum supported release.
  Do not assume a table exists from its name. Test optional-plugin dependencies both enabled and absent.
  Use prepared statements and the plugin's declared `plg_*` tables for plugin-owned data.
- Test with the real database engine and application permissions. The portal database user is DML-only;
  do not run ad-hoc schema changes from a request. `CREATE TABLE IF NOT EXISTS` does not upgrade an
  existing table: define and test a supported upgrade path before changing its schema.
- Scope deduplication keys to the provider's documented ID namespace, including account and receiving
  number where needed. The same external ID for two independent destinations must not silently discard
  one message. Enforce uniqueness atomically in the database; a SELECT followed by INSERT races under
  concurrent webhook retries. Define behavior for missing IDs and partial attachment failures.
- Check the actual event producer, event name and payload fields before implementing a consumer.
  For example, `web/bin/voicemail_notify.php` emits `voicemail.created` with `extension`, `caller`,
  `duration` and `new_count`; it does not supply a transcript. Its `voicemail.transcribed` emission
  supplies `summary` and the full `transcript` when available (older releases supply only `summary`). Do not summarize call metadata
  while presenting the result as a summary of the recording.
- Test event delivery from producer to consumer, retries and out-of-order delivery. Mock fixtures must
  match real payloads; a fixture invented to satisfy the consumer can conceal a broken integration.

### Call flow, compatibility and lifecycle

- Keep synchronous call-path work bounded. The provider timeout must fit inside the local callback
  timeout, with time left for database and response processing. Document the maximum added delay;
  synchronous HTTP is not delay-free. Use background jobs where the result is not needed immediately.
- On lookup failure, preserve the existing caller name and allow the call to continue. Test timeout,
  empty/malformed response and provider outage as well as success. Filter every value written into
  Asterisk configuration; reject configuration syntax and control-character injection.
- Set `requires_aipbx` to the first release containing every required hook, table and API. Code present
  only on `main` is not available in an older release. Release the core dependency before publishing
  a plugin that requires it, and test the declared minimum version.
- Run on the supported PHP runtime, including PHP 8.5, with deprecations visible. Avoid obsolete calls
  such as `curl_close()` on PHP 8.5. Keep dependencies and required extensions documented.
- Verify fresh install, upgrade with existing data, repeated sync, disable/re-enable and uninstall.
  Preserve data on disable, stop workers/callback effects when disabled, and verify token/configuration
  cleanup. Document data retention and the destructive effects of uninstalling.

### Evidence required before release

Include applicable evidence in the plugin README or pull request. Mark checks that do not apply with
an explanation; do not describe mocked provider tests as live integration tests.

| Area | Required evidence |
|---|---|
| Authentication | External request, missing/wrong token, spoofed forwarding header and wrong method rejected before any paid request or side effect; valid caller succeeds. |
| User permissions | User A cannot read, send, export or modify user B's records by changing an ID. |
| Network safety | Controlled attempts to fetch loopback/private IPv4 and IPv6, a redirect to an internal target, and oversized/chunked media are rejected. Use an isolated local test server, never a production target. |
| Data integrity | Real-schema queries pass; exact retries store once; independent destinations/accounts are preserved; concurrent retries do not duplicate side effects. |
| Event contract | A real producer payload reaches the handler and produces the intended result, including the content needed by the feature. |
| Call behavior | For dialplan/call changes, real calls on an isolated LXD test installation pass for success, timeout and failure. Generated-config assertions alone are insufficient. |
| Packaging and compatibility | PHP lint, focused regression tests, minimum-version check, packaged ZIP install and upgrade with existing data pass. |
| Provider behavior | Request/response fixtures cover errors as well as success; record separately whether a provider sandbox or live account was tested. |

Security fixes require a reproducible isolated attack test that fails before the fix and passes after
it. Run relevant checks in CI and keep them with the plugin. Passing syntax checks or a high coverage
percentage alone does not establish correct authorization, billing behavior or call handling.

Develop and test in a workspace or isolated installation. Do not edit the managed `/opt/aipbx` checkout
on live servers. Distribute bundled changes through tagged AiPBX releases and community changes through
the supported plugin package/catalog update path.
