"""EMA Lightning backend: Turkish text-to-speech (pip package ema-lightning).

Model files come from the Hugging Face repository canberkkkkkk/ema-lightning
into $HF_HOME (the unit sets /var/lib/aipbx-ai/hf). torch, numpy and
ema_lightning are imported only when the model is downloaded or loaded, so
the service (and its tests) start without them.

Loading never contacts the Hub: the files are resolved from the local cache
and handed to the package's loaders. Only `download` goes online.
"""
import gc
import os
import shutil
import threading
from pathlib import Path

REPO = "canberkkkkkk/ema-lightning"
FILES = ("config.json", "ema.pt", "decoder.pt")
WARMUP_TEXT = "Merhaba."


def hub_cache_dir():
    """Where huggingface_hub keeps repositories (same rules as the library)."""
    if os.environ.get("HF_HUB_CACHE"):
        return Path(os.environ["HF_HUB_CACHE"])
    if os.environ.get("HF_HOME"):
        return Path(os.environ["HF_HOME"]) / "hub"
    xdg = os.environ.get("XDG_CACHE_HOME") or str(Path.home() / ".cache")
    return Path(xdg) / "huggingface" / "hub"


def _tree_bytes(path):
    """Bytes under path, symlinks followed once per target.

    huggingface_hub 2.x may keep blobs in a store shared by all repositories
    (hub/blobs) and link them from the repository: counting link targets
    gives the real size of one model.
    """
    seen, total = set(), 0
    for root, _dirs, files in os.walk(path):
        for name in files:
            real = os.path.realpath(os.path.join(root, name))
            if real in seen:
                continue
            seen.add(real)
            try:
                total += os.stat(real).st_size
            except OSError:
                continue
    return total


class EmaLightningBackend:
    def __init__(self, spec, data_dir):
        self.spec = spec
        self.data_dir = Path(data_dir)
        self._tts = None
        self._np = None

    # ---- files -----------------------------------------------------------

    @property
    def repo_dir(self):
        return hub_cache_dir() / ("models--" + REPO.replace("/", "--"))

    def installed(self):
        ref = self.repo_dir / "refs" / "main"
        try:
            revision = ref.read_text().strip()
        except OSError:
            return False
        snapshot = self.repo_dir / "snapshots" / revision
        return bool(revision) and all((snapshot / f).is_file() for f in FILES)

    def disk_bytes(self):
        return _tree_bytes(self.repo_dir) if self.repo_dir.exists() else 0

    def download(self, progress):
        from huggingface_hub import hf_hub_download

        # Progress = growth of the whole Hub cache (blobs and the xet staging
        # area) against the published download size; it ends at 100 when every
        # file is in place.
        root = hub_cache_dir().parent if os.environ.get("HF_HOME") else hub_cache_dir()
        root.mkdir(parents=True, exist_ok=True)
        base = _tree_bytes(root)
        expected = max(1, self.spec.download_mb * 1024 * 1024)
        done = threading.Event()

        def watch():
            while not done.wait(1.0):
                grown = max(0, _tree_bytes(root) - base)
                progress(min(99, 100 * grown // expected))

        watcher = threading.Thread(target=watch, name="ema-download-progress", daemon=True)
        watcher.start()
        try:
            for name in FILES:
                hf_hub_download(REPO, name)
        finally:
            done.set()
            watcher.join(timeout=2)
        progress(100)

    def delete_files(self):
        self.unload()
        # The library's own cache cleanup also frees blobs in the shared store.
        try:
            from huggingface_hub import scan_cache_dir
            cache = scan_cache_dir(hub_cache_dir())
            revisions = [r.commit_hash for repo in cache.repos if repo.repo_id == REPO for r in repo.revisions]
            if revisions:
                cache.delete_revisions(*revisions).execute()
        except Exception:  # noqa: BLE001 - no library or a broken cache: remove the folder below
            pass
        shutil.rmtree(self.repo_dir, ignore_errors=True)
        locks = hub_cache_dir() / ".locks" / self.repo_dir.name
        shutil.rmtree(locks, ignore_errors=True)

    # ---- memory ----------------------------------------------------------

    def load(self):
        import numpy as np
        import torch
        from huggingface_hub import hf_hub_download

        device = "cuda" if torch.cuda.is_available() else "cpu"
        paths = {f: hf_hub_download(REPO, f, local_files_only=True) for f in FILES}
        tts = self._from_local_files(paths, device)
        if device == "cuda":
            tts.lightning()   # CUDA graphs; on a CPU it only warns
        # The CPU batch size is measured once and cached under
        # $XDG_CACHE_HOME/ema_lightning; do it now, not in the first request.
        tts.best_batch_size()
        for _ in tts.stream(WARMUP_TEXT, seed=0, sample_rate=8000):
            pass
        self._np = np
        self._tts = tts

    @staticmethod
    def _from_local_files(paths, device):
        """EMA from files already on disk; EMA() itself would ask the Hub first."""
        from ema_lightning import EMA
        try:
            import torch
            from ema_lightning.decoder import load_decoder
            from ema_lightning.frontend import Frontend
            from ema_lightning.model import load_acoustic
            builder = EMA._from_parts
        except (ImportError, AttributeError):
            # A later package version without these internals: the public
            # constructor still works (it finds the cached files).
            return EMA(device=device)
        dev = torch.device(device)
        model = load_acoustic(paths["ema.pt"], dev)
        decoder = load_decoder(paths["decoder.pt"], dev)
        return builder(model, decoder, Frontend(model.vocab), dev)

    def unload(self):
        if self._tts is not None:
            self._tts = None
            gc.collect()

    # ---- inference -------------------------------------------------------

    def synthesize(self, text, speed, sample_rate, on_first_audio=None):
        tts, np = self._tts, self._np
        if tts is None:
            raise RuntimeError("model not loaded")
        chunks = []
        for chunk in tts.stream(text, speed=speed, sample_rate=sample_rate):
            if not chunks and on_first_audio is not None:
                on_first_audio()
            chunks.append(chunk)
        if not chunks:
            return b""
        audio = np.concatenate(chunks)
        return (np.clip(audio, -1.0, 1.0) * 32767.0).round().astype("<i2").tobytes()
