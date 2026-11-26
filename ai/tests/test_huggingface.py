"""Model downloads: only Hugging Face hosts, SHA-256 checked before a file is kept."""
import hashlib
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import hf, models, onnx_models  # noqa: E402


class _FakeResp:
    def __init__(self, data):
        self._data, self._pos = data, 0

    def read(self, n=-1):
        chunk = self._data[self._pos:self._pos + n]
        self._pos += len(chunk)
        return chunk

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False


class UrlSafetyTest(unittest.TestCase):
    def test_only_https_hub_and_cdn_hosts_are_allowed(self):
        for url in ("https://huggingface.co/a/b/resolve/main/m.onnx", "https://cdn-lfs.hf.co/file",
                    "https://cas-bridge.xethub.hf.co/x"):
            self.assertTrue(hf._is_allowed_url(url), url)
        for url in ("http://huggingface.co/a", "https://evil.example/huggingface.co", "https://huggingface.co.evil.example/x",
                    "file:///etc/passwd"):
            self.assertFalse(hf._is_allowed_url(url), url)

    def test_redirect_to_an_untrusted_host_is_refused(self):
        with mock.patch.object(hf._OPENER, "open") as opener:
            hf.open_download("https://huggingface.co/org/model/resolve/main/m.onnx")
            req = opener.call_args.args[0]
        handler = hf._SafeRedirectHandler()
        self.assertIsNotNone(handler.redirect_request(req, None, 302, "Found", {}, "https://cdn-lfs.hf.co/file"))
        with self.assertRaises(hf.HfSecurityError):
            handler.redirect_request(req, None, 302, "Found", {}, "https://attacker.invalid/file")
        with self.assertRaises(hf.HfSecurityError):
            hf.open_download("https://attacker.invalid/m.onnx")


class DownloadShaTest(unittest.TestCase):
    def _spec(self, sha, size):
        return models.ModelSpec(
            id="x-model", title="x", kind="vad", languages=(), license="MIT", license_url="", homepage="",
            download_mb=1, backend=onnx_models.SileroBackend, engine="silero",
            source={"repo": "org/test", "revision": "a" * 40,
                    "files": {"model.onnx": {"path": "m.onnx", "sha256": sha, "bytes": size}}})

    def test_good_download_is_kept(self):
        payload = b"onnx-bytes"
        with tempfile.TemporaryDirectory() as tmp:
            spec = self._spec(hashlib.sha256(payload).hexdigest(), len(payload))
            backend = spec.backend(spec, tmp)
            with mock.patch.object(hf._OPENER, "open", return_value=_FakeResp(payload)):
                backend.download(lambda p: None)
            self.assertTrue(backend.installed())
            self.assertEqual(backend.disk_bytes(), len(payload))
            backend.delete_files()
            self.assertFalse(backend.installed())

    def test_sha256_mismatch_is_rejected_and_part_deleted(self):
        payload = b"onnx-bytes"
        with tempfile.TemporaryDirectory() as tmp:
            spec = self._spec("0" * 64, len(payload))
            backend = spec.backend(spec, tmp)
            with mock.patch.object(hf._OPENER, "open", return_value=_FakeResp(payload)):
                with self.assertRaisesRegex(RuntimeError, "checksum mismatch"):
                    backend.download(lambda p: None)
            self.assertFalse((Path(tmp) / "models" / spec.id / "model.onnx").exists())
            self.assertFalse((Path(tmp) / "models" / spec.id / "model.onnx.part").exists())


class RegistryTest(unittest.TestCase):
    def test_only_voices_and_call_detectors_are_local(self):
        self.assertEqual({s.kind for s in models.REGISTRY}, {"tts", "vad", "turn"})
        self.assertFalse([s.id for s in models.REGISTRY if s.id.startswith(("whisper", "vosk", "e5"))])


if __name__ == "__main__":
    unittest.main()
