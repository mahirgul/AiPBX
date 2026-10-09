"""Piper voices (engines/piper.py) as models of the local AI service.

Each catalogue entry names one voice of the Hugging Face repository
rhasspy/piper-voices at a pinned revision, with the SHA-256 of its two files
(`<voice>.onnx` and `<voice>.onnx.json`). They are downloaded into
$AIPBX_AI_DATA/models/<id>/ as voice.onnx and voice.onnx.json and checked
before every load. Phonemes come from the espeak-ng program (apt package,
installed with the runtime).
"""
import gc
import hashlib
import os
import shutil
import urllib.request
from pathlib import Path

REPO_URL = "https://huggingface.co/rhasspy/piper-voices/resolve/"
WARMUP_TEXT = "Hallo."
LOCAL_NAMES = {".onnx": "voice.onnx", ".onnx.json": "voice.onnx.json"}


def _sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def _local_name(remote):
    return LOCAL_NAMES[".onnx.json"] if remote.endswith(".onnx.json") else LOCAL_NAMES[".onnx"]


class PiperBackend:
    """spec.source = {"revision": ..., "files": {"<path in repo>": "<sha256>", ...}, "speaker": optional}."""

    def __init__(self, spec, data_dir):
        self.spec = spec
        self.dir = Path(data_dir) / "models" / spec.id
        self._voice = None
        self._np = None

    def _files(self):
        return {_local_name(remote): (remote, sha) for remote, sha in self.spec.source["files"].items()}

    def installed(self):
        return all((self.dir / name).is_file() for name in self._files())

    def disk_bytes(self):
        return sum((self.dir / n).stat().st_size for n in self._files() if (self.dir / n).is_file())

    def download(self, progress):
        self.dir.mkdir(parents=True, exist_ok=True)
        total = max(1, self.spec.download_mb * 1024 * 1024)
        done = 0
        base = REPO_URL + self.spec.source["revision"] + "/"
        for name, (remote, sha) in self._files().items():
            target = self.dir / name
            if target.is_file() and _sha256(target) == sha:
                done += target.stat().st_size
                continue
            part = self.dir / (name + ".part")
            req = urllib.request.Request(base + remote, headers={"User-Agent": "aipbx-ai"})
            with urllib.request.urlopen(req, timeout=60) as resp, open(part, "wb") as out:
                while block := resp.read(1 << 16):
                    out.write(block)
                    done += len(block)
                    progress(min(99, 100 * done // total))
            got = _sha256(part)
            if got != sha:
                part.unlink(missing_ok=True)
                raise RuntimeError(f"{remote.rsplit('/', 1)[-1]}: checksum mismatch ({got[:12]}…), not loaded")
            os.replace(part, target)
        progress(100)

    def delete_files(self):
        self.unload()
        shutil.rmtree(self.dir, ignore_errors=True)

    def load(self):
        import numpy as np

        from .engines.piper import PiperVoice, espeak_available

        if not espeak_available():
            raise RuntimeError("espeak-ng is not installed (install the runtime again)")
        for name, (_remote, sha) in self._files().items():
            if _sha256(self.dir / name) != sha:
                raise RuntimeError(f"{name}: checksum mismatch, download the model again")
        voice = PiperVoice(str(self.dir / "voice.onnx"), str(self.dir / "voice.onnx.json"))
        voice.say(WARMUP_TEXT, sample_rate=8000, speaker=self.spec.source.get("speaker"))
        self._np, self._voice = np, voice

    def unload(self):
        if self._voice is not None:
            self._voice = None
            gc.collect()

    def synthesize(self, text, speed, sample_rate, on_first_audio=None):
        voice, np = self._voice, self._np
        if voice is None:
            raise RuntimeError("model not loaded")
        audio = voice.say(text, speed=speed, sample_rate=sample_rate, on_first_audio=on_first_audio,
                          speaker=self.spec.source.get("speaker"))
        return (np.clip(audio, -1.0, 1.0) * 32767.0).round().astype("<i2").tobytes()
