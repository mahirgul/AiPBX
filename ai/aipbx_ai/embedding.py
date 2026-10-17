"""Sentence meaning for the voice requests: multilingual-e5-small on ONNX Runtime.

``intents.match`` compares what a caller said with the example sentences of
each request. Letters alone (TF-IDF of character n-grams) find "fatura" in
"faturamı", but not "the document you billed me" for an *invoice* request.
A sentence-embedding model puts sentences with the same meaning close
together, whatever the words: on 40 Turkish routing requests that avoided
the keywords, letters matched 6, this model 31 (docs/local-ai.md).

Model: intfloat/multilingual-e5-small (MIT, 118 M parameters, 100 languages)
in the int8 ONNX export of Xenova/multilingual-e5-small, pinned to a revision
and checked by SHA-256 (models.py). Files:

    $AIPBX_AI_DATA/models/<id>/model.onnx       (~113 MB)
    $AIPBX_AI_DATA/models/<id>/tokenizer.json   (~17 MB)

About 3 ms per sentence on 2 cores and ~250 MB of memory. Of the larger
models measured on the same sentences, multilingual-e5-base (28/40) and
gte-multilingual-base (27/40) were not better, only slower.

The tokenizer is XLM-RoBERTa's SentencePiece unigram model, done here in a
few lines (Viterbi over the piece scores) so the runtime needs no tokenizer
library; ``tokenize`` gives the same ids as the Hugging Face tokenizers
package for ordinary text (tests/test_embedding.py).
"""
import hashlib
import json
import os
import shutil
import unicodedata
import urllib.request
from pathlib import Path

REPO_URL = "https://huggingface.co/"
MAX_TOKENS = 128          # requests are one sentence; e5 allows 512
PREFIX = "query: "        # e5 expects a prefix; both sides use "query: " for similarity
BOS, EOS, UNK = 0, 2, 3


def _sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


class UnigramTokenizer:
    """SentencePiece unigram model of an XLM-RoBERTa tokenizer.json."""

    def __init__(self, path):
        with open(path, encoding="utf-8") as f:
            model = json.load(f)["model"]
        vocab = model["vocab"]
        self.pieces = {piece: (i, score) for i, (piece, score) in enumerate(vocab) if i > UNK}
        self.max_len = max(len(p) for p in self.pieces)
        self.unk_score = min(score for _, score in self.pieces.values()) - 10.0

    @staticmethod
    def normalize(text):
        # The Precompiled normaliser of the model is NFKC with whitespace
        # folded; the Metaspace pre-tokenizer then marks word starts with "▁".
        text = unicodedata.normalize("NFKC", text)
        return " ".join(text.split())

    def _word(self, word):
        n = len(word)
        best = [(0.0, None, None)] + [(-1e18, None, None)] * n    # (score, start, id)
        for i in range(n):
            if best[i][0] <= -1e17:
                continue
            base = best[i][0]
            matched = False
            for j in range(i + 1, min(n, i + self.max_len) + 1):
                hit = self.pieces.get(word[i:j])
                if hit is not None:
                    matched = True
                    s = base + hit[1]
                    if s > best[j][0]:
                        best[j] = (s, i, hit[0])
            if not matched or best[i + 1][0] <= -1e17:
                s = base + self.unk_score
                if s > best[i + 1][0]:
                    best[i + 1] = (s, i, UNK)
        ids, j = [], n
        while j > 0:
            _, i, tid = best[j]
            ids.append(tid)
            j = i
        ids.reverse()
        out = []
        for tid in ids:                      # consecutive unknown pieces become one, as in tokenizers
            if tid == UNK and out and out[-1] == UNK:
                continue
            out.append(tid)
        return out

    def tokenize(self, text):
        ids = [BOS]
        for word in self.normalize(text).split(" "):
            if word:
                ids.extend(self._word("▁" + word))
        return ids[:MAX_TOKENS - 1] + [EOS]


class EmbeddingBackend:
    """spec.source = {"repo", "revision", "files": {name: {"path", "sha256", "bytes"}}}."""

    def __init__(self, spec, data_dir):
        self.spec = spec
        self.dir = Path(data_dir) / "models" / spec.id
        self.session = None
        self.tokenizer = None
        self.np = None
        self._cache = {}

    def installed(self):
        return all((self.dir / name).is_file() for name in self.spec.source["files"])

    def disk_bytes(self):
        return sum(f.stat().st_size for f in self.dir.rglob("*") if f.is_file()) if self.dir.exists() else 0

    def download(self, progress):
        src = self.spec.source
        self.dir.mkdir(parents=True, exist_ok=True)
        total = max(1, sum(f["bytes"] for f in src["files"].values()))
        done = 0
        for name, info in src["files"].items():
            part = self.dir / (name + ".part")
            url = f"{REPO_URL}{src['repo']}/resolve/{src['revision']}/{info['path']}"
            req = urllib.request.Request(url, headers={"User-Agent": "aipbx-ai"})
            try:
                with urllib.request.urlopen(req, timeout=60) as resp, open(part, "wb") as out:
                    while block := resp.read(1 << 16):
                        out.write(block)
                        done += len(block)
                        progress(min(99, 99 * done // total))
                got = _sha256(part)
                if got != info["sha256"]:
                    raise RuntimeError(f"{name}: checksum mismatch ({got[:12]}…)")
                os.replace(part, self.dir / name)
            except BaseException:
                part.unlink(missing_ok=True)
                raise
        progress(100)

    def delete_files(self):
        self.unload()
        shutil.rmtree(self.dir, ignore_errors=True)

    def load(self):
        import numpy as np
        import onnxruntime as ort
        opts = ort.SessionOptions()
        opts.intra_op_num_threads = 1      # one short sentence: threads cost more than they give
        self.session = ort.InferenceSession(str(self.dir / "model.onnx"), opts, providers=["CPUExecutionProvider"])
        self.tokenizer = UnigramTokenizer(self.dir / "tokenizer.json")
        self.np = np

    def unload(self):
        self.session = None
        self.tokenizer = None
        self._cache.clear()

    def embed(self, texts):
        """Unit vectors (numpy array, one row per text). Example sentences repeat
        on every call of an application, so vectors are cached by text; the
        model manager runs one job at a time, so no lock is needed here."""
        np = self.np
        missing = [t for t in dict.fromkeys(texts) if t not in self._cache]
        if missing:
            encs = [self.tokenizer.tokenize(PREFIX + t) for t in missing]
            n = max(len(e) for e in encs)
            ids = np.array([e + [1] * (n - len(e)) for e in encs], dtype=np.int64)
            mask = np.array([[1] * len(e) + [0] * (n - len(e)) for e in encs], dtype=np.int64)
            feed = {"input_ids": ids, "attention_mask": mask}
            if any(i.name == "token_type_ids" for i in self.session.get_inputs()):
                feed["token_type_ids"] = np.zeros_like(ids)
            hidden = self.session.run(None, feed)[0]
            vec = (hidden * mask[..., None]).sum(1) / mask.sum(1, keepdims=True)
            vec = vec / np.linalg.norm(vec, axis=1, keepdims=True)
            if len(self._cache) > 5000:
                self._cache.clear()
            for t, v in zip(missing, vec):
                self._cache[t] = v
        return np.stack([self._cache[t] for t in texts])
