"""Hugging Face custom model validation, security rejection, SHA-256 & API tests."""
import hashlib
import http.client
import json
import os
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import custom, hf, models, server, ollama_backend  # noqa: E402


def _sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


class HfValidationTest(unittest.TestCase):
    def test_normalize_repo_accepts_slug_and_hf_url(self):
        self.assertEqual(hf.normalize_repo("Systran/faster-whisper-small"), "Systran/faster-whisper-small")
        self.assertEqual(
            hf.normalize_repo("https://huggingface.co/Systran/faster-whisper-small/tree/main"),
            "Systran/faster-whisper-small",
        )

    def test_normalize_repo_rejects_non_hf_urls_and_traversal(self):
        for bad in (
            "http://huggingface.co/org/model",
            "https://evil.example/org/model",
            "file:///etc/passwd",
            "../etc/passwd",
            "org/model/../../x",
            "onlyonepart",
        ):
            with self.assertRaises(hf.HfError):
                hf.normalize_repo(bad)

    def test_detect_supported_formats(self):
        # Piper ONNX
        d = hf.detect_repo("org/voice", [
            {"path": "en_US-lessac-medium.onnx", "size": 1000, "lfs": {"oid": "a" * 64, "size": 1000}},
            {"path": "en_US-lessac-medium.onnx.json", "size": 10},
        ])
        self.assertTrue(d["supported"])
        self.assertEqual((d["kind"], d["engine"], d["format"]), ("tts", "piper", "onnx"))

        # Whisper CTranslate2
        d = hf.detect_repo("Systran/faster-whisper-small", [
            {"path": "model.bin", "size": 2000, "lfs": {"oid": "b" * 64, "size": 2000}},
            {"path": "config.json", "size": 50},
            {"path": "vocabulary.txt", "size": 100},
        ])
        self.assertTrue(d["supported"])
        self.assertEqual((d["kind"], d["engine"], d["format"]), ("stt", "whisper", "ctranslate2"))

        # Embedding ONNX
        d = hf.detect_repo("Xenova/e5-small", [
            {"path": "onnx/model.onnx", "size": 3000, "lfs": {"oid": "c" * 64, "size": 3000}},
            {"path": "tokenizer.json", "size": 200},
        ])
        self.assertTrue(d["supported"])
        self.assertEqual((d["kind"], d["engine"], d["format"]), ("embedding", "embedding", "onnx"))

        # GGUF
        d = hf.detect_repo("org/tiny-gguf", [
            {"path": "model-q4.gguf", "size": 4000, "lfs": {"oid": "d" * 64, "size": 4000}},
        ])
        self.assertTrue(d["supported"])
        self.assertEqual((d["kind"], d["engine"], d["format"]), ("llm", "gguf", "gguf"))

        # Safetensors
        d = hf.detect_repo("org/safe-llm", [
            {"path": "model.safetensors", "size": 5000, "lfs": {"oid": "e" * 64, "size": 5000}},
            {"path": "config.json", "size": 50},
        ])
        self.assertTrue(d["supported"])
        self.assertEqual((d["kind"], d["engine"], d["format"]), ("llm", "safetensors", "safetensors"))

    def test_rejects_pickle_pytorch_and_remote_code(self):
        for bad_file in ("weights.pkl", "data.pickle", "pytorch_model.pt", "model.pth", "modeling.py"):
            d = hf.detect_repo("org/unsafe", [
                {"path": bad_file, "size": 100},
                {"path": "model.safetensors", "size": 1000, "lfs": {"oid": "a" * 64}},
            ])
            self.assertFalse(d["supported"], f"{bad_file} must be rejected")
            self.assertIn("rejected", d["reason"])

        d_rc = hf.detect_repo(
            "org/remote-code",
            [{"path": "model.safetensors", "size": 1000, "lfs": {"oid": "a" * 64}}, {"path": "config.json", "size": 40}],
            config_bytes=b'{"auto_map": {"AutoModel": "modeling.CustomModel"}}',
        )
        self.assertFalse(d_rc["supported"])
        self.assertIn("remote Python code", d_rc["reason"])

    def test_gated_repo_requires_token(self):
        def fetch(url, timeout=30, token=None):
            if url.endswith("/api/models/org/gated"):
                return json.dumps({"sha": "abc123", "gated": True}).encode()
            raise AssertionError(url)

        cat = custom.Catalog(tempfile.gettempdir(), fetch=fetch)
        with self.assertRaisesRegex(custom.CatalogError, "gated"):
            cat.hf_inspect("org/gated")

    def test_license_and_manifest_round_trip(self):
        cfg = b'{"architectures": ["LlamaForCausalLM"]}'
        readme = "---\nlicense: cc-by-nc-4.0\n---\n# Model"

        def fetch(url, timeout=30, token=None):
            if url.endswith("/api/models/org/nc-gguf"):
                return json.dumps({"sha": "deadbeef" * 5, "cardData": {"license": "cc-by-nc-4.0"}}).encode()
            if "/tree/" in url:
                return json.dumps([
                    {"path": "m.gguf", "size": 1024, "lfs": {"oid": "1" * 64, "size": 1024}},
                    {"path": "README.md", "size": len(readme)},
                ]).encode()
            if url.endswith("/README.md"):
                return readme.encode()
            if url.endswith("/config.json"):
                return cfg
            raise AssertionError(url)

        with tempfile.TemporaryDirectory() as tmp:
            cat = custom.Catalog(tmp, fetch=fetch)
            spec = cat.hf_spec("org/nc-gguf")
            self.assertFalse(spec.commercial)
            self.assertEqual(spec.engine, "gguf")
            cat.save(spec)
            (loaded,) = custom.Catalog(tmp).load_saved()
            self.assertEqual(loaded.id, spec.id)
            self.assertEqual(loaded.engine, "gguf")
            self.assertIs(loaded.backend, ollama_backend.OllamaBackend)


class HfDownloadShaTest(unittest.TestCase):
    def test_sha256_mismatch_is_rejected_and_part_deleted(self):
        payload = b"gguf-bytes"
        wrong_sha = "0" * 64

        class _FakeResp:
            def __init__(self, data):
                self._data = data
                self._pos = 0

            def read(self, n=-1):
                if self._pos >= len(self._data):
                    return b""
                chunk = self._data[self._pos:self._pos + n]
                self._pos += len(chunk)
                return chunk

            def __enter__(self):
                return self

            def __exit__(self, *args):
                return False

        with tempfile.TemporaryDirectory() as tmp:
            spec = models.ModelSpec(
                id="hf-org-test",
                title="org/test",
                kind="llm",
                languages=("en",),
                license="mit",
                license_url="https://huggingface.co/org/test",
                homepage="https://huggingface.co/org/test",
                download_mb=1,
                backend=hf.CustomWeightsBackend,
                engine="gguf",
                source={"repo": "org/test", "revision": "abc123", "files": {"m.gguf": {"path": "m.gguf", "sha256": wrong_sha, "bytes": len(payload)}}},
                custom=True,
            )
            backend = hf.CustomWeightsBackend(spec, tmp)
            with mock.patch.object(hf._OPENER, "open", return_value=_FakeResp(payload)):
                with self.assertRaisesRegex(RuntimeError, "checksum mismatch"):
                    backend.download(lambda p: None)
            self.assertFalse((Path(tmp) / "models" / spec.id / "m.gguf").exists())
            self.assertFalse((Path(tmp) / "models" / spec.id / "m.gguf.part").exists())


class HfServerEndpointsTest(unittest.TestCase):
    def test_hf_search_inspect_and_add_endpoints(self):
        class _FakeManager:
            disk_limit_mb = 5120

            def __init__(self):
                self.added = {}

            def describe_all(self):
                return []

            def source_files(self, mid):
                return []

            def has(self, mid):
                return mid in self.added

            def disk(self):
                return {"used_mb": 0, "limit_mb": self.disk_limit_mb}

            def add(self, spec, download_token=None, *, persist=None):
                if persist is not None:
                    persist(spec)
                self.added[spec.id] = spec
                return "downloading"

        def fetch(url, timeout=30, token=None):
            if "/api/models?" in url:
                return json.dumps([{"id": "org/tiny-gguf", "downloads": 42, "likes": 5, "tags": ["license:mit"]}]).encode()
            if url.endswith("/api/models/org/tiny-gguf"):
                return json.dumps({"sha": "a" * 40, "cardData": {"license": "mit"}}).encode()
            if "/tree/" in url:
                return json.dumps([{"path": "tiny.gguf", "size": 100, "lfs": {"oid": "b" * 64, "size": 100}}]).encode()
            raise AssertionError(url)

        with tempfile.TemporaryDirectory() as tmp:
            mgr = _FakeManager()
            cat = custom.Catalog(tmp, fetch=fetch)
            httpd = server.AiServer(("127.0.0.1", 0), "secret-token", mgr, catalog=cat)
            port = httpd.server_address[1]
            t = threading.Thread(target=httpd.serve_forever, daemon=True)
            t.start()
            try:
                def call(path, body):
                    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
                    conn.request("POST", path, json.dumps(body), {"Authorization": "Bearer secret-token", "Content-Type": "application/json"})
                    resp = conn.getresponse()
                    data = json.loads(resp.read().decode())
                    conn.close()
                    return resp.status, data

                st, res = call("/v1/hf/search", {"query": "tiny"})
                self.assertEqual(st, 200)
                self.assertEqual(res["models"][0]["repo"], "org/tiny-gguf")

                st, res = call("/v1/hf/inspect", {"repo": "org/tiny-gguf"})
                self.assertEqual(st, 200)
                self.assertTrue(res["info"]["supported"])
                self.assertEqual(res["info"]["format"], "gguf")

                st, res = call("/v1/models/add", {"engine": "huggingface", "repo": "org/tiny-gguf", "accept_license": True})
                self.assertEqual(st, 202)
                self.assertEqual(res["id"], "hf-org-tiny-gguf")
                self.assertTrue(mgr.has("hf-org-tiny-gguf"))
            finally:
                httpd.shutdown()
                httpd.server_close()


class RuntimeRegressionTest(unittest.TestCase):
    def spec(self, backend=ollama_backend.OllamaBackend):
        return models.ModelSpec(
            id="hf-org-test", title="Test", kind="llm", languages=("en",), license="mit",
            license_url="", homepage="", download_mb=1, backend=backend, engine="gguf",
            source={"repo": "org/test", "revision": "a" * 40,
                    "files": {"test.gguf": {"path": "test.gguf", "sha256": _sha(b"weights"), "bytes": 7}}}, custom=True)

    def test_ollama_import_warmup_chat_and_unload(self):
        with tempfile.TemporaryDirectory() as tmp:
            spec = self.spec()
            backend = spec.backend(spec, tmp)
            backend.dir.mkdir(parents=True)
            (backend.dir / "test.gguf").write_bytes(b"weights")
            with mock.patch.object(ollama_backend, "request", side_effect=lambda method, *a, **kw: False if method == "HEAD" else {"message": {"content": "Answer"}}) as api:
                backend.load()
                self.assertTrue(backend.loaded)
                self.assertEqual(api.call_args_list[1].args[1], "/api/blobs/sha256:" + _sha(b"weights"))
                self.assertEqual(api.call_args_list[2].args[1], "/api/create")
                self.assertEqual(api.call_args_list[3].args[1], "/api/generate")
                self.assertEqual(backend.chat("Hello"), "Answer")
                backend.unload()
                self.assertFalse(backend.loaded)
                self.assertEqual(api.call_args.args[2]["keep_alive"], 0)

    def test_existing_ollama_blob_is_not_uploaded_again(self):
        with tempfile.TemporaryDirectory() as tmp:
            spec = self.spec()
            backend = spec.backend(spec, tmp)
            backend.dir.mkdir(parents=True)
            (backend.dir / "test.gguf").write_bytes(b"weights")
            with mock.patch.object(ollama_backend, "request", return_value=True) as api:
                backend.load()
                self.assertTrue(backend.loaded)
                self.assertFalse(any(c.args[0] == "POST" and c.args[1].startswith("/api/blobs/") for c in api.call_args_list))

    def test_ollama_cannot_be_ready_without_loading(self):
        with tempfile.TemporaryDirectory() as tmp:
            spec = self.spec()
            backend = spec.backend(spec, tmp)
            backend.dir.mkdir(parents=True)
            (backend.dir / "test.gguf").write_bytes(b"weights")
            with mock.patch.object(ollama_backend, "request", side_effect=RuntimeError("unavailable")):
                with self.assertRaises(RuntimeError):
                    backend.load()
                self.assertFalse(backend.loaded)
                with self.assertRaises(RuntimeError):
                    backend.chat("Question")

    def test_gated_download_auth_is_transient_and_redirect_strips_it(self):
        with mock.patch.object(hf._OPENER, "open") as opener:
            hf.open_download("https://huggingface.co/org/model/resolve/main/model.gguf", "hf_test_secret")
            req = opener.call_args.args[0]
            self.assertEqual(req.get_header("Authorization"), "Bearer hf_test_secret")
            redirected = hf._SafeRedirectHandler().redirect_request(
                req, None, 302, "Found", {}, "https://cdn-lfs.hf.co/file")
            self.assertIsNone(redirected.get_header("Authorization"))
            with self.assertRaises(hf.HfSecurityError):
                hf._SafeRedirectHandler().redirect_request(req, None, 302, "Found", {}, "https://attacker.invalid/file")

    def test_unknown_onnx_runtime_refuses_instead_of_claiming_ready(self):
        with tempfile.TemporaryDirectory() as tmp:
            spec = self.spec(hf.CustomWeightsBackend)
            with self.assertRaisesRegex(RuntimeError, "No runtime"):
                spec.backend(spec, tmp).load()

    def test_download_checks_free_disk_space_and_cleans_stale_parts(self):
        with tempfile.TemporaryDirectory() as tmp:
            spec = self.spec(hf.CustomWeightsBackend)
            backend = spec.backend(spec, tmp)
            stale = backend.dir / "old.part"
            stale.parent.mkdir(parents=True, exist_ok=True)
            stale.write_bytes(b"partial")
            fake_usage = mock.Mock(free=1024)
            with mock.patch("shutil.disk_usage", return_value=fake_usage):
                with self.assertRaisesRegex(RuntimeError, "disk space"):
                    backend.download(lambda p: None)
            self.assertFalse(stale.exists())


if __name__ == "__main__":
    unittest.main()
