"""Tests for intents.match: normalisation, keyword rules, and 24 hotel requests
as Vosk (vosk-tr-small) wrote them from telephone audio, errors included.

    python3 -m unittest discover ai/tests
"""
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai.intents import match, normalize  # noqa: E402

HOTEL = [
    {"id": "towels", "keywords": ["havlu"]},
    {"id": "cleaning", "keywords": ["temiz", "topla", "çarşaf", "süpür"]},
    {"id": "food", "keywords": ["yemek", "sipariş", "menü", "kahvaltı", "servis"]},
    {"id": "wakeup", "keywords": ["uyandır", "uyan", "alarm"]},
    {"id": "fault", "keywords": ["çalışmıyor", "bozuk", "gelmiyor", "yanmıyor", "açılmıyor", "arıza"]},
    {"id": "checkout", "keywords": ["çıkış", "hesap", "fatura", "ödeme"]},
]

# (expected intent, Vosk transcript). What the caller said is in the comment
# where recognition went wrong.
PHRASES = [
    ("towels", "i̇ki tane daha havlu alabilir miyim"),
    ("towels", "temiz havlu gönderin"),
    ("towels", "banyoda havlu kalmadı"),
    ("towels", "aldım getirebilir misiniz"),                 # "havlu getirebilir misiniz"
    ("cleaning", "odamı temizle edebilir miyim"),
    ("cleaning", "oda temizliği için birini gönderin"),
    ("cleaning", "odanın toplanmasını istiyorum"),
    ("cleaning", "yatak çal lafları değiştirirsin lütfen"),  # "yatak çarşaflarını"
    ("food", "yemek sipariş etmek istiyorum"),
    ("food", "oda servisinden iki top ve çay alabilir miyim"),
    ("food", "akşam yemeğine odada yemek yiyoruz"),
    ("food", "sipariş vermek istiyorum"),
    ("wakeup", "sabah yedide beni uyandıran musunuz"),
    ("wakeup", "uyandırma kaybetti istiyorum"),
    ("wakeup", "yarın sabah altı buçukta arayın lütfen"),    # no keyword at all
    ("wakeup", "beni erken uyandırın uçağım var"),
    ("fault", "klima çalışmıyor"),
    ("fault", "televizyon açılmayan"),
    ("fault", "sıcak su gelmiyor"),
    ("fault", "odadaki ışık yanmıyor"),
    ("checkout", "sakin öğrenebilir miyim"),                 # "hesabımı öğrenebilir miyim"
    ("checkout", "hesabımı kapatmak istiyorum"),
    ("checkout", "geç çıkış yapabilir miyim"),
    ("checkout", "fatura mı hazırlanmış"),
]

# Known misses with keywords only: the request word itself was misrecognised
# or never said.
KEYWORD_MISSES = {
    "aldım getirebilir misiniz",
    "yatak çal lafları değiştirirsin lütfen",
    "yarın sabah altı buçukta arayın lütfen",
    "sakin öğrenebilir miyim",
}

# Examples an administrator might write (not the test sentences).
EXAMPLES = {
    "towels": ["İki havlu daha alabilir miyim?", "Banyoya temiz havlu getirir misiniz?"],
    "cleaning": ["Odamın temizlenmesini istiyorum.", "Yatak çarşaflarını değiştirir misiniz?"],
    "food": ["Oda servisinden yemek sipariş etmek istiyorum.", "Kahvaltı menüsü nedir?"],
    "wakeup": ["Yarın sabah yedide beni uyandırır mısınız?", "Sabah altıda arayın lütfen."],
    "fault": ["Klima çalışmıyor.", "Televizyon açılmıyor.", "Sıcak su gelmiyor."],
    "checkout": ["Hesabımı öğrenebilir miyim?", "Çıkış yapmak istiyorum.", "Faturamı hazırlar mısınız?"],
}


class NormalizeTest(unittest.TestCase):
    def test_turkish_case_and_folding(self):
        self.assertEqual(normalize("İSTANBUL'da IŞIK  yanmıyor!"), "istanbul da isik yanmiyor")
        self.assertEqual(normalize("i̇ki"), "iki")              # "İ".lower() leaves U+0307
        self.assertEqual(normalize("Çarşaf, ÖDEME; Grüße"), "carsaf odeme grusse")
        self.assertEqual(normalize("  a_b-c  "), "a b c")


class MatchTest(unittest.TestCase):
    def test_hotel_phrases_with_keywords(self):
        correct, wrong = 0, []
        for expected, text in PHRASES:
            got = match(text, HOTEL, 0.5)["intent"]
            if got == expected:
                correct += 1
            else:
                wrong.append(text)
        self.assertGreaterEqual(correct, 20)
        self.assertEqual(set(wrong), KEYWORD_MISSES)    # exactly the hopeless ones; none wrong-intent
        for text in KEYWORD_MISSES:
            self.assertIsNone(match(text, HOTEL, 0.5)["intent"])   # "none", not a wrong intent

    def test_hotel_phrases_with_examples(self):
        # Similarity alone scores at most 0.6 (0.6 x cosine), paraphrases about
        # 0.3-0.4: with examples, a threshold of 0.3 lets them decide.
        intents = [dict(i, examples=EXAMPLES[i["id"]]) for i in HOTEL]
        results = {text: match(text, intents, 0.3)["intent"] for _, text in PHRASES}
        correct = sum(results[text] == expected for expected, text in PHRASES)
        self.assertEqual(correct, 23)
        self.assertIsNone(results["aldım getirebilir misiniz"])
        # at 0.5 the examples change nothing: still the 20 keyword hits
        self.assertEqual(sum(match(t, intents, 0.5)["intent"] == e for e, t in PHRASES), 20)

    def test_prefix_and_stems(self):
        m = match("oda temizliği", HOTEL, 0.5)
        self.assertEqual((m["intent"], m["score"]), ("cleaning", 0.7))
        self.assertEqual(match("hesabımı", HOTEL, 0.5)["intent"], "checkout")    # p -> b
        self.assertEqual(match("hesabımı", HOTEL, 0.5)["score"], round(0.7 * 0.85, 3))
        self.assertEqual(match("akşam yemeğine", HOTEL, 0.5)["intent"], "food")  # k -> ğ
        self.assertEqual(match("televizyon açılmadı", HOTEL, 0.5)["intent"], "fault")
        self.assertIsNone(match("tema", HOTEL, 0.5)["intent"])
        self.assertIsNone(match("", HOTEL, 0.5)["intent"])

    def test_extra_keywords_raise_score(self):
        self.assertEqual(match("yemek sipariş menü", HOTEL, 0.5)["score"], 0.9)
        self.assertEqual(match("yemek sipariş menü kahvaltı servis", HOTEL, 0.5)["score"], 1.0)

    def test_modifier_rule(self):
        m = match("temiz havlu gönderin", HOTEL, 0.5)
        self.assertEqual(m["intent"], "towels")
        self.assertEqual(m["scores"]["cleaning"], 0.0)
        # not adjacent: both count, too close to call
        m = match("havlu ve oda temizliği", HOTEL, 0.5)
        self.assertIsNone(m["intent"])
        self.assertEqual((m["score"], m["second"]), (0.7, 0.7))

    def test_shared_keyword_counts_less(self):
        intents = [{"id": "a", "keywords": ["oda", "havlu"]}, {"id": "b", "keywords": ["oda", "temiz"]}]
        m = match("oda", intents, 0.5)
        self.assertIsNone(m["intent"])
        self.assertEqual(m["scores"], {"a": 0.35, "b": 0.35})
        self.assertEqual(match("oda temizliği", intents, 0.5)["intent"], "b")

    def test_multi_word_keyword(self):
        intents = [{"id": "late", "keywords": ["geç çıkış"]}, {"id": "out", "keywords": ["fatura"]}]
        self.assertEqual(match("geç çıkışı yapabilir miyim", intents, 0.5)["intent"], "late")
        self.assertIsNone(match("çıkış geç", intents, 0.5)["intent"])

    def test_threshold_and_margin(self):
        intents = [{"id": "a", "keywords": ["havlu"]}]
        self.assertEqual(match("havlu", intents, 0.7)["intent"], "a")
        self.assertIsNone(match("havlu", intents, 0.71)["intent"])
        self.assertEqual(match("havlu", intents, 0)["intent"], "a")
        self.assertIsNone(match("başka", intents, 0)["intent"])     # score 0 never matches

    def test_similarity_only(self):
        intents = [{"id": "wifi", "keywords": [], "examples": ["Wifi şifresi nedir?"]},
                   {"id": "taxi", "keywords": [], "examples": ["Bana bir taksi çağırır mısınız?"]}]
        m = match("wifi şifresini öğrenebilir miyim", intents, 0.2)
        self.assertEqual(m["intent"], "wifi")
        self.assertGreater(m["score"], 0.2)
        self.assertLessEqual(m["score"], 0.6)

    def test_english_and_german(self):
        intents = [{"id": "towels", "keywords": ["towel", "handtuch", "handtücher"]},
                   {"id": "cleaning", "keywords": ["clean", "sauber", "reinig"]}]
        self.assertEqual(match("Could I get two more towels?", intents, 0.5)["intent"], "towels")
        self.assertEqual(match("clean towels please", intents, 0.5)["intent"], "towels")
        self.assertEqual(match("Bitte saubere Handtücher", intents, 0.5)["intent"], "towels")
        self.assertEqual(match("Zimmerreinigung bitte", intents, 0.5)["intent"], None)  # word starts differently
        self.assertEqual(match("I'd like my room cleaned", intents, 0.5)["intent"], "cleaning")


if __name__ == "__main__":
    unittest.main()
