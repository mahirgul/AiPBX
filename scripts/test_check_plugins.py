#!/usr/bin/env python3
"""Unit tests for AiPBX Plugin Validator (scripts/check_plugins.py)."""

from __future__ import annotations

import json
import os
import shutil
import tempfile
import unittest

from check_plugins import PluginValidator


class TempPluginTree:
    """Helper to create a temporary plugin directory structure for testing."""

    def __init__(self, slug: str, files: dict[str, str | dict]):
        self.slug = slug
        self.files = files
        self.temp_dir = ""

    def __enter__(self) -> str:
        self.temp_dir = tempfile.mkdtemp(prefix=f"test_plugin_{self.slug}_")
        plugin_path = os.path.join(self.temp_dir, self.slug)
        os.makedirs(plugin_path, exist_ok=True)

        for rel_path, content in self.files.items():
            full = os.path.join(plugin_path, rel_path)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            if isinstance(content, dict):
                with open(full, "w", encoding="utf-8") as f:
                    json.dump(content, f, indent=2)
            else:
                with open(full, "w", encoding="utf-8") as f:
                    f.write(content)
        return plugin_path

    def __exit__(self, exc_type, exc_val, exc_tb):
        if os.path.isdir(self.temp_dir):
            shutil.rmtree(self.temp_dir, ignore_errors=True)


def valid_manifest(slug: str = "sample-plugin", **overrides) -> dict:
    data = {
        "slug": slug,
        "name": "Sample Test Plugin",
        "version": "1.0.0",
        "author": "Alice Contributor",
        "author_url": "https://example.com/alice",
        "type": "community",
        "description": "A sample plugin for unit testing.",
    }
    data.update(overrides)
    return data


class PluginValidatorTests(unittest.TestCase):
    def test_valid_minimal_plugin(self):
        slug = "sample-plugin"
        files = {"plugin.json": valid_manifest(slug)}
        with TempPluginTree(slug, files) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertTrue(v.validate(), v.errors)

    def test_missing_required_fields(self):
        slug = "bad-plugin"
        manifest = valid_manifest(slug)
        del manifest["author"]
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("Missing or empty 'author'" in e for e in v.errors))

    def test_invalid_slug_format(self):
        slug = "Invalid_Slug"
        manifest = valid_manifest(slug)
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("Invalid slug format" in e for e in v.errors))

    def test_slug_mismatch_with_directory(self):
        slug = "dir-slug"
        manifest = valid_manifest("different-slug")
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("Slug mismatch" in e for e in v.errors))

    def test_author_url_must_be_http_or_https(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, author_url="ftp://example.com")
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("Invalid 'author_url'" in e for e in v.errors))

    def test_multiple_different_community_authors_are_allowed(self):
        """Open source rule: multiple community plugins with different authors are 100% valid."""
        with TempPluginTree("plugin-one", {"plugin.json": valid_manifest("plugin-one", author="Alice")}) as path1:
            v1 = PluginValidator(path1, slug="plugin-one", check_php_syntax=False)
            self.assertTrue(v1.validate(), v1.errors)

        with TempPluginTree("plugin-two", {"plugin.json": valid_manifest("plugin-two", author="Bob")}) as path2:
            v2 = PluginValidator(path2, slug="plugin-two", check_php_syntax=False)
            self.assertTrue(v2.validate(), v2.errors)

    def test_unknown_hook_rejected(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, hooks={"nonexistent_hook": True})
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("Unknown hook 'nonexistent_hook'" in e for e in v.errors))

    def test_admin_page_missing_group_and_route(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, hooks={"admin_page": {}})
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("either 'group' or 'route'" in e for e in v.errors))

    def test_admin_page_missing_handler_file(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, hooks={"admin_page": {"group": "integrations"}})
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("admin/page.php was not found" in e for e in v.errors))

    def test_admin_page_with_page_php_passes(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, hooks={"admin_page": {"group": "integrations"}})
        files = {
            "plugin.json": manifest,
            "admin/page.php": "<?php echo 'Hello'; ?>",
        }
        with TempPluginTree(slug, files) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertTrue(v.validate(), v.errors)

    def test_worker_hook_requires_tick_php(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, hooks={"worker": True})
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("worker/tick.php was not found" in e for e in v.errors))

    def test_public_endpoints_requires_handler_php(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, hooks={"public_endpoints": True})
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("public/handler.php was not found" in e for e in v.errors))

    def test_events_must_be_list(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, hooks={"events": "call.ended"})
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("must be a list of event strings" in e for e in v.errors))

    def test_duplicate_settings_key_rejected(self):
        slug = "sample-plugin"
        manifest = valid_manifest(
            slug,
            settings_schema=[
                {"key": "api_key", "type": "password"},
                {"key": "api_key", "type": "text"},
            ],
        )
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("duplicate key 'api_key'" in e for e in v.errors))

    def test_secret_must_be_password_type(self):
        slug = "sample-plugin"
        manifest = valid_manifest(
            slug,
            settings_schema=[
                {"key": "api_token", "type": "text", "secret": True},
            ],
        )
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("must be 'password'" in e for e in v.errors))

    def test_select_type_requires_options(self):
        slug = "sample-plugin"
        manifest = valid_manifest(
            slug,
            settings_schema=[
                {"key": "mode", "type": "select"},
            ],
        )
        with TempPluginTree(slug, {"plugin.json": manifest}) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("requires an 'options' list" in e for e in v.errors))

    def test_code_reads_undeclared_setting(self):
        slug = "sample-plugin"
        manifest = valid_manifest(
            slug,
            settings_schema=[
                {"key": "api_url", "type": "url"},
            ],
        )
        files = {
            "plugin.json": manifest,
            "Service.php": "<?php $secret = $settings['api_token']; ?>",
        }
        with TempPluginTree(slug, files) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("reads setting 'api_token'" in e for e in v.errors))

    def test_code_reads_declared_setting_passes(self):
        slug = "sample-plugin"
        manifest = valid_manifest(
            slug,
            settings_schema=[
                {"key": "api_url", "type": "url"},
            ],
        )
        files = {
            "plugin.json": manifest,
            "Service.php": "<?php $url = $settings['api_url']; ?>",
        }
        with TempPluginTree(slug, files) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertTrue(v.validate(), v.errors)

    def test_schema_sql_non_plg_prefix_rejected(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug)
        files = {
            "plugin.json": manifest,
            "schema.sql": "CREATE TABLE custom_data (id INT PRIMARY KEY);",
        }
        with TempPluginTree(slug, files) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("must start with 'plg_' prefix" in e for e in v.errors))

    def test_schema_sql_forbidden_sql_rejected(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug)
        files = {
            "plugin.json": manifest,
            "schema.sql": "CREATE TABLE plg_data (id INT); DROP TABLE sys_users;",
        }
        with TempPluginTree(slug, files) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertFalse(v.validate())
            self.assertTrue(any("forbidden SQL keyword" in e for e in v.errors))

    def test_valid_schema_sql_passes(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug, tables=["plg_sample_table"])
        files = {
            "plugin.json": manifest,
            "schema.sql": "CREATE TABLE IF NOT EXISTS plg_sample_table (id INT AUTO_INCREMENT PRIMARY KEY);",
        }
        with TempPluginTree(slug, files) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=False)
            self.assertTrue(v.validate(), v.errors)

    def test_php_syntax_error_detected(self):
        slug = "sample-plugin"
        manifest = valid_manifest(slug)
        files = {
            "plugin.json": manifest,
            "broken.php": "<?php function broken( { return 1; }",
        }
        with TempPluginTree(slug, files) as path:
            v = PluginValidator(path, slug=slug, check_php_syntax=True)
            self.assertFalse(v.validate())
            self.assertTrue(any("PHP syntax error" in e for e in v.errors))


if __name__ == "__main__":
    unittest.main(verbosity=2)
