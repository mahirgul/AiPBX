# Plugin System & Developer Guide

AiPBX includes a modular **Plugin Catalog & Custom `.zip` Plugin Engine** (`PluginManager`) integrated with `/api/v1/*` and Outbound Webhooks.

- 📦 **Bundled catalog (`plugins/<slug>/`)**: Ships in the repository. Official plugins: `google-auth`, `ms-teams`, `web-widgets`, `auto-dialer`, `ai-cloud`, `ai-tts`, `ai-apps`, `ai-models`, `ai-voicemail`. Community plugin bundled with AiPBX: `ai-assistant` by Gregory Merriweather ([@shido6](https://github.com/shido6)) (his signaling capture is part of AiPBX itself since 1.50.8). Fresh installations start lean; administrators enable only the plugins they need under **Integrations → Plugins** (`/plugins`). Existing installations automatically keep previously configured integrations enabled on upgrade.
- 🌐 **Community plugins**: published in [AiPBX-plugins](https://github.com/mahirgul/AiPBX-plugins) (`plugins/community/<slug>/`) and installed as an uploaded `.zip`. The plugins above are maintained only here, in this repository.
- 📤 **Custom Uploaded Plugins (`/var/lib/aipbx/plugins/<slug>/`)**: Any developer or administrator can package a custom integration into a `.zip` archive and upload it directly in **Integrations → Plugins → Upload Custom Plugin (`.zip`)**.

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
        "events": ["call.started", "call.ended", "voicemail.received"],
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
Triggered asynchronously by `aipbx-webhook-worker.service` whenever an event listed in `hooks.events` occurs (`call.started`, `call.answered`, `call.ended`, `call.missed`, `voicemail.received`, `fax.received`, `fax.sent`, `queue.CallEntered`, `queue.AgentConnect`, `queue.Abandon`, `config.sync_applied`).

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
- **`public/handler.php`** (`"public_endpoints": true`): returns `function (string $subPath, array $settings)`
  for `/api/plugins/<slug>/<subPath>`. **There is no sign-in on this URL**; check the caller yourself.
  Plain HTTP is accepted only from `127.0.0.1` (Asterisk `CURL()`), everyone else is redirected to HTTPS.
- **`admin/action.php`**: returns `function (string $slug, array $settings): array`, run on every request
  to `/plugins/<slug>` before the page is drawn. Check CSRF (`verifyCSRFToken($_POST['csrf_token'])`) for
  your own forms; return `['message' => ...]`, `['error' => ...]` or `[]`.
- **Alerts in the bell** (AiPBX 1.50.8+): `NoticeService::raise($key, 'warn'|'fail', $title, $message, $link, $slug)`
  opens or updates one problem, `NoticeService::resolve($key)` ends it. Raise the same key on every pass
  while the problem lasts and resolve it when it is gone; the bell shows it to administrators until then,
  and the health check *Other alerts* e-mails it to the health recipients.
  Prefix keys with your slug, pass your slug as the last argument (a disabled plugin's alerts are hidden),
  and check `class_exists('NoticeService')` for older AiPBX versions.

---

## 4. Packaging and Uploading Your Custom `.zip` Plugin

1. Package your plugin folder into a `.zip` file:
   ```bash
   zip -r my-crm-bridge.zip my-crm-bridge/
   ```
2. In the AiPBX portal, go to **Integrations → Plugins** (`/plugins`).
3. Click **Upload Custom Plugin (`.zip`)** and select `my-crm-bridge.zip`.
4. AiPBX validates the archive (blocks path traversal, checks `plugin.json`, and runs `php -l` syntax checks on every `.php` file), extracts it to `/var/lib/aipbx/plugins/my-crm-bridge/`, enables it, and adds it to the sidebar menu (`Integrations` or `AI`).

---

## 5. Safety & Fault Isolation ("Safe Mode")

- Every plugin hook (`events/handler.php`, `sync/Sync.php`, `admin/page.php`) is wrapped in an isolated `try / catch (\Throwable)` block.
- If a plugin throws **5 consecutive runtime errors**, `PluginManager` automatically disables the plugin (**Safe Mode**), removes its generated Asterisk `.conf` files, and records a `plugin_safe_mode` entry in **Security → Audit Log** (`/audit-log`).
