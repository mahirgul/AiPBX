"""Whisper models: per-file download checked by SHA-256, token decoding, features, catalogue.

The file handling runs without numpy/ctranslate2, against a fake Hugging Face
repository in a temporary folder (file:// URLs); the features need numpy and
a real transcription needs ctranslate2 and a model, so those parts are skipped
without them.
"""
import hashlib
import io
import os
import sys
import tempfile
import unittest
import wave
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import whisper_backend  # noqa: E402

try:
    import numpy as np
    HAVE_NUMPY = True
except ImportError:
    HAVE_NUMPY = False

try:
    import ctranslate2  # noqa: F401
    HAVE_CT2 = HAVE_NUMPY
except ImportError:
    HAVE_CT2 = False

REVISION = "0123456789abcdef0123456789abcdef01234567"
FILES = {"config.json": b'{"fake": 1}', "model.bin": b"weights" * 100, "vocabulary.txt": b"<|endoftext|>\n"}


class WhisperDownloadTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.repo = root / "hf" / "Systran" / "faster-whisper-x" / "resolve" / REVISION
        self.repo.mkdir(parents=True)
        for name, body in FILES.items():
            (self.repo / name).write_bytes(body)
        self.orig = whisper_backend.REPO_URL
        whisper_backend.REPO_URL = (root / "hf").as_uri() + "/"
        self.data = root / "data"

    def tearDown(self):
        whisper_backend.REPO_URL = self.orig
        self.tmp.cleanup()

    def backend(self, **overrides):
        files = {n: {"sha256": hashlib.sha256(b).hexdigest(), "bytes": len(b)} for n, b in FILES.items()}
        files.update(overrides)
        spec = SimpleNamespace(id="whisper-x", languages=("tr-TR",), download_mb=1,
                               source={"repo": "Systran/faster-whisper-x", "revision": REVISION,
                                       "language": "tr", "files": files})
        return whisper_backend.WhisperBackend(spec, self.data)

    def test_download_checks_and_installs(self):
        b = self.backend()
        self.assertFalse(b.installed())
        self.assertEqual(b.disk_bytes(), 0)
        seen = []
        b.download(seen.append)
        self.assertTrue(b.installed())
        self.assertEqual(seen[-1], 100)
        self.assertEqual(b.disk_bytes(), sum(len(x) for x in FILES.values()))
        self.assertEqual((b.dir / "model.bin").read_bytes(), FILES["model.bin"])
        self.assertEqual([], list(b.dir.glob("*.part")))
        self.assertEqual(b.dir, self.data / "models" / "whisper-x")

    def test_checksum_mismatch_is_refused(self):
        b = self.backend(**{"model.bin": {"sha256": "0" * 64, "bytes": 700}})
        with self.assertRaisesRegex(RuntimeError, "model.bin: checksum mismatch"):
            b.download(lambda p: None)
        self.assertFalse((b.dir / "model.bin").exists())
        self.assertFalse(b.installed())
        self.assertEqual([], list(b.dir.glob("*.part")))

    def test_good_files_are_not_fetched_again(self):
        b = self.backend()
        b.download(lambda p: None)
        (self.repo / "model.bin").write_bytes(b"changed upstream")   # would fail the checksum
        b.download(lambda p: None)
        self.assertTrue(b.installed())

    def test_delete_files(self):
        b = self.backend()
        b.download(lambda p: None)
        b.delete_files()
        self.assertFalse(b.installed())
        self.assertFalse(b.dir.exists())

    def test_transcribe_needs_load(self):
        with self.assertRaisesRegex(RuntimeError, "not loaded"):
            self.backend().transcribe(b"")

    def test_language_from_source_or_languages(self):
        b = self.backend()
        self.assertEqual(b.language, "tr")
        self.assertEqual(b._prompt(), ["<|startoftranscript|>", "<|tr|>", "<|transcribe|>", "<|notimestamps|>"])
        b.spec.source.pop("language")
        b.spec.languages = ("de-DE",)
        self.assertEqual(b.language, "de")


class RepeatTest(unittest.TestCase):
    def test_repeated_sentence_is_kept_once(self):
        c = whisper_backend.collapse_repeats
        self.assertEqual(c("Faturamı hazırlar mısınız? Faturamı hazırlar mısınız?"), "Faturamı hazırlar mısınız?")
        # cut off by the token cap in the middle of a further copy
        self.assertEqual(c("İki tane havlu alabilir miyim? İki tane havlu alabilir miyim? İki tane ha"),
                         "İki tane havlu alabilir miyim?")
        self.assertEqual(c("Alo alo"), "Alo alo")                    # short words stay
        self.assertEqual(c("Klima çalışmıyor."), "Klima çalışmıyor.")
        self.assertEqual(c(""), "")


class TokenTextTest(unittest.TestCase):
    def test_byte_level_tokens(self):
        # "Ġ" is the space byte, "Ä±" the two UTF-8 bytes of "ı" in GPT-2's byte table.
        self.assertEqual(whisper_backend.tokens_to_text(["<|tr|>", "ĠKl", "ima", "ĠÃ§al", "Ä±ÅŁ", "m", "Ä±yor", ".", "<|endoftext|>"]),
                         "Klima çalışmıyor.")
        self.assertEqual(whisper_backend.tokens_to_text([]), "")


class CatalogueTest(unittest.TestCase):
    def test_whisper_entries(self):
        from aipbx_ai import models
        entries = [s for s in models.REGISTRY if s.engine == "whisper"]
        self.assertTrue(entries)
        for s in entries:
            self.assertEqual(s.kind, "stt")
            self.assertIs(s.backend, whisper_backend.WhisperBackend)
            self.assertTrue(s.commercial)
            self.assertRegex(s.source["revision"], r"^[0-9a-f]{40}$")
            self.assertEqual(set(s.source["files"]), {"config.json", "model.bin", "vocabulary.txt"})
            for f in s.source["files"].values():
                self.assertRegex(f["sha256"], r"^[0-9a-f]{64}$")
            size = sum(f["bytes"] for f in s.source["files"].values())
            self.assertEqual(s.download_mb, -(-size // (1024 * 1024)))
            self.assertEqual(s.languages[0].split("-")[0], s.source["language"])


@unittest.skipUnless(HAVE_NUMPY, "numpy not installed")
class FeatureTest(unittest.TestCase):
    def test_mel_filters_shape_and_norm(self):
        f = whisper_backend.mel_filters(np, 80)
        self.assertEqual(f.shape, (80, 201))
        self.assertTrue((f >= 0).all())
        self.assertTrue((f.sum(axis=1) > 0).all())          # every band covers some FFT bins

    def test_log_mel_shape_and_range(self):
        t = np.arange(16000) / 16000
        audio = (0.3 * np.sin(2 * np.pi * 440 * t)).astype(np.float32)
        m = whisper_backend.log_mel(np, audio, whisper_backend.mel_filters(np, 80))
        self.assertEqual(m.shape, (80, 3000))
        self.assertAlmostEqual(float(m.max() - m.min()), 2.0, places=4)   # clipped 8 decades, /4
        self.assertGreater(m[:, :100].mean(), m[:, 200:].mean())          # the padding is quiet


@unittest.skipUnless(HAVE_CT2 and os.environ.get("AIPBX_WHISPER_MODEL"),
                     "set AIPBX_WHISPER_MODEL=<folder with config.json, model.bin, vocabulary.txt>")
class RuntimeTest(unittest.TestCase):
    def test_silence_gives_little_or_no_text(self):
        d = Path(os.environ["AIPBX_WHISPER_MODEL"])
        files = {n: {"sha256": whisper_backend._sha256(d / n), "bytes": (d / n).stat().st_size}
                 for n in ("config.json", "model.bin", "vocabulary.txt")}
        spec = SimpleNamespace(id=d.name, languages=("tr-TR",), source={"language": "tr", "files": files})
        b = whisper_backend.WhisperBackend(spec, d.parent.parent)
        b.load()
        buf = io.BytesIO()
        with wave.open(buf, "wb") as w:
            w.setnchannels(1); w.setsampwidth(2); w.setframerate(8000); w.writeframes(b"\0\0" * 16000)
        r = b.transcribe(buf.getvalue())
        self.assertEqual(r["seconds"], 2.0)
        self.assertEqual(r["text"], "")
        b.unload()


if __name__ == "__main__":
    unittest.main()
