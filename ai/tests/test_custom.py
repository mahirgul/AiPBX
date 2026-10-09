"""Catalogue search: Piper voices from the index, pinned and checked when added."""
import hashlib
import json
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import custom  # noqa: E402

CFG = b'{"audio": {"sample_rate": 22050}}'
KEY = "tr_TR-dfki-medium"
VDIR = "tr/tr_TR/dfki/medium"
INDEX = {
    KEY: {"key": KEY, "name": "dfki", "quality": "medium", "num_speakers": 1,
          "language": {"code": "tr_TR", "name_english": "Turkish", "country_english": "Türkiye"},
          "files": {f"{VDIR}/{KEY}.onnx": {"size_bytes": 63 * 1024 * 1024, "md5_digest": "x"},
                    f"{VDIR}/{KEY}.onnx.json": {"size_bytes": len(CFG), "md5_digest": hashlib.md5(CFG).hexdigest()},
                    f"{VDIR}/MODEL_CARD": {"size_bytes": 10, "md5_digest": "y"}}},
    "de_DE-bad key!": {"files": {}},
}


def fake_hub(card="* License: CC0\n", cfg=CFG):
    def fetch(url, timeout=30):
        if url.endswith("/voices.json"):
            return json.dumps(INDEX).encode()
        if url.endswith("/api/models/rhasspy/piper-voices"):
            return json.dumps({"sha": "abc123"}).encode()
        if "/tree/abc123/" in url:
            return json.dumps([{"path": f"{VDIR}/{KEY}.onnx", "lfs": {"oid": "f" * 64}},
                               {"path": f"{VDIR}/{KEY}.onnx.json"}]).encode()
        if url.endswith(".onnx.json"):
            return cfg
        if url.endswith("/MODEL_CARD"):
            return card.encode()
        raise AssertionError(url)
    return fetch


class CatalogTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.tmp.cleanup()

    def test_list_skips_bad_keys_and_marks_known(self):
        cat = custom.Catalog(self.tmp.name, fetch=fake_hub())
        voices = cat.piper_voices()
        self.assertEqual([v["key"] for v in voices], [KEY])
        self.assertEqual((voices[0]["size_mb"], voices[0]["registered"], voices[0]["model_id"]), (63, False, "piper-tr-tr-dfki-medium"))
        known = {f"{VDIR}/{KEY}.onnx": "piper-tr"}
        self.assertEqual(cat.piper_voices(known)[0]["model_id"], "piper-tr")
        self.assertTrue(cat.piper_voices(known)[0]["registered"])

    def test_spec_is_pinned_and_licence_read(self):
        spec = custom.Catalog(self.tmp.name, fetch=fake_hub()).piper_spec(KEY)
        self.assertEqual(spec.source["revision"], "abc123")
        self.assertEqual(spec.source["files"][f"{VDIR}/{KEY}.onnx"], "f" * 64)
        self.assertEqual(spec.source["files"][f"{VDIR}/{KEY}.onnx.json"], hashlib.sha256(CFG).hexdigest())
        self.assertEqual((spec.license, spec.commercial, spec.custom, spec.languages), ("CC0", True, True, ("tr-TR",)))

    def test_noncommercial_and_finetuned_are_flagged(self):
        nc = custom.Catalog(self.tmp.name, fetch=fake_hub("* License: CC BY-NC-SA 4.0\n")).piper_spec(KEY)
        self.assertFalse(nc.commercial)
        ft = custom.Catalog(self.tmp.name, fetch=fake_hub("* License: CC0\nFine-tuned from lessac\n")).piper_spec(KEY)
        self.assertFalse(ft.commercial)
        self.assertIn("Fine-tuned", ft.note)

    def test_changed_config_is_refused(self):
        with self.assertRaisesRegex(custom.CatalogError, "does not match"):
            custom.Catalog(self.tmp.name, fetch=fake_hub(cfg=b"{}")).piper_spec(KEY)

    def test_unknown_or_odd_key(self):
        cat = custom.Catalog(self.tmp.name, fetch=fake_hub())
        for key in ("xx_XX-none-low", "../../etc", 42):
            with self.assertRaises(custom.CatalogError):
                cat.piper_spec(key)

    def test_manifest_round_trip(self):
        cat = custom.Catalog(self.tmp.name, fetch=fake_hub())
        spec = cat.piper_spec(KEY)
        cat.save(spec)
        (back,) = custom.Catalog(self.tmp.name).load_saved()
        self.assertEqual(back.id, spec.id)
        self.assertEqual(back.source, spec.source)
        self.assertTrue(back.custom)
        self.assertIs(back.backend, custom.PiperBackend)


if __name__ == "__main__":
    unittest.main()
