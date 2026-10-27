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

#: A key on the left of an `=>`: `yealink`, `cisco-cm`, `provision_discovery_pool`.
#: Vendor keys may carry a hyphen — the Cisco CallManager series is `cisco-cm` —
#: so a pattern of `[a-z0-9]+` would skip that entry and compare two shorter
#: tables, which is exactly what this test is here to prevent. entries() below
#: also fails on a key this pattern still cannot read, so the next key form to
#: appear breaks the test instead of quietly dropping out of the comparison.
_KEY = r"([A-Za-z0-9][A-Za-z0-9_-]*)"

#: The OUI block of PhoneModels.php is private, so it is parsed rather than
#: included:  'yealink' => ['001565', ...],  inside  private const OUI = [ … ];
_OUI_ENTRY = re.compile(rf"'{_KEY}'\s*=>\s*\[([^\]]*)\]")
_PREFIX = re.compile(r"'([0-9a-f]{6})'")
#: 'vendor' => 'Label',  inside the VENDORS constant.
_LABEL = re.compile(rf"'{_KEY}'\s*=>\s*'([^']+)'")
#: 'provision_discovery_x' => 'default',  inside SETTINGS.
_SETTING = re.compile(rf"'(provision_discovery[a-z_]*)'\s*=>\s*'([^']*)'")


def read(path):
    if not os.path.exists(path):
        raise unittest.SkipTest(f"cannot check parity: {path} is not in this checkout")
    with open(path, encoding="utf-8") as handle:
        return handle.read()


def block(text, marker, what):
    """The body of a PHP constant, or a failure when the constant is gone."""
    split = text.split(marker, 1)
    if len(split) == 1:
        raise AssertionError(f"{what} is gone; this test needs updating")
    return split[1].split("];", 1)[0]


def entries(body, pattern, what):
    """The pattern's matches in a constant body — every `=>` of it.

    findall() alone is not enough: an entry the pattern cannot read is simply
    missing from the result, and comparing two shorter tables passes. Every
    `=>` left in the body after the matches are removed is an entry this test
    would have skipped, so it is an error rather than a quiet success.
    """
    found = pattern.findall(body)
    unread = [line.strip() for line in pattern.sub("", body).splitlines() if "=>" in line]
    if unread:
        raise AssertionError(
            f"{what} has {len(unread)} entry(s) this test cannot read, so they "
            f"would be missing from the comparison: {'; '.join(unread[:3])}")
    return found


def php_oui():
    body = block(read(MODELS_PHP), "private const OUI = [", "PhoneModels::OUI")
    return {vendor: tuple(prefixes) for vendor, prefixes in
            ((vendor, _PREFIX.findall(prefixes))
             for vendor, prefixes in entries(body, _OUI_ENTRY, "PhoneModels::OUI"))}


def php_vendors():
    body = block(read(MODELS_PHP), "public const VENDORS = [", "PhoneModels::VENDORS")
    return dict(entries(body, _LABEL, "PhoneModels::VENDORS"))


def php_settings():
    body = block(read(DISCOVERY_PHP), "public const SETTINGS = [",
                 "PhoneDiscoveryService::SETTINGS")
    return dict(entries(body, _SETTING, "PhoneDiscoveryService::SETTINGS"))


class Parsing(unittest.TestCase):
    """The guard reads the PHP by pattern, so the pattern itself is checked."""

    def test_a_hyphenated_vendor_key_is_read(self):
        body = "'yealink' => 'Yealink', 'cisco-cm' => 'Cisco (CallManager)',"
        self.assertEqual(entries(body, _LABEL, "PhoneModels::VENDORS"),
                         [("yealink", "Yealink"),
                          ("cisco-cm", "Cisco (CallManager)")])

    def test_an_unreadable_entry_fails_instead_of_disappearing(self):
        with self.assertRaises(AssertionError):
            entries("'Yealink Pro' => 'Yealink',", _LABEL, "PhoneModels::VENDORS")


class VendorTable(unittest.TestCase):
    def test_a_hyphenated_vendor_is_compared(self):
        # 'cisco-cm' has no prefix in PhoneModels::OUI (Cisco's prefixes cover
        # routers and switches too), so the vendor list is the only place it can
        # be checked. It used to be skipped by the pattern, which left the
        # comparison passing while one key of the two tables was never read.
        self.assertIn("cisco-cm", php_vendors(),
                      "PhoneModels::VENDORS is not being read whole")

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
