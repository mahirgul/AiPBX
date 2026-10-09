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
disk, not in memory; "error" keeps the last failure until the next install.
Each model runs one job at a time (its lock); at most MAX_WAITING requests
may wait for it, more get ServiceBusy (HTTP 503).
"""
import logging
import threading
import time
from dataclasses import dataclass

from .ema import EmaLightningBackend

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
    ),
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


class _Entry:
    def __init__(self, spec, backend):
        self.spec = spec
        self.backend = backend
        self.state = None        # None: derived from the disk (absent/installed)
        self.progress = 0
        self.error = None
        self.job_lock = threading.Lock()   # one synthesis/benchmark at a time
        self.pending = 0                   # running + waiting jobs


class ModelManager:
    def __init__(self, specs, data_dir, backend_factory=None):
        make = backend_factory or (lambda spec: spec.backend(spec, data_dir))
        self._mu = threading.Lock()
        self._entries = {s.id: _Entry(s, make(s)) for s in specs}

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
        }

    def describe_all(self):
        return [self.describe(i) for i in self._entries]

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
                if self._state(entry) != INSTALLED:
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
            entry.state = DOWNLOADING if download else LOADING
            entry.progress, entry.error = 0, None
            state = entry.state
        self._spawn(entry, download)
        return state

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
                entry.state, entry.progress, entry.error = None, 0, None
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
            entry.backend.load()
            with self._mu:
                entry.state = READY
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
