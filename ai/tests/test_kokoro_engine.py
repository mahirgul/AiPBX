"""Kokoro engine: phoneme rewriting, runs (chunking), style rows, and the
backend's downloads and shared model file.

The text rules and the file handling run everywhere; the model parts need
numpy (a fake ONNX session stands in for the model) and the espeak-ng round
trip needs the espeak-ng program, so those are skipped without them.
"""
import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from aipbx_ai import kokoro_backend  # noqa: E402
from aipbx_ai.engines import kokoro, piper  # noqa: E402

HAVE_NUMPY = kokoro.np is not None
T = kokoro.TIE

# The Kokoro v1.0 vocabulary is one id per character; a few are enough here.
VOCAB = {ch: i + 1 for i, ch in enumerate(" .,?!;:…abcdehiklmnorstuwzAIOQTWYæɑəɹɛɜɪʊʌˈˌᵊʧʤðθŋ")}


class PhonemeRewriteTest(unittest.TestCase):
    def test_american_diphthongs_and_affricates(self):
        # espeak-ng --ipa=2 output with ties -> misaki letters
        self.assertEqual(kokoro.to_kokoro(f"hə{T}l", "en-us"), "hᵊl")
        self.assertEqual(kokoro.to_kokoro(f"hoʊtˈɛl", "en-us"), "hOtˈɛl")
        self.assertEqual(kokoro.to_kokoro(f"no{T}ʊ", "en-us"), "nO")
        self.assertEqual(kokoro.to_kokoro(f"la{T}ɪk ka{T}ʊ bˈɔ{T}ɪ", "en-us"), "lIk kW bˈY")
        self.assertEqual(kokoro.to_kokoro(f"t{T}ʃˈɜːt{T}ʃ d{T}ʒˈʌd{T}ʒ", "en-us"), "ʧˈɜɹʧ ʤˈʌʤ")
        self.assertEqual(kokoro.to_kokoro(f"pɹˈe{T}ɪ", "en-us"), "pɹˈA")

    def test_american_rules(self):
        self.assertEqual(kokoro.to_kokoro("sˈɜːvɪs", "en-us"), "sˈɜɹvɪs")       # ɜː -> ɜɹ
        self.assertEqual(kokoro.to_kokoro("fɔːɹ", "en-us"), "fɔɹ")               # length marks dropped
        self.assertEqual(kokoro.to_kokoro(f"lˈɪɾə{T}l", "en-us"), "lˈɪTᵊl")  # flap -> T
        self.assertEqual(kokoro.to_kokoro("bˈʌʔn̩", "en-us"), "bˈʌtn")    # glottal stop -> t
        self.assertEqual(kokoro.to_kokoro("ɐbˈa{T}ʊt".replace("{T}", T), "en-us"), "əbˈWt")
        self.assertEqual(kokoro.to_kokoro("wˈɛðɚɹ", "en-us"), "wˈɛðəɹ")         # linking r once
        self.assertEqual(kokoro.to_kokoro("dˈɑːlɚ", "en-us"), "dˈɑləɹ")

    def test_british_rules(self):
        self.assertEqual(kokoro.to_kokoro(f"hə{T}ʊtˈɛl", "en-gb"), "hQtˈɛl")
        self.assertEqual(kokoro.to_kokoro(f"hˈe{T}ə", "en-gb"), "hˈɛː")         # misaki: hˈɛː
        self.assertEqual(kokoro.to_kokoro(f"hˈi{T}ə", "en-gb"), "hˈɪə")         # misaki: hˈɪə
        self.assertEqual(kokoro.to_kokoro("sˈɜːvɪs", "en-gb"), "sˈɜːvɪs")       # length kept

    def test_other_languages(self):
        self.assertEqual(kokoro.to_kokoro(f"t{T}ʃˈa{T}ʊ", "it"), "ʧˈW")
        self.assertEqual(kokoro.to_kokoro(f"ɡɾˈat{T}sje", "it"), "ɡɾˈaʦje")
        self.assertEqual(kokoro.to_kokoro("(en)ɡˈʊd(es) bwˈenos", "es"), "ɡˈʊd bwˈenos")  # flags dropped
        self.assertEqual(kokoro.to_kokoro("a-b", "fr-fr"), "ab")
        self.assertEqual(kokoro.to_kokoro("ˈola", "es"), "ˈola")               # bare e/o kept

    def test_word_hyphen(self):
        self.assertEqual(kokoro.WORD_HYPHEN.sub(" ", "thirty-four check-in 1-2 a - b"),
                         "thirty four check in 1-2 a - b")

    def test_dialect(self):
        self.assertEqual(kokoro.dialect("af_heart"), ("en-us", "en-US"))
        self.assertEqual(kokoro.dialect("bm_george"), ("en-gb", "en-GB"))
        self.assertEqual(kokoro.dialect("pf_dora")[0], "pt-br")
        for bad in ("jf_alpha", "zf_xiaobei", "", "xx"):
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                kokoro.dialect(bad)

    def test_sentences_keep_punctuation_like_misaki(self):
        got = kokoro.sentences_from_clauses(["həlˈoʊ", "wˈɜːld", f"hˈa{T}ʊ", "ok"],
                                            [",", ".", "?", ""], "en-us")
        self.assertEqual(got, ["həlˈO, wˈɜɹld.", "hˈW?", "ɔk"])

    def test_sentences_skip_empty(self):
        self.assertEqual(kokoro.sentences_from_clauses(["", "a"], [".", "."], "es"), ["a."])


class RunTest(unittest.TestCase):
    def test_short_sentences_are_joined(self):
        runs = kokoro.make_runs(["hˈɛlO.", "ðə bˈæləns ɪz wˈʌn θˈWzənd dˈɑləɹz."], VOCAB, short=20)
        self.assertEqual(len(runs), 1)
        self.assertEqual("".join(runs[0]), "hˈɛlO. ðə bˈæləns ɪz wˈʌn θˈWzənd dˈɑləɹz.")

    def test_long_sentences_stay_apart(self):
        a, b = "a" * 30 + ".", "b" * 30 + "."
        runs = kokoro.make_runs([a, b], VOCAB, short=20)
        self.assertEqual(["".join(r) for r in runs], [a, b])

    def test_short_tail_joins_the_previous_run(self):
        a = "a" * 30 + "."
        runs = kokoro.make_runs([a, "ok."], VOCAB, short=20)
        self.assertEqual(["".join(r) for r in runs], [a + " ok."])

    def test_limit_and_clause_cuts(self):
        sentence = ", ".join(["abcdeabcde"] * 10) + "."   # 118 tokens
        runs = kokoro.make_runs([sentence], VOCAB, limit=50, short=0)
        self.assertTrue(all(len(r) <= 50 for r in runs))
        self.assertTrue(all(r[-1] in ",." for r in runs))  # cut after the commas
        self.assertEqual(" ".join("".join(r) for r in runs), sentence)

    def test_no_run_over_510_tokens(self):
        words = " ".join(["ðə"] * 400) + "."
        runs = kokoro.make_runs([words], VOCAB)
        self.assertTrue(runs)
        self.assertTrue(all(0 < len(r) <= kokoro.MAX_TOKENS for r in runs))

    def test_unknown_characters_are_dropped_and_counted(self):
        missing = {}
        runs = kokoro.make_runs(["aβc."], VOCAB, missing, short=0)
        self.assertEqual(["".join(r) for r in runs], ["ac."])
        self.assertEqual(missing, {"β": 1})


class VocabTest(unittest.TestCase):
    def test_tokenizer_json_and_config_json(self):
        with tempfile.TemporaryDirectory() as tmp:
            tok = Path(tmp) / "tokenizer.json"
            tok.write_text(json.dumps({"model": {"vocab": {"$": 0, ";": 1, "a": 43, "ab": 9}}}))
            self.assertEqual(kokoro.load_vocab(tok), {";": 1, "a": 43})
            cfg = Path(tmp) / "config.json"
            cfg.write_text(json.dumps({"vocab": {"a": 43}}))
            self.assertEqual(kokoro.load_vocab(cfg), {"a": 43})
            cfg.write_text("{}")
            with self.assertRaises(ValueError):
                kokoro.load_vocab(cfg)


@unittest.skipUnless(HAVE_NUMPY, "numpy is not installed")
class StyleAndSynthesisTest(unittest.TestCase):
    def pack(self):
        np = kokoro.np
        # row i holds the value i, so the chosen row is visible
        return np.arange(510, dtype=np.float32)[:, None, None] * np.ones((1, 1, 256), np.float32)

    def test_style_row_is_length_minus_one(self):
        pack = self.pack()
        self.assertEqual(kokoro.style_row(pack, 1).shape, (1, 256))
        self.assertEqual(float(kokoro.style_row(pack, 1)[0, 0]), 0.0)
        self.assertEqual(float(kokoro.style_row(pack, 42)[0, 0]), 41.0)
        self.assertEqual(float(kokoro.style_row(pack, 510)[0, 0]), 509.0)
        self.assertEqual(float(kokoro.style_row(pack, 600)[0, 0]), 509.0)
        with self.assertRaises(ValueError):
            kokoro.style_row(pack, 0)

    def test_voice_pack_files(self):
        np = kokoro.np
        with tempfile.TemporaryDirectory() as tmp:
            pack = self.pack()
            pack.tofile(Path(tmp) / "af_test.bin")
            pack.tofile(Path(tmp) / "bm_test.bin")
            packs = kokoro.load_voice_packs(tmp)
            self.assertEqual(sorted(packs), ["af_test", "bm_test"])
            self.assertEqual(packs["af_test"].shape, (510, 1, 256))
            one = kokoro.load_voice_packs(Path(tmp) / "bm_test.bin")
            self.assertEqual(list(one), ["bm_test"])
            named = kokoro.load_voice_packs({"am_x": str(Path(tmp) / "af_test.bin")})
            self.assertTrue(np.array_equal(named["am_x"], pack))

    def test_synthesis_inputs(self):
        """say() feeds [0, ids, 0], the style row of len(ids) - 1 and the speed."""
        np = kokoro.np
        seen = []

        class Session:
            def run(self, _out, args):
                seen.append(args)
                return [np.full((1, 2400 * (args["input_ids"].shape[1] - 2)), 0.5, np.float32)]

        voice = kokoro.KokoroVoice.__new__(kokoro.KokoroVoice)
        voice.vocab, voice.missing = VOCAB, {}
        voice.packs = {"af_test": self.pack()}
        voice.session, voice._ids_name, voice._int_speed = Session(), "input_ids", False
        voice.runs = lambda text, name: [list("abc."), list("a" * 30 + ",")]
        first = []
        audio = voice.say("ignored", speed=1.25, sample_rate=24000, voice="af_test",
                          on_first_audio=lambda: first.append(len(seen)))
        self.assertEqual(first, [1])
        ids = seen[0]["input_ids"]
        self.assertEqual(ids.tolist(), [[0, VOCAB["a"], VOCAB["b"], VOCAB["c"], VOCAB["."], 0]])
        self.assertEqual(float(seen[0]["style"][0, 0]), 3.0)    # 4 tokens -> row 3
        self.assertEqual(float(seen[1]["style"][0, 0]), 30.0)   # 31 tokens -> row 30
        self.assertAlmostEqual(float(seen[0]["speed"][0]), 1.25)
        pause = round(kokoro.PAUSES["."] / 1.25 * 24000)
        self.assertEqual(len(audio), 2400 * 4 + pause + 2400 * 31)
        with self.assertRaises(ValueError):
            voice.say("x", voice="bf_unknown")
        with self.assertRaises(ValueError):
            voice.say("x", speed=3.0, voice="af_test")


@unittest.skipUnless(piper.espeak_available(), "espeak-ng is not installed")
class EspeakTest(unittest.TestCase):
    def phonemize(self, text, voice):
        v = kokoro.KokoroVoice.__new__(kokoro.KokoroVoice)
        return v.phonemize(text, voice)

    def test_american(self):
        got = self.phonemize("Hello, how are you? Please press one.", "af_heart")
        self.assertEqual(len(got), 2)
        self.assertTrue(got[0].endswith("?"))
        self.assertIn(",", got[0])
        self.assertIn("O", got[0])          # hello: the oʊ diphthong as one letter
        self.assertNotIn(T, "".join(got))

    def test_hyphenated_number_is_two_words(self):
        got = self.phonemize("thirty-four", "af_heart")
        self.assertEqual(len(got[0].split()), 2)

    def test_british(self):
        got = self.phonemize("Hello there.", "bf_emma")
        self.assertIn("Q", got[0])          # əʊ


def _sha(data):
    return hashlib.sha256(data).hexdigest()


class KokoroBackendTest(unittest.TestCase):
    """Downloads from a fake release (file:// URLs), checksums and the shared model file."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.repo = root / "repo"
        self.rev = "rev1"
        base = self.repo / self.rev
        (base / "onnx").mkdir(parents=True)
        (base / "voices").mkdir()
        self.files = {"onnx/model.onnx": b"fake model" * 100, "tokenizer.json": b"{}",
                      "voices/af_a.bin": b"voice a", "voices/bm_b.bin": b"voice b"}
        for path, body in self.files.items():
            (base / path).write_bytes(body)
        self.data = root / "data"
        self.orig = kokoro_backend.REPO_URL
        kokoro_backend.REPO_URL = self.repo.as_uri() + "/"

    def tearDown(self):
        kokoro_backend.REPO_URL = self.orig
        self.tmp.cleanup()

    def entry(self, path, body=None):
        body = self.files[path] if body is None else body
        return {"path": path, "sha256": _sha(body), "bytes": len(body)}

    def backend(self, model_id, voice):
        source = {"revision": self.rev, "voice_name": voice,
                  "model": self.entry("onnx/model.onnx"), "config": self.entry("tokenizer.json"),
                  "voice": self.entry(f"voices/{voice}.bin")}
        return kokoro_backend.KokoroBackend(SimpleNamespace(id=model_id, source=source), self.data)

    def test_download_installs_and_checks(self):
        a = self.backend("kokoro-a", "af_a")
        self.assertFalse(a.installed())
        seen = []
        a.download(seen.append)
        self.assertTrue(a.installed())
        self.assertEqual(seen[-1], 100)
        self.assertTrue(a.model_path.is_file())
        self.assertEqual(a.model_path.parent, self.data / "models" / "_shared" / "kokoro")
        self.assertEqual((a.dir / "voice.bin").read_bytes(), b"voice a")
        self.assertEqual([], list(self.data.rglob("*.part")))

    def test_changed_file_is_refused(self):
        (self.repo / self.rev / "voices" / "af_a.bin").write_bytes(b"tampered")
        a = self.backend("kokoro-a", "af_a")
        with self.assertRaisesRegex(RuntimeError, "checksum mismatch"):
            a.download(lambda p: None)
        self.assertFalse(a.installed())
        self.assertFalse((a.dir / "voice.bin").exists())
        self.assertEqual([], list(self.data.rglob("*.part")))

    def test_changed_model_is_refused(self):
        (self.repo / self.rev / "onnx" / "model.onnx").write_bytes(b"tampered")
        a = self.backend("kokoro-a", "af_a")
        with self.assertRaisesRegex(RuntimeError, "checksum mismatch"):
            a.download(lambda p: None)
        self.assertFalse(a.model_path.exists())

    def test_shared_model_is_stored_once_and_counted_once(self):
        a, b = self.backend("kokoro-a", "af_a"), self.backend("kokoro-b", "bm_b")
        a.download(lambda p: None)
        b.download(lambda p: None)
        self.assertEqual(a.model_path, b.model_path)
        self.assertEqual(len(list((self.data / "models" / "_shared" / "kokoro").iterdir())), 1)
        model = len(self.files["onnx/model.onnx"])
        # The first voice (smallest id) carries the model's size, the other one does not.
        self.assertGreater(a.disk_bytes(), model)
        self.assertLess(b.disk_bytes(), model)
        own = sum((a.dir / n).stat().st_size for n in ("voice.bin", "tokenizer.json", "model.ref"))
        self.assertEqual(a.disk_bytes(), own + model)

    def test_shared_model_deleted_with_the_last_voice(self):
        a, b = self.backend("kokoro-a", "af_a"), self.backend("kokoro-b", "bm_b")
        a.download(lambda p: None)
        b.download(lambda p: None)
        a.delete_files()
        self.assertFalse(a.installed())
        self.assertFalse(a.dir.exists())
        self.assertTrue(b.installed())                 # model kept for b
        model = len(self.files["onnx/model.onnx"])
        self.assertGreater(b.disk_bytes(), model)      # b now carries it
        b.delete_files()
        self.assertFalse(b.model_path.exists())
        self.assertFalse((self.data / "models" / "_shared").exists())

    def test_download_again_reuses_the_model(self):
        a, b = self.backend("kokoro-a", "af_a"), self.backend("kokoro-b", "bm_b")
        a.download(lambda p: None)
        (self.repo / self.rev / "onnx" / "model.onnx").unlink()   # no second download
        b.download(lambda p: None)
        self.assertTrue(b.installed())

    def test_other_model_versions_are_independent(self):
        a = self.backend("kokoro-a", "af_a")
        a.download(lambda p: None)
        other = self.backend("kokoro-b", "bm_b")
        other.spec.source["model"] = dict(other.spec.source["model"], sha256="0" * 64)
        self.assertNotEqual(a.model_path, other.model_path)
        other.delete_files()                       # nothing installed: a's model stays
        self.assertTrue(a.installed())


    @unittest.skipUnless(HAVE_NUMPY, "numpy is not installed")
    def test_running_voices_share_one_session(self):
        made = []

        class FakeVoice:
            def __init__(self, model, packs, config):
                made.append(self)
                self.packs = dict(packs)

            def add_voice(self, name, path):
                self.packs[name] = path

            def remove_voice(self, name):
                self.packs.pop(name)

            def say(self, text, **kw):
                return kokoro.np.zeros(8, kokoro.np.float32)

        orig = (kokoro.KokoroVoice, piper.espeak_available)
        kokoro.KokoroVoice, piper.espeak_available = FakeVoice, lambda: True
        try:
            a, b = self.backend("kokoro-a", "af_a"), self.backend("kokoro-b", "bm_b")
            a.download(lambda p: None)
            b.download(lambda p: None)
            a.load()
            b.load()
            self.assertEqual(len(made), 1)
            self.assertEqual(sorted(made[0].packs), ["af_a", "bm_b"])
            self.assertEqual(len(b.synthesize("x", 1.0, 8000)), 16)
            a.unload()
            self.assertEqual(sorted(made[0].packs), ["bm_b"])
            self.assertIn(b.model_sha, kokoro_backend._engines)
            b.unload()
            self.assertNotIn(b.model_sha, kokoro_backend._engines)
            a.load()
            self.assertEqual(len(made), 2)      # a new session after the last one stopped
            a.unload()
        finally:
            kokoro.KokoroVoice, piper.espeak_available = orig


class CatalogueTest(unittest.TestCase):
    def test_kokoro_entries(self):
        from aipbx_ai import models

        voices = [s for s in models.REGISTRY if s.engine == "kokoro"]
        self.assertGreaterEqual(len(voices), 2)
        shas = {s.source["model"]["sha256"] for s in voices}
        self.assertEqual(len(shas), 1)  # one shared model file
        for s in voices:
            with self.subTest(id=s.id):
                self.assertIs(s.backend, kokoro_backend.KokoroBackend)
                self.assertEqual(len(s.source["revision"]), 40)
                for key in ("model", "voice", "config"):
                    self.assertRegex(s.source[key]["sha256"], "^[0-9a-f]{64}$")
                self.assertEqual(s.source["voice"]["path"], f"voices/{s.source['voice_name']}.bin")
                kokoro.dialect(s.source["voice_name"])
                self.assertIn(s.languages[0], ("en-US", "en-GB"))
                self.assertGreaterEqual(s.download_mb * 1024 * 1024,
                                        s.source["model"]["bytes"] + s.source["voice"]["bytes"])


if __name__ == "__main__":
    unittest.main()
