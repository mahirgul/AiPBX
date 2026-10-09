"""EMA Lightning on ONNX Runtime and numpy, without PyTorch.

The three graphs (text.onnx, sound.onnx, decoder.onnx) are EMA Lightning's
own weights exported with export/ema_to_onnx.py of sewox/turkish-neural-tts;
see the NOTICE of the models-ema-lightning-1 release. This file does what the
ema-lightning package (Apache-2.0, github.com/canberk7/ema-lightning) does
around its PyTorch model: the text frontend and the chunker are copied from
it, and the duration plan, the frame timeline (the export script's
timeline()), the noise and the 48 kHz -> N kHz resampler are ported to numpy.
Checked against the PyTorch engine with the same noise: largest audio
difference 4e-5 at an RMS level of 0.06, resampler identical.

numpy, onnxruntime and normalizer_tr are imported by the backend only when
the model loads, so the service and its tests start without them.
"""
import json
import re
import unicodedata
from itertools import pairwise

import numpy as np
import onnxruntime as ort
from normalizer_tr import Normalizer

RATE = 48000
MAX_WORD_FRAMES = 250
MAX_FRAMES = 3000

# ---- frontend.py (verbatim logic) ----
TURKISH = frozenset("çğıöşüÇĞİÖŞÜ")
TYPOGRAPHY = str.maketrans({"’": "'", "‘": "'", "ʼ": "'", "´": "'", "`": "'", "“": '"', "”": '"', "„": '"',
                            "«": '"', "»": '"', "–": "-", "—": "-", "−": "-", "…": "..."})
UNSAFE = re.compile("[\x00-\x08\x0b-\x1f\x7f-\x9f؜‎‏‪-‮⁦-⁩]")


class Frontend:
    def __init__(self, vocab):
        self.vocab = frozenset(vocab)
        self.normalizer = Normalizer()

    def __call__(self, text):
        text = UNSAFE.sub(" ", text.encode("utf-8", "ignore").decode("utf-8"))
        if not text.strip():
            return ""
        try:
            text = self.normalizer.normalize(text, ambiguity_policy="fallback").normalized_text
        except Exception:
            pass
        text = text.translate(TYPOGRAPHY).replace("İ", "i").replace("I", "ı").lower()
        out = []
        for ch in text:
            if ch not in TURKISH:
                ch = "".join(c for c in unicodedata.normalize("NFKD", ch) if not unicodedata.combining(c))
            out.append(ch if ch and all(c in self.vocab for c in ch) else " ")
        return re.sub(r"\s+", " ", "".join(out)).strip()


# ---- chunker.py (verbatim logic) ----
LETTERS_PER_SECOND, MAX_SECONDS, MAX_LETTERS = 18.0, 10.0, 250
CUTS = ((re.compile(r"[.!?]+[\"')]*(?= )"), 0.25), (re.compile(r"[,;:](?= )"), 0.12), (re.compile(r"\S(?= )"), 0.12))
LETTER = re.compile(r"[^\W\d_]")


def chunk(text, speed):
    limit = int(min(MAX_LETTERS, LETTERS_PER_SECOND * MAX_SECONDS * speed))
    pieces, rest = [], text.strip()
    while rest:
        cut, pause = len(rest), 0.0
        if len(rest) > limit:
            cut = limit
            for pattern, gap in CUTS:
                ends = [m.end() for m in pattern.finditer(rest, 0, limit + 1)]
                if ends:
                    cut, pause = ends[-1], gap
                    break
        piece, rest = rest[:cut].strip(), rest[cut:].strip()
        if LETTER.search(piece):
            if piece.rstrip("\"')")[-1:] not in (".", "!", "?"):
                piece = piece.rstrip(",;:- ") + "."
            pieces.append((piece, pause))
    if pieces:
        pieces[-1] = (pieces[-1][0], 0.0)
    return pieces


# ---- resampler (one-shot version of audio.Resampler: same windowed-sinc taps) ----
def resample(audio, rate):
    factor = RATE // rate
    if factor == 1:
        return audio
    half = 32 * factor
    n = np.arange(-half, half + 1, dtype=np.float64)
    cutoff = 0.45 / factor
    taps = 2 * cutoff * np.sinc(2 * cutoff * n) * np.blackman(2 * half + 1)
    taps = (taps / taps.sum()).astype(np.float32)
    padded = np.concatenate([np.zeros(half, np.float32), audio, np.zeros(half + factor, np.float32)])
    full = np.convolve(padded, taps[::-1], mode="valid")
    out = full[::factor]
    return out[: (len(audio) + factor - 1) // factor]


class EmaOnnx:
    def __init__(self, model_dir, threads=0):
        meta = json.load(open(f"{model_dir}/meta.json"))
        self.stoi = {ch: i for i, ch in enumerate(meta["vocab"])}
        self.steps = len(meta["times"])
        self.latent_dim = meta["latent_dim"]
        self.frontend = Frontend(meta["vocab"])
        so = ort.SessionOptions()
        if threads:
            so.intra_op_num_threads = threads
        prov = ["CPUExecutionProvider"]
        self.text = ort.InferenceSession(f"{model_dir}/text.onnx", so, providers=prov)
        self.sound = ort.InferenceSession(f"{model_dir}/sound.onnx", so, providers=prov)
        self.decoder = ort.InferenceSession(f"{model_dir}/decoder.onnx", so, providers=prov)

    def piece_audio(self, text, speed, rng):
        ids = np.array([[self.stoi.get(ch, 1) for ch in text]], np.int64)
        L = ids.shape[1]
        starts = [i for i, ch in enumerate(text) if ch != " " and (i == 0 or text[i - 1] == " ")] or [0]
        bounds = [0] + starts[1:] + [len(text)]
        cw, wstart = [], []
        for w, (a, b) in enumerate(pairwise(bounds)):
            cw += [w] * (b - a)
            wstart += [a] * (b - a)
        cw, wstart = np.array(cw), np.array(wstart)
        mask = np.ones((1, L), np.float32)
        h, dur = self.text.run(None, {"ids": ids, "mask": mask})
        dur = dur[0] / speed
        n_words = int(cw[-1]) + 1
        counts = np.clip(np.round(np.bincount(cw, weights=dur, minlength=n_words)), 1, MAX_WORD_FRAMES).astype(np.int64)
        frames = min(int(counts.sum()), MAX_FRAMES)
        fw = np.repeat(np.arange(n_words), counts)[:frames]
        fp = ((np.arange(frames) - (np.cumsum(counts) - counts)[fw]) / counts[fw]).astype(np.float32)
        # timeline() of the export script, in numpy
        c = np.clip(dur, 1e-4, None)
        done = np.cumsum(c)
        before = done - c
        total = np.bincount(cw, weights=c, minlength=n_words)[cw]
        cp = np.clip((done - before[wstart] - 0.5 * c) / np.clip(total, 1e-8, None), 0.0, 1.0)
        wlen = np.clip(np.bincount(cw, minlength=n_words).astype(np.float64), 1.0, None)
        woff = np.cumsum(wlen) - wlen
        cg = (woff[cw] + cp * wlen[cw]).astype(np.float32)[None]
        fg = (woff[fw] + fp * wlen[fw]).astype(np.float32)[None]
        rel = cw[None, :] - fw[:, None]
        allow = ((rel >= -1) & (rel <= 1)).astype(np.float32)[None]
        noise = rng.standard_normal((1, self.steps, frames, self.latent_dim)).astype(np.float32)
        lat, = self.sound.run(None, {"h": h, "cg": cg, "fg": fg, "allow": allow, "fmask": np.ones((1, frames), np.float32),
                                     "noise": noise, "fpos": np.arange(frames, dtype=np.float32)[None]})
        audio, = self.decoder.run(None, {"z": np.ascontiguousarray(lat.transpose(0, 2, 1))})
        return audio[0]

    def say(self, text, speed=1.0, sample_rate=8000, seed=None, on_first_audio=None):
        """float32 audio at sample_rate; on_first_audio() runs when the first piece is ready."""
        rng = np.random.default_rng(seed)
        parts = []
        for piece, pause in chunk(self.frontend(text), speed):
            parts.append(self.piece_audio(piece, speed, rng))
            if on_first_audio is not None and len(parts) == 1:
                on_first_audio()
            if pause:
                parts.append(np.zeros(round(pause * RATE), np.float32))
        audio = np.concatenate(parts) if parts else np.zeros(0, np.float32)
        return resample(audio, sample_rate)
