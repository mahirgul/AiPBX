"""EMA Lightning backend: Turkish text-to-speech on ONNX Runtime (ema_engine.py).

The model files are the ONNX export published as the AiPBX release
`models-ema-lightning-1` (34 MB, Apache-2.0; NOTICE there). They are
downloaded once into $AIPBX_AI_DATA/models/ema-lightning and checked against
the SHA-256 sums below, so a changed file is never loaded.

Versions up to 1.9.1 ran the PyTorch package from the Hugging Face cache
($AIPBX_AI_DATA/hf). A server that had that model (its licence accepted)
gets the ONNX files automatically, and the old cache is removed.
"""
import gc
import hashlib
import os
import shutil
import urllib.request
from pathlib import Path

RELEASE = "https://github.com/mahirgul/AiPBX/releases/download/models-ema-lightning-1/"
FILES = {
    "text.onnx": "b832ac6d0a54f822a80765797053b315b31d73f8cfbdaf5347dd1865ff3c47a3",
    "sound.onnx": "17e311a817e8b679a779772fd12c73f518d134a4cfaa3eb910965a82cbf1e38d",
    "decoder.onnx": "19ea56dcfea380c3b266bd23c8e23ab8e62328a2f82cad21393d0cfdd42673ce",
    "meta.json": "9619dc032ff941fc966c6bcb502282cfdf54c2e4005a036c9712fac85d54522d",
}
WARMUP_TEXT = "Merhaba."
LEGACY_REPO_DIR = "hf/hub/models--canberkkkkkk--ema-lightning"


def _sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


class EmaLightningBackend:
    def __init__(self, spec, data_dir):
        self.spec = spec
        self.data_dir = Path(data_dir)
        self.dir = self.data_dir / "models" / spec.id
        self._tts = None
        self._np = None

    # ---- files -----------------------------------------------------------

    def installed(self):
        return all((self.dir / name).is_file() for name in FILES)

    def disk_bytes(self):
        return sum((self.dir / n).stat().st_size for n in FILES if (self.dir / n).is_file())

    def migration_pending(self):
        """The PyTorch version of this model was installed (1.9.x): fetch the ONNX files."""
        return not self.installed() and (self.data_dir / LEGACY_REPO_DIR).exists()

    def _remove_legacy(self):
        for sub in ("hf", "cache"):
            shutil.rmtree(self.data_dir / sub, ignore_errors=True)

    def download(self, progress):
        self.dir.mkdir(parents=True, exist_ok=True)
        total = max(1, self.spec.download_mb * 1024 * 1024)
        done = 0
        for name, sha in FILES.items():
            target = self.dir / name
            if target.is_file() and _sha256(target) == sha:
                done += target.stat().st_size
                continue
            part = self.dir / (name + ".part")
            req = urllib.request.Request(RELEASE + name, headers={"User-Agent": "aipbx-ai"})
            with urllib.request.urlopen(req, timeout=60) as resp, open(part, "wb") as out:
                while block := resp.read(1 << 16):
                    out.write(block)
                    done += len(block)
                    progress(min(99, 100 * done // total))
            got = _sha256(part)
            if got != sha:
                part.unlink(missing_ok=True)
                raise RuntimeError(f"{name}: checksum mismatch ({got[:12]}…), not loaded")
            os.replace(part, target)
        self._remove_legacy()
        progress(100)

    def delete_files(self):
        self.unload()
        shutil.rmtree(self.dir, ignore_errors=True)
        self._remove_legacy()

    # ---- memory ----------------------------------------------------------

    def load(self):
        import numpy as np

        from .ema_engine import EmaOnnx

        for name, sha in FILES.items():
            if _sha256(self.dir / name) != sha:
                raise RuntimeError(f"{name}: checksum mismatch, download the model again")
        tts = EmaOnnx(str(self.dir))
        tts.say(WARMUP_TEXT, sample_rate=8000, seed=0)
        self._np, self._tts = np, tts

    def unload(self):
        if self._tts is not None:
            self._tts = None
            gc.collect()

    # ---- inference -------------------------------------------------------

    def synthesize(self, text, speed, sample_rate, on_first_audio=None):
        tts, np = self._tts, self._np
        if tts is None:
            raise RuntimeError("model not loaded")
        audio = tts.say(text, speed=speed, sample_rate=sample_rate, on_first_audio=on_first_audio)
        return (np.clip(audio, -1.0, 1.0) * 32767.0).round().astype("<i2").tobytes()
