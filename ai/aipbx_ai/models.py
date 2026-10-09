"""Model registry and the state machine of every model.

A model is a ModelSpec (what the portal shows) plus a backend class that
knows how to download, load, run and delete it. Adding a model means adding
a ModelSpec to REGISTRY and, for a new kind of model, a backend class with
the same methods as ema.EmaLightningBackend:

    installed() -> bool          all files are on disk
    disk_bytes() -> int          bytes the model's files use
    download(progress)           fetch the files; progress(percent 0..100)
    load()                       load into memory (slow; runs in a thread)
    unload()                     drop it from memory
    delete_files()               remove the files from disk
    synthesize(text, speed, sample_rate, on_first_audio=None) -> bytes
                                 (kind "tts" only) 16-bit LE mono PCM

States: absent -> downloading -> loading -> ready. "installed" = files on
disk, not in memory (stopped); "error" keeps the last failure until the next
install. An administrator runs (load) and stops (unload) installed models;
the choice is kept in $AIPBX_AI_DATA/state.json, so a restart brings back the
models that were running. Downloads stop at the disk limit
(AIPBX_AI_DISK_LIMIT_MB, default 5120).
Each model runs one job at a time (its lock); at most MAX_WAITING requests
may wait for it, more get ServiceBusy (HTTP 503).
"""
import json
import logging
import os
import threading
import time
from dataclasses import dataclass, field
from pathlib import Path

from . import sysinfo
from .ema import EmaLightningBackend
from .kokoro_backend import KokoroBackend
from .piper_backend import PiperBackend

log = logging.getLogger("aipbx_ai.models")

MAX_WAITING = 4

ABSENT, DOWNLOADING, INSTALLED, LOADING, READY, ERROR = (
    "absent", "downloading", "installed", "loading", "ready", "error")


@dataclass(frozen=True)
class ModelSpec:
    id: str
    title: str
    kind: str              # "tts" now; "embedding" later
    languages: tuple
    license: str
    license_url: str
    homepage: str
    download_mb: int
    backend: type
    engine: str = "ema"
    commercial: bool = True     # the licence allows commercial use
    # Our own measurement on a 2-core server (shown as the recommendation).
    measured: dict = field(default_factory=dict)
    note: str = ""              # licence or quality remark shown with the model
    gender: str = ""            # voice models: "female" / "male" / ""
    source: dict = field(default_factory=dict)   # backend-specific download details


# Piper voices: rhasspy/piper-voices at a pinned revision. Licences differ per
# voice (MODEL_CARD); a voice fine-tuned from a research or non-commercial
# voice is marked commercial=False.
PIPER_REVISION = "c10ece1aade47bb51c153c893d14e5bf8e5b7117"
PIPER_LICENSE_URL = "https://huggingface.co/rhasspy/piper-voices/blob/c10ece1aade47bb51c153c893d14e5bf8e5b7117/"


def _piper(id, title, path, onnx_sha, json_sha, mb, languages, license, commercial, gender="", note="",
           measured=None, speaker=None):
    name = path.split("/")
    voice = f"{name[1]}-{name[2]}-{name[3]}"
    source = {"revision": PIPER_REVISION,
              "files": {f"{path}/{voice}.onnx": onnx_sha, f"{path}/{voice}.onnx.json": json_sha}}
    if speaker is not None:
        source["speaker"] = speaker
    return ModelSpec(id=id, title=title, kind="tts", languages=languages, license=license,
                     license_url=PIPER_LICENSE_URL + path + "/MODEL_CARD",
                     homepage="https://github.com/rhasspy/piper", download_mb=mb, backend=PiperBackend,
                     engine="piper", commercial=commercial, measured=measured or {}, note=note,
                     gender=gender, source=source)


# Kokoro-82M voices: onnx-community/Kokoro-82M-v1.0-ONNX at a pinned revision.
# Model and voices are Apache-2.0 (hexgrad/Kokoro-82M; only the Japanese and
# French voices were trained with CC BY audio, VOICES.md). Every voice uses the
# same model file, stored once (kokoro_backend.py), so download_mb is the size
# for the first Kokoro voice; further voices add about half a megabyte.
KOKORO_REVISION = "1939ad2a8e416c0acfeecc08a694d14ef25f2231"
# fp32: on a 2-core CPU the quantized exports are no faster and differ audibly
# from it (docs/local-ai.md); fp16 gives NaN on the CPU.
KOKORO_MODEL = {"path": "onnx/model.onnx",
                "sha256": "8fbea51ea711f2af382e88c833d9e288c6dc82ce5e98421ea61c058ce21a34cb", "bytes": 325532232}
KOKORO_CONFIG = {"path": "tokenizer.json",
                 "sha256": "77a02c8e164413299b4b4c403b14f8e0e1c1b727db4d46a09d6327b861060a34", "bytes": 3497}
KOKORO_LICENSE_URL = "https://huggingface.co/hexgrad/Kokoro-82M/blob/f3ff3571791e39611d31c381e3a41a3af07b4987/README.md"
KOKORO_SHARED_NOTE = ("Shares one model file and its memory with the other Kokoro voices. "
                      "Natural, but slow on a small server: fine for announcements, not for live calls.")


def _kokoro(id, title, voice, voice_sha, languages, gender, grade, measured=None):
    source = {"revision": KOKORO_REVISION, "voice_name": voice, "model": KOKORO_MODEL,
              "config": KOKORO_CONFIG, "voice": {"path": f"voices/{voice}.bin", "sha256": voice_sha, "bytes": 522240}}
    mb = -(-(KOKORO_MODEL["bytes"] + 522240 + KOKORO_CONFIG["bytes"]) // (1024 * 1024))
    return ModelSpec(id=id, title=title, kind="tts", languages=languages, license="Apache-2.0",
                     license_url=KOKORO_LICENSE_URL,
                     homepage="https://huggingface.co/hexgrad/Kokoro-82M", download_mb=mb, backend=KokoroBackend,
                     engine="kokoro", commercial=True, measured=measured or {},
                     note=f"Voice grade {grade} (VOICES.md). {KOKORO_SHARED_NOTE}", gender=gender, source=source)


REGISTRY = (
    ModelSpec(
        id="ema-lightning",
        title="EMA Lightning",
        kind="tts",
        languages=("tr",),
        license="Apache-2.0",
        license_url="https://github.com/mahirgul/AiPBX/releases/tag/models-ema-lightning-1",
        homepage="https://github.com/canberk7/ema-lightning",
        download_mb=34,
        backend=EmaLightningBackend,
        engine="ema",
        measured={"realtime_factor": 11, "memory_mb": 250, "load_s": 1.3},
        gender="female",
    ),
    _piper("piper-de-mls", "Piper MLS (German)", "de/de_DE/mls/medium",
           "69cd1d2aa5a35839a518966fcc4924b5f93e5f8c948ed0752b1a616ad53f65bf",
           "b0af1c89ddfdc72d32e015729b0e89b99eec13c2c8caa1db7488d98e9e570b40", 77, ("de-DE",),
           "CC-BY-4.0", True, note="Trained from scratch on Multilingual LibriSpeech (CC BY 4.0: name the source). 236 speakers.",
           measured={"realtime_factor": 13, "memory_mb": 146, "load_s": 2.0}),
    _piper("piper-de-thorsten", "Piper Thorsten (German)", "de/de_DE/thorsten/medium",
           "7e64762d8e5118bb578f2eea6207e1a35a8e0c30595010b666f983fc87bb7819",
           "974adee790533adb273a1ac88f49027d2a1b8f0f2cf4905954a4791e79264e85", 63, ("de-DE",),
           "CC0 data, model unclear", False, gender="male",
           note="Fine-tuned from the lessac voice, whose data allows research use only.",
           measured={"realtime_factor": 13.5, "memory_mb": 170, "load_s": 2.0}),
    _piper("piper-de-kerstin", "Piper Kerstin (German)", "de/de_DE/kerstin/low",
           "d352a7641892cebf2903859af94e9ba81a141110215fe3943bcda7f7da401b7a",
           "56e708556b7b9b7a53c4f8957e021421e69f11a600962bba554cffbe72cf2d47", 63, ("de-DE",),
           "CC0 data, model unclear", False, gender="female",
           note="Fine-tuned from the ryan voice (CC BY-NC-SA: no commercial use).",
           measured={"realtime_factor": 16.5, "memory_mb": 166, "load_s": 2.0}),
    _piper("piper-en-cori", "Piper Cori (English, UK)", "en/en_GB/cori/high",
           "470b4dd634c98f8a4850d7626ffc3dfc90774628eeef6605a6dd8f88f30a5903",
           "9e7fb5b5671612c22f3c81cbe46c1ae87b031a4632bcb509e499dad6f1e2adec", 115, ("en-GB",),
           "Public domain", True, gender="female",
           note="Slow on a small server (high quality): fine for announcements, not for live calls.",
           measured={"realtime_factor": 2.1, "memory_mb": 228, "load_s": 2.5}),
    # memory_mb: the shared model; further running Kokoro voices add almost nothing.
    _kokoro("kokoro-en-heart", "Kokoro Heart (English, US)", "af_heart",
            "d583ccff3cdca2f7fae535cb998ac07e9fcb90f09737b9a41fa2734ec44a8f0b", ("en-US",), "female", "A",
            measured={"realtime_factor": 1.7, "memory_mb": 460, "load_s": 1.6}),
    _kokoro("kokoro-en-michael", "Kokoro Michael (English, US)", "am_michael",
            "1d1f21dd8da39c30705cd4c75d039d265e9bc4a2a93ed09bc9e1b1225eb95ba1", ("en-US",), "male", "C+",
            measured={"realtime_factor": 1.75, "memory_mb": 460, "load_s": 1.6}),
    _kokoro("kokoro-en-emma", "Kokoro Emma (English, UK)", "bf_emma",
            "669fe0647f9dd04fcab92f1439a40eeb4c8b4ab1f82e4996fe3d918ce4a63b73", ("en-GB",), "female", "B-",
            measured={"realtime_factor": 1.65, "memory_mb": 460, "load_s": 1.6}),
    _kokoro("kokoro-en-george", "Kokoro George (English, UK)", "bm_george",
            "c4b235a4c1f2cd3b939fed08b899ce9385638b763f7b73a59616c4fc9bd6c9bc", ("en-GB",), "male", "C",
            measured={"realtime_factor": 1.65, "memory_mb": 460, "load_s": 1.6}),
)


class UnknownModel(Exception):
    pass


class NotReady(Exception):
    pass


class ModelBusy(Exception):
    """The model is downloading or loading; it cannot be removed now."""


class ServiceBusy(Exception):
    """Too many requests are waiting for this model."""


class WrongKind(Exception):
    pass


class DiskLimit(Exception):
    """Downloading the model would exceed the disk limit for models."""


class _Entry:
    def __init__(self, spec, backend):
        self.spec = spec
        self.backend = backend
        self.state = None        # None: derived from the disk (absent/installed)
        self.progress = 0
        self.error = None
        self.job_lock = threading.Lock()   # one synthesis/benchmark at a time
        self.pending = 0                   # running + waiting jobs
        self.memory_mb = 0                 # growth of the process when it was loaded


class ModelManager:
    def __init__(self, specs, data_dir, backend_factory=None, disk_limit_mb=None):
        make = backend_factory or (lambda spec: spec.backend(spec, data_dir))
        self._mu = threading.Lock()
        self._entries = {s.id: _Entry(s, make(s)) for s in specs}
        self._state_file = Path(data_dir) / "state.json"
        self._stopped = self._read_stopped()
        if disk_limit_mb is None:
            disk_limit_mb = int(os.environ.get("AIPBX_AI_DISK_LIMIT_MB", "5120") or 5120)
        self.disk_limit_mb = disk_limit_mb

    # ---- run/stop choice (survives restarts) -----------------------------

    def _read_stopped(self):
        try:
            data = json.loads(self._state_file.read_text())
            return set(x for x in data.get("stopped", []) if isinstance(x, str))
        except (OSError, ValueError, AttributeError):
            return set()

    def _write_stopped(self):
        try:
            tmp = self._state_file.with_suffix(".tmp")
            tmp.write_text(json.dumps({"stopped": sorted(self._stopped)}))
            os.replace(tmp, self._state_file)
        except OSError:
            log.warning("could not save %s", self._state_file)

    def _set_stopped(self, model_id, stopped):
        changed = (model_id in self._stopped) != stopped
        if stopped:
            self._stopped.add(model_id)
        else:
            self._stopped.discard(model_id)
        if changed:
            self._write_stopped()

    # ---- queries ---------------------------------------------------------

    def _entry(self, model_id):
        entry = self._entries.get(model_id) if isinstance(model_id, str) else None
        if entry is None:
            raise UnknownModel(model_id)
        return entry

    def _state(self, entry):
        """Caller holds self._mu."""
        if entry.state is not None:
            return entry.state
        return INSTALLED if entry.backend.installed() else ABSENT

    def state(self, model_id):
        entry = self._entry(model_id)
        with self._mu:
            return self._state(entry)

    def describe(self, model_id):
        entry = self._entry(model_id)
        spec = entry.spec
        with self._mu:
            state = self._state(entry)
            progress = entry.progress
            error = entry.error
        if state in (INSTALLED, LOADING, READY):
            progress = 100
        elif state == ABSENT:
            progress = 0
        try:
            disk_mb = round(entry.backend.disk_bytes() / (1024 * 1024))
        except OSError:
            disk_mb = 0
        return {
            "id": spec.id, "title": spec.title, "kind": spec.kind,
            "languages": list(spec.languages), "license": spec.license,
            "license_url": spec.license_url, "homepage": spec.homepage,
            "download_mb": spec.download_mb, "state": state,
            "progress": int(progress), "disk_mb": disk_mb,
            "error": error if state == ERROR else None,
            "engine": spec.engine, "commercial": spec.commercial, "measured": dict(spec.measured),
            "memory_mb": entry.memory_mb if state == READY else 0,
            "note": spec.note, "gender": spec.gender,
        }

    def describe_all(self):
        return [self.describe(i) for i in self._entries]

    def files_present(self, model_id):
        return self._entry(model_id).backend.installed()

    def disk(self):
        used = 0
        for entry in self._entries.values():
            try:
                used += entry.backend.disk_bytes()
            except OSError:
                pass
        return {"used_mb": round(used / (1024 * 1024)), "limit_mb": self.disk_limit_mb}

    # ---- lifecycle -------------------------------------------------------

    def start(self):
        """Loads every installed model in the background (service start).

        A model whose earlier format is still on disk (its licence accepted
        then) is downloaded again in the current format.
        """
        for entry in self._entries.values():
            pending = getattr(entry.backend, "migration_pending", None)
            if pending is not None and pending():
                log.info("%s: earlier model format found, downloading the current one", entry.spec.id)
                self.install(entry.spec.id)
                continue
            with self._mu:
                if self._state(entry) != INSTALLED or entry.spec.id in self._stopped:
                    continue
                entry.state, entry.error = LOADING, None
            self._spawn(entry, download=False)

    def install(self, model_id):
        """Starts download + load; idempotent. Returns the state after the call."""
        entry = self._entry(model_id)
        with self._mu:
            state = self._state(entry)
            if state in (DOWNLOADING, LOADING, READY):
                return state
            download = not entry.backend.installed()
        if download and self.disk()["used_mb"] + entry.spec.download_mb > self.disk_limit_mb:
            raise DiskLimit(model_id)
        self._set_stopped(model_id, False)
        with self._mu:
            state = self._state(entry)
            if state in (DOWNLOADING, LOADING, READY):
                return state
            entry.state = DOWNLOADING if download else LOADING
            entry.progress, entry.error = 0, None
            state = entry.state
        self._spawn(entry, download)
        return state

    def stop(self, model_id):
        """Unloads a running model (files stay); it stays stopped after restarts."""
        entry = self._entry(model_id)
        with self._mu:
            state = self._state(entry)
            if state in (DOWNLOADING, LOADING):
                raise ModelBusy(model_id)
            self._set_stopped(model_id, True)
            if state != READY:
                return state
            entry.state = LOADING   # no new jobs while it unloads
        with entry.job_lock:        # a running synthesis finishes first
            entry.backend.unload()
        with self._mu:
            entry.state, entry.memory_mb = None, 0
            return self._state(entry)

    def remove(self, model_id):
        entry = self._entry(model_id)
        with self._mu:
            if self._state(entry) in (DOWNLOADING, LOADING):
                raise ModelBusy(model_id)
            entry.state = LOADING  # blocks a parallel install/remove meanwhile
        try:
            entry.backend.unload()
            entry.backend.delete_files()
        finally:
            with self._mu:
                entry.state, entry.progress, entry.error, entry.memory_mb = None, 0, None, 0
            self._set_stopped(model_id, False)
        return self.state(model_id)

    def _spawn(self, entry, download):
        threading.Thread(target=self._install_job, args=(entry, download),
                         name=f"install-{entry.spec.id}", daemon=True).start()

    def _install_job(self, entry, download):
        spec_id = entry.spec.id
        try:
            if download:
                log.info("%s: downloading", spec_id)

                def progress(pct):
                    with self._mu:
                        entry.progress = max(0, min(100, int(pct)))
                entry.backend.download(progress)
                with self._mu:
                    entry.state, entry.progress = LOADING, 100
            log.info("%s: loading", spec_id)
            started = time.monotonic()
            before = sysinfo.rss_mb()
            entry.backend.load()
            with self._mu:
                entry.state = READY
                # Approximate: other models loading at the same time count too.
                entry.memory_mb = max(0, sysinfo.rss_mb() - before)
            log.info("%s: ready in %.1f s", spec_id, time.monotonic() - started)
        except Exception as e:  # noqa: BLE001 - any failure becomes the "error" state
            log.exception("%s: install/load failed", spec_id)
            try:
                entry.backend.unload()
            except Exception:  # noqa: BLE001
                pass
            with self._mu:
                entry.state, entry.error = ERROR, _short(e)

    # ---- jobs ------------------------------------------------------------

    def _run_job(self, model_id, kind, fn):
        entry = self._entry(model_id)
        if kind and entry.spec.kind != kind:
            raise WrongKind(model_id)
        with self._mu:
            if self._state(entry) != READY:
                raise NotReady(model_id)
            if entry.pending > MAX_WAITING:   # one running + MAX_WAITING waiting
                raise ServiceBusy(model_id)
            entry.pending += 1
        try:
            with entry.job_lock:
                with self._mu:
                    if self._state(entry) != READY:
                        raise NotReady(model_id)
                return fn(entry.backend)
        finally:
            with self._mu:
                entry.pending -= 1

    def synthesize(self, model_id, text, speed, sample_rate):
        return self._run_job(model_id, "tts",
                             lambda b: b.synthesize(text, speed, sample_rate))

    def benchmark(self, model_id, text, sample_rate):
        """Speed test: the seconds of audio made per second of work."""
        def job(backend):
            first = []
            start = time.perf_counter()
            pcm = backend.synthesize(
                text, 1.0, sample_rate,
                on_first_audio=lambda: first.append(time.perf_counter()))
            seconds = time.perf_counter() - start
            audio_seconds = len(pcm) / 2 / sample_rate
            first_ms = int(round(((first[0] if first else start + seconds) - start) * 1000))
            return {
                "audio_seconds": round(audio_seconds, 3),
                "first_audio_ms": first_ms,
                "seconds": round(seconds, 3),
                "realtime_factor": round(audio_seconds / seconds, 2) if seconds > 0 else 0.0,
            }
        return self._run_job(model_id, "tts", job)


def _short(error):
    text = f"{type(error).__name__}: {error}".strip()
    return text if len(text) <= 300 else text[:297] + "..."
