"""Download cancel of a local model and the built-in knowledge lookup of a conversation."""
import json
import os
import sys
import tempfile
import threading
import time
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai import cloud_llm, conversation, secret_refs  # noqa: E402
from aipbx_ai.models import ABSENT, DOWNLOADING, ERROR, ModelBusy, ModelManager, ModelSpec  # noqa: E402
from test_server import SPEC, FakeBackend  # noqa: E402


class CancelTest(unittest.TestCase):
    def test_cancel_deletes_partial_files(self):
        backends = {}

        def factory(spec):
            backends[spec.id] = FakeBackend(spec)
            return backends[spec.id]

        manager = ModelManager((SPEC,), "/nonexistent", backend_factory=factory)
        fake = backends[SPEC.id]
        fake.download_gate.clear()
        self.assertEqual(manager.install(SPEC.id), DOWNLOADING)
        self.assertEqual(manager.cancel(SPEC.id), DOWNLOADING)
        fake.download_gate.set()
        for _ in range(100):
            if manager.state(SPEC.id) == ABSENT:
                break
            time.sleep(0.02)
        self.assertEqual(manager.state(SPEC.id), ABSENT)
        self.assertFalse(fake.files)
        self.assertIsNone(manager.describe(SPEC.id)["error"])
        with self.assertRaises(ModelBusy):      # nothing is downloading any more
            manager.cancel(SPEC.id)

    def test_cancel_after_progress_100_before_loading(self):
        post_100_gate = threading.Event()
        reached_100 = threading.Event()
        loaded = []

        class RaceBackend(FakeBackend):
            def download(self, progress):
                self.files = True
                progress(100)
                reached_100.set()
                post_100_gate.wait(timeout=5)

            def load(self):
                loaded.append(True)
                super().load()

        fake = RaceBackend(SPEC)
        manager = ModelManager((SPEC,), "/nonexistent", backend_factory=lambda s: fake)
        self.assertEqual(manager.install(SPEC.id), DOWNLOADING)
        self.assertTrue(reached_100.wait(timeout=5))
        self.assertEqual(manager.cancel(SPEC.id), DOWNLOADING)
        post_100_gate.set()
        for _ in range(100):
            if manager.state(SPEC.id) == ABSENT:
                break
            time.sleep(0.02)
        self.assertEqual(manager.state(SPEC.id), ABSENT)
        self.assertFalse(loaded)
        self.assertFalse(fake.files)

    def test_cancel_handles_delete_files_runtime_error(self):
        custom_spec = ModelSpec(
            id="custom-llm", title="Custom LLM", kind="tts", languages=("en",),
            license="MIT", license_url="https://example.com", homepage="https://example.com",
            download_mb=1, backend=FakeBackend, custom=True,
        )

        class BrokenDeleteBackend(FakeBackend):
            def delete_files(self):
                raise RuntimeError("backend gone")

        fake = BrokenDeleteBackend(custom_spec)
        fake.download_gate.clear()
        manager = ModelManager((custom_spec,), "/nonexistent", backend_factory=lambda s: fake)
        self.assertEqual(manager.install(custom_spec.id), DOWNLOADING)
        self.assertEqual(manager.cancel(custom_spec.id), DOWNLOADING)
        fake.download_gate.set()
        for _ in range(100):
            if not manager.has(custom_spec.id):
                break
            time.sleep(0.02)
        self.assertFalse(manager.has(custom_spec.id))

    def test_download_error_cleans_up_partial_files(self):
        deleted = []

        class FailingDownloadBackend(FakeBackend):
            def download(self, progress):
                self.files = True
                raise OSError("network drop")

            def delete_files(self):
                deleted.append(True)
                super().delete_files()

        fake = FailingDownloadBackend(SPEC)
        manager = ModelManager((SPEC,), "/nonexistent", backend_factory=lambda s: fake)
        manager.install(SPEC.id)
        for _ in range(100):
            if manager.state(SPEC.id) == ERROR:
                break
            time.sleep(0.02)
        self.assertEqual(manager.state(SPEC.id), ERROR)
        self.assertTrue(deleted)
        self.assertFalse(fake.files)

    def test_remove_ignores_unload_and_delete_runtime_error(self):
        custom_spec = ModelSpec(
            id="custom-rm", title="Custom RM", kind="tts", languages=("en",),
            license="MIT", license_url="https://example.com", homepage="https://example.com",
            download_mb=1, backend=FakeBackend, custom=True,
        )

        class BrokenRemoveBackend(FakeBackend):
            def unload(self):
                raise RuntimeError("backend unavailable")

            def delete_files(self):
                raise RuntimeError("backend gone")

        fake = BrokenRemoveBackend(custom_spec)
        fake.files = True
        manager = ModelManager((custom_spec,), "/nonexistent", backend_factory=lambda s: fake)
        self.assertEqual(manager.remove(custom_spec.id), ABSENT)
        self.assertFalse(manager.has(custom_spec.id))


CFG = {"llm_cloud": {"provider": "openai", "api_key": "sk-test-1234567890", "model": "gpt-4.1-mini"},
       "intents": [{"id": "sales", "name": "Sales", "keywords": ["price"], "examples": []}],
       "instructions": "", "tools": [], "knowledge": True}


class KnowledgeToolTest(unittest.TestCase):
    def test_validate(self):
        self.assertTrue(conversation.validate({"knowledge": True}, lambda u: None)["knowledge"])
        self.assertFalse(conversation.validate({"knowledge": "yes"}, lambda u: None)["knowledge"])
        with self.assertRaises(conversation.ConversationError):
            conversation.validate({"knowledge": True, "tools": [
                {"name": "knowledge", "description": "d", "url": "https://h.example.com"}]}, lambda u: None)

    def test_prompt_and_step(self):
        self.assertIn('"knowledge"', conversation.prompt(CFG, [], "en"))
        self.assertNotIn('"knowledge"', conversation.prompt(dict(CFG, knowledge=False), [], "en"))
        raw = json.dumps({"action": "lookup", "tool": "knowledge", "query": "opening hours"})
        self.assertEqual(conversation.parse_step(raw, CFG)["tool"], "knowledge")
        with self.assertRaises(cloud_llm.CloudLlmError):
            conversation.parse_step(raw, dict(CFG, knowledge=False))

    def test_resolver_unavailable(self):
        os.environ["AIPBX_AI_RESOLVER_SOCKET"] = "/nonexistent/resolve.sock"
        try:
            with self.assertRaises(ValueError):
                secret_refs.knowledge(1, "opening hours", 3)
        finally:
            del os.environ["AIPBX_AI_RESOLVER_SOCKET"]


if __name__ == "__main__":
    unittest.main()
