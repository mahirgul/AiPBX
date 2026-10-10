"""Matching a caller's transcript to the intents of a voice_requests application.

Pure Python, no dependencies; the same code serves Turkish, German and
English. ``match(transcript, intents, threshold)`` is the whole interface.

Normalisation (``normalize``)
    Turkish lower case first ("İ" -> "i", "I" -> "ı"), then everything is
    lower-cased, diacritics are folded for comparison (ç->c, ğ->g, ı->i, ö->o,
    ş->s, ü->u, ä->a, ß->ss, the stray U+0307 that str.lower() leaves after
    "İ" is dropped), punctuation becomes a space and spaces are collapsed.
    Folding means a keyword typed without Turkish letters ("calismiyor")
    still matches what the recogniser writes ("çalışmıyor").

Keyword hits
    A keyword matches a word of the transcript when the word starts with it
    ("temiz" matches "temizliği"). Two Turkish-friendly relaxations count as
    a *stem hit* (weight 0.85 of a full hit):
      * consonant softening at the end of the keyword: p/ç/t/k/g may appear as
        b/c/d/ğ ("hesap" -> "hesabımı", "yemek" -> "yemeğine");
      * keywords of 8 letters or more may differ in their last 4 letters
        (verb forms: "açılmıyor" -> "açılmayan", "çalışmıyor" -> "çalışmadı").
    A keyword of several words matches as a phrase: each keyword word matches
    the next transcript word in the same way.

Keyword specificity
    1. Shared keywords count less: a keyword's weight is divided by the
       number of intents that have the same keyword stem (one keyword a
       prefix of the other), so a word listed under two intents cannot
       decide between them on its own.
    2. Modifier rule: when the hits of two *different* intents are adjacent
       words, the first one is a modifier of the second and is dropped. Noun
       phrases are head-final in Turkish, German and English ("temiz havlu",
       "saubere Handtücher", "clean towels"), so in "temiz havlu gönderin"
       the request is towels, not cleaning.

Score per intent
    keyword_score = min(1, 0.7 * w_best + 0.1 * (sum of the other hits' weights))
        (all weights 1: 0.7 for one keyword, +0.1 per further distinct keyword)
    similarity    = best cosine similarity between the transcript and one of
                    the intent's examples, over TF-IDF vectors of character
                    3- and 4-grams of the words (IDF over the examples of all
                    intents)
    score         = max(keyword_score, 0.6 * similarity)

Decision
    The best-scoring intent wins, unless best < threshold, or both the best and
    the second score are above 0 and best - second < 0.1 (too close to call):
    then the result is uncertain (intent None).
"""
import math
import re
import unicodedata

KEYWORD_BASE = 0.7
KEYWORD_EXTRA = 0.1
STEM_WEIGHT = 0.85
SIMILARITY_WEIGHT = 0.6
MARGIN = 0.1
LONG_KEYWORD = 8          # letters; such keywords may differ in their last 4
LONG_SLACK = 4

_SOFTEN = {"p": "b", "c": "c", "t": "d", "k": "g", "g": "g"}   # after folding: ç->c, ğ->g
_PUNCT = re.compile(r"[^\w\s]|_", re.UNICODE)
_SPACE = re.compile(r"\s+")


def normalize(text):
    """Lower case (Turkish-aware), diacritics folded, punctuation removed, single spaces."""
    text = str(text).replace("İ", "i").replace("I", "ı").lower()
    text = text.replace("ı", "i").replace("ß", "ss")
    text = unicodedata.normalize("NFKD", text)
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = _PUNCT.sub(" ", text)
    return _SPACE.sub(" ", text).strip()


def _word_match(keyword, word):
    """1.0 for a prefix hit, STEM_WEIGHT for a stem hit, 0 otherwise."""
    if word.startswith(keyword):
        return 1.0
    n = len(keyword)
    if n >= 3 and keyword[-1] in _SOFTEN and word.startswith(keyword[:-1] + _SOFTEN[keyword[-1]]):
        return STEM_WEIGHT
    if n >= LONG_KEYWORD and word.startswith(keyword[:n - LONG_SLACK]):
        return STEM_WEIGHT
    return 0.0


def _phrase_hits(kw_words, words):
    """[(start, end, quality)] where the keyword phrase matches the transcript words."""
    hits = []
    n = len(kw_words)
    for i in range(len(words) - n + 1):
        quality = 1.0
        for j, kw in enumerate(kw_words):
            q = _word_match(kw, words[i + j])
            if not q:
                break
            quality = min(quality, q)
        else:
            hits.append((i, i + n - 1, quality))
    return hits


def _ngrams(text):
    grams = {}
    for word in text.split():
        padded = f" {word} "
        for n in (3, 4):
            for i in range(len(padded) - n + 1):
                g = padded[i:i + n]
                grams[g] = grams.get(g, 0) + 1
    return grams


def _vector(grams, idf, default_idf):
    vec = {g: c * idf.get(g, default_idf) for g, c in grams.items()}
    norm = math.sqrt(sum(v * v for v in vec.values()))
    return vec, norm


def _cosine(a, b):
    (va, na), (vb, nb) = a, b
    if not na or not nb:
        return 0.0
    if len(va) > len(vb):
        va, vb = vb, va
    return sum(v * vb.get(g, 0.0) for g, v in va.items()) / (na * nb)


def _keyword_stems(intents):
    """Normalised keywords per intent: [(intent index, keyword, words)]."""
    out = []
    for idx, intent in enumerate(intents):
        seen = set()
        for kw in intent.get("keywords") or ():
            norm = normalize(kw)
            if norm and norm not in seen:
                seen.add(norm)
                out.append((idx, norm, norm.split()))
    return out


def match(transcript, intents, threshold=0.5):
    """Best intent for a transcript.

    intents: [{"id", "keywords": [...], "examples": [...]}, ...]
    Returns {"intent": id or None, "score": best score, "second": second best,
             "scores": {id: score}} (scores rounded to 3 decimals).
    """
    words = normalize(transcript).split()
    keywords = _keyword_stems(intents)

    # Specificity: in how many intents does each keyword stem occur?
    sharing = {}
    for idx, kw, _ in keywords:
        owners = {j for j, other, _ in keywords if other.startswith(kw) or kw.startswith(other)}
        sharing[(idx, kw)] = max(1, len(owners))

    # Keyword hits: (intent, keyword, start, end, weight)
    hits = []
    for idx, kw, kw_words in keywords:
        for start, end, quality in _phrase_hits(kw_words, words):
            hits.append((idx, kw, start, end, quality / sharing[(idx, kw)]))
    # Modifier rule: a hit right before another intent's hit is dropped.
    hits = [h for h in hits
            if not any(o[0] != h[0] and o[2] == h[3] + 1 for o in hits)]

    keyword_score = {}
    for idx in range(len(intents)):
        best_per_kw = {}
        for h in hits:
            if h[0] == idx:
                best_per_kw[h[1]] = max(best_per_kw.get(h[1], 0.0), h[4])
        if best_per_kw:
            ws = sorted(best_per_kw.values(), reverse=True)
            keyword_score[idx] = min(1.0, KEYWORD_BASE * ws[0] + KEYWORD_EXTRA * sum(ws[1:]))

    # Similarity to the examples (TF-IDF of character 3/4-grams).
    examples = [(idx, _ngrams(normalize(ex))) for idx, intent in enumerate(intents)
                for ex in intent.get("examples") or ()]
    examples = [(idx, g) for idx, g in examples if g]
    similarity = {}
    if examples and words:
        df = {}
        for _, grams in examples:
            for g in grams:
                df[g] = df.get(g, 0) + 1
        n_docs = len(examples)
        idf = {g: math.log((1 + n_docs) / (1 + d)) + 1 for g, d in df.items()}
        default_idf = math.log(1 + n_docs) + 1
        query = _vector(_ngrams(" ".join(words)), idf, default_idf)
        for idx, grams in examples:
            sim = _cosine(query, _vector(grams, idf, default_idf))
            if sim > similarity.get(idx, 0.0):
                similarity[idx] = sim

    scores = {}
    for idx, intent in enumerate(intents):
        scores[intent["id"]] = round(max(keyword_score.get(idx, 0.0),
                                         SIMILARITY_WEIGHT * similarity.get(idx, 0.0)), 3)
    ranked = sorted(scores.items(), key=lambda kv: kv[1], reverse=True)
    best_id, best = ranked[0] if ranked else (None, 0.0)
    second = ranked[1][1] if len(ranked) > 1 else 0.0
    uncertain = best <= 0 or best < threshold or (best > 0 and second > 0 and best - second < MARGIN)
    return {"intent": None if uncertain else best_id, "score": best, "second": second, "scores": scores}
