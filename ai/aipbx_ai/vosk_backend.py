"""Vosk speech-to-text models (Kaldi, Apache-2.0) for the local AI service.

The small models (Turkish 36 MB, German 46 MB, English 41 MB) come as zip
files from alphacephei.com, pinned by SHA-256. The zip is checked, unpacked
into $AIPBX_AI_DATA/models/<id>/model (no path outside that folder accepted)
and deleted. The `vosk` package (pip, Apache-2.0) recognises 16 kHz 16-bit
mono audio; 8 kHz telephone audio is upsampled first.
"""
import gc
import hashlib
import io
import json
import os
import shutil
import urllib.request
import wave
import zipfile
from pathlib import Path

BASE_URL = "https://alphacephei.com/vosk/models/"
RATE = 16000
MAX_SECONDS = 60


def _sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def _tree_bytes(path):
    return sum(f.stat().st_size for f in Path(path).rglob("*") if f.is_file())


def pcm16_from_wav(data):
    """(16-bit mono PCM at 16 kHz, seconds) from WAV bytes (8/16/22.05/24/44.1/48 kHz, mono or stereo)."""
    import numpy as np

    try:
        w = wave.open(io.BytesIO(data))
    except (wave.Error, EOFError) as e:
        raise ValueError(f"not a WAV file: {e}") from None
    if w.getsampwidth() != 2:
        raise ValueError("WAV must be 16-bit PCM")
    rate, channels, frames = w.getframerate(), w.getnchannels(), w.getnframes()
    if not 8000 <= rate <= 48000 or channels not in (1, 2):
        raise ValueError("unsupported WAV format")
    if frames / rate > MAX_SECONDS:
        raise ValueError(f"audio longer than {MAX_SECONDS} s")
    x = np.frombuffer(w.readframes(frames), dtype="<i2").astype(np.float32) / 32768.0
    if channels == 2:
        x = x.reshape(-1, 2).mean(axis=1)
    if rate != RATE:
        from .engines.piper import resample
        x = resample(x, rate, RATE)
    return (np.clip(x, -1.0, 1.0) * 32767.0).round().astype("<i2").tobytes(), frames / rate


class VoskBackend:
    """spec.source = {"file": "<name>.zip", "sha256": ...}."""

    def __init__(self, spec, data_dir):
        self.spec = spec
        self.dir = Path(data_dir) / "models" / spec.id
        self._model = None

    @property
    def model_dir(self):
        return self.dir / "model"

    def installed(self):
        return self.model_dir.is_dir() and any(self.model_dir.rglob("final.mdl"))

    def disk_bytes(self):
        return _tree_bytes(self.dir) if self.dir.exists() else 0

    def download(self, progress):
        self.dir.mkdir(parents=True, exist_ok=True)
        part = self.dir / "download.zip.part"
        total = max(1, self.spec.download_mb * 1024 * 1024)
        done = 0
        req = urllib.request.Request(BASE_URL + self.spec.source["file"], headers={"User-Agent": "aipbx-ai"})
        with urllib.request.urlopen(req, timeout=60) as resp, open(part, "wb") as out:
            while block := resp.read(1 << 16):
                out.write(block)
                done += len(block)
                progress(min(95, 95 * done // total))
        got = _sha256(part)
        if got != self.spec.source["sha256"]:
            part.unlink(missing_ok=True)
            raise RuntimeError(f"{self.spec.source['file']}: checksum mismatch ({got[:12]}…), not unpacked")
        staging = self.dir / "model.new"
        shutil.rmtree(staging, ignore_errors=True)
        staging.mkdir()
        root = staging.resolve()
        with zipfile.ZipFile(part) as z:
            for info in z.infolist():
                # Drop the top folder of the zip; refuse anything leaving the target.
                rel = Path(*Path(info.filename).parts[1:])
                if not rel.parts:
                    continue
                target = (staging / rel).resolve()
                if root not in target.parents:
                    raise RuntimeError("the model archive contains an unsafe path")
                if info.is_dir():
                    target.mkdir(parents=True, exist_ok=True)
                else:
                    target.parent.mkdir(parents=True, exist_ok=True)
                    with z.open(info) as src, open(target, "wb") as dst:
                        shutil.copyfileobj(src, dst)
        part.unlink(missing_ok=True)
        shutil.rmtree(self.model_dir, ignore_errors=True)
        os.replace(staging, self.model_dir)
        progress(100)

    def delete_files(self):
        self.unload()
        shutil.rmtree(self.dir, ignore_errors=True)

    def load(self):
        from vosk import Model, SetLogLevel

        SetLogLevel(-1)
        self._model = Model(str(self.model_dir))

    def unload(self):
        if self._model is not None:
            self._model = None
            gc.collect()

    def transcribe(self, wav):
        """{"text": ..., "seconds": audio length} from WAV bytes."""
        from vosk import KaldiRecognizer

        model = self._model
        if model is None:
            raise RuntimeError("model not loaded")
        pcm, seconds = pcm16_from_wav(wav)
        rec = KaldiRecognizer(model, RATE)
        rec.AcceptWaveform(pcm)
        return {"text": json.loads(rec.FinalResult()).get("text", ""), "seconds": round(seconds, 2)}
