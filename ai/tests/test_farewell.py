import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from aipbx_ai.farewell import assistant_says_goodbye, caller_says_goodbye, fold  # noqa: E402


class FarewellTest(unittest.TestCase):
    def test_fold(self):
        self.assertEqual(fold("Teşekkürler, İyi günler!"), "tesekkurler iyi gunler")
        self.assertEqual(fold("Tschüß, Größe"), "tschuss grosse")

    def test_caller(self):
        cases = [
            ("Teşekkürler iyi günler", "tr", "", True),
            ("görüşürüz", "tr", "Adınızı söyler misiniz?", True),                  # a goodbye is a goodbye
            ("Teşekkürler", "tr", "Başka bir isteğiniz var mı?", True),
            ("Teşekkürler", "tr", "Randevu yarın saat 10'da.", True),
            ("Teşekkürler", "tr", "Adınızı söyler misiniz?", False),               # thanks for a question's answer
            ("Hayır teşekkürler", "tr", "Başka bir konuda yardımcı olabilir miyim?", True),
            ("Hayır", "tr", "Adınızı söyler misiniz?", False),
            ("tamam", "tr", "Başka bir şey var mı?", False),                       # filler alone says nothing
            ("evet", "tr", "Başka bir şey var mı?", False),
            ("Teşekkürler bir şey daha soracağım", "tr", "", False),
            ("thanks, can you also check my balance", "en", "", False),
            ("thank you bye", "en", "Anything else?", True),
            ("no thanks", "en", "Is there anything else I can help with?", True),
            ("yes thanks", "en", "Do you confirm?", False),
            ("Danke, auf Wiedersehen", "de", "", True),
            ("nein danke", "de", "Kann ich sonst noch etwas für Sie tun?", True),
            ("Tschüss", "de", "Ihre Nummer?", True),
            ("bye", "fr", "", True),                                               # an unknown language uses English
            ("", "tr", "", False),
            ("bye " * 9, "en", "", False),                                         # too long to be only a goodbye
        ]
        for text, lang, last, expected in cases:
            with self.subTest(text=text, lang=lang, last=last):
                self.assertEqual(caller_says_goodbye(text, lang, last), expected)

    def test_assistant(self):
        self.assertTrue(assistant_says_goodbye("Rica ederim, iyi günler dilerim!", "tr"))
        self.assertTrue(assistant_says_goodbye("Have a great day!", "en"))
        self.assertFalse(assistant_says_goodbye("İyi günler. Başka bir konuda yardımcı olabilir miyim?", "tr"))
        self.assertFalse(assistant_says_goodbye("Randevunuz yarın saat 10'da.", "tr"))
        self.assertFalse(assistant_says_goodbye("", "tr"))


if __name__ == "__main__":
    unittest.main()
