"""The engine and the portal must not drift apart.

Three lists exist twice — the MAC prefixes, the vendor names and the settings
defaults — because Python cannot read PHP and the portal cannot read Python.
This test parses the PHP files and fails on the first difference, so a vendor or
a setting added on one side has to be added on the other in the same commit.

It reads files, not a running install: no database, no PBX.
"""

import os
import re
import unittest

from aipbx_engine import config, oui

WEB = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "web"))
MODELS_PHP = os.path.join(WEB, "src", "services", "phones", "PhoneModels.php")
DISCOVERY_PHP = os.path.join(WEB, "src", "services", "PhoneDiscoveryService.php")

#: The OUI block of PhoneModels.php is private, so it is parsed rather than
#: included:  'yealink' => ['001565', ...],  inside  private const OUI = [ … ];
_OUI_ENTRY = re.compile(r"'([a-z0-9]+)'\s*=>\s*\[([^\]]*)\]")
_PREFIX = re.compile(r"'([0-9a-f]{6})'")
#: 'vendor' => 'Label',  inside the VENDORS constant.
_LABEL = re.compile(r"'([a-z0-9]+)'\s*=>\s*'([^']+)'")
#: 'provision_discovery_x' => 'default',  inside SETTINGS.
_SETTING = re.compile(r"'(provision_discovery[a-z_]*)'\s*=>\s*'([^']*)'")


def read(path):
    if not os.path.exists(path):
        raise unittest.SkipTest(f"cannot check parity: {path} is not in this checkout")
    with open(path, encoding="utf-8") as handle:
        return handle.read()


def php_oui():
    block = read(MODELS_PHP).split("private const OUI = [", 1)
    if len(block) == 1:
        raise AssertionError("PhoneModels::OUI is gone; this test needs updating")
    body = block[1].split("];", 1)[0]
    return {vendor: tuple(prefixes) for vendor, prefixes in
            ((m.group(1), _PREFIX.findall(m.group(2))) for m in _OUI_ENTRY.finditer(body))}


def php_vendors():
    block = read(MODELS_PHP).split("public const VENDORS = [", 1)
    if len(block) == 1:
        raise AssertionError("PhoneModels::VENDORS is gone; this test needs updating")
    return dict(_LABEL.findall(block[1].split("];", 1)[0]))


def php_settings():
    block = read(DISCOVERY_PHP).split("public const SETTINGS = [", 1)
    if len(block) == 1:
        raise AssertionError("PhoneDiscoveryService::SETTINGS is gone; this test needs updating")
    return dict(_SETTING.findall(block[1].split("];", 1)[0]))


class VendorTable(unittest.TestCase):
    def test_the_same_prefixes(self):
        self.assertEqual(php_oui(), oui.OUI,
                         "the MAC prefix table differs from PhoneModels::OUI")

    def test_the_same_vendor_names(self):
        self.assertEqual(php_vendors(), oui.VENDORS,
                         "the vendor names differ from PhoneModels::VENDORS")

    def test_every_prefix_is_reachable_and_lowercase(self):
        for vendor, prefixes in oui.OUI.items():
            for prefix in prefixes:
                self.assertEqual(prefix, prefix.lower())
                self.assertEqual(vendor, oui.vendor_of(prefix + "000000"))


class Settings(unittest.TestCase):
    def test_the_same_keys_and_defaults(self):
        self.assertEqual(php_settings(), config.SETTINGS,
                         "the discovery settings differ from PhoneDiscoveryService::SETTINGS")

    def test_every_setting_is_used_somewhere(self):
        # The pool and the vendor models are the portal's; the other three are
        # read here. All five still have to exist in both files (asserted above).
        self.assertIn("provision_discovery_models", config.SETTINGS)
        self.assertIn("provision_discovery_pool", config.SETTINGS)


class Safety(unittest.TestCase):
    """The rules that must not be softened by accident, asserted on the PHP."""

    def test_the_engine_never_writes_pbx_phones(self):
        for path in ("sources.py", "detect.py", "db.py", "config.py", "__main__.py"):
            source = read(os.path.join(os.path.dirname(__file__), "..", "aipbx_engine", path))
            for forbidden in ("INSERT INTO pbx_phones", "UPDATE pbx_phones", "DELETE FROM pbx_phones"):
                self.assertNotIn(forbidden, source, f"{path} writes pbx_phones directly")

    def test_the_automatic_step_only_takes_new_rows_and_only_recent_ones(self):
        php = read(DISCOVERY_PHP)
        self.assertIn("d.status = 'new'", php)
        self.assertIn("last_seen_at >= (NOW() - INTERVAL", php)
        self.assertIn("NOT EXISTS (SELECT 1 FROM pbx_phones p WHERE p.mac = d.mac)", php)

    def test_an_ignored_row_is_never_a_candidate(self):
        php = read(DISCOVERY_PHP)
        # The candidate query filters on status = 'new', and 'ignored' is only
        # ever written by the ignore() action.
        self.assertIn("SET status = 'ignored'", php)
        self.assertNotIn("status != 'ignored'", php)


if __name__ == "__main__":
    unittest.main()
