"""Vosk models: zip download checked by SHA-256 and unpacked safely; WAV input checks."""
import hashlib
import io
import os
import sys
import tempfile
import unittest
import wave
import zipfile
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import vosk_backend  # noqa: E402

try:
    import numpy  # noqa: F401
    HAVE_NUMPY = True
except ImportError:
    HAVE_NUMPY = False


def make_zip(entries):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        for name, data in entries.items():
            z.writestr(name, data)
    return buf.getvalue()


class VoskBackendTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.release = Path(self.tmp.name) / "release"
        self.release.mkdir()
        self.orig = vosk_backend.BASE_URL
        vosk_backend.BASE_URL = self.release.as_uri() + "/"

    def tearDown(self):
        vosk_backend.BASE_URL = self.orig
        self.tmp.cleanup()

    def backend(self, data, sha=None):
        (self.release / "m.zip").write_bytes(data)
        spec = SimpleNamespace(id="vosk-x", download_mb=1,
                               source={"file": "m.zip", "sha256": sha or hashlib.sha256(data).hexdigest()})
        return vosk_backend.VoskBackend(spec, Path(self.tmp.name) / "data")

    def test_download_unpacks_without_top_folder(self):
        b = self.backend(make_zip({"vosk-model-x/final.mdl": "m", "vosk-model-x/ivector/final.ie": "i"}))
        self.assertFalse(b.installed())
        b.download(lambda p: None)
        self.assertTrue(b.installed())
        self.assertTrue((b.model_dir / "ivector" / "final.ie").is_file())
        self.assertFalse(any(b.dir.glob("*.part")))
        b.delete_files()
        self.assertFalse(b.dir.exists())

    def test_checksum_mismatch(self):
        b = self.backend(make_zip({"x/final.mdl": "m"}), sha="0" * 64)
        with self.assertRaisesRegex(RuntimeError, "checksum mismatch"):
            b.download(lambda p: None)
        self.assertFalse(b.installed())

    def test_unsafe_path_refused(self):
        b = self.backend(make_zip({"x/final.mdl": "m", "x/../../evil": "e"}))
        with self.assertRaisesRegex(RuntimeError, "unsafe path"):
            b.download(lambda p: None)
        self.assertFalse((Path(self.tmp.name) / "evil").exists())

    @unittest.skipUnless(HAVE_NUMPY, "numpy not installed")
    def test_wav_input(self):
        buf = io.BytesIO()
        with wave.open(buf, "wb") as w:
            w.setnchannels(1); w.setsampwidth(2); w.setframerate(8000); w.writeframes(b"\x00\x00" * 8000)
        pcm, seconds = vosk_backend.pcm16_from_wav(buf.getvalue())
        self.assertEqual((seconds, len(pcm)), (1.0, 32000))     # 1 s at 16 kHz
        with self.assertRaises(ValueError):
            vosk_backend.pcm16_from_wav(b"not a wav")


if __name__ == "__main__":
    unittest.main()
