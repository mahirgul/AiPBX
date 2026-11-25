import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

try:
    import numpy as np
except ImportError:                    # the Local AI runtime brings numpy; the gateway alone does not
    np = None

from aipbx_ai import onnx_models  # noqa: E402  (imports without numpy: it is loaded on first use)
from aipbx_ai.models import REGISTRY  # noqa: E402


class CatalogueTest(unittest.TestCase):
    def test_detectors_are_pinned_catalogue_entries(self):
        specs = {s.id: s for s in REGISTRY if s.kind in ("vad", "turn")}
        self.assertEqual(sorted(specs), ["silero-vad", "smart-turn-v3"])
        for spec in specs.values():
            for name, info in spec.source["files"].items():
                self.assertRegex(spec.source["revision"], r"^[0-9a-f]{40}$")     # no branch names
                self.assertRegex(info["sha256"], r"^[0-9a-f]{64}$")
                self.assertGreater(info["bytes"], 1_000_000)
            self.assertTrue(spec.commercial)
        self.assertEqual((specs["silero-vad"].license, specs["smart-turn-v3"].license), ("MIT", "BSD-2-Clause"))


@unittest.skipIf(np is None, "numpy is not installed")
class FeaturesTest(unittest.TestCase):
    def test_log_mel_matches_the_reference_implementation(self):
        # Values taken from Pipecat's own numpy implementation of the Whisper features for this signal.
        t = np.arange(16000 * 3) / 16000
        sig = (0.3 * np.sin(2 * np.pi * (200 + 300 * t) * t) + 0.05 * np.sin(2 * np.pi * 3137 * t)
               + 0.02 * np.sin(1.7e4 * t * t)).astype(np.float32)
        feats = onnx_models.log_mel(sig)
        self.assertEqual(feats.shape, (80, 800))
        self.assertAlmostEqual(float(feats.mean()), -0.10816078633069992, places=4)
        self.assertAlmostEqual(float(feats.std()), 0.34667932987213135, places=4)
        for (row, col), expected in {(10, 700): -0.21891438961029053, (40, 790): 0.4766182005405426,
                                     (79, 799): 0.03866725042462349}.items():
            self.assertAlmostEqual(float(feats[row, col]), expected, places=3)

    def test_audio_longer_or_shorter_than_8_seconds(self):
        self.assertEqual(onnx_models.log_mel(np.zeros(100, np.float32) + 0.1).shape, (80, 800))
        self.assertEqual(onnx_models.log_mel(np.ones(20 * 16000, np.float32) * 0.1).shape, (80, 800))

    def test_widen_doubles_the_rate(self):
        pcm = np.array([0, 1000, -1000, 2000], dtype="<i2").tobytes()
        out = onnx_models.widen(pcm)
        self.assertEqual(len(out), 8)
        self.assertAlmostEqual(float(out[1]), 500 / 32768, places=5)          # between 0 and 1000
        self.assertEqual(len(onnx_models.widen(b"\x01")), 0)

    def test_silero_stream_windows_and_state(self):
        calls = []

        class Session:
            def run(self, _outputs, feed):
                calls.append(feed["input"].shape)
                return [np.array([[0.7]], np.float32), feed["state"] + 1]

        stream = onnx_models.SileroStream(Session())
        self.assertEqual(stream.feed(np.zeros(160, "<i2").tobytes()), 0.0)     # 160 samples: not a window yet
        self.assertAlmostEqual(stream.feed(np.zeros(160, "<i2").tobytes()), 0.7, places=5)   # 320 samples: one window of 256
        self.assertEqual(calls, [(1, 288)])                                    # 32 context + 256 new
        self.assertEqual(float(stream.state.max()), 1.0)                       # the recurrent state is carried on


if __name__ == "__main__":
    unittest.main()
