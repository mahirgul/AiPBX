"""Piper voices on ONNX Runtime and numpy, without the piper-tts package.

A Piper voice is a VITS model exported to ONNX (`<voice>.onnx`) plus its
config (`<voice>.onnx.json`), as published in the Hugging Face repository
rhasspy/piper-voices. This module does what Piper (github.com/rhasspy/piper,
MIT, archived) does around that model, following these sources:

* Phonemes: piper-phonemize `src/phonemize.cpp` (phonemize_eSpeak). Piper
  calls espeak_TextToPhonemesWithTerminator() of its espeak-ng fork once per
  clause, in IPA mode (phonememode 0x02, no tie character), with the voice
  named by the config's `espeak.voice`. Each clause's phoneme string is NFD
  decomposed and split into single code points, "(lang)" switch flags are
  dropped, and the clause terminator is appended as a phoneme: "." "?" "!"
  end the sentence; "," ":" ";" are followed by a space " " and the sentence
  goes on. A clause that ends at the end of the text without punctuation
  (CLAUSE_EOF) gets nothing appended.

  Here espeak-ng runs as a separate program (Ubuntu package espeak-ng, GPL):

      espeak-ng -q -b 1 --ipa -v <espeak.voice> --stdin

  `--ipa` without a number is the same phoneme mode 0x02 (IPA, no separator,
  no tie), `-q` synthesizes no audio, `-b 1` reads UTF-8, and the text goes
  in on stdin as one line (control characters and line breaks made spaces:
  a blank line would be a paragraph break). The program prints one line per
  clause but not the clause terminator, so split_clauses() finds the same
  clause ends with the rules of espeak-ng's ReadClause() (readclause.c,
  1.52) and gives each line its terminator. When the counts differ, each
  clause is phonemized by a call of its own.

  Checked against the official piper-tts 1.8.0 package (piper1-gpl, its
  bundled espeak-ng 1.52.0.1 fork) on 28 German and English sentences:
  phonemes and ids are identical except for small espeak-ng data
  differences ("p.m." -> "pˌiːʲˈɛm" there, "pˌiːˈɛm" here) and two things. (1) Ubuntu's espeak-ng 1.52.0 writes German "r" after a
  plosive as the tap "ɾ" ("bɾˈoːt"), the fork that voices were trained with
  writes "r"; when the installed espeak-ng does that, it is mapped back
  (ESPEAK_FIXES). (2) After an ellipsis ("..."; CLAUSE_SEMICOLON with
  CLAUSE_OPTIONAL_SPACE_AFTER) Piper appends nothing, gluing the next
  clause's first word to the last one ("hˈaloːviː"); a word space is kept
  here instead.

* Phoneme ids: piper-phonemize `src/phoneme_ids.cpp` (phonemes_to_ids) with
  PhonemeIdConfig defaults from `src/phoneme_ids.hpp` (pad "_", bos "^",
  eos "$", interspersePad, addBos, addEos). That is the function training
  used (piper_train/preprocess.py -> phoneme_ids_espeak):

      ^ _ p1 _ p2 _ ... pn _ $

  Phonemes missing from the config's `phoneme_id_map` are skipped. Note that
  python_run/piper/voice.py (phonemes_to_ids) leaves out the pad right after
  "^"; the C++ form used in training, by the C++ piper binary and by its
  successor piper1-gpl (src/piper/phoneme_ids.py) is kept here.

* Synthesis: python_run/piper/voice.py (synthesize_ids_to_raw). Inputs
  `input` int64 [1, n], `input_lengths` int64 [1], `scales` float32
  [noise_scale, length_scale, noise_w] from the config's `inference` block,
  and `sid` int64 [1] for voices with num_speakers > 1 (the config's
  `default_speaker_id`, else 0, when no speaker is given; a speaker can be
  given by number or by a name of `speaker_id_map`). Each sentence is
  synthesized on its own and peak-normalized as audio_float_to_int16() in
  python_run/piper/util.py does (to PEAK, leaving headroom for the
  resampler); sentences are joined with a short silence (Piper's
  --sentence_silence). With noise_scale = noise_w = 0 the audio is
  bit-identical to piper-tts 1.8.0 for the voices tried.

Only voices with phoneme_type "espeak" (the default) are supported, and
Arabic voices would need libtashkeel diacritization first, which Piper adds
and this module does not.
"""
import json
import re
import shutil
import subprocess
import unicodedata
from math import gcd

try:  # the service and the tests start without them; PiperVoice needs both
    import numpy as np
except ImportError:  # pragma: no cover
    np = None
try:
    import onnxruntime as ort
except ImportError:  # pragma: no cover
    ort = None

ESPEAK = "espeak-ng"
BOS, EOS, PAD = "^", "$", "_"
SENTENCE_END = frozenset(".?!")
CLAUSE_PUNCT = frozenset(",:;")
MAX_TEXT = 5000
MIN_SPEED, MAX_SPEED = 0.5, 2.0
RATES = (8000, 16000, 24000, 48000)
SENTENCE_SILENCE = 0.2  # seconds between sentences
MAX_PHONEMES = 400  # longer sentences are synthesized in parts (VITS attention is quadratic)
PEAK = 0.95
# piper-phonemize DEFAULT_PHONEME_MAP (phonemize.cpp)
DEFAULT_PHONEME_MAP = {"pt-br": {"c": ["k"]}}

UNSAFE = re.compile("[\x00-\x08\x0b-\x1f\x7f-\x9f\u061c\u200e\u200f\u202a-\u202e\u2066-\u2069]")
# Clause punctuation as espeak-ng's ReadClause() (readclause.c, 1.52) reads
# it: a run of dots (an ellipsis), a run of ? and ! (the first one counts),
# or one of . , : ; and the dashes, followed by white space, the end of the
# text, or a bracket or quote (IsBracket()); those closing marks stay with
# the clause.
CLOSERS = r"()\[\]{}<>\"'`\u00ab\u00bb\u2018-\u201f"  # a regex character class body
CLAUSE_END = re.compile(rf"(\.{{2,}}|\u2026|[?!]+|[.,:;\u2013\u2014])(?=[\s{CLOSERS}]|$)[{CLOSERS}]*")
ALNUM = re.compile(r"[^\W_]")
ELLIPSIS = "\u2026"


def espeak_available():
    """True when the espeak-ng program can be run."""
    return shutil.which(ESPEAK) is not None


def check_request(text, speed, sample_rate):
    """Raise ValueError for a request say() does not take."""
    if not isinstance(text, str):
        raise ValueError("text must be a string")
    if len(text) > MAX_TEXT:
        raise ValueError(f"text is longer than {MAX_TEXT} characters")
    try:
        speed = float(speed)
    except (TypeError, ValueError):
        raise ValueError("speed must be a number") from None
    if not MIN_SPEED <= speed <= MAX_SPEED:
        raise ValueError(f"speed must be between {MIN_SPEED} and {MAX_SPEED}")
    if isinstance(sample_rate, bool) or sample_rate not in RATES:
        raise ValueError(f"sample_rate must be one of {', '.join(map(str, RATES))}")
    return speed


def clean_text(text):
    """One line of text: control and direction characters become spaces."""
    text = UNSAFE.sub(" ", text.encode("utf-8", "ignore").decode("utf-8"))
    return re.sub(r"\s+", " ", text).strip()


def split_clauses(text):
    """[(clause text, terminator)] where espeak-ng ends its clauses.

    The terminator is what Piper appends for the clause: "." "?" "!" end a
    sentence, "," ":" ";" do not (a dash counts as ";", CLAUSE_SEMICOLON),
    ELLIPSIS stands for "..." and "" for the end of the text. As in
    ReadClause(), a single "." followed by a lowercase word ("z. B. morgen",
    "am 3. oktober", "e.g. today") and punctuation before any letter or digit
    of the clause do not end it.
    """
    clauses, start = [], 0
    for m in CLAUSE_END.finditer(text):
        punct = m.group(1)
        if not ALNUM.search(text, start, m.start()):
            continue
        if punct == ".":
            rest = text[m.end(1):].lstrip()
            if rest[:1].islower():
                continue
        if punct[0] in "?!":
            term = punct[0]
        elif punct[0] == "." and len(punct) == 1:
            term = "."
        elif punct[0] in ".\u2026":
            term = ELLIPSIS
        elif punct in "\u2013\u2014":
            term = ";"
        else:
            term = punct
        clauses.append((text[start:m.end()].strip(), term))
        start = m.end()
    if ALNUM.search(text, start):
        clauses.append((text[start:].strip(), ""))
    elif clauses and text[start:].strip():
        clauses[-1] = (clauses[-1][0] + " " + text[start:].strip(), clauses[-1][1])
    return clauses


def _run_espeak(lines, voice, ipa="--ipa"):
    """Phoneme lines of espeak-ng; `ipa` is its IPA option ("--ipa=2": ties)."""
    proc = subprocess.run(
        [ESPEAK, "-q", "-b", "1", ipa, "-v", voice, "--stdin"],
        input="".join(line + "\n" for line in lines).encode("utf-8"),
        capture_output=True, timeout=60, check=False,
    )
    if proc.returncode != 0:
        err = proc.stderr.decode("utf-8", "replace").strip()
        raise RuntimeError(f"espeak-ng failed ({proc.returncode}): {err[:200]}")
    return [ln.strip() for ln in proc.stdout.decode("utf-8", "replace").splitlines() if ln.strip()]


def espeak_clauses(text, clauses, voice, ipa="--ipa"):
    """IPA phoneme string per clause, from the espeak-ng program.

    espeak-ng reads the whole text in one call, so abbreviations and numbers
    see their neighbours as they did in training; it prints one line per
    clause. When its clause count differs from split_clauses() (a quote
    right after the punctuation, a word espeak-ng's dictionary handles
    differently), each clause is phonemized by a call of its own instead.
    """
    if not clauses:
        return []
    out = _run_espeak([text], voice, ipa)
    if len(out) == len(clauses):
        return out
    return [" ".join(_run_espeak([clause], voice, ipa)) for clause, _ in clauses]


# Compensation for espeak-ng versions that differ from the one Piper voices
# were trained with: voice -> (probe word, phonemes that show the newer
# behaviour, [(pattern, replacement)]).
ESPEAK_FIXES = {
    "de": ("Brot", "b\u027e\u02c8o\u02d0t", [(re.compile("(?<=[pbtdk\u0261])\u027e"), "r")]),
}


def espeak_fixes(voice):
    """The fixes the installed espeak-ng needs for this voice ([] if none)."""
    fix = ESPEAK_FIXES.get(voice)
    if not fix:
        return []
    probe, newer, rules = fix
    return rules if _run_espeak([probe], voice) == [newer] else []


def apply_fixes(ipa, fixes):
    for pattern, repl in fixes:
        ipa = pattern.sub(repl, ipa)
    return ipa


def clause_phonemes(ipa, phoneme_map=None):
    """phonemize.cpp: NFD code points, phoneme map, (lang) flags dropped."""
    out, in_flag = [], False
    for ch in unicodedata.normalize("NFD", ipa):
        for ph in (phoneme_map.get(ch, [ch]) if phoneme_map else [ch]):
            if in_flag:
                in_flag = ph != ")"
            elif ph == "(":
                in_flag = True
            else:
                out.append(ph)
    return out


def sentences_from_clauses(clause_ipa, terminators, phoneme_map=None):
    """Join clause phonemes into sentences with Piper's punctuation rules."""
    sentences, current = [], None
    for ipa, term in zip(clause_ipa, terminators):
        if current is None:
            current = []
            sentences.append(current)
        current.extend(clause_phonemes(ipa, phoneme_map))
        if term in SENTENCE_END:
            current.append(term)
            current = None
        elif term in CLAUSE_PUNCT:
            current.extend([term, " "])
        elif term == ELLIPSIS:
            # CLAUSE_SEMICOLON | CLAUSE_OPTIONAL_SPACE_AFTER: Piper appends
            # nothing and joins the next clause without a gap; a word space
            # keeps the words apart.
            current.append(" ")
    return [s for s in sentences if any(p.strip() for p in s)]


def phonemes_to_ids(phonemes, id_map, missing=None):
    """phoneme_ids.cpp: ^ _ p1 _ p2 _ ... pn _ $ (missing phonemes skipped)."""
    pad = id_map[PAD]
    ids = list(id_map[BOS]) + list(pad)
    for ph in phonemes:
        mapped = id_map.get(ph)
        if mapped is None:
            if missing is not None:
                missing[ph] = missing.get(ph, 0) + 1
            continue
        ids.extend(mapped)
        ids.extend(pad)
    ids.extend(id_map[EOS])
    return ids


def split_long(phonemes, limit=MAX_PHONEMES):
    """Cut a very long sentence after a clause mark, else at a word gap."""
    parts, rest = [], list(phonemes)
    while len(rest) > limit:
        window = rest[:limit]
        cut = 0
        for i in range(len(window) - 1, 0, -1):
            if window[i] == " " and window[i - 1] in CLAUSE_PUNCT:
                cut = i + 1
                break
        if not cut:
            cut = max((i + 1 for i, p in enumerate(window) if p == " "), default=limit)
        parts.append(rest[:cut])
        rest = rest[cut:]
    if rest:
        parts.append(rest)
    return parts


# ---- resampler ----------------------------------------------------------

_FILTERS = {}


def _polyphase(up, down, zero_crossings=32, rolloff=0.9, beta=8.6):
    """Kaiser-windowed sinc low-pass for up/down, split into `up` phases."""
    key = (up, down)
    if key not in _FILTERS:
        width = max(up, down)
        half = zero_crossings * width
        n = np.arange(-half, half + 1, dtype=np.float64)
        cutoff = rolloff * 0.5 / width  # cycles per sample at rate*up
        h = 2 * cutoff * np.sinc(2 * cutoff * n) * np.kaiser(2 * half + 1, beta)
        h *= up / h.sum()  # each phase sums to ~1: DC kept
        taps = -(-len(h) // up)
        h = np.concatenate([h, np.zeros(taps * up - len(h))])
        # phases[p, k] = h[p + k*up]
        _FILTERS[key] = (h.reshape(taps, up).T.astype(np.float32).copy(), half)
    return _FILTERS[key]


def resample(audio, from_rate, to_rate, block=8192):
    """Rational resampling (upsample by L, low-pass, downsample by M) in numpy.

    upfirdn-style polyphase form: output sample n sits at t = n*M + delay on
    the upsampled grid; it is the dot product of filter phase t mod L with
    the input samples ending at t // L. Output length is ceil(len * L / M).
    """
    if np is None:
        raise RuntimeError("numpy is needed to resample")
    x = np.asarray(audio, dtype=np.float32).reshape(-1)
    from_rate, to_rate = int(from_rate), int(to_rate)
    if from_rate <= 0 or to_rate <= 0:
        raise ValueError("sample rates must be positive")
    if from_rate == to_rate or not len(x):
        return x.copy()
    g = gcd(from_rate, to_rate)
    up, down = to_rate // g, from_rate // g
    phases, delay = _polyphase(up, down)
    taps = phases.shape[1]
    n_out = -(-len(x) * up // down)
    xp = np.concatenate([np.zeros(taps, np.float32), x, np.zeros(taps + 1, np.float32)])
    k = np.arange(taps)
    out = np.empty(n_out, np.float32)
    for start in range(0, n_out, block):
        t = np.arange(start, min(n_out, start + block), dtype=np.int64) * down + delay
        idx = (t // up)[:, None] - k[None, :] + taps  # into the padded input
        np.clip(idx, 0, len(xp) - 1, out=idx)
        out[start:start + len(t)] = np.einsum("nk,nk->n", phases[t % up], xp[idx])
    return out


# ---- voice ----------------------------------------------------------------

class PiperVoice:
    def __init__(self, onnx_path, config_path, threads=0):
        if np is None or ort is None:
            raise RuntimeError("numpy and onnxruntime are needed for Piper voices")
        with open(config_path, encoding="utf-8") as f:
            cfg = json.load(f)
        if cfg.get("phoneme_type", "espeak") != "espeak":
            raise ValueError(f"phoneme_type {cfg.get('phoneme_type')!r} is not supported")
        self.config = cfg
        self.espeak_voice = cfg["espeak"]["voice"]
        self.sample_rate = int(cfg["audio"]["sample_rate"])
        self.id_map = {k: list(v) for k, v in cfg["phoneme_id_map"].items()}
        for sym in (BOS, EOS, PAD):
            if sym not in self.id_map:
                raise ValueError(f"phoneme_id_map has no {sym!r}")
        pmap = cfg.get("phoneme_map") or DEFAULT_PHONEME_MAP.get(self.espeak_voice) or {}
        self.phoneme_map = {k: (list(v) if isinstance(v, list) else [v]) for k, v in pmap.items()}
        inf = cfg.get("inference", {})
        self.noise_scale = float(inf.get("noise_scale", 0.667))
        self.length_scale = float(inf.get("length_scale", 1.0))
        self.noise_w = float(inf.get("noise_w", 0.8))
        self.num_speakers = int(cfg.get("num_speakers", 1))
        self.speaker_id_map = dict(cfg.get("speaker_id_map") or {})
        self.default_speaker = int(cfg.get("default_speaker_id", 0))
        lang = cfg.get("language") or {}
        family = lang.get("family") or (lang.get("code") or self.espeak_voice).replace("-", "_").split("_")[0]
        self.languages = [family.lower()]
        self.missing = {}
        if not espeak_available():
            raise RuntimeError("espeak-ng is not installed")
        self.fixes = espeak_fixes(self.espeak_voice)

        so = ort.SessionOptions()
        if threads:
            so.intra_op_num_threads = threads
            so.inter_op_num_threads = 1
        self.session = ort.InferenceSession(str(onnx_path), so, providers=["CPUExecutionProvider"])
        self._inputs = {i.name for i in self.session.get_inputs()}

    # ---- text -> ids --------------------------------------------------

    def phonemize(self, text):
        """Phonemes per sentence (lists of single code points)."""
        text = clean_text(text)
        clauses = split_clauses(text)
        ipa = [apply_fixes(x, self.fixes) for x in espeak_clauses(text, clauses, self.espeak_voice)]
        return sentences_from_clauses(ipa, [t for _, t in clauses], self.phoneme_map)

    def speaker_id(self, speaker):
        if self.num_speakers <= 1:
            if speaker not in (None, 0, "0"):
                raise ValueError("this voice has a single speaker")
            return None
        if speaker is None:
            return self.default_speaker
        if isinstance(speaker, str) and speaker in self.speaker_id_map:
            return int(self.speaker_id_map[speaker])
        try:
            sid = int(speaker)
        except (TypeError, ValueError):
            raise ValueError(f"unknown speaker {speaker!r}") from None
        if isinstance(speaker, bool) or not 0 <= sid < self.num_speakers:
            raise ValueError(f"speaker must be 0..{self.num_speakers - 1} or a name of speaker_id_map")
        return sid

    def synthesize_ids(self, ids, length_scale, sid=None):
        args = {
            "input": np.array([ids], dtype=np.int64),
            "input_lengths": np.array([len(ids)], dtype=np.int64),
            "scales": np.array([self.noise_scale, length_scale, self.noise_w], dtype=np.float32),
        }
        if sid is not None and "sid" in self._inputs:
            args["sid"] = np.array([sid], dtype=np.int64)
        audio = self.session.run(None, args)[0]
        return np.asarray(audio, dtype=np.float32).reshape(-1)

    # ---- public -------------------------------------------------------

    def say(self, text, speed=1.0, sample_rate=8000, on_first_audio=None, speaker=None):
        """float32 mono audio at sample_rate; on_first_audio() runs once when
        the first sentence is ready."""
        speed = check_request(text, speed, sample_rate)
        sid = self.speaker_id(speaker)
        length_scale = self.length_scale / speed
        gap = np.zeros(round(SENTENCE_SILENCE * self.sample_rate), np.float32)
        parts = []
        for sentence in self.phonemize(text):
            pieces = []
            for chunk in split_long(sentence):
                ids = phonemes_to_ids(chunk, self.id_map, self.missing)
                pieces.append(self.synthesize_ids(ids, length_scale, sid))
            audio = np.concatenate(pieces)
            # python_run/piper/util.py audio_float_to_int16: peak normalization
            audio *= PEAK / max(0.01, float(np.max(np.abs(audio))) if len(audio) else 0.0)
            if parts:
                parts.append(gap)
            parts.append(audio)
            if on_first_audio is not None and len(parts) == 1:
                on_first_audio()
        out = np.concatenate(parts) if parts else np.zeros(0, np.float32)
        return resample(out, self.sample_rate, sample_rate)
