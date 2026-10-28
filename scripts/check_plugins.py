#!/usr/bin/env python3
"""AiPBX Plugin Validator & Manifest Contract Checker.

Validates official bundled plugins (plugins/<slug>/) and custom uploaded plugins
against the PluginManager specification:
  1. Manifest format (plugin.json: slug, name, version, author, hooks, scopes)
  2. Hook definitions and matching handler files (admin, events, worker, public, sync)
  3. Settings schema consistency (no duplicate keys, secret=password, type validity)
  4. Static code analysis: verifies PHP code does not read undeclared settings keys
  5. Database schema security: enforces plg_* prefix and rejects destructive SQL
  6. PHP syntax linting (php -l) on all plugin scripts

Usage:
  python3 scripts/check_plugins.py                     # check all plugins in plugins/
  python3 scripts/check_plugins.py --plugin <slug>     # check a single plugin
  python3 scripts/check_plugins.py --path <dir>        # check an external/uploaded plugin folder
  python3 scripts/test_check_plugins.py                # run self-tests
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

KNOWN_HOOKS = {
    "admin_page",
    "auth_provider",
    "ai_provider",
    "tts_provider",
    "public_endpoints",
    "sync_domain",
    "events",
    "worker",
}

VALID_SETTING_TYPES = {
    "text",
    "password",
    "boolean",
    "number",
    "select",
    "textarea",
    "email",
    "url",
}

FORBIDDEN_SQL_KEYWORDS = re.compile(
    r"\b(DROP|TRUNCATE|ALTER|GRANT|REVOKE|INTO\s+OUTFILE|LOAD_FILE|sys_users|sys_settings|sys_api_tokens|sys_plugins)\b",
    re.IGNORECASE,
)

SETTINGS_DIRECT_READ = re.compile(r"\$settings\s*\[\s*['\"]([a-zA-Z0-9_]+)['\"]\s*\]")
SETTINGS_CHAINED_READ = re.compile(
    r"getAllSettings\(\s*['\"][^'\"]+['\"]\s*(?:,\s*(?:true|false)\s*)?\)\s*\[\s*['\"]([a-zA-Z0-9_]+)['\"]\s*\]"
)
SETTINGS_VAR_ASSIGN = re.compile(
    r"\$([a-zA-Z0-9_]+)\s*=\s*[^;]*?getAllSettings\(\s*['\"]([^'\"]+)['\"]"
)


class PluginValidator:
    def __init__(self, plugin_dir: str, slug: str | None = None, check_php_syntax: bool = True):
        self.plugin_dir = os.path.abspath(plugin_dir)
        self.slug = slug or os.path.basename(self.plugin_dir)
        self.check_php_syntax = check_php_syntax
        self.errors: list[str] = []
        self.warnings: list[str] = []
        self.manifest_data: dict = {}
        self.declared_settings: set[str] = set()

    def add_error(self, message: str) -> None:
        self.errors.append(f"[{self.slug}] ERROR: {message}")

    def add_warning(self, message: str) -> None:
        self.warnings.append(f"[{self.slug}] WARN: {message}")

    def validate(self) -> bool:
        if not os.path.isdir(self.plugin_dir):
            self.add_error(f"Plugin directory not found: {self.plugin_dir}")
            return False

        manifest_path = os.path.join(self.plugin_dir, "plugin.json")
        if not os.path.isfile(manifest_path):
            self.add_error("Missing required manifest: plugin.json")
            return False

        try:
            with open(manifest_path, "r", encoding="utf-8") as f:
                self.manifest_data = json.load(f)
        except Exception as e:
            self.add_error(f"Invalid JSON in plugin.json: {e}")
            return False

        if not isinstance(self.manifest_data, dict):
            self.add_error("plugin.json root must be a JSON object")
            return False

        self._validate_manifest_fields()
        self._validate_hooks()
        self._validate_settings_schema()
        self._validate_settings_usage_in_code()
        self._validate_database_schema()

        if self.check_php_syntax:
            self._validate_php_syntax()

        return len(self.errors) == 0

    def _validate_manifest_fields(self) -> None:
        data = self.manifest_data

        # Slug check
        slug = data.get("slug")
        if not slug or not isinstance(slug, str):
            self.add_error("Missing or empty 'slug'")
        elif not re.match(r"^[a-z0-9][a-z0-9-]{1,48}$", slug):
            self.add_error(f"Invalid slug format '{slug}': must match ^[a-z0-9][a-z0-9-]{{1,48}}$")
        elif slug != self.slug:
            self.add_error(f"Slug mismatch: manifest specifies '{slug}', directory is '{self.slug}'")

        # Name check
        name = data.get("name")
        if not name or not isinstance(name, str) or not name.strip():
            self.add_error("Missing or empty 'name'")

        # Name i18n check (optional)
        if "name_i18n" in data:
            if not isinstance(data["name_i18n"], dict):
                self.add_error("'name_i18n' must be a JSON object mapping language codes to strings")
            else:
                for lang, text in data["name_i18n"].items():
                    if not isinstance(lang, str) or len(lang) != 2 or not isinstance(text, str):
                        self.add_error(f"Invalid entry in 'name_i18n': '{lang}'")

        # Version check
        version = data.get("version")
        if not version or not isinstance(version, str) or not version.strip():
            self.add_error("Missing or empty 'version'")

        # Author check
        author = data.get("author")
        if not author or not isinstance(author, str) or not author.strip():
            self.add_error("Missing or empty 'author'")

        # Author URL check (optional)
        author_url = data.get("author_url")
        if author_url is not None:
            if not isinstance(author_url, str) or not re.match(r"^https?://[^\s]+$", author_url):
                self.add_error(f"Invalid 'author_url': must be a valid http:// or https:// URL")

        # Type check (official or community)
        p_type = data.get("type", "community")
        if p_type not in ("official", "community"):
            self.add_error(f"Invalid 'type': must be 'official' or 'community', got '{p_type}'")

        # Scopes check (optional)
        if "scopes" in data:
            if not isinstance(data["scopes"], list) or not all(isinstance(s, str) for s in data["scopes"]):
                self.add_error("'scopes' must be a list of strings")

    def _validate_hooks(self) -> None:
        data = self.manifest_data
        hooks = data.get("hooks")
        if hooks is None:
            return

        if not isinstance(hooks, dict):
            self.add_error("'hooks' must be a JSON object")
            return

        is_official = data.get("type") == "official"

        for hook_name, hook_val in hooks.items():
            if hook_name not in KNOWN_HOOKS:
                self.add_error(f"Unknown hook '{hook_name}'. Supported: {', '.join(sorted(KNOWN_HOOKS))}")
                continue

            if hook_name == "admin_page":
                if not isinstance(hook_val, dict):
                    self.add_error("hooks.admin_page must be an object (e.g. {'group': 'integrations'} or {'route': '...'})")
                else:
                    route = hook_val.get("route")
                    group = hook_val.get("group")
                    if not route and not group:
                        self.add_error("hooks.admin_page must specify either 'group' or 'route'")
                    # If there's no custom core route, admin/page.php should exist
                    if not route:
                        page_php = os.path.join(self.plugin_dir, "admin", "page.php")
                        if not os.path.isfile(page_php):
                            self.add_error("hooks.admin_page specified without 'route', but admin/page.php was not found")

            elif hook_name == "worker":
                if hook_val:
                    tick_php = os.path.join(self.plugin_dir, "worker", "tick.php")
                    if not os.path.isfile(tick_php):
                        self.add_error("hooks.worker is enabled, but worker/tick.php was not found")

            elif hook_name == "public_endpoints":
                if hook_val is True:
                    public_handler = os.path.join(self.plugin_dir, "public", "handler.php")
                    if not os.path.isfile(public_handler):
                        self.add_error("hooks.public_endpoints is enabled, but public/handler.php was not found")
                elif isinstance(hook_val, list):
                    # List of custom route endpoints (e.g. ['/widget-api'])
                    if not is_official:
                        public_handler = os.path.join(self.plugin_dir, "public", "handler.php")
                        if not os.path.isfile(public_handler):
                            self.add_error("hooks.public_endpoints list specified, but public/handler.php was not found")

            elif hook_name == "events":
                if not isinstance(hook_val, list) or not all(isinstance(e, str) for e in hook_val):
                    self.add_error("hooks.events must be a list of event strings (e.g. ['call.ended'])")
                else:
                    # In community plugins or standalone plugins with events, events/handler.php should exist
                    events_handler = os.path.join(self.plugin_dir, "events", "handler.php")
                    if not os.path.isfile(events_handler) and not is_official:
                        self.add_error("hooks.events declared, but events/handler.php was not found")

            elif hook_name == "sync_domain":
                if not isinstance(hook_val, str) or not hook_val.strip():
                    self.add_error("hooks.sync_domain must be a non-empty string domain name")

    def _validate_settings_schema(self) -> None:
        data = self.manifest_data
        schema = data.get("settings_schema")
        if schema is None:
            return

        if not isinstance(schema, list):
            self.add_error("'settings_schema' must be a list of setting definitions")
            return

        seen_keys: set[str] = set()
        for idx, item in enumerate(schema):
            if not isinstance(item, dict):
                self.add_error(f"settings_schema[{idx}] must be a JSON object")
                continue

            key = item.get("key")
            if not key or not isinstance(key, str) or not re.match(r"^[a-zA-Z0-9_]{1,64}$", key):
                self.add_error(f"settings_schema[{idx}]: invalid or missing 'key' (must be alphanumeric/underscore)")
                continue

            if key in seen_keys:
                self.add_error(f"settings_schema: duplicate key '{key}'")
            seen_keys.add(key)
            self.declared_settings.add(key)

            stype = item.get("type")
            if not stype or stype not in VALID_SETTING_TYPES:
                self.add_error(f"settings_schema[{key}]: invalid 'type' '{stype}'. Supported: {', '.join(sorted(VALID_SETTING_TYPES))}")

            if item.get("secret") and stype != "password":
                self.add_error(f"settings_schema[{key}]: 'secret' is true but 'type' is '{stype}' (must be 'password')")

            if stype == "select":
                options = item.get("options")
                if not options or not isinstance(options, (list, dict)):
                    self.add_error(f"settings_schema[{key}]: type 'select' requires an 'options' list or map")

    def _validate_settings_usage_in_code(self) -> None:
        """Scan PHP files in this plugin for reads of $settings['key'] and ensure they are declared."""
        for root_dir, _, files in os.walk(self.plugin_dir):
            for file_name in files:
                if not file_name.endswith(".php"):
                    continue
                file_path = os.path.join(root_dir, file_name)
                rel_path = os.path.relpath(file_path, self.plugin_dir)

                try:
                    with open(file_path, "r", encoding="utf-8", errors="replace") as f:
                        content = f.read()
                except OSError:
                    continue

                accessed_keys: set[str] = set()

                # $settings['key']
                for m in SETTINGS_DIRECT_READ.findall(content):
                    accessed_keys.add(m)

                # PluginManager::getAllSettings('slug')['key']
                for m in SETTINGS_CHAINED_READ.findall(content):
                    accessed_keys.add(m)

                # Variable assign: $cfg = PluginManager::getAllSettings('slug'); $cfg['key']
                for var_name, assigned_slug in SETTINGS_VAR_ASSIGN.findall(content):
                    if assigned_slug == self.slug:
                        var_read_pattern = re.compile(r"\$" + re.escape(var_name) + r"\s*\[\s*['\"]([a-zA-Z0-9_]+)['\"]\s*\]")
                        for m in var_read_pattern.findall(content):
                            accessed_keys.add(m)

                for k in accessed_keys:
                    if k not in self.declared_settings:
                        self.add_error(
                            f"{rel_path}: reads setting '{k}', but '{k}' is never declared in plugin.json settings_schema"
                        )

    def _validate_database_schema(self) -> None:
        """Enforce plg_* prefix and security rules on schema.sql."""
        schema_file = os.path.join(self.plugin_dir, "schema.sql")
        if not os.path.isfile(schema_file):
            return

        try:
            with open(schema_file, "r", encoding="utf-8", errors="replace") as f:
                raw_sql = f.read()
        except OSError as e:
            self.add_error(f"Failed to read schema.sql: {e}")
            return

        # Strip comments
        clean = re.sub(r"/\*.*?\*/", "", raw_sql, flags=re.DOTALL)
        clean_lines = [line for line in clean.splitlines() if not line.strip().startswith("--")]
        statements = [s.strip() for s in "\n".join(clean_lines).split(";") if s.strip()]

        created_tables: list[str] = []
        for stmt in statements:
            # Check for forbidden keywords
            if FORBIDDEN_SQL_KEYWORDS.search(stmt):
                self.add_error(f"schema.sql security violation: statement contains forbidden SQL keyword or core table reference")
                continue

            # Must be CREATE TABLE
            m = re.match(r"^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?", stmt, re.IGNORECASE)
            if not m:
                self.add_error(f"schema.sql violation: only 'CREATE TABLE [IF NOT EXISTS] plg_<name>' statements are allowed")
                continue

            tbl_name = m.group(1).lower()
            if not tbl_name.startswith("plg_"):
                self.add_error(f"schema.sql security violation: table '{tbl_name}' must start with 'plg_' prefix")
            else:
                created_tables.append(tbl_name)

        # Check declared tables in plugin.json
        declared_tables = self.manifest_data.get("tables")
        if declared_tables is not None:
            if not isinstance(declared_tables, list):
                self.add_error("plugin.json 'tables' must be a list of table names")
            else:
                for t in declared_tables:
                    if not str(t).startswith("plg_"):
                        self.add_error(f"plugin.json 'tables' entry '{t}' must start with 'plg_' prefix")
                for created in created_tables:
                    if created not in declared_tables:
                        self.add_warning(f"schema.sql creates table '{created}', but it is not listed in plugin.json 'tables'")

    def _validate_php_syntax(self) -> None:
        """Run php -l on every PHP file in this plugin."""
        for root_dir, _, files in os.walk(self.plugin_dir):
            for file_name in files:
                if not file_name.endswith(".php"):
                    continue
                php_file = os.path.join(root_dir, file_name)
                rel_path = os.path.relpath(php_file, self.plugin_dir)

                res = subprocess.run(["php", "-l", php_file], capture_output=True, text=True)
                if res.returncode != 0:
                    err_msg = res.stderr.strip() or res.stdout.strip()
                    self.add_error(f"PHP syntax error in {rel_path}: {err_msg}")


def check_all_catalog_plugins(plugins_root: str, check_php_syntax: bool = True) -> int:
    if not os.path.isdir(plugins_root):
        print(f"Plugins directory not found: {plugins_root}", file=sys.stderr)
        return 1

    total_plugins = 0
    total_errors = 0
    total_warnings = 0

    print(f"Scanning plugins in: {plugins_root}\n")

    for entry in sorted(os.listdir(plugins_root)):
        full_path = os.path.join(plugins_root, entry)
        if not os.path.isdir(full_path):
            continue  # Skip files like README.md

        validator = PluginValidator(full_path, slug=entry, check_php_syntax=check_php_syntax)
        total_plugins += 1
        success = validator.validate()

        if validator.warnings:
            for w in validator.warnings:
                print(w)
            total_warnings += len(validator.warnings)

        if not success:
            for e in validator.errors:
                print(e, file=sys.stderr)
            total_errors += len(validator.errors)
        else:
            p_type = validator.manifest_data.get("type", "community")
            version = validator.manifest_data.get("version", "1.0.0")
            author = validator.manifest_data.get("author", "Unknown")
            print(f"✓ {entry:<16} v{version:<7} [{p_type:<9}] by {author}")

    print("\n" + "=" * 55)
    print(f"Results: {total_plugins} plugin(s) inspected, {total_errors} error(s), {total_warnings} warning(s).")
    return 1 if total_errors > 0 else 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--plugin", help="Check a specific plugin by slug under plugins/")
    parser.add_argument("--path", help="Check a specific plugin directory anywhere on disk")
    parser.add_argument("--no-php-lint", action="store_true", help="Skip php -l syntax check")
    parser.add_argument("--root", help="Override repository root directory")
    args = parser.parse_args(argv)

    root_dir = os.path.abspath(args.root) if args.root else ROOT
    plugins_dir = os.path.join(root_dir, "plugins")
    check_syntax = not args.no_php_lint

    if args.path:
        target_path = os.path.abspath(args.path)
        validator = PluginValidator(target_path, check_php_syntax=check_syntax)
        ok = validator.validate()
        for w in validator.warnings:
            print(w)
        for e in validator.errors:
            print(e, file=sys.stderr)
        if ok:
            print(f"✓ Plugin at '{target_path}' passed validation.")
            return 0
        return 1

    if args.plugin:
        target_path = os.path.join(plugins_dir, args.plugin)
        validator = PluginValidator(target_path, slug=args.plugin, check_php_syntax=check_syntax)
        ok = validator.validate()
        for w in validator.warnings:
            print(w)
        for e in validator.errors:
            print(e, file=sys.stderr)
        if ok:
            print(f"✓ Plugin '{args.plugin}' passed validation.")
            return 0
        return 1

    return check_all_catalog_plugins(plugins_dir, check_php_syntax=check_syntax)


if __name__ == "__main__":
    sys.exit(main())
