"""Kokoro-82M voices on ONNX Runtime and numpy, without the kokoro package.

Kokoro-82M (github.com/hexgrad/kokoro, Apache-2.0) is a StyleTTS 2 model
with a fixed 512-token context. Its ONNX export, onnx-community/
Kokoro-82M-v1.0-ONNX on Hugging Face, takes

    input_ids  int64   [1, n + 2]   0, token ids of the phonemes, 0
    style      float32 [1, 256]     a row of the voice pack (below)
    speed      float32 [1]          1.0 = normal; the durations are divided by it

and returns float32 audio at 24 kHz. A voice pack (`voices/<name>.bin`) is
a raw float32 array [510, 1, 256]: one style vector per input length. The
vocabulary (one id per phoneme character) is `model.vocab` of the export's
tokenizer.json, the same as `vocab` of hexgrad/Kokoro-82M config.json.

What happens around the model follows these references:

* Style row and input layout: hexgrad/kokoro `kokoro/pipeline.py`
  (KPipeline.infer: `pack[len(ps)-1]`, ps being the phoneme string, one
  token per character) and `kokoro/model.py` (forward: input_ids =
  [0, *tokens, 0]); thewh1teagle/kokoro-onnx (MIT) `_style_for()` does the
  same ("n phonemes use row n - 1"). So n tokens (without the two pads) use
  row n - 1, and n is at most 510.

* Chunking: at most 510 tokens per run (pipeline.py truncates beyond that,
  kokoro-onnx `chunker.py` splits at sentence, then clause, then word
  boundaries). Here each sentence is one run, so the first sentence can be
  played while the rest is made; a sentence over 510 tokens is cut after a
  clause mark, else at a word gap (the Piper engine's split_long()).
  VOICES.md warns that voices are weak on utterances under 10-20 tokens and
  suggests bundling short ones, so a sentence under SHORT_TOKENS is joined
  with its neighbour when the two fit.

* Phonemes: Kokoro was trained on the phonemes of misaki
  (github.com/hexgrad/misaki), its own G2P. For English misaki uses a
  pronunciation lexicon plus a neural fallback and only falls back to
  espeak-ng for unknown words; for Spanish, French, Hindi, Italian and
  Brazilian Portuguese it always uses espeak-ng. This engine uses espeak-ng
  for every language, as kokoro-onnx does: no extra Python packages (misaki
  pulls in spaCy and its models), at the cost of English pronunciation that
  is somewhat less natural than with misaki (espeak-ng's own stress and
  vowel choices, and numbers or currency read the espeak-ng way).

  espeak-ng runs as the Piper engine runs it (engines/piper.py: one call per
  text, clauses split with espeak-ng's rules, terminators re-added) but with
  `--ipa=2`, which joins the letters of a multi-letter phoneme with the tie
  U+0361, as the phonemizer package does for misaki (tie='^'). The espeak
  phonemes are then rewritten to Kokoro's as misaki `espeak.py` does:
  EspeakFallback (English, `E2M`, the US/GB rules, "ɾ"->"T" and "ʔ"->"t" for
  model version 1.0) and EspeakG2P (other languages: diphthongs and
  affricates to single letters, ties and hyphens removed). Deviations, all
  checked against misaki's own lexicon spellings ("hair" hˈɛː, "here" hˈɪə
  in British English):
    - misaki replaces a bare "e" by "A" before its British "e^ə" -> "ɛː"
      rule, which therefore never matches; the British rules run first here.
    - misaki's British "iə" -> "ɪə" and US "ɪə" -> "iə" rules never match a
      tied "i^ə"; here they run after the ties are removed.
    - a linking r ("ɚɹ", "weather is") would become "əɹɹ"; it becomes "əɹ".
    - English words joined by a hyphen ("thirty-four") are read as two
      words, as misaki does; espeak-ng would make them one.
    - espeak-ng's language switch flags "(en)" are removed for every
      language (misaki keeps them in the English fallback).

* Audio: each run's output is joined with a short pause after the
  punctuation (kokoro-onnx trims the model's leading and trailing silence
  and adds sentence and clause pauses; the model's own silence is kept
  here, it is short), scaled down only if a peak exceeds PEAK and resampled
  to the requested rate with the Piper engine's resampler.

Japanese and Mandarin voices need misaki's G2P and are not supported.
"""
import re
from pathlib import Path

from .piper import (ELLIPSIS, check_request, clean_text, espeak_available, espeak_clauses,
                    resample, split_clauses, split_long)

try:  # the service and the tests start without them; KokoroVoice needs both
    import numpy as np
except ImportError:  # pragma: no cover
    np = None
try:
    import onnxruntime as ort
except ImportError:  # pragma: no cover
    ort = None

SAMPLE_RATE = 24000
STYLE_DIM = 256
MAX_TOKENS = 510        # 512-token context minus the two pads
SHORT_TOKENS = 20       # shorter runs are joined with a neighbour (VOICES.md)
PEAK = 0.95
TIE = "͡"
SYLLABIC = "̩"
# Voice name prefix -> (espeak-ng voice, language code). hexgrad/kokoro
# pipeline.py LANG_CODES; "a" and "b" are misaki's English, whose espeak-ng
# fallback uses en-us / en-gb.
DIALECTS = {
    "a": ("en-us", "en-US"),
    "b": ("en-gb", "en-GB"),
    "e": ("es", "es"),
    "f": ("fr-fr", "fr"),
    "h": ("hi", "hi"),
    "i": ("it", "it"),
    "p": ("pt-br", "pt-BR"),
}
# Pause after a run, by its last character (seconds).
PAUSES = {".": 0.25, "!": 0.25, "?": 0.25, ELLIPSIS: 0.25, ",": 0.1, ":": 0.1, ";": 0.1}
TERMINATORS = {".": ".", "?": "?", "!": "!", ",": ",", ":": ":", ";": ";", ELLIPSIS: ELLIPSIS, "": ""}
SENTENCE_END = frozenset(".?!")
FLAGS = re.compile(r"\([a-z][a-z0-9-]*\)")
# English "thirty-four", "check-in": espeak-ng writes them as one word
# ("θˈɜɹTifˈɔɹ"), misaki as two; a space gives misaki's form.
WORD_HYPHEN = re.compile(r"(?<=[^\W\d_])-(?=[^\W\d_])")


def _by_length(table):
    return sorted(table.items(), key=lambda kv: -len(kv[0]))


# misaki espeak.py EspeakFallback.E2M ("^" is the tie)
EN_E2M = _by_length({
    "ʔˌn" + SYLLABIC: "ʔn", "ʔn" + SYLLABIC: "ʔn",
    "a" + TIE + "ɪ": "I", "a" + TIE + "ʊ": "W",
    "d" + TIE + "ʒ": "ʤ",
    "e" + TIE + "ɪ": "A", "e": "A",
    "t" + TIE + "ʃ": "ʧ",
    "ɔ" + TIE + "ɪ": "Y",
    "ə" + TIE + "l": "ᵊl",
    "ʲo": "jo", "ʲə": "jə", "ʲ": "",
    "ɚ": "əɹ",
    "r": "ɹ",
    "x": "k", "ç": "k",
    "ɐ": "ə",
    "ɬ": "l",
    "̃": "",
})
# misaki espeak.py EspeakG2P.e2m (version 1.0: no nasal vowel letters)
G2P_E2M = _by_length({
    "a" + TIE + "ɪ": "I", "a" + TIE + "ʊ": "W",
    "d" + TIE + "z": "ʣ", "d" + TIE + "ʒ": "ʤ",
    "e" + TIE + "ɪ": "A",
    "o" + TIE + "ʊ": "O", "ə" + TIE + "ʊ": "Q",
    "s" + TIE + "s": "S",
    "t" + TIE + "s": "ʦ", "t" + TIE + "ʃ": "ʧ",
    "ɔ" + TIE + "ɪ": "Y",
})


def numpy_available():
    return np is not None and ort is not None


def dialect(voice_name):
    """The voice's (espeak-ng voice, language code), from its name prefix."""
    prefix = str(voice_name)[:1]
    if prefix not in DIALECTS:
        raise ValueError(f"voice {voice_name!r}: only {', '.join(sorted(DIALECTS))}* voices "
                         "are supported (Japanese and Mandarin need misaki)")
    return DIALECTS[prefix]


def to_kokoro(ipa, espeak_voice):
    """espeak-ng phonemes (ties as U+0361) -> Kokoro's phoneme letters."""
    ps = FLAGS.sub("", ipa)
    if espeak_voice in ("en-us", "en-gb"):
        british = espeak_voice == "en-gb"
        ps = ps.replace("ɚɹ", "ɚ")             # linking r
        if british:
            ps = ps.replace("e" + TIE + "ə", "ɛː")  # before "e" -> "A"
        for old, new in EN_E2M:
            ps = ps.replace(old, new)
        ps = re.sub("(\\S)" + SYLLABIC, "ᵊ\\1", ps).replace(SYLLABIC, "")
        ps = ps.replace(TIE, "")
        if british:
            ps = ps.replace("iə", "ɪə")
            ps = ps.replace("əʊ", "Q")
        else:
            ps = ps.replace("oʊ", "O")
            ps = ps.replace("ɜːɹ", "ɜɹ")
            ps = ps.replace("ɜː", "ɜɹ")
            ps = ps.replace("ɪə", "iə")
            ps = ps.replace("ː", "")
        ps = ps.replace("o", "ɔ")                         # espeak-ng < 1.52
        return ps.replace("ɾ", "T").replace("ʔ", "t")
    for old, new in G2P_E2M:
        ps = ps.replace(old, new)
    return ps.replace(TIE, "").replace("-", "")


def sentences_from_clauses(clause_ipa, terminators, espeak_voice):
    """Kokoro phoneme string per sentence, punctuation as misaki writes it
    ("həlˈO, wˈɜɹld.": the mark right after the word, then a space)."""
    sentences, current = [], None
    for ipa, term in zip(clause_ipa, terminators):
        ps = " ".join(to_kokoro(ipa, espeak_voice).split())
        if current is None:
            current = []
            sentences.append(current)
        current.append(ps + TERMINATORS.get(term, ""))
        if term in SENTENCE_END:
            current = None
    out = [" ".join(p for p in s if p) for s in sentences]
    return [s for s in out if s.strip(".,:;?!" + ELLIPSIS + " ")]


def known(ps, vocab, missing=None):
    """The characters of ps that are in the vocabulary (each one token)."""
    out = []
    for ch in ps:
        if ch in vocab:
            out.append(ch)
        elif missing is not None:
            missing[ch] = missing.get(ch, 0) + 1
    return out


def make_runs(sentences, vocab, missing=None, limit=MAX_TOKENS, short=SHORT_TOKENS):
    """Phoneme lists of at most `limit` tokens, one per model run.

    Each sentence is one run; a longer one is cut after a clause mark or at
    a word gap; a run under `short` tokens is joined with the next one (or
    the last one with the one before) when both fit in `limit`.
    """
    pieces = []
    for s in sentences:
        chars = known(s, vocab, missing)
        for part in split_long(chars, limit):
            while part and part[-1] == " ":
                part = part[:-1]
            while part and part[0] == " ":
                part = part[1:]
            if part:
                pieces.append(part)
    runs = []
    for part in pieces:
        if runs and len(runs[-1]) < short and len(runs[-1]) + 1 + len(part) <= limit:
            runs[-1] = runs[-1] + [" "] + part
        else:
            runs.append(part)
    if len(runs) > 1 and len(runs[-1]) < short and len(runs[-2]) + 1 + len(runs[-1]) <= limit:
        runs[-2:] = [runs[-2] + [" "] + runs[-1]]
    return runs


def style_row(pack, n_tokens):
    """pipeline.py: pack[len(ps) - 1], as a [1, 256] array."""
    if n_tokens < 1:
        raise ValueError("no tokens")
    return pack[min(n_tokens, len(pack)) - 1].reshape(1, -1)


def load_vocab(config_path):
    """Phoneme -> id from tokenizer.json (model.vocab) or a Kokoro config.json (vocab)."""
    import json

    with open(config_path, encoding="utf-8") as f:
        cfg = json.load(f)
    vocab = cfg.get("vocab") or (cfg.get("model") or {}).get("vocab")
    if not isinstance(vocab, dict) or not vocab:
        raise ValueError(f"{config_path}: no phoneme vocabulary")
    vocab = {k: int(v) for k, v in vocab.items() if len(k) == 1}
    vocab.pop("$", None)  # the pad, never a phoneme
    return vocab


def load_voice_packs(path):
    """{name: float32 [N, 1, 256]} from a voices/ folder of .bin files, one .bin
    file (named after its stem), an .npz, or a {name: .bin path} dict."""
    if isinstance(path, dict):
        return {name: np.fromfile(f, dtype=np.float32).reshape(-1, 1, STYLE_DIM) for name, f in path.items()}
    path = Path(path)
    files = sorted(path.glob("*.bin")) if path.is_dir() else [path]
    packs = {}
    for f in files:
        if f.suffix == ".npz":
            with np.load(f) as z:
                packs.update({k: np.asarray(z[k], np.float32).reshape(-1, 1, STYLE_DIM) for k in z.files})
        else:
            packs[f.stem] = np.fromfile(f, dtype=np.float32).reshape(-1, 1, STYLE_DIM)
    if not packs:
        raise ValueError(f"{path}: no voice packs")
    return packs


class KokoroVoice:
    def __init__(self, model_path, voices, config_path, threads=0):
        if not numpy_available():
            raise RuntimeError("numpy and onnxruntime are needed for Kokoro voices")
        if not espeak_available():
            raise RuntimeError("espeak-ng is not installed")
        self.vocab = load_vocab(config_path)
        self.packs = {k: v for k, v in load_voice_packs(voices).items() if k[:1] in DIALECTS}
        if not self.packs:
            raise ValueError("no supported voice in the voice packs")
        self.sample_rate = SAMPLE_RATE
        self.languages = sorted({dialect(name)[1] for name in self.packs})
        self.missing = {}

        so = ort.SessionOptions()
        if threads:
            so.intra_op_num_threads = threads
            so.inter_op_num_threads = 1
        self.session = ort.InferenceSession(str(model_path), so, providers=["CPUExecutionProvider"])
        inputs = {i.name: i.type for i in self.session.get_inputs()}
        # kokoro-onnx: older exports call the token input "tokens"
        self._ids_name = "input_ids" if "input_ids" in inputs else "tokens"
        self._int_speed = "int" in inputs.get("speed", "float")

    @property
    def voices(self):
        return sorted(self.packs)

    def add_voice(self, name, path):
        """Loads one more voice pack (.bin) for this model."""
        dialect(name)
        self.packs = dict(self.packs, **load_voice_packs({name: path}))
        self.languages = sorted({dialect(n)[1] for n in self.packs})

    def remove_voice(self, name):
        self.packs = {k: v for k, v in self.packs.items() if k != name}
        self.languages = sorted({dialect(n)[1] for n in self.packs})

    def _pack(self, voice):
        if voice is None:
            if len(self.packs) != 1:
                raise ValueError("several voices are loaded: name one")
            voice = next(iter(self.packs))
        if voice not in self.packs:
            raise ValueError(f"unknown voice {voice!r}")
        return voice, self.packs[voice]

    # ---- text -> runs -------------------------------------------------

    def phonemize(self, text, voice):
        """Kokoro phoneme string per sentence."""
        espeak_voice = dialect(voice)[0]
        text = clean_text(text)
        if espeak_voice in ("en-us", "en-gb"):
            text = WORD_HYPHEN.sub(" ", text)
        clauses = split_clauses(text)
        ipa = espeak_clauses(text, clauses, espeak_voice, ipa="--ipa=2")
        return sentences_from_clauses(ipa, [t for _, t in clauses], espeak_voice)

    def runs(self, text, voice):
        return make_runs(self.phonemize(text, voice), self.vocab, self.missing)

    def synthesize_tokens(self, tokens, pack, speed):
        ids = [self.vocab[p] for p in tokens]
        speed = max(1, round(speed)) if self._int_speed else speed
        args = {
            self._ids_name: np.array([[0, *ids, 0]], dtype=np.int64),
            "style": np.asarray(style_row(pack, len(ids)), dtype=np.float32),
            "speed": np.array([speed], dtype=np.int32 if self._int_speed else np.float32),
        }
        return np.asarray(self.session.run(None, args)[0], dtype=np.float32).reshape(-1)

    # ---- public -------------------------------------------------------

    def say(self, text, speed=1.0, sample_rate=8000, on_first_audio=None, voice=None):
        """float32 mono audio at sample_rate; on_first_audio() runs once when
        the first run is ready."""
        speed = check_request(text, speed, sample_rate)
        voice, pack = self._pack(voice)
        parts = []
        for tokens in self.runs(text, voice):
            audio = self.synthesize_tokens(tokens, pack, speed)
            pause = PAUSES.get(tokens[-1], 0.0) / speed
            parts.append(audio)
            parts.append(np.zeros(round(pause * SAMPLE_RATE), np.float32))
            if on_first_audio is not None and len(parts) == 2:
                on_first_audio()
        if parts:
            parts.pop()  # no pause after the last run
        out = np.concatenate(parts) if parts else np.zeros(0, np.float32)
        peak = float(np.max(np.abs(out))) if len(out) else 0.0
        if peak > PEAK:
            out *= PEAK / peak
        return resample(out, SAMPLE_RATE, sample_rate)
