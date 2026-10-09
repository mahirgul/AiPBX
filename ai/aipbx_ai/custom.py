"""Models the administrator adds from a catalogue search (AI -> Local models).

Piper: the index voices.json of rhasspy/piper-voices lists every voice with its
files, sizes and MD5 sums. Adding a voice pins the repository's current
revision, takes the model's SHA-256 from the Hub (LFS) and checks the small
config against the index's MD5, so later downloads are verified like the
built-in voices. The licence line of the voice's MODEL_CARD is shown to the
administrator, who accepts it before the download.

An added model is described by $AIPBX_AI_DATA/models/<id>/manifest.json and
registered again on every start; removing the model deletes the folder.
"""
import hashlib
import json
import logging
import re
import time
import urllib.request
from dataclasses import asdict
from pathlib import Path

from .models import ModelSpec
from .piper_backend import PiperBackend

log = logging.getLogger("aipbx_ai.custom")

HUB = "https://huggingface.co"
PIPER_REPO = "rhasspy/piper-voices"
INDEX_MAX_AGE = 24 * 3600
_ID_OK = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$")
_KEY_OK = re.compile(r"^[A-Za-z]{2,3}_[A-Za-z]{2}-[A-Za-z0-9_]+-(x_low|low|medium|high)$")
FREE_LICENCES = ("cc0", "public domain", "cc-by 4.0", "cc by 4.0", "cc-by-4.0", "mit", "apache")
NONCOMMERCIAL = ("-nc", " nc", "noncommercial", "non-commercial", "by-nc")


class CatalogError(Exception):
    pass


def _get(url, timeout=30):
    req = urllib.request.Request(url, headers={"User-Agent": "aipbx-ai"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read()


class Catalog:
    def __init__(self, data_dir, fetch=_get):
        self.data_dir = Path(data_dir)
        self.fetch = fetch
        self._index = None
        self._index_time = 0.0

    # ---- Piper index ---------------------------------------------------

    def _piper_index(self):
        cache = self.data_dir / "cache" / "piper-voices.json"
        now = time.time()
        if self._index is not None and now - self._index_time < INDEX_MAX_AGE:
            return self._index
        try:
            if cache.is_file() and now - cache.stat().st_mtime < INDEX_MAX_AGE:
                self._index = json.loads(cache.read_text())
                self._index_time = cache.stat().st_mtime
                return self._index
        except (OSError, ValueError):
            pass
        try:
            raw = self.fetch(f"{HUB}/{PIPER_REPO}/resolve/main/voices.json")
            index = json.loads(raw)
        except Exception as e:  # noqa: BLE001
            raise CatalogError(f"the voice list could not be loaded: {e}") from None
        try:
            cache.parent.mkdir(parents=True, exist_ok=True)
            cache.write_bytes(raw)
        except OSError:
            pass
        self._index, self._index_time = index, now
        return index

    def piper_voices(self, known=None):
        """Every voice of the index; `known` maps a model file path to the id of a
        model already registered (built-in or added), shown as such."""
        known = known or {}
        out = []
        for key, v in sorted(self._piper_index().items()):
            if not _KEY_OK.match(key):
                continue
            lang = v.get("language", {})
            size = sum(f.get("size_bytes", 0) for name, f in v.get("files", {}).items() if name.endswith(".onnx"))
            onnx = next((n for n in v.get("files", {}) if n.endswith(".onnx")), "")
            mid = known.get(onnx, model_id_for(key))
            out.append({
                "key": key, "id": mid, "name": v.get("name", ""), "quality": v.get("quality", ""),
                "language": lang.get("code", ""), "language_name": lang.get("name_english", ""),
                "country": lang.get("country_english", ""), "speakers": v.get("num_speakers", 1),
                "size_mb": round(size / (1024 * 1024)), "registered": onnx in known, "model_id": mid,
                "card_url": f"{HUB}/{PIPER_REPO}/blob/main/{_voice_dir(v)}/MODEL_CARD" if _voice_dir(v) else "",
            })
        return out

    def piper_spec(self, key):
        """A ModelSpec for one voice, with the revision and checksums pinned now."""
        if not isinstance(key, str) or not _KEY_OK.match(key):
            raise CatalogError("unknown voice")
        v = self._piper_index().get(key)
        if not v:
            raise CatalogError("unknown voice")
        vdir = _voice_dir(v)
        onnx = f"{vdir}/{key}.onnx"
        cfg = f"{vdir}/{key}.onnx.json"
        files = v.get("files", {})
        if onnx not in files or cfg not in files:
            raise CatalogError("the voice has no model files")
        try:
            revision = json.loads(self.fetch(f"{HUB}/api/models/{PIPER_REPO}"))["sha"]
            tree = json.loads(self.fetch(f"{HUB}/api/models/{PIPER_REPO}/tree/{revision}/{vdir}"))
            onnx_sha = next(f["lfs"]["oid"] for f in tree if f.get("path") == onnx and f.get("lfs"))
            cfg_raw = self.fetch(f"{HUB}/{PIPER_REPO}/resolve/{revision}/{cfg}")
            card = self.fetch(f"{HUB}/{PIPER_REPO}/resolve/{revision}/{vdir}/MODEL_CARD").decode("utf-8", "replace")
        except Exception as e:  # noqa: BLE001
            raise CatalogError(f"the voice could not be looked up: {e}") from None
        if hashlib.md5(cfg_raw).hexdigest() != files[cfg].get("md5_digest"):  # noqa: S324 - the index's own sum
            raise CatalogError("the voice's configuration does not match the index")
        licence = _licence_line(card)
        lang = v.get("language", {})
        code = lang.get("code", "").replace("_", "-")
        low = licence.lower()
        commercial = any(x in low for x in FREE_LICENCES) and not any(x in low for x in NONCOMMERCIAL)
        note = "Added from the Piper voice list. Licence from its model card: " + (licence or "not stated") + "."
        if "fine-tun" in card.lower():
            commercial = False
            note += " Fine-tuned from another voice: check that voice's licence too."
        return ModelSpec(
            id=model_id_for(key), title=f"Piper {v.get('name', key)} ({lang.get('name_english', code)}, {v.get('quality', '')})",
            kind="tts", languages=(code,), license=licence or "see model card",
            license_url=f"{HUB}/{PIPER_REPO}/blob/{revision}/{vdir}/MODEL_CARD",
            homepage="https://github.com/rhasspy/piper",
            download_mb=max(1, round(files[onnx].get("size_bytes", 0) / (1024 * 1024))),
            backend=PiperBackend, engine="piper", commercial=commercial, note=note,
            source={"revision": revision, "files": {onnx: onnx_sha, cfg: hashlib.sha256(cfg_raw).hexdigest()}},
            custom=True,
        )

    # ---- manifests -----------------------------------------------------

    def save(self, spec):
        folder = self.data_dir / "models" / spec.id
        folder.mkdir(parents=True, exist_ok=True)
        data = asdict(spec)
        data["backend"] = spec.engine
        data["languages"] = list(spec.languages)
        (folder / "manifest.json").write_text(json.dumps(data, ensure_ascii=False, indent=1))

    def load_saved(self):
        specs = []
        for path in sorted((self.data_dir / "models").glob("*/manifest.json")):
            try:
                d = json.loads(path.read_text())
                if d.get("engine") != "piper" or not _ID_OK.match(d.get("id", "")):
                    continue
                d["backend"] = PiperBackend
                d["languages"] = tuple(d.get("languages", ()))
                d["custom"] = True
                specs.append(ModelSpec(**{k: d[k] for k in ModelSpec.__dataclass_fields__ if k in d}))
            except (OSError, ValueError, TypeError, KeyError) as e:
                log.warning("skipping %s: %s", path, e)
        return specs


def model_id_for(key):
    mid = "piper-" + re.sub(r"[^a-z0-9.-]+", "-", key.lower()).strip("-")
    return mid[:64]


def _voice_dir(v):
    for name in v.get("files", {}):
        if name.endswith(".onnx"):
            return name.rsplit("/", 1)[0]
    return ""


def _licence_line(card):
    for line in card.splitlines():
        m = re.match(r"^\s*\*\s*License:\s*(.+)$", line, re.I)
        if m:
            return m.group(1).strip()[:200]
    return ""
