"""The runtime on Linux arm64, where normalizer-tr cannot be installed."""
import importlib
import pathlib
import re
import sys
import unittest
from unittest import mock

REQUIREMENTS = pathlib.Path(__file__).resolve().parents[1] / "requirements.txt"


class Arm64RuntimeTests(unittest.TestCase):
    def test_normalizer_is_not_required_on_arm64(self):
        # One package without a wheel stopped every model from installing.
        line = next(l for l in REQUIREMENTS.read_text().splitlines() if l.startswith("normalizer-tr"))
        self.assertRegex(line, r';\s*platform_machine\s*!=\s*"aarch64"$')

    def test_only_normalizer_is_left_out(self):
        marked = [l for l in REQUIREMENTS.read_text().splitlines()
                  if re.match(r"^[a-z0-9]", l) and ";" in l]
        self.assertEqual(len(marked), 1)

    def test_ema_refuses_clearly_without_the_normalizer(self):
        # Without it digits are dropped from the speech, so EMA must not load.
        stubs = {"numpy": mock.MagicMock(), "onnxruntime": mock.MagicMock(), "normalizer_tr": None}
        sys.modules.pop("aipbx_ai.ema_engine", None)
        with mock.patch.dict(sys.modules, stubs):
            with self.assertRaisesRegex(ImportError, "Piper"):
                importlib.import_module("aipbx_ai.ema_engine")
        sys.modules.pop("aipbx_ai.ema_engine", None)


if __name__ == "__main__":
    unittest.main()
