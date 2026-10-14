"""Sentence meaning: the unigram tokenizer and the meaning layer of intents.match.

The real model is tested only when its files are on disk (AIPBX_E5_DIR=<folder
with model.onnx and tokenizer.json>); the rest runs everywhere.
"""
import json
import math
import os
import tempfile
import unittest
from pathlib import Path

from aipbx_ai.embedding import BOS, EOS, UNK, UnigramTokenizer
from aipbx_ai.intents import match

E5_DIR = os.environ.get("AIPBX_E5_DIR")


def _tokenizer(pieces):
    vocab = [["<s>", 0.0], ["<pad>", 0.0], ["</s>", 0.0], ["<unk>", 0.0]] + [[p, s] for p, s in pieces]
    path = Path(tempfile.mkdtemp()) / "tokenizer.json"
    path.write_text(json.dumps({"model": {"type": "Unigram", "unk_id": UNK, "vocab": vocab}}), encoding="utf-8")
    return UnigramTokenizer(path)


class UnigramTest(unittest.TestCase):
    def test_best_split_wins(self):
        tok = _tokenizer([("▁fatura", -2.0), ("▁fat", -3.0), ("ura", -3.0), ("m", -2.5)])
        # "▁fatura" (one piece, -2) beats "▁fat"+"ura" (-6); then "m".
        self.assertEqual(tok.tokenize("faturam"), [BOS, 4, 7, EOS])

    def test_unknown_letters_become_one_unk(self):
        tok = _tokenizer([("▁a", -1.0)])
        self.assertEqual(tok.tokenize("a xyz"), [BOS, 4, UNK, EOS])

    def test_spaces_folded(self):
        tok = _tokenizer([("▁a", -1.0), ("▁b", -1.0)])
        self.assertEqual(tok.tokenize("  a \n  b "), [BOS, 4, 5, EOS])


def _fake_embed(table):
    """Unit vectors from a {text: angle} table: close angles = close meaning."""
    def embed(texts):
        return [[math.cos(table.get(t, 3.0)), math.sin(table.get(t, 3.0))] for t in texts]
    return embed


INTENTS = [
    {"id": "accounting", "name": "Accounting", "keywords": ["invoice"], "examples": ["I have a question about my bill"]},
    {"id": "support", "name": "Support", "keywords": ["broken"], "examples": ["my device does not work"]},
]


class MeaningTest(unittest.TestCase):
    def test_meaning_decides_without_keywords(self):
        embed = _fake_embed({"I have a question about my bill": 0.0, "invoice": 0.05, "Accounting": 0.1,
                             "my device does not work": 1.5, "broken": 1.55, "Support": 1.6,
                             "the amount you charged is wrong": 0.02})
        m = match("the amount you charged is wrong", INTENTS, 0.5, embed=embed)
        self.assertEqual((m["intent"], m["method"]), ("accounting", "meaning"))

    def test_close_meaning_falls_back_to_keywords(self):
        # Both intents equally close: meaning is not sure, the keyword decides.
        embed = _fake_embed({"I have a question about my bill": 0.7, "invoice": 0.7, "Accounting": 0.7,
                             "my device does not work": 0.7, "broken": 0.7, "Support": 0.7,
                             "it is broken": 0.7})
        m = match("it is broken", INTENTS, 0.5, embed=embed)
        self.assertEqual((m["intent"], m["method"]), ("support", "keywords"))

    def test_model_error_keeps_keyword_result(self):
        def broken(texts):
            raise RuntimeError("model gone")
        m = match("my invoice", INTENTS, 0.5, embed=broken)
        self.assertEqual((m["intent"], m["method"]), ("accounting", "keywords"))

    def test_without_model_unchanged(self):
        m = match("something else entirely", INTENTS, 0.5)
        self.assertIsNone(m["intent"])
        self.assertIsNone(m["method"])


@unittest.skipUnless(E5_DIR, "AIPBX_E5_DIR not set (real multilingual-e5-small files)")
class RealModelTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        from aipbx_ai.embedding import EmbeddingBackend

        class Spec:
            id = "e5"
            source = {"files": {"model.onnx": {}, "tokenizer.json": {}}}
        cls.backend = EmbeddingBackend(Spec, tempfile.mkdtemp())
        cls.backend.dir = Path(E5_DIR)
        cls.backend.load()

    def test_same_ids_as_tokenizers_library(self):
        try:
            from tokenizers import Tokenizer
        except ImportError:
            self.skipTest("tokenizers not installed")
        ref = Tokenizer.from_file(str(Path(E5_DIR) / "tokenizer.json"))
        for text in ("query: faturamla ilgili bir sorum var", "query: İSTANBUL'da  ŞİMDİ çok güzel!",
                     "query: Größe straße", "query: naïve café 1.234,50 TL 😀"):
            self.assertEqual(self.backend.tokenizer.tokenize(text), ref.encode(text).ids, text)

    def test_turkish_routing_by_meaning(self):
        intents = [
            {"id": "muhasebe", "name": "Muhasebe", "keywords": ["fatura", "ödeme"],
             "examples": ["faturam hakkında bilgi almak istiyorum", "ödeme yapmak istiyorum"]},
            {"id": "destek", "name": "Teknik destek", "keywords": ["arıza", "çalışmıyor"],
             "examples": ["cihazım çalışmıyor", "bir arıza bildirmek istiyorum"]},
            {"id": "ik", "name": "İnsan kaynakları", "keywords": ["iş başvurusu", "maaş"],
             "examples": ["iş başvurusu yapmak istiyorum", "maaşım hakkında soru soracağım"]},
        ]
        m = match("kredi kartıyla ödeyebilir miyim", intents, 0.5, embed=self.backend.embed)
        self.assertEqual(m["intent"], "muhasebe")
        m = match("yazıcı kağıt sıkıştırıyor", intents, 0.5, embed=self.backend.embed)
        self.assertEqual(m["intent"], "destek")


if __name__ == "__main__":
    unittest.main()
