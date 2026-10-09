"""Piper engine: clause and id rules, the resampler and input checks.

The text rules run everywhere; the resampler needs numpy and the
espeak-ng round trip needs the espeak-ng program, so those parts are skipped
on a machine without them (the CI runner has neither).
"""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from aipbx_ai.engines import piper  # noqa: E402

HAVE_NUMPY = piper.np is not None

# A small id map in the layout of a Piper config (_ ^ $ space first).
ID_MAP = {"_": [0], "^": [1], "$": [2], " ": [3], "!": [4], ",": [8], ".": [10],
          "?": [13], "a": [14], "b": [15], "h": [20], "l": [24], "o": [27],
          "ˈ": [120], "ː": [122], "̧": [140], "c": [16]}


class PhonemeIdTest(unittest.TestCase):
    def test_bos_pad_eos_layout(self):
        # ^ _ h _ ˈ _ a _ l _ o _ ː _ $  (phoneme_ids.cpp with pad after ^)
        ids = piper.phonemes_to_ids(list("hˈaloː"), ID_MAP)
        self.assertEqual(ids, [1, 0, 20, 0, 120, 0, 14, 0, 24, 0, 27, 0, 122, 0, 2])

    def test_missing_phoneme_is_skipped_and_counted(self):
        missing = {}
        ids = piper.phonemes_to_ids(["a", "ʁ", "b"], ID_MAP, missing)
        self.assertEqual(ids, [1, 0, 14, 0, 15, 0, 2])
        self.assertEqual(missing, {"ʁ": 1})

    def test_multi_id_phoneme(self):
        ids = piper.phonemes_to_ids(["a"], dict(ID_MAP, a=[14, 15]))
        self.assertEqual(ids, [1, 0, 14, 15, 0, 2])

    def test_clause_punctuation_and_sentences(self):
        # Phonemes injected as espeak-ng would print them, one per clause.
        ipa = ["hˈalo", "ba", "ob", "la"]
        terms = [",", ".", "?", ""]
        got = piper.sentences_from_clauses(ipa, terms)
        self.assertEqual(got, [list("hˈalo") + [",", " "] + list("ba") + ["."],
                               list("ob") + ["?"],
                               list("la")])

    def test_language_flags_dropped_and_nfd(self):
        # "(en)" switch flags are removed; ç decomposes to c + combining cedilla
        self.assertEqual(piper.clause_phonemes("a(en)b(de)ç"), ["a", "b", "c", "̧"])

    def test_phoneme_map(self):
        self.assertEqual(piper.clause_phonemes("ca", {"c": ["k"]}), ["k", "a"])

    def test_split_clauses(self):
        text = "Guten Tag, Sie sind verbunden. Kostet 1.234,50 Euro! Wirklich? Ja"
        self.assertEqual(piper.split_clauses(text), [
            ("Guten Tag,", ","), ("Sie sind verbunden.", "."),
            ("Kostet 1.234,50 Euro!", "!"), ("Wirklich?", "?"), ("Ja", "")])

    def test_split_clauses_follows_espeak_rules(self):
        cases = {
            # a single "." before a lowercase word is no clause end
            "Bitte z. B. morgen. Am 3. oktober.": [("Bitte z.", "."), ("B. morgen.", "."), ("Am 3. oktober.", ".")],
            # punctuation before any letter or digit is no clause end
            "Ja , . Nein": [("Ja ,", ","), (". Nein", "")],
            # runs of ? and !: the first one counts
            "What!? Really?! ok": [("What!?", "!"), ("Really?!", "?"), ("ok", "")],
            # dots and the ellipsis character; dashes count as ";"
            "Hallo... wie\u2026 so \u2014 gut - ja": [("Hallo...", piper.ELLIPSIS), ("wie\u2026", piper.ELLIPSIS),
                                                   ("so \u2014", ";"), ("gut - ja", "")],
            # a closing bracket or quote after the punctuation still ends the
            # clause and stays with it
            "Gut (sehr gut.) Danke": [("Gut (sehr gut.)", "."), ("Danke", "")],
            'He said: "Fine!" Then': [("He said:", ":"), ('"Fine!"', "!"), ("Then", "")],
            "...": [],
        }
        for text, want in cases.items():
            with self.subTest(text=text):
                self.assertEqual(piper.split_clauses(text), want)

    def test_ellipsis_joins_with_a_space(self):
        got = piper.sentences_from_clauses(["ha", "ob"], [piper.ELLIPSIS, "?"])
        self.assertEqual(got, [list("ha ob?")])

    def test_german_tap_after_plosive_is_mapped_back(self):
        # espeak-ng 1.52.0 (Ubuntu) writes "bɾˈoːt"; the fork Piper trained with writes "br"
        rules = piper.ESPEAK_FIXES["de"][2]
        self.assertEqual(piper.apply_fixes("bɾˈoːt iːɾ ɡɾʊnt dɾˈaɪ fɾaʊ", rules), "brˈoːt iːɾ ɡrʊnt drˈaɪ fɾaʊ")

    def test_clean_text(self):
        self.assertEqual(piper.clean_text("a\nb\x00c\u202e d  "), "a b c d")

    def test_split_long_prefers_clause_marks(self):
        ph = list("aa, bb cc, dd")
        parts = piper.split_long(ph, limit=8)
        self.assertEqual(["".join(p) for p in parts], ["aa, ", "bb cc, ", "dd"])
        self.assertEqual(sum(map(len, parts)), len(ph))


class RequestCheckTest(unittest.TestCase):
    def test_accepts(self):
        self.assertEqual(piper.check_request("Hallo", 1, 8000), 1.0)
        self.assertEqual(piper.check_request("x" * 5000, 2.0, 48000), 2.0)

    def test_rejects(self):
        for args in [("x" * 5001, 1.0, 8000), ("a", 0.4, 8000), ("a", 2.1, 8000),
                     ("a", "fast", 8000), ("a", 1.0, 22050), ("a", 1.0, True), (None, 1.0, 8000)]:
            with self.subTest(args=args[1:]), self.assertRaises(ValueError):
                piper.check_request(*args)


@unittest.skipUnless(HAVE_NUMPY, "numpy is not installed")
class ResampleTest(unittest.TestCase):
    def dominant(self, x, rate):
        np = piper.np
        spec = np.abs(np.fft.rfft(x * np.hanning(len(x))))
        return np.argmax(spec) * rate / len(x)

    def test_lengths(self):
        np = piper.np
        x = np.zeros(22050, np.float32)
        for to, n in [(8000, 8000), (16000, 16000), (24000, 24000), (48000, 48000)]:
            self.assertEqual(len(piper.resample(x, 22050, to)), n)
        self.assertEqual(len(piper.resample(np.zeros(1001, np.float32), 22050, 8000)), 364)  # ceil(1001*160/441)
        self.assertEqual(len(piper.resample(x, 22050, 22050)), 22050)

    def test_sine_keeps_frequency(self):
        np = piper.np
        for src, dst, f in [(22050, 8000, 1000.0), (16000, 48000, 440.0), (22050, 24000, 3000.0)]:
            with self.subTest(src=src, dst=dst):
                t = np.arange(src) / src
                y = piper.resample(np.sin(2 * np.pi * f * t).astype(np.float32), src, dst)
                self.assertAlmostEqual(self.dominant(y, dst), f, delta=2.0)
                mid = y[len(y) // 4: 3 * len(y) // 4]
                ref = np.sin(2 * np.pi * f * np.arange(len(y)) / dst)[len(y) // 4: 3 * len(y) // 4]
                self.assertLess(np.max(np.abs(mid - ref)), 0.01)  # amplitude and phase kept

    def test_dc_preserved(self):
        np = piper.np
        y = piper.resample(np.full(22050, 0.5, np.float32), 22050, 8000)
        self.assertLess(np.max(np.abs(y[200:-200] - 0.5)), 1e-3)

    def test_above_new_nyquist_is_removed(self):
        np = piper.np
        t = np.arange(22050) / 22050
        y = piper.resample(np.sin(2 * np.pi * 6000 * t).astype(np.float32), 22050, 8000)
        self.assertLess(np.sqrt(np.mean(y[200:-200] ** 2)), 1e-3)


@unittest.skipUnless(piper.espeak_available(), "espeak-ng is not installed")
class EspeakTest(unittest.TestCase):
    def test_round_trip(self):
        text = "Hello, world. How are you?"
        clauses = piper.split_clauses(text)
        ipa = piper.espeak_clauses(text, clauses, "en-us")
        self.assertEqual(len(ipa), 3)
        sentences = piper.sentences_from_clauses(ipa, [t for _, t in clauses])
        self.assertEqual(len(sentences), 2)
        self.assertEqual(sentences[0][-1], ".")
        self.assertIn(",", sentences[0])
        self.assertEqual(sentences[1][-1], "?")


if __name__ == "__main__":
    unittest.main()
