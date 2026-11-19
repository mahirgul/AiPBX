"""Hugging Face Hub client, format/security detector, and SHA-256 verifier.

Only https://huggingface.co (and its HTTPS CDN redirects) is ever contacted.
Pickle/PyTorch weights (.pkl, .pickle, .pt, .pth, .ckpt, .joblib) and remote
Python code (.py / trust_remote_code / auto_map) are strictly rejected.
"""
import hashlib
import json
import os
import re
import shutil
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

HUB = "https://huggingface.co"
HUB_HOST = "huggingface.co"
_CDN_SUFFIXES = (".huggingface.co", ".hf.co")

_REPO_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$")
_REV_RE = re.compile(r"^[A-Za-z0-9._-]{1,64}$")
_SHA256_RE = re.compile(r"^[0-9a-f]{64}$")

FORBIDDEN_EXTENSIONS = (".pkl", ".pickle", ".pt", ".pth", ".ckpt", ".joblib", ".py")
ALLOWED_EXTENSIONS = (".onnx", ".onnx.json", ".gguf", ".safetensors", ".json", ".txt", ".bin")

FREE_LICENSES = ("cc0", "public domain", "cc-by-4.0", "cc-by 4.0", "cc by 4.0", "mit", "apache-2.0", "apache", "bsd", "isc")
NONCOMMERCIAL = ("-nc", " nc", "noncommercial", "non-commercial", "by-nc", "research-only", "research only", "cc-by-nc")

# Only formats the local runtime can run: Whisper in CTranslate2 (STT) and ONNX
# sentence embeddings. TTS comes from the Piper voice index (custom.py), language
# models from Ollama; other Hub formats need PyTorch/GPU and are not listed.
KIND_FILTERS = {
    "stt": ("automatic-speech-recognition", "ctranslate2"),
    "embedding": ("sentence-similarity", "onnx"),
}


class HfError(Exception):
    pass


class HfSecurityError(HfError):
    pass


def _is_allowed_url(url):
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != "https":
        return False
    host = (parsed.hostname or "").lower()
    if host == HUB_HOST:
        return True
    return any(host.endswith(suf) for suf in _CDN_SUFFIXES)


class _SafeRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        resolved = urllib.parse.urljoin(req.full_url, newurl)
        if not _is_allowed_url(resolved):
            raise HfSecurityError(f"redirect to untrusted host rejected: {resolved}")
        new_req = super().redirect_request(req, fp, code, msg, headers, resolved)
        # Never forward the Hugging Face token to third-party/CDN hosts.
        if new_req is not None and urllib.parse.urlsplit(resolved).hostname != HUB_HOST:
            new_req.headers.pop("Authorization", None)
            new_req.unredirected_hdrs.pop("Authorization", None)
        return new_req


_OPENER = urllib.request.build_opener(_SafeRedirectHandler())


def default_fetch(url, timeout=30, token=None):
    if not _is_allowed_url(url):
        raise HfSecurityError(f"only {HUB} URLs are allowed")
    headers = {"User-Agent": "aipbx-ai"}
    if token and urllib.parse.urlsplit(url).hostname == HUB_HOST:
        headers["Authorization"] = f"Bearer {token.strip()}"
    req = urllib.request.Request(url, headers=headers)
    try:
        with _OPENER.open(req, timeout=timeout) as resp:
            return resp.read()
    except urllib.error.HTTPError as e:
        if e.code in (401, 403):
            raise HfError("Hugging Face rejected the request (gated or private repo: provide a valid access token)") from None
        if e.code == 404:
            raise HfError("Hugging Face repository or revision not found") from None
        raise HfError(f"Hugging Face HTTP {e.code}") from None
    except HfError:
        raise
    except Exception as e:  # noqa: BLE001
        raise HfError(f"Hugging Face request failed: {e}") from None


def open_download(url, token=None):
    if not _is_allowed_url(url):
        raise HfSecurityError("untrusted model download URL")
    headers = {"User-Agent": "aipbx-ai"}
    if token and urllib.parse.urlsplit(url).hostname == HUB_HOST:
        headers["Authorization"] = "Bearer " + token.strip()
    return _OPENER.open(urllib.request.Request(url, headers=headers), timeout=60)


def normalize_repo(repo_or_url):
    """Accepts 'org/model' or 'https://huggingface.co/org/model[/...]'."""
    if not isinstance(repo_or_url, str):
        raise HfError("repository name must be a string")
    s = repo_or_url.strip()
    if not s:
        raise HfError("repository name is required")
    if "://" in s:
        parsed = urllib.parse.urlsplit(s)
        if parsed.scheme != "https" or (parsed.hostname or "").lower() != HUB_HOST:
            raise HfSecurityError(f"only {HUB} URLs are allowed")
        parts = [p for p in parsed.path.split("/") if p]
        if len(parts) < 2:
            raise HfError("invalid Hugging Face repository URL")
        s = f"{parts[0]}/{parts[1]}"
    s = s.strip("/")
    if ".." in s or not _REPO_RE.match(s):
        raise HfError("invalid Hugging Face repository name (expected org/model)")
    return s


def validate_revision(revision):
    if not revision:
        return "main"
    if not isinstance(revision, str) or ".." in revision or not _REV_RE.match(revision):
        raise HfError("invalid revision")
    return revision


def model_id_for(repo, prefix="hf"):
    slug = re.sub(r"[^a-z0-9._-]+", "-", repo.lower().replace("/", "-")).strip("-.")
    mid = f"{prefix}-{slug}" if prefix else slug
    return mid[:64].rstrip("-.")


def sha256_bytes(data):
    return hashlib.sha256(data).hexdigest()


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def _has_forbidden_ext(path):
    low = path.lower()
    return any(low.endswith(ext) for ext in FORBIDDEN_EXTENSIONS)


def _extract_license(meta, readme=""):
    card = meta.get("cardData") or {}
    lic = card.get("license")
    if isinstance(lic, list) and lic:
        lic = ", ".join(str(x) for x in lic if x)
    if isinstance(lic, str) and lic.strip():
        return lic.strip()[:200]
    for tag in meta.get("tags") or []:
        if isinstance(tag, str) and tag.startswith("license:"):
            return tag.split(":", 1)[1].strip()[:200]
    for line in (readme or "").splitlines():
        m = re.match(r"^\s*(?:\*\s*)?license:\s*(.+)$", line, re.I)
        if m:
            return m.group(1).strip().strip("\"'")[:200]
    return ""


def _extract_languages(meta):
    card = meta.get("cardData") or {}
    langs = card.get("language") or card.get("languages") or []
    if isinstance(langs, str):
        langs = [langs]
    out = []
    for l in langs:
        if isinstance(l, str) and re.match(r"^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$", l.strip()):
            out.append(l.strip().replace("_", "-"))
    return tuple(dict.fromkeys(out)) or ("en",)


def is_commercial(license_str, readme=""):
    low = (license_str or "").lower()
    if not low or low in ("unknown", "other", "see model card", "not stated"):
        return False
    if any(x in low for x in NONCOMMERCIAL):
        return False
    if "fine-tun" in (readme or "").lower():
        return False
    return any(x == low for x in FREE_LICENSES)


def _check_config_remote_code(config_bytes):
    if not config_bytes:
        return None
    try:
        cfg = json.loads(config_bytes.decode("utf-8", "replace"))
    except ValueError:
        return None
    if isinstance(cfg, dict):
        if cfg.get("auto_map") or cfg.get("trust_remote_code"):
            return "repository requests remote Python code execution (auto_map / trust_remote_code)"
    return None


def detect_repo(repo, tree_entries, meta=None, config_bytes=b"", readme=""):
    """Inspects a repository's file tree and returns format, engine, kind, and required files."""
    meta = meta or {}
    files = {}
    for item in tree_entries:
        path = item.get("path") or item.get("rfilename") or ""
        if not path or item.get("type") == "directory":
            continue
        if ".." in path.split("/"):
            raise HfSecurityError(f"unsafe file path in repository: {path}")
        size = int((item.get("lfs") or {}).get("size") or item.get("size") or 0)
        sha = (item.get("lfs") or {}).get("oid") or ""
        files[path] = {"path": path, "size": size, "sha256": sha}

    # Check for remote code / pickle files anywhere in model artifacts.
    forbidden = [p for p in files if _has_forbidden_ext(p)]
    cfg_err = _check_config_remote_code(config_bytes)
    if cfg_err:
        return {"supported": False, "reason": cfg_err, "files": []}

    # 1. Piper TTS (*.onnx + *.onnx.json)
    onnx_files = sorted(p for p in files if p.endswith(".onnx"))
    for onnx_path in onnx_files:
        cfg_path = onnx_path + ".json"
        if cfg_path in files:
            selected = [files[onnx_path], files[cfg_path]]
            return {
                "supported": True,
                "kind": "tts",
                "engine": "piper",
                "format": "onnx",
                "files": selected,
            }

    # 2. Whisper / CTranslate2 STT (model.bin + config.json + vocabulary)
    if "model.bin" in files and "config.json" in files:
        vocab = "vocabulary.txt" if "vocabulary.txt" in files else ("vocabulary.json" if "vocabulary.json" in files else None)
        if vocab:
            return {
                "supported": True,
                "kind": "stt",
                "engine": "whisper",
                "format": "ctranslate2",
                "files": [files["model.bin"], files["config.json"], files[vocab]],
            }

    # 3. Embedding ONNX (model.onnx or onnx/model.onnx + tokenizer.json)
    emb_onnx = next((p for p in ("onnx/model.onnx", "model.onnx") if p in files), None)
    tok = next((p for p in ("tokenizer.json", "onnx/tokenizer.json") if p in files), None)
    if emb_onnx and tok:
        return {
            "supported": True,
            "kind": "embedding",
            "engine": "embedding",
            "format": "onnx",
            "files": [files[emb_onnx], files[tok]],
        }

    # 4. Generic ONNX (not Piper/Embedding, but safe ONNX)
    if onnx_files and not forbidden:
        chosen = [files[onnx_files[0]]]
        if "config.json" in files:
            chosen.append(files["config.json"])
        if tok:
            chosen.append(files[tok])
        return {
            "supported": True,
            "kind": "embedding",
            "engine": "onnx",
            "format": "onnx",
            "files": chosen,
        }

    # 5. GGUF (*.gguf)
    ggufs = sorted((p for p in files if p.lower().endswith(".gguf")), key=lambda p: files[p]["size"] or 0)
    if ggufs and not forbidden:
        return {
            "supported": True,
            "kind": "llm",
            "engine": "gguf",
            "format": "gguf",
            "files": [files[ggufs[0]]],
        }

    # 6. Safetensors (*.safetensors + optional config/tokenizer, strictly no pickle/pt/py)
    safes = sorted(p for p in files if p.lower().endswith(".safetensors"))
    if safes and not forbidden:
        chosen = [files[p] for p in safes]
        for extra in ("config.json", "tokenizer.json", "tokenizer_config.json", "special_tokens_map.json", "generation_config.json", "model.safetensors.index.json", "tokenizer.model"):
            if extra in files:
                chosen.append(files[extra])
        return {
            "supported": True,
            "kind": "llm",
            "engine": "safetensors",
            "format": "safetensors",
            "files": chosen,
        }

    if forbidden:
        exts = ", ".join(sorted({Path(p).suffix.lower() for p in forbidden}))
        return {
            "supported": False,
            "reason": f"unsafe or unsupported weight/code format ({exts}): pickle, PyTorch .pt/.pth, and .py remote code are rejected",
            "files": [],
        }
    return {
        "supported": False,
        "reason": "no supported format found (expected ONNX, GGUF, safetensors, or CTranslate2 model.bin + config.json)",
        "files": [],
    }


class CustomWeightsBackend:
    """Downloads, SHA-256 verifies, and stores GGUF / safetensors / generic ONNX files."""

    def __init__(self, spec, data_dir):
        self.spec = spec
        self.dir = Path(data_dir) / "models" / spec.id

    def _files(self):
        return self.spec.source["files"]

    def installed(self):
        return all((self.dir / name).is_file() for name in self._files())

    def disk_bytes(self):
        return sum((self.dir / n).stat().st_size for n in self._files() if (self.dir / n).is_file())

    def download(self, progress):
        src = self.spec.source
        self.dir.mkdir(parents=True, exist_ok=True)
        for stale in self.dir.rglob("*.part"):
            stale.unlink(missing_ok=True)
        files = self._files()
        needed = sum(int(f.get("bytes", 0) or 0) for name, f in files.items() if not (self.dir / name).is_file())
        if needed > 0:
            free = shutil.disk_usage(self.dir).free
            margin = 64 * 1024 * 1024
            if free < needed + margin:
                need_mb = -(-needed // (1024 * 1024))
                free_mb = free // (1024 * 1024)
                raise RuntimeError(f"Not enough free disk space: need {need_mb} MB (+64 MB buffer), only {free_mb} MB free")
        total = max(1, sum(f.get("bytes", 0) for f in files.values()))
        done = 0
        for name, info in files.items():
            target = self.dir / name
            target.parent.mkdir(parents=True, exist_ok=True)
            sha = info["sha256"]
            if target.is_file() and sha256_file(target) == sha:
                done += target.stat().st_size
                continue
            part = target.with_name(target.name + ".part")
            url = f"{HUB}/{src['repo']}/resolve/{src['revision']}/{info['path']}"
            req = urllib.request.Request(url, headers={"User-Agent": "aipbx-ai"})
            try:
                with open_download(url, getattr(self, "download_token", None)) as resp, open(part, "wb") as out:
                    while block := resp.read(1 << 16):
                        out.write(block)
                        done += len(block)
                        progress(min(99, 100 * done // total))
                got = sha256_file(part)
                if got != sha:
                    raise RuntimeError(f"{name}: checksum mismatch ({got[:12]}…), not saved")
                os.replace(part, target)
            except BaseException:
                part.unlink(missing_ok=True)
                raise
        progress(100)

    def load(self):
        raise RuntimeError("No runtime supports this model format; use a supported Ollama language model or Piper/Whisper model")

    def unload(self):
        pass

    def delete_files(self):
        shutil.rmtree(self.dir, ignore_errors=True)


class HfClient:
    def __init__(self, fetch=default_fetch):
        self.fetch = fetch

    def search(self, query="", kind="", limit=100, token=None):
        """Hub models of the runnable formats only (KIND_FILTERS); every kind when `kind` is empty."""
        kinds = [kind] if kind in KIND_FILTERS else ([] if kind else list(KIND_FILTERS))
        out = []
        for k in kinds:
            out += self._search_kind(query, k, limit, token)
        return out

    def _search_kind(self, query, kind, limit, token):
        params = [("limit", str(max(1, min(200, int(limit)))))]
        if query and query.strip():
            params.append(("search", query.strip()))
        params += [("filter", f) for f in KIND_FILTERS[kind]]
        qs = urllib.parse.urlencode(params)
        for field in ("downloads", "likes", "pipeline_tag", "tags", "gated", "lastModified"):
            qs += "&expand%5B%5D=" + field
        raw = self.fetch(f"{HUB}/api/models?{qs}", timeout=20, token=token)
        try:
            items = json.loads(raw)
        except ValueError as e:
            raise HfError(f"invalid Hugging Face search response: {e}") from None
        out = []
        for m in items if isinstance(items, list) else []:
            repo = m.get("id") or m.get("modelId") or ""
            if not repo:
                continue
            total_params = 0
            if isinstance(m.get("gguf"), dict) and m["gguf"].get("total"):
                total_params = int(m["gguf"]["total"])
            elif isinstance(m.get("safetensors"), dict) and m["safetensors"].get("total"):
                total_params = int(m["safetensors"]["total"])
            if total_params >= 1_000_000_000:
                params_label = f"{total_params / 1_000_000_000:.1f}B"
            elif total_params >= 1_000_000:
                params_label = f"{total_params / 1_000_000:.0f}M"
            else:
                params_label = "-"
            total_bytes = 0
            if isinstance(m.get("gguf"), dict) and m["gguf"].get("totalFileSize"):
                total_bytes = int(m["gguf"]["totalFileSize"])
            if total_bytes >= 1_000_000_000:
                size_label = f"{total_bytes / 1_000_000_000:.1f} GB"
            elif total_bytes >= 1_000_000:
                size_label = f"{total_bytes / 1_000_000:.0f} MB"
            else:
                size_label = "-"
            out.append({
                "repo": repo,
                "params_label": params_label,
                "size_label": size_label,
                "downloads": int(m.get("downloads") or 0),
                "likes": int(m.get("likes") or 0),
                "license": _extract_license(m),
                "last_modified": m.get("lastModified") or "",
                "pipeline_tag": m.get("pipeline_tag") or "",
                "gated": bool(m.get("gated")),
                "kind": kind,
            })
        return out

    def inspect(self, repo_or_url, revision=None, token=None):
        repo = normalize_repo(repo_or_url)
        rev_arg = validate_revision(revision) if revision else None
        meta_url = f"{HUB}/api/models/{repo}" + (f"/revision/{rev_arg}" if rev_arg else "")
        meta = json.loads(self.fetch(meta_url, timeout=20, token=token))
        if meta.get("gated") and not token:
            raise HfError("this model is gated on Hugging Face; provide an access token to inspect and download it")
        pinned_rev = meta.get("sha") or rev_arg or "main"
        tree = json.loads(self.fetch(f"{HUB}/api/models/{repo}/tree/{pinned_rev}?recursive=1", timeout=20, token=token))

        config_bytes = b""
        if any(f.get("path") == "config.json" for f in tree):
            config_bytes = self.fetch(f"{HUB}/{repo}/resolve/{pinned_rev}/config.json", timeout=20, token=token)
        readme = ""
        for card_name in ("README.md", "MODEL_CARD"):
            if any(f.get("path") == card_name for f in tree):
                readme = self.fetch(f"{HUB}/{repo}/resolve/{pinned_rev}/{card_name}", timeout=20, token=token).decode("utf-8", "replace")
                break

        det = detect_repo(repo, tree, meta=meta, config_bytes=config_bytes, readme=readme)
        # Ensure every selected file has a SHA-256 (LFS oid or fetched blob hash).
        resolved_files = []
        for f in det.get("files", []):
            sha = f.get("sha256") or ""
            size = int(f.get("size") or 0)
            if not _SHA256_RE.match(sha):
                raw = self.fetch(f"{HUB}/{repo}/resolve/{pinned_rev}/{f['path']}", timeout=30, token=token)
                sha = sha256_bytes(raw)
                size = len(raw)
            resolved_files.append({"path": f["path"], "size": size, "sha256": sha})

        lic = _extract_license(meta, readme) or "see model card"
        commercial = is_commercial(lic, readme)
        total_bytes = sum(f["size"] for f in resolved_files)
        return {
            "repo": repo,
            "revision": pinned_rev,
            "supported": det["supported"],
            "reason": det.get("reason", ""),
            "kind": det.get("kind", ""),
            "engine": det.get("engine", ""),
            "format": det.get("format", ""),
            "languages": list(_extract_languages(meta)),
            "license": lic,
            "license_url": f"{HUB}/{repo}/blob/{pinned_rev}/README.md",
            "homepage": f"{HUB}/{repo}",
            "commercial": commercial,
            "files": resolved_files,
            "total_bytes": total_bytes,
            "download_mb": max(1, -(-total_bytes // (1024 * 1024))) if total_bytes else 0,
            "gated": bool(meta.get("gated")),
        }
