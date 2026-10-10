"""Whisper speech-to-text (OpenAI Whisper, MIT) on CTranslate2 for the local AI service.

The model files are the CTranslate2 conversions of the Hugging Face
repositories Systran/faster-whisper-<size> (MIT, the Whisper weights in
float16), pinned to a revision and checked by SHA-256 per file:

    $AIPBX_AI_DATA/models/<id>/config.json
    $AIPBX_AI_DATA/models/<id>/model.bin
    $AIPBX_AI_DATA/models/<id>/vocabulary.txt

CTranslate2 (`ctranslate2` on PyPI, MIT, about 140 MB) loads them with int8
weights on the CPU and runs encoder and decoder; everything else is numpy
here, so the runtime needs neither PyTorch nor the faster-whisper package
(which would add PyAV, the Hugging Face client and the tokenizers library,
about 165 MB more):

  * the log-Mel spectrogram (80 or 128 bands, 25 ms window, 10 ms hop) is
    computed as Whisper does;
  * the prompt names the language of the catalogue entry
    (spec.source["language"]: "tr", "de", "en", ...) and asks for a
    transcription without timestamps, so the language is never guessed;
  * the byte-level BPE tokens of the result become text through the GPT-2
    byte table (no tokenizer library).

Speed on a small server (docs/local-ai.md): Whisper's encoder always works on
a 30 s window, which made a 3 s request take 3.4 s with the small model on
2 cores. Here the encoder sees only the audio plus 3 s of silence
(CONTEXT_PAD): 0.9 s for the same request, with almost the same accuracy.
The price is that the decoder now and then repeats its sentence; the output
is capped at a length that fits the audio (TOKENS_PER_SECOND) and a repeat is
removed (collapse_repeats). Beam size 5: fewer word errors than greedy
decoding on our test audio (docs/local-ai.md) for about 50 % more time.

The audio is not cut by a VAD here; the caller (voice_requests) sends one
utterance. When Whisper itself thinks there is no speech (no-speech
probability > 0.6 and an average log probability < -1, faster-whisper's
rule), the text is empty, so silence does not come back as an invented
sentence. Audio longer than 30 s is transcribed in 30 s pieces.
"""
import gc
import hashlib
import os
import re
import shutil
import urllib.request
from pathlib import Path

from .vosk_backend import pcm16_from_wav

REPO_URL = "https://huggingface.co/"
RATE = 16000
N_FFT, HOP, CHUNK_SECONDS = 400, 160, 30
N_FRAMES = CHUNK_SECONDS * RATE // HOP            # 3000 frames of 10 ms
MAX_TOKENS = 224                                  # Whisper's limit for the generated part
TOKENS_BASE, TOKENS_PER_SECOND = 10, 12           # cap for one piece of audio (speech: ~3-8 tokens/s)
NO_SPEECH_PROB, LOGPROB_FLOOR = 0.6, -1.0
BEAM_SIZE = 5
CONTEXT_PAD = 3.0       # the encoder sees the audio + 3 s of silence; None: Whisper's full 30 s
_REPEAT = re.compile(r"(\S.{4,}?)(?:\s+\1)+", re.S)


def _sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


# ---- features (numpy) -----------------------------------------------------

def mel_filters(np, n_mels, sr=RATE, n_fft=N_FFT):
    """Mel filter bank of librosa.filters.mel(sr, n_fft, n_mels) (Slaney scale and norm), as Whisper uses."""
    f_sp, min_log_hz = 200.0 / 3, 1000.0
    min_log_mel, logstep = min_log_hz / f_sp, np.log(6.4) / 27.0

    def hz_to_mel(f):
        f = np.asarray(f, dtype=np.float64)
        return np.where(f >= min_log_hz, min_log_mel + np.log(np.maximum(f, 1e-10) / min_log_hz) / logstep, f / f_sp)

    def mel_to_hz(m):
        m = np.asarray(m, dtype=np.float64)
        return np.where(m >= min_log_mel, min_log_hz * np.exp(logstep * (m - min_log_mel)), m * f_sp)

    fft_freqs = np.linspace(0, sr / 2, 1 + n_fft // 2)
    mel_f = mel_to_hz(np.linspace(hz_to_mel(0.0), hz_to_mel(sr / 2), n_mels + 2))
    fdiff = np.diff(mel_f)
    ramps = mel_f[:, None] - fft_freqs[None, :]
    lower = -ramps[:-2] / fdiff[:-1, None]
    upper = ramps[2:] / fdiff[1:, None]
    weights = np.maximum(0, np.minimum(lower, upper))
    weights *= (2.0 / (mel_f[2:n_mels + 2] - mel_f[:n_mels]))[:, None]
    return weights.astype(np.float32)


def log_mel(np, audio, filters, frames=N_FRAMES):
    """Whisper's log-Mel spectrogram of 16 kHz float audio (up to 30 s, padded with silence):
    (n_mels, frames) float32; frames=3000 is Whisper's full 30 s window."""
    x = np.asarray(audio, dtype=np.float32)[: CHUNK_SECONDS * RATE]
    x = np.concatenate([x, np.zeros(CHUNK_SECONDS * RATE, np.float32)])
    x = np.pad(x, N_FFT // 2, mode="reflect")
    window = (0.5 - 0.5 * np.cos(2 * np.pi * np.arange(N_FFT) / N_FFT)).astype(np.float32)
    n = min(1 + (len(x) - N_FFT) // HOP, frames + 1)     # the last frame is dropped, as in Whisper
    windows = x[np.arange(N_FFT)[None, :] + HOP * np.arange(n)[:, None]]
    power = np.abs(np.fft.rfft(windows * window, axis=1)) ** 2
    mel = filters @ power[:-1][:frames].T.astype(np.float32)
    log = np.log10(np.maximum(mel, 1e-10))
    log = np.maximum(log, log.max() - 8.0)
    return ((log + 4.0) / 4.0).astype(np.float32)


def collapse_repeats(text):
    """One copy of a phrase (5+ characters) that the decoder repeated back to back.

    On a shortened window Whisper sometimes says the same sentence again until
    the token cap cuts it; the cut-off start of a further copy goes too.
    """
    text = _REPEAT.sub(r"\1", text).strip()
    for i, ch in enumerate(text):
        head, tail = text[:i].strip(), text[i + 1:].strip()
        if ch == " " and len(head) >= 5 and tail and head.startswith(tail):
            return head
    return text


def _byte_decoder():
    """GPT-2's printable-character -> byte table (the inverse of bytes_to_unicode)."""
    bs = list(range(ord("!"), ord("~") + 1)) + list(range(ord("¡"), ord("¬") + 1)) + list(range(ord("®"), ord("ÿ") + 1))
    cs = bs[:]
    n = 0
    for b in range(256):
        if b not in bs:
            bs.append(b)
            cs.append(256 + n)
            n += 1
    return {chr(c): b for b, c in zip(bs, cs)}


_BYTES = _byte_decoder()


def tokens_to_text(tokens):
    """Text of Whisper's BPE token strings; special tokens (<|...|>) are left out."""
    data = bytearray()
    for tok in tokens:
        if tok.startswith("<|") and tok.endswith("|>"):
            continue
        for ch in tok:
            b = _BYTES.get(ch)
            if b is not None:
                data.append(b)
    return data.decode("utf-8", "replace").strip()


# ---- backend ----------------------------------------------------------------

class WhisperBackend:
    """spec.source = {"repo": "Systran/faster-whisper-small", "revision": ..., "language": "tr",
    "files": {"<name>": {"sha256": ..., "bytes": ...}, ...}}."""

    def __init__(self, spec, data_dir):
        self.spec = spec
        self.dir = Path(data_dir) / "models" / spec.id
        self._model = None
        self._filters = None
        self._np = None

    @property
    def language(self):
        return self.spec.source.get("language") or self.spec.languages[0].split("-")[0]

    @property
    def beam_size(self):
        return int(self.spec.source.get("beam_size", BEAM_SIZE))

    @property
    def context_pad(self):
        return self.spec.source.get("context_pad", CONTEXT_PAD)

    def _files(self):
        return self.spec.source["files"]

    def installed(self):
        return all((self.dir / name).is_file() for name in self._files())

    def disk_bytes(self):
        return sum((self.dir / n).stat().st_size for n in self._files() if (self.dir / n).is_file())

    def download(self, progress):
        self.dir.mkdir(parents=True, exist_ok=True)
        files = self._files()
        total = max(1, sum(f["bytes"] for f in files.values()))
        done = 0
        base = f"{REPO_URL}{self.spec.source['repo']}/resolve/{self.spec.source['revision']}/"
        for name, entry in files.items():
            target = self.dir / name
            if target.is_file() and _sha256(target) == entry["sha256"]:
                done += target.stat().st_size
                continue
            part = self.dir / (name + ".part")
            req = urllib.request.Request(base + name, headers={"User-Agent": "aipbx-ai"})
            try:
                with urllib.request.urlopen(req, timeout=60) as resp, open(part, "wb") as out:
                    while block := resp.read(1 << 16):
                        out.write(block)
                        done += len(block)
                        progress(min(99, 100 * done // total))
                got = _sha256(part)
                if got != entry["sha256"]:
                    raise RuntimeError(f"{name}: checksum mismatch ({got[:12]}…), not loaded")
                os.replace(part, target)
            finally:
                part.unlink(missing_ok=True)
        progress(100)

    def delete_files(self):
        self.unload()
        shutil.rmtree(self.dir, ignore_errors=True)

    def load(self):
        import ctranslate2
        import numpy as np

        for name, entry in self._files().items():
            if _sha256(self.dir / name) != entry["sha256"]:
                raise RuntimeError(f"{name}: checksum mismatch, download the model again")
        model = ctranslate2.models.Whisper(str(self.dir), device="cpu", compute_type="int8",
                                           intra_threads=os.cpu_count() or 1, inter_threads=1)
        self._np = np
        self._filters = mel_filters(np, getattr(model, "n_mels", 80))
        self._model = model
        self._run(np.zeros(RATE, np.float32))      # warm-up: the first run allocates its buffers

    def unload(self):
        if self._model is not None:
            self._model = None
            gc.collect()

    def _prompt(self):
        return ["<|startoftranscript|>", f"<|{self.language}|>", "<|transcribe|>", "<|notimestamps|>"]

    def _run(self, audio):
        import ctranslate2

        model, np = self._model, self._np
        if model is None:
            raise RuntimeError("model not loaded")
        frames = N_FRAMES
        if self.context_pad is not None:
            frames = min(N_FRAMES, (len(audio) // HOP + int(self.context_pad * 100) + 1) // 2 * 2)
        features = ctranslate2.StorageView.from_array(
            np.ascontiguousarray(log_mel(np, audio, self._filters, frames)[None]))
        prompt = self._prompt()
        tokens = min(MAX_TOKENS, TOKENS_BASE + int(TOKENS_PER_SECOND * len(audio) / RATE))
        result = model.generate(features, [prompt], beam_size=self.beam_size, max_length=len(prompt) + tokens,
                                return_scores=True, return_no_speech_prob=True, suppress_blank=True)[0]
        if result.no_speech_prob > NO_SPEECH_PROB and result.scores and result.scores[0] < LOGPROB_FLOOR:
            return ""
        return collapse_repeats(tokens_to_text(result.sequences[0]))

    def transcribe(self, wav):
        """{"text": ..., "seconds": audio length} from WAV bytes."""
        if self._model is None:
            raise RuntimeError("model not loaded")
        pcm, seconds = pcm16_from_wav(wav)
        audio = self._np.frombuffer(pcm, dtype="<i2").astype(self._np.float32) / 32768.0
        # Whisper hears 30 s at a time; longer audio (up to 60 s) is cut into 30 s pieces.
        step = CHUNK_SECONDS * RATE
        parts = [self._run(audio[i:i + step]) for i in range(0, max(1, len(audio)), step)]
        return {"text": " ".join(p for p in parts if p), "seconds": round(seconds, 2)}
