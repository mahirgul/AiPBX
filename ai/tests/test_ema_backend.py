"""EMA Lightning backend: download with checksums and the move from the PyTorch format.

Runs without numpy/onnxruntime: only the file handling is tested, against a
fake release in a temporary folder (file:// URLs).
"""
import hashlib
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from aipbx_ai import ema  # noqa: E402


class EmaBackendTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.release = root / "release"
        self.release.mkdir()
        self.data = root / "data"
        files = {}
        for name in ema.FILES:
            body = f"fake {name}".encode()
            (self.release / name).write_bytes(body)
            files[name] = hashlib.sha256(body).hexdigest()
        self.orig = (ema.RELEASE, ema.FILES)
        ema.RELEASE, ema.FILES = self.release.as_uri() + "/", files
        self.backend = ema.EmaLightningBackend(SimpleNamespace(id="ema-lightning", download_mb=1), self.data)

    def tearDown(self):
        ema.RELEASE, ema.FILES = self.orig
        self.tmp.cleanup()

    def test_download_checks_and_installs(self):
        self.assertFalse(self.backend.installed())
        seen = []
        self.backend.download(seen.append)
        self.assertTrue(self.backend.installed())
        self.assertEqual(seen[-1], 100)
        self.assertGreater(self.backend.disk_bytes(), 0)
        self.assertEqual([], list(self.backend.dir.glob("*.part")))

    def test_changed_file_is_refused(self):
        (self.release / "sound.onnx").write_bytes(b"tampered")
        with self.assertRaisesRegex(RuntimeError, "checksum mismatch"):
            self.backend.download(lambda p: None)
        self.assertFalse((self.backend.dir / "sound.onnx").exists())
        self.assertFalse(self.backend.installed())

    def test_earlier_pytorch_model_is_replaced(self):
        legacy = self.data / ema.LEGACY_REPO_DIR
        legacy.mkdir(parents=True)
        (legacy / "x").write_text("old")
        self.assertTrue(self.backend.migration_pending())
        self.backend.download(lambda p: None)
        self.assertFalse(self.backend.migration_pending())
        self.assertFalse((self.data / "hf").exists())

    def test_delete_files(self):
        self.backend.download(lambda p: None)
        self.backend.delete_files()
        self.assertFalse(self.backend.installed())
        self.assertFalse(self.backend.dir.exists())


if __name__ == "__main__":
    unittest.main()
