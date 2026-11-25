import tempfile
import unittest
from pathlib import Path

from aipbx_ai.models import ModelManager
from aipbx_ai.voice_requests import AppStore


class ClearTtsCacheTest(unittest.TestCase):
    def test_memory_and_disk_are_emptied(self):
        d = tempfile.mkdtemp()
        store = AppStore(ModelManager((), d), d)
        store.tts_dir.mkdir(parents=True, exist_ok=True)
        (store.tts_dir / ("a" * 64 + ".pcm")).write_bytes(b"\x00\x01" * 100)
        (store.tts_dir / "keep.txt").write_text("not a prompt")
        store._cache[(1, 1, "greeting")] = b"\x00\x00"
        store._phrase_cache["k"] = b"\x00\x00"
        self.assertEqual({"removed": 1, "bytes": 200}, store.clear_tts_cache())
        self.assertFalse(store._cache or store._phrase_cache)
        self.assertEqual(["keep.txt"], sorted(p.name for p in store.tts_dir.iterdir()))
        self.assertEqual({"removed": 0, "bytes": 0}, store.clear_tts_cache())


if __name__ == "__main__":
    unittest.main()
