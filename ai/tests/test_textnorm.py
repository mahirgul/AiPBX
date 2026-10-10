"""Text normalisation (textnorm) for the Piper and Kokoro voices.

The rules need the num2words package; without it normalize() returns the
text unchanged and the table tests are skipped.
"""
import random
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from aipbx_ai import textnorm  # noqa: E402
from aipbx_ai.engines import kokoro, piper  # noqa: E402

HAVE_NUM2WORDS = textnorm.available()
HAVE_NUMPY = piper.np is not None

# (language, input, expected)
CASES = [
    # ---- German: amounts ----
    ("de", "Ihr Kontostand beträgt 1.234,50 Euro.",
     "Ihr Kontostand beträgt eintausend zweihundert vier und dreißig Euro fünfzig."),
    ("de", "1.234,50 €", "eintausend zweihundert vier und dreißig Euro fünfzig"),
    ("de", "€ 5", "fünf Euro"),
    ("de", "€5", "fünf Euro"),
    ("de", "5,- €", "fünf Euro"),
    ("de", "0,99 €", "neun und neunzig Cent"),
    ("de", "0,01 €", "ein Cent"),
    ("de", "1,00 €", "ein Euro"),
    ("de", "2,50 Euro", "zwei Euro fünfzig"),
    ("de", "3,5 €", "drei Euro fünfzig"),
    ("de", "12 EUR", "zwölf Euro"),
    ("de", "3 Cent", "drei Cent"),
    ("de", "1 Cent", "ein Cent"),
    ("de", "21 Euro", "einund zwanzig Euro"),
    ("de", "101 Euro", "einhundert ein Euro"),
    ("de", "am 31.12.", "am einund dreißigsten Dezember."),
    ("de", "1.200.000", "eine Million zweihundert tausend"),
    ("de", "45", "fünf und vierzig"),
    ("de", "-5 €", "minus fünf Euro"),
    ("de", "CHF 10", "zehn Franken"),
    ("de", "1 Mio. Euro", "eine Million Euro"),
    # ---- German: numbers ----
    ("de", "2,5", "zwei Komma fünf"),
    ("de", "-3", "minus drei"),
    ("de", "1.000.000", "eine Million"),
    ("de", "50 %", "fünfzig Prozent"),
    ("de", "50%", "fünfzig Prozent"),
    ("de", "15,5 %", "fünfzehn Komma fünf Prozent"),
    ("de", "-5 °C", "minus fünf Grad Celsius"),
    ("de", "1 Minute", "eine Minute"),
    ("de", "1 Tag", "ein Tag"),
    ("de", "Drücken Sie die 1.", "Drücken Sie die eins."),
    ("de", "10-20 Personen", "zehn bis zwanzig Personen"),
    ("de", "seit 1990", "seit neunzehnhundert neunzig"),
    ("de", "Version 1.9.4", "Version 1.9.4"),
    ("de", "3-Zimmer-Wohnung", "drei-Zimmer-Wohnung"),
    # ---- German: times ----
    ("de", "14:30", "vierzehn Uhr dreißig"),
    ("de", "14:30 Uhr", "vierzehn Uhr dreißig"),
    ("de", "9 Uhr", "neun Uhr"),
    ("de", "9.30 Uhr", "neun Uhr dreißig"),
    ("de", "08:05", "acht Uhr fünf"),
    ("de", "1:00 Uhr", "ein Uhr"),
    ("de", "9-17 Uhr", "neun bis siebzehn Uhr"),
    ("de", "9:00 - 17:30 Uhr", "neun Uhr bis siebzehn Uhr dreißig"),
    ("de", "Mo.-Fr. 9 bis 17 Uhr", "Montag bis Freitag neun bis siebzehn Uhr"),
    # ---- German: dates and ordinals ----
    ("de", "am 3. Oktober", "am dritten Oktober"),
    ("de", "bis zum 3. Oktober", "bis zum dritten Oktober"),
    ("de", "seit dem 1. Januar", "seit dem ersten Januar"),
    ("de", "ab dem 2. Mai", "ab dem zweiten Mai"),
    ("de", "der 3. Oktober", "der dritte Oktober"),
    ("de", "Montag, 3. Oktober 2026", "Montag, dritter Oktober zweitausend sechs und zwanzig"),
    ("de", "03.10.2026", "dritter Oktober zweitausend sechs und zwanzig"),
    ("de", "am 03.10.2026", "am dritten Oktober zweitausend sechs und zwanzig"),
    ("de", "am 3.10. um 9 Uhr", "am dritten Oktober um neun Uhr"),
    ("de", "Termin am 3.10. Bitte", "Termin am dritten Oktober. Bitte"),
    ("de", "2026-10-03", "dritter Oktober zweitausend sechs und zwanzig"),
    ("de", "am 3. Okt.", "am dritten Oktober"),
    ("de", "im 1. Stock", "im ersten Stock"),
    ("de", "1. Stock", "erster Stock"),
    ("de", "1. Klasse", "erste Klasse"),
    ("de", "in der 2. Etage", "in der zweiten Etage"),
    ("de", "zum 1. Mal", "zum ersten Mal"),
    ("de", "Platz 3. Dann", "Platz drei. Dann"),
    # ---- German: phone numbers, codes, abbreviations ----
    ("de", "Bitte rufen Sie uns unter 0212 555 12 34 an.",
     "Bitte rufen Sie uns unter null zwei eins zwei, fünf fünf fünf, eins zwei, drei vier an."),
    ("de", "+49 30 1234567", "plus vier neun, drei null, eins zwei drei vier fünf sechs sieben"),
    ("de", "(0212) 555 12 34", "null zwei eins zwei, fünf fünf fünf, eins zwei, drei vier"),
    ("de", "030/1234567", "null drei null, eins zwei drei vier fünf sechs sieben"),
    ("de", "Kundennummer 1234567", "Kundennummer eins zwei drei vier fünf sechs sieben"),
    ("de", "2026-2027", "zweitausend sechs und zwanzig bis zweitausend sieben und zwanzig"),
    ("de", "Buchung AB-1234", "Buchung A B eins zwei drei vier"),
    ("de", "z. B. hier", "zum Beispiel hier"),
    ("de", "z.B. hier", "zum Beispiel hier"),
    ("de", "ca. 5", "circa fünf"),
    ("de", "Nr. 5", "Nummer fünf"),
    ("de", "A bzw. B", "A beziehungsweise B"),
    ("de", "Äpfel usw. Dann", "Äpfel und so weiter. Dann"),
    ("de", "Äpfel usw. und", "Äpfel und so weiter und"),
    ("de", "Hauptstr. 5", "Hauptstraße fünf"),
    ("de", "Tel. 0800", "Telefon null acht null null"),
    # ---- English: amounts ----
    ("en-US", "Your balance is $1,234.50.",
     "Your balance is one thousand two hundred thirty-four dollars and fifty cents."),
    ("en-US", "$5", "five dollars"),
    ("en-US", "$1", "one dollar"),
    ("en-US", "$5.50", "five dollars and fifty cents"),
    ("en-US", "$0.50", "fifty cents"),
    ("en-US", "€20", "twenty euros"),
    ("en-GB", "£3.99", "three pounds and ninety-nine pence"),
    ("en-GB", "£0.01", "one penny"),
    ("en-US", "1,234 dollars", "one thousand two hundred thirty-four dollars"),
    ("en-US", "USD 10", "ten dollars"),
    ("en-US", "10 EUR", "ten euros"),
    ("en-US", "$1.5 million", "one point five million dollars"),
    # ---- English: numbers ----
    ("en-US", "2.5", "two point five"),
    ("en-US", "-3", "minus three"),
    ("en-US", "50%", "fifty percent"),
    ("en-US", "50 %", "fifty percent"),
    ("en-US", "1 °C", "one degree Celsius"),
    ("en-US", "101", "one hundred one"),
    ("en-GB", "101", "one hundred and one"),
    ("en-US", "1,000,000", "one million"),
    ("en-US", "10-20", "ten to twenty"),
    ("en-US", "in 2026", "in twenty twenty-six"),
    # ---- English: times ----
    ("en-US", "14:30", "fourteen thirty"),
    ("en-US", "2:30 pm", "two thirty PM"),
    ("en-US", "at 2:30 p.m. Please", "at two thirty PM. Please"),
    ("en-US", "9:05", "nine oh five"),
    ("en-US", "10:00", "ten o'clock"),
    ("en-US", "14:00", "fourteen hundred"),
    ("en-US", "9am-5pm", "nine AM to five PM"),
    ("en-US", "Mon-Fri 9am to 5pm", "Monday to Friday nine AM to five PM"),
    # ---- English: dates and ordinals ----
    ("en-US", "October 3", "October third"),
    ("en-US", "October 3rd, 2026", "October third, twenty twenty-six"),
    ("en-US", "Oct. 3", "October third"),
    ("en-GB", "October 3", "October the third"),
    ("en-US", "3 October", "the third of October"),
    ("en-GB", "on the 3rd of October", "on the third of October"),
    ("en-US", "21st", "twenty-first"),
    ("en-US", "2nd floor", "second floor"),
    ("en-US", "10/03/2026", "October third, twenty twenty-six"),
    ("en-GB", "10/03/2026", "the tenth of March, twenty twenty-six"),
    ("en-US", "until 12/31/2026", "until December thirty-first, twenty twenty-six"),
    ("en-GB", "12/31/2026", "the thirty-first of December, twenty twenty-six"),
    ("en", "2026-10-03", "October third, twenty twenty-six"),
    # ---- English: phone numbers, codes, abbreviations ----
    ("en-US", "Please call us on 0212 555 1234.",
     "Please call us on zero two one two, five five five, one two three four."),
    ("en-US", "(555) 123-4567", "five five five, one two three, four five six seven"),
    ("en-US", "+1 555 123 4567", "plus one, five five five, one two three, four five six seven"),
    ("en-US", "order AB-1234", "order eigh B one two three four"),
    ("en-US", "No. 5", "number five"),
    ("en-US", "I said no. Then", "I said no. Then"),
    ("en-US", "approx. 5", "approximately five"),
    ("en-US", "St. Mary's Church", "Saint Mary's Church"),
    ("en-US", "123 Main St.", "one hundred twenty-three Main Street."),
    ("en-US", "Visit St. Louis", "Visit St. Louis"),
    ("en-US", "e.g. this", "for example this"),
    ("en-US", "i.e. that", "that is that"),
    ("en-US", "Mr Smith", "Mister Smith"),
    ("en-US", "Mrs. Smith", "Missus Smith"),
    ("en-US", "Dr. Smith", "Doctor Smith"),
    ("en-US", "5 apples", "five apples"),
]


@unittest.skipUnless(HAVE_NUM2WORDS, "num2words is not installed")
class TableTest(unittest.TestCase):
    def test_cases(self):
        self.assertGreaterEqual(len(CASES), 60)
        for lang, text, expected in CASES:
            with self.subTest(lang=lang, text=text):
                self.assertEqual(textnorm.normalize(text, lang), expected)

    def test_language_tags(self):
        self.assertEqual(textnorm.language("de_DE"), ("de", ""))
        self.assertEqual(textnorm.language("de-CH"), ("de", ""))
        self.assertEqual(textnorm.language("en"), ("en", "us"))
        self.assertEqual(textnorm.language("en_US"), ("en", "us"))
        self.assertEqual(textnorm.language("en-gb-x-rp"), ("en", "gb"))
        self.assertIsNone(textnorm.language("fr"))
        self.assertIsNone(textnorm.language(None))

    def test_text_without_numbers_is_unchanged(self):
        for lang in ("de", "en-US", "en-GB"):
            text = "Hallo, willkommen. Hello, how are you today?"
            self.assertEqual(textnorm.normalize(text, lang), text)


class UnknownLanguageTest(unittest.TestCase):
    def test_other_languages_unchanged(self):
        for lang in ("fr", "es", "it", "pt-BR", "tr", "hi", "", None, 42):
            for text in ("1.234,50 €", "$1,234.50", "14:30 Uhr", "am 3. Oktober", "z. B."):
                self.assertEqual(textnorm.normalize(text, lang), text)

    def test_not_a_string(self):
        self.assertIsNone(textnorm.normalize(None, "de"))
        self.assertEqual(textnorm.normalize("", "en"), "")


class NeverRaisesTest(unittest.TestCase):
    ALPHABET = "0123456789012345678901234567890123456789 .,:;/-+–−%€$£°()'\"!?abcAEZäöüUhrpmstndé \n"
    WORDS = [" Uhr", " Euro", " EUR", " Cent", " am ", " der ", " Oktober", " October", " pm", " a.m.",
             " Stock", " z. B.", " usw.", " St.", " Mr ", " No.", " % ", "st", "rd", " Mio.", " million"]

    def test_fuzz(self):
        rng = random.Random(1234)
        for i in range(600):
            parts = []
            for _ in range(rng.randint(1, 12)):
                if rng.random() < 0.3:
                    parts.append(rng.choice(self.WORDS))
                else:
                    parts.append("".join(rng.choice(self.ALPHABET) for _ in range(rng.randint(1, 8))))
            text = "".join(parts)
            for lang in ("de", "en-US", "en-GB"):
                with self.subTest(text=text, lang=lang):
                    out = textnorm.normalize(text, lang)
                    self.assertIsInstance(out, str)

    def test_huge_numbers_left_alone(self):
        text = "9" * 40 + " €"
        for lang in ("de", "en"):
            self.assertIsInstance(textnorm.normalize(text, lang), str)


@unittest.skipUnless(HAVE_NUMPY and HAVE_NUM2WORDS, "numpy or num2words is not installed")
class EngineWiringTest(unittest.TestCase):
    def test_piper_say_normalizes_by_config_language(self):
        seen = []
        voice = piper.PiperVoice.__new__(piper.PiperVoice)
        voice.num_speakers, voice.length_scale, voice.sample_rate = 1, 1.0, 22050
        voice.text_language = "de_DE"
        voice.phonemize = lambda text: seen.append(text) or []
        voice.say("Am 3. Oktober um 14:30 Uhr.", sample_rate=8000)
        voice.normalize_text = False
        voice.say("Am 3. Oktober um 14:30 Uhr.", sample_rate=8000)
        self.assertEqual(seen, ["Am dritten Oktober um vierzehn Uhr dreißig.", "Am 3. Oktober um 14:30 Uhr."])

    def test_kokoro_say_normalizes_by_voice_prefix(self):
        seen = []
        voice = kokoro.KokoroVoice.__new__(kokoro.KokoroVoice)
        voice.packs = {"af_x": None, "bf_x": None, "ef_x": None}
        voice.runs = lambda text, name: seen.append(text) or []
        voice.say("10/03/2026", voice="af_x")
        voice.say("10/03/2026", voice="bf_x")
        voice.say("10/03/2026", voice="ef_x")
        voice.normalize_text = False
        voice.say("10/03/2026", voice="af_x")
        self.assertEqual(seen, ["October third, twenty twenty-six", "the tenth of March, twenty twenty-six",
                                "10/03/2026", "10/03/2026"])


if __name__ == "__main__":
    unittest.main()
