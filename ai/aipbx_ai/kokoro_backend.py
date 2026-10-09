"""Kokoro-82M voices (engines/kokoro.py) as models of the local AI service.

Every catalogue entry is one voice of the Hugging Face repository
onnx-community/Kokoro-82M-v1.0-ONNX at a pinned revision. All voices run on
the same model file, so it is stored once:

    $AIPBX_AI_DATA/models/_shared/kokoro/<sha256>.onnx    the model (shared)
    $AIPBX_AI_DATA/models/<id>/voice.bin                  the voice pack
    $AIPBX_AI_DATA/models/<id>/tokenizer.json             the phoneme vocabulary
    $AIPBX_AI_DATA/models/<id>/model.ref                  SHA-256 of the model it uses

A voice's model.ref is written before the model is fetched and removed with
the voice; the model file is deleted when no model.ref names it any more.
Both steps hold one lock, so a voice that is removed while another one is
being downloaded never takes the model away from it. The disk use of the
shared file is counted once: for the installed voice with the smallest id
that uses it (the portal's total stays right; that voice shows the model's
size, the others only their own few hundred kilobytes).

In memory the model is shared as well: the voices that run use one ONNX
Runtime session (about 650 MB with the fp32 model), each adds only its voice
pack (0.5 MB). The session is dropped when the last of them stops.

Every file is checked against its SHA-256 after the download and before
every load. Phonemes come from the espeak-ng program (installed with the
runtime).
"""
import gc
import hashlib
import os
import shutil
import threading
import urllib.request
from pathlib import Path

REPO_URL = "https://huggingface.co/onnx-community/Kokoro-82M-v1.0-ONNX/resolve/"
SHARED_DIR = Path("_shared") / "kokoro"
WARMUP_TEXT = "Hello."
VOICE_FILE, CONFIG_FILE, REF_FILE = "voice.bin", "tokenizer.json", "model.ref"

_shared_lock = threading.Lock()   # model.ref files and the shared model file
_engine_lock = threading.Lock()   # _engines
_engines = {}                     # model sha -> {"voice": KokoroVoice, "users": {model ids}}


def _sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


class KokoroBackend:
    """spec.source = {"revision": ..., "voice_name": "af_heart",
    "model"|"voice"|"config": {"path": <path in repo>, "sha256": ..., "bytes": ...}}."""

    def __init__(self, spec, data_dir):
        self.spec = spec
        self.models_dir = Path(data_dir) / "models"
        self.dir = self.models_dir / spec.id
        self.shared_dir = self.models_dir / SHARED_DIR
        self._voice = None
        self._np = None

    # ---- files -----------------------------------------------------------

    @property
    def model_sha(self):
        return self.spec.source["model"]["sha256"]

    @property
    def model_path(self):
        return self.shared_dir / f"{self.model_sha}.onnx"

    def _own_files(self):
        """local name -> source entry, for the files in the voice's folder."""
        src = self.spec.source
        return {VOICE_FILE: src["voice"], CONFIG_FILE: src["config"]}

    def _users(self, sha):
        """Ids of the voices whose model.ref names this model (caller may hold the lock)."""
        users = []
        try:
            entries = list(self.models_dir.iterdir())
        except OSError:
            return users
        for d in entries:
            try:
                if d.name != SHARED_DIR.parts[0] and (d / REF_FILE).read_text().strip() == sha:
                    users.append(d.name)
            except OSError:
                continue
        return sorted(users)

    def installed(self):
        return (all((self.dir / n).is_file() for n in self._own_files())
                and (self.dir / REF_FILE).is_file() and self.model_path.is_file())

    def disk_bytes(self):
        """Own files, plus the shared model for the first installed voice using it."""
        total = sum((self.dir / n).stat().st_size for n in (*self._own_files(), REF_FILE)
                    if (self.dir / n).is_file())
        if self.installed() and self.model_path.is_file():
            first = next((u for u in self._users(self.model_sha)
                          if (self.models_dir / u / VOICE_FILE).is_file()), None)
            if first == self.spec.id:
                total += self.model_path.stat().st_size
        return total

    def _fetch(self, entry, target, progress, done, total):
        """Downloads one file to target (checked); returns the bytes counted so far."""
        if target.is_file() and _sha256(target) == entry["sha256"]:
            return done + target.stat().st_size
        part = target.with_name(f"{target.name}.{self.spec.id}.part")
        url = REPO_URL + self.spec.source["revision"] + "/" + entry["path"]
        req = urllib.request.Request(url, headers={"User-Agent": "aipbx-ai"})
        try:
            with urllib.request.urlopen(req, timeout=60) as resp, open(part, "wb") as out:
                while block := resp.read(1 << 16):
                    out.write(block)
                    done += len(block)
                    progress(min(99, 100 * done // total))
            got = _sha256(part)
            if got != entry["sha256"]:
                raise RuntimeError(f"{entry['path'].rsplit('/', 1)[-1]}: checksum mismatch ({got[:12]}…), not loaded")
            os.replace(part, target)
        finally:
            part.unlink(missing_ok=True)
        return done

    def download(self, progress):
        src = self.spec.source
        self.dir.mkdir(parents=True, exist_ok=True)
        total = max(1, sum(e["bytes"] for e in (src["model"], src["voice"], src["config"])))
        done = 0
        with _shared_lock:
            # Claim the model first: a parallel removal of another voice keeps it.
            (self.dir / REF_FILE).write_text(self.model_sha + "\n")
            self.shared_dir.mkdir(parents=True, exist_ok=True)
            done = self._fetch(src["model"], self.model_path, progress, done, total)
        for name, entry in self._own_files().items():
            done = self._fetch(entry, self.dir / name, progress, done, total)
        progress(100)

    def delete_files(self):
        self.unload()
        with _shared_lock:
            shutil.rmtree(self.dir, ignore_errors=True)
            if not self._users(self.model_sha):
                self.model_path.unlink(missing_ok=True)
                for leftover in self.shared_dir.glob(f"{self.model_sha}.onnx.*.part"):
                    leftover.unlink(missing_ok=True)
                for d in (self.shared_dir, self.shared_dir.parent):
                    try:
                        d.rmdir()   # only when empty
                    except OSError:
                        pass

    # ---- memory ----------------------------------------------------------

    def load(self):
        import numpy as np

        from .engines.kokoro import KokoroVoice
        from .engines.piper import espeak_available

        if not espeak_available():
            raise RuntimeError("espeak-ng is not installed (install the runtime again)")
        checks = [(self.model_path, self.model_sha)]
        checks += [(self.dir / n, e["sha256"]) for n, e in self._own_files().items()]
        for path, sha in checks:
            if not path.is_file() or _sha256(path) != sha:
                raise RuntimeError(f"{path.name}: missing or checksum mismatch, download the model again")
        name = self.spec.source["voice_name"]
        pack = str(self.dir / VOICE_FILE)
        with _engine_lock:
            shared = _engines.get(self.model_sha)
            if shared is None:
                # The voice pack is named after the voice (its prefix selects the dialect).
                voice = KokoroVoice(str(self.model_path), {name: pack}, str(self.dir / CONFIG_FILE))
                shared = _engines[self.model_sha] = {"voice": voice, "users": set()}
            else:
                shared["voice"].add_voice(name, pack)
            shared["users"].add(self.spec.id)
            self._np, self._voice = np, shared["voice"]
        try:
            self._voice.say(WARMUP_TEXT, sample_rate=8000, voice=name)
        except Exception:
            self.unload()
            raise

    def unload(self):
        if self._voice is None:
            return
        with _engine_lock:
            shared = _engines.get(self.model_sha)
            if shared is not None:
                shared["users"].discard(self.spec.id)
                if shared["users"]:
                    shared["voice"].remove_voice(self.spec.source["voice_name"])
                else:
                    del _engines[self.model_sha]
            self._voice = None
        gc.collect()

    # ---- inference -------------------------------------------------------

    def synthesize(self, text, speed, sample_rate, on_first_audio=None):
        voice, np = self._voice, self._np
        if voice is None:
            raise RuntimeError("model not loaded")
        audio = voice.say(text, speed=speed, sample_rate=sample_rate, on_first_audio=on_first_audio,
                          voice=self.spec.source["voice_name"])
        return (np.clip(audio, -1.0, 1.0) * 32767.0).round().astype("<i2").tobytes()
