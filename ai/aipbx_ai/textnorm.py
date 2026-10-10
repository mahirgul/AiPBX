"""Text normalisation for the espeak-ng based voices (Piper, Kokoro).

espeak-ng reads written numbers badly in telephone announcements: German
"1.234,50 Euro" becomes "eintausend zweihundert vierunddreißig Komma fünf
null Euro", "14:30 Uhr" "vierzehn Uhr dreißig Uhr", "am 3. Oktober" ends the
sentence after "drei", and English "$1,234.50" is read "dollar one thousand
... point five zero". normalize() writes numbers, amounts, times, dates,
phone numbers and a few abbreviations out as words before the text is
phonemised. EMA Lightning (Turkish) does the same with normalizer-tr.

Languages: German ("de", "de-DE", "de_CH" ...) and English ("en", "en-US",
"en-GB" ...). Any other language returns the text unchanged. Bare "en" and
en-US/en-CA follow American conventions (month/day dates, no "and" inside
numbers); every other English region follows British ones (day/month, "one
hundred and one").

Cardinal and ordinal words come from num2words (LGPL-2.1, installed as a
separate package); without it, normalize() returns the text unchanged. The
rules around it are regular expressions, applied in this order (each one
sees only the digits the earlier ones left):

 1. abbreviations      de "z. B." -> "zum Beispiel", en "e.g." -> "for example"
 2. amounts            de "1.234,50 €" -> "eintausend zweihundert vier und
                       dreißig Euro fünfzig", "0,99 €" -> "neun und neunzig
                       Cent";
                       en "$1,234.50" -> "one thousand two hundred thirty-four
                       dollars and fifty cents"
 3. times              de "14:30 Uhr" / "14.30 Uhr" / "14:30" -> "vierzehn Uhr
                       dreißig", "9-17 Uhr" -> "neun bis siebzehn Uhr";
                       en "14:30" -> "fourteen thirty", "9:05" -> "nine oh
                       five", "10:00" -> "ten o'clock", "2:30 pm" -> "two
                       thirty PM" (espeak-ng spells "PM"; "a m" would be read
                       as the article "a")
 4. numeric dates      de "03.10.2026" -> "dritter Oktober
                       zweitausend sechs und zwanzig" (the ordinal is inflected
                       by the word before it, see below); en "10/03/2026":
                       en-US month/day -> "October third, twenty twenty-six",
                       en-GB day/month -> "the tenth of March, twenty
                       twenty-six"; ISO "2026-10-03" in both
 5. ordinals           de "am 3. Oktober" -> "am dritten Oktober", "1. Stock"
                       -> "erster Stock" (only before a month or a noun of a
                       small list, where the dot cannot end a sentence);
                       en "October 3rd" -> "October third", "3 October" ->
                       "the third of October", "21st" -> "twenty-first"
 6. phone numbers      "+49 30 1234567" -> "plus vier neun, drei null, eins
                       zwei ..." digit by digit, a comma (short pause) between
                       the groups as written; en uses "zero", not "oh"
 7. codes              "AB-1234" -> "A B eins zwei drei vier" (letters
                       spelled, digits one by one)
 8. numbers            ranges "10-20" -> "zehn bis zwanzig" / "ten to
                       twenty"; "-5" -> "minus fünf"; "2,5" -> "zwei Komma
                       fünf"; "50 %" -> "fünfzig Prozent"; de 4-digit years
                       after "im Jahr", "seit" ... -> "neunzehnhundert neunzig"

German number words are spaced as espeak-ng reads digits itself ("vier und
dreißig", a word break after "tausend" and "hundert"): it mispronounces the
long compounds ("zweihundertvierunddreißig" with a "v").

German ordinal endings (weak/strong adjective declension, simplified):
"-en" after am, im, vom, zum, zur, beim, dem, den, des, einem, einer, eines
and after ab, bis, seit without an article ("bis 31.12." -> "bis
einund dreißigsten Dezember", as it is spoken); "-e" after der, die, das
(but "der" before a feminine noun is dative: "in der 2. Etage" -> "zweiten");
otherwise strong, by the noun's gender: masculine "-er" ("dritter Oktober"),
neuter "-es", feminine "-e".

Every rule leaves its span unchanged when something does not fit (a month
13, a number too large, an exception), and normalize() never raises.
"""
import re

try:
    from num2words import num2words as _num2words
except Exception:  # not installed: normalize() leaves the text alone
    _num2words = None

__all__ = ["normalize", "available", "language"]

MAX_NUMBER = 10 ** 15
S = "[   ]"          # a space, also no-break and narrow no-break
SP = S + "?"
# A number does not start right after a letter, digit or separator...
NB = r"(?<![\w.,:/])"
# ...and does not run on into one.
NA = r"(?![\w]|[.,:/]\d)"
UPPER_START = re.compile(r"[ \t]+[A-ZÄÖÜ]")


def available():
    """True when num2words is installed (normalize() does nothing without it)."""
    return _num2words is not None


def language(lang):
    """("de" | "en", region) for a language tag, or None."""
    if not isinstance(lang, str):
        return None
    parts = lang.strip().lower().replace("_", "-").split("-")
    if parts[0] == "de":
        return ("de", "")
    if parts[0] == "en":
        region = parts[1] if len(parts) > 1 else ""
        return ("en", "us" if region in ("", "us", "ca") else "gb")
    return None


def normalize(text, lang):
    """text with numbers, amounts, times, dates and abbreviations written out
    for the language; unchanged for other languages. Never raises."""
    try:
        if not isinstance(text, str) or not text or _num2words is None:
            return text
        lang = language(lang)
        if lang is None:
            return text
        rules = _RULES.get(lang)
        if rules is None:
            rules = _RULES[lang] = _German() if lang[0] == "de" else _English(lang[1] == "us")
        out = text
        for pattern, func in rules.rules():
            out = pattern.sub(_safe(func), out)
        return out
    except Exception:
        return text


_RULES = {}


def _safe(func):
    def repl(m):
        try:
            out = func(m)
        except Exception:
            out = None
        return m.group(0) if out is None else out
    return repl


def _word_before(m, count=1):
    """The last `count` words before the match, lower case."""
    words = re.findall(r"[\w]+", m.string[max(0, m.start() - 40):m.start()])
    return " ".join(words[-count:]).lower() if words else ""


def _keep_period(m):
    """A period eaten by the match ended the sentence: give it back when the
    text ends there or the next word starts with a capital letter."""
    rest = m.string[m.end():]
    return "." if not rest.strip() or UPPER_START.match(rest) else ""


def _int(digits):
    return int(re.sub(r"[^\d]", "", digits))


# ---------------------------------------------------------------------------

class _Language:
    DIGITS = ()
    MONTHS = ()

    def __init__(self):
        self._rules = None

    def words(self, n, to="cardinal"):
        if not 0 <= n < MAX_NUMBER:
            raise ValueError("number out of range")
        return self.tidy(_num2words(n, lang=self.CODE, to=to))

    def tidy(self, words):
        return words

    def digits(self, s):
        return " ".join(self.DIGITS[int(c)] for c in s if c.isdigit())

    def fraction(self, frac):
        return self.digits(frac)

    def rules(self):
        if self._rules is None:
            self._rules = [(re.compile(p, f), func) for p, f, func in self.table()]
        return self._rules

    # ---- phone numbers and codes (shared) -----------------------------

    PHONE = (r"(?<![\w.,:/+])(?P<num>(?:\+" + SP + r")?\(?\d+\)?(?:(?:" + SP + r"[-/]" + SP + "|" + S +
             r")\(?\d+\)?)*)" + NA)

    def phone(self, m):
        num = m.group("num")
        groups = re.findall(r"\d+", num)
        total = sum(len(g) for g in groups)
        plus = num.startswith("+")
        if plus:
            ok = total >= 5
        elif groups[0].startswith("0"):
            ok = total >= 3
        elif len(groups) == 1:
            ok = total >= 7
        else:
            # "2026-2027" is a range of years, not a number
            years = len(groups) == 2 and all(len(g) == 4 and g[0] in "12" for g in groups)
            ok = total >= 7 and not years
        if not ok:
            return None
        words = ", ".join(self.digits(g) for g in groups)
        return (self.PLUS + " " + words) if plus else words

    CODE_RE = r"(?<![\w-])(?P<l>[A-Z]{1,3})(?P<h>-?)(?P<d>\d{2,})(?![\w]|[.,:/-]\d)"

    def code(self, m):
        letters, digits = m.group("l"), m.group("d")
        if not m.group("h") and len(digits) < 3:
            return None
        return " ".join(self.letter(c) for c in letters) + " " + self.digits(digits)

    def letter(self, c):
        return c


# ---------------------------------------------------------------------------

class _German(_Language):
    CODE = "de"
    DIGITS = ("null", "eins", "zwei", "drei", "vier", "fünf", "sechs", "sieben", "acht", "neun")
    PLUS = "plus"
    MONTHS = ("Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September",
              "Oktober", "November", "Dezember")
    MONTH_RE = (r"Januar|Jan\.|Februar|Feb\.|März|Mär\.|Mrz\.|April|Apr\.|Mai|Juni|Jun\.|Juli|Jul\.|"
                r"August|Aug\.|September|Sept\.|Sep\.|Oktober|Okt\.|November|Nov\.|Dezember|Dez\.")
    NUM = r"\d{1,3}(?:\.\d{3})+|\d+"
    # Nouns an ordinal "3." may stand before (the dot cannot end a sentence
    # there), with their gender.
    ORDINAL_NOUNS = {
        "Stock": "m", "Platz": "m", "Advent": "m", "Geburtstag": "m", "Tag": "m", "Monat": "m",
        "Feiertag": "m", "Weihnachtstag": "m", "Weltkrieg": "m", "Versuch": "m", "Rang": "m",
        "Etage": "f", "Klasse": "f", "Liga": "f", "Bundesliga": "f", "Runde": "f", "Auflage": "f",
        "Hälfte": "f", "Mahnung": "f", "Rate": "f", "Ausgabe": "f", "Woche": "f", "Stelle": "f",
        "Obergeschoss": "n", "Untergeschoss": "n", "Geschoss": "n", "Mal": "n", "Jahrhundert": "n",
        "Jahrtausend": "n", "Quartal": "n", "Halbjahr": "n", "Semester": "n", "Kapitel": "n",
        "Jahr": "n", "Lebensjahr": "n",
    }
    # Feminine nouns a number is often written before ("1 Minute" -> "eine").
    FEMININE = frozenset((
        "Minute", "Sekunde", "Stunde", "Person", "Woche", "Nacht", "Nummer", "Taste", "Stelle",
        "Option", "Nachricht", "Mail", "Datei", "Seite", "Rechnung", "Bestellung", "Lieferung",
        "Packung", "Flasche", "Tablette", "Etage", "Million", "Milliarde", "Billion", "Zeile",
        "Ziffer", "Sache", "Frage", "Antwort", "Rate", "Karte", "Leitung", "Linie", "Runde",
    ))
    MASCULINE_E = frozenset(("Name", "Kunde", "Junge", "Käse", "Gedanke", "Buchstabe", "Glaube",
                             "Wille", "Friede", "Funke", "Kollege", "Hase", "Affe", "Löwe", "Auge",
                             "Ende", "Erbe", "Interesse", "Gebäude", "Gebirge", "Getreide"))
    FEMININE_ENDINGS = ("ung", "heit", "keit", "ion", "tät", "schaft", "ei")
    EN_AFTER = frozenset(("am", "im", "vom", "zum", "zur", "beim", "dem", "den", "des", "einem",
                          "einer", "eines", "ab", "bis", "seit"))
    E_AFTER = frozenset(("der", "die", "das"))
    YEAR_AFTER = frozenset(("jahr", "jahre", "jahres", "anno", "seit", "bis", "von", "ab", "im",
                            "vor", "nach", "um", "bis", "sommer", "winter", "frühjahr", "herbst"))
    CURRENCIES = {  # written -> (unit, minor unit)
        "€": ("Euro", "Cent"), "EUR": ("Euro", "Cent"), "Euro": ("Euro", "Cent"),
        "$": ("Dollar", "Cent"), "USD": ("Dollar", "Cent"), "Dollar": ("Dollar", "Cent"),
        "£": ("Pfund", "Pence"), "GBP": ("Pfund", "Pence"), "Pfund": ("Pfund", "Pence"),
        "CHF": ("Franken", "Rappen"), "Franken": ("Franken", "Rappen"),
    }
    MINOR = {"Cent": "Cent", "ct": "Cent", "ct.": "Cent", "Rappen": "Rappen", "Pence": "Pence"}
    UNITS = {"%": "Prozent", "‰": "Promille", "°C": "Grad Celsius", "°F": "Grad Fahrenheit",
             "°": "Grad"}
    ABBREVIATIONS = [
        # (pattern, words, may end a sentence)
        (r"\bz\." + SP + r"B\.", "zum Beispiel", False),
        (r"\bd\." + SP + r"h\.", "das heißt", False),
        (r"\bu\." + SP + r"a\.", "unter anderem", True),
        (r"\bu\." + SP + r"U\.", "unter Umständen", False),
        (r"\bca\.", "circa", False),
        (r"\bNr\.", "Nummer", False),
        (r"\bbzw\.", "beziehungsweise", False),
        (r"\busw\.", "und so weiter", True),
        (r"\betc\.", "et cetera", True),
        (r"\binkl\.", "inklusive", False),
        (r"\bzzgl\.", "zuzüglich", False),
        (r"\bevtl\.", "eventuell", False),
        (r"\bggf\.", "gegebenenfalls", False),
        (r"\bTel\.", "Telefon", False),
        (r"\bStr\.", "Straße", False),
        (r"(?<=[a-zäöüß])str\.", "straße", False),
        (r"\bMio\.", "Millionen", False),
        (r"\bMrd\.", "Milliarden", False),
        (r"\bDr\.", "Doktor", False),
        (r"\bProf\.", "Professor", False),
    ]
    WEEKDAYS = {"mo": "Montag", "di": "Dienstag", "mi": "Mittwoch", "do": "Donnerstag",
                "fr": "Freitag", "sa": "Samstag", "so": "Sonntag"}

    def table(self):
        num, dec = self.NUM, r"(?:,(?:\d+|-{1,2}|–))?"
        cur = r"€|EUR|\$|USD|£|GBP|CHF"
        cur_after = cur + r"|Euro|Dollar|Pfund|Franken|Cent|ct\.?|Rappen|Pence"
        time = r"(?P<h{0}>[01]?\d|2[0-4])(?:(?P<s{0}>[:.])(?P<m{0}>[0-5]\d))?"
        day = r"(?P<d>0?[1-9]|[12]\d|3[01])"
        wd = r"(?P<a>Mo|Di|Mi|Do|Fr|Sa|So)\.?" + SP + r"(?:[-–]|bis)" + SP + r"(?P<b>Mo|Di|Mi|Do|Fr|Sa|So)\b\.?"
        rows = [(p, 0, self.abbreviation(w, end)) for p, w, end in self.ABBREVIATIONS]
        rows += [
            (r"\b" + wd, 0, self.weekdays),
            (NB + r"(?P<neg>[-−])?(?P<c1>" + cur + ")" + SP + r"(?P<amt>" + num + ")(?P<dec>" + dec + ")" + NA,
             0, self.amount),
            (NB + r"(?P<neg>[-−])?(?P<amt>" + num + ")(?P<dec>" + dec + ")" + SP + "(?P<c2>" + cur_after +
             r")(?![\w])", 0, self.amount),
            (NB + time.format(1) + r"(?:" + SP + r"(?:[-–]|bis)" + SP + time.format(2) + r")?" +
             r"(?P<uhr>" + SP + r"Uhr\b)?" + NA, 0, self.time),
            (NB + r"(?P<y>\d{4})-(?P<mo>0[1-9]|1[0-2])-(?P<d>0[1-9]|[12]\d|3[01])" + NA, 0, self.date),
            (NB + day + r"\.(?P<mo>0?[1-9]|1[0-2])\.(?P<y>\d{4}|\d{2})?(?![\w]|[.,:/]\d)", 0, self.date),
            (NB + day + r"\." + SP + r"(?P<mon>" + self.MONTH_RE + r")(?:" + S + r"(?P<y>\d{4})" + NA + ")?",
             0, self.month_date),
            (NB + r"(?P<n>\d{1,3})\." + S + r"(?P<noun>" + "|".join(self.ORDINAL_NOUNS) + r")\b",
             0, self.noun_ordinal),
            (self.PHONE, 0, self.phone),
            (self.CODE_RE, 0, self.code),
            (NB + r"(?P<a>" + num + r")" + SP + r"[-–]" + SP + r"(?P<b>" + num + ")" + NA, 0, self.range),
            (r"(?<![\w.,:/\-−])(?P<neg>[-−])?(?P<int>" + num + r")(?P<dec>,\d+)?" +
             r"(?:(?P<unit>" + SP + r"(?:%|‰|°C|°F|°))|(?P<scale>" + S +
             r"(?:Millionen|Million|Milliarden|Milliarde)\b))?" + r"(?![\w]|[.,:/]\d)", 0, self.number),
        ]
        return rows

    # ---- words --------------------------------------------------------

    # espeak-ng splits long compounds badly ("zweihundertvierunddreißig":
    # "...tviːr...", "fünfundvierzig": "...vˌiːɾtsɪç"); it reads digits as
    # "vier und dreißig" with "tausend"/"hundert" ending a word, so the
    # words are spaced the same way: 1234 -> "eintausend zweihundert vier
    # und dreißig", 21 -> "einund zwanzig".
    SPLIT_AFTER = re.compile(r"(tausend|hundert)(?=[a-zäöüß])(?!st)")
    SPLIT_UND = re.compile(r"(ein|zwei|drei|vier|fünf|sechs|sieben|acht|neun)und"
                           r"(?=zwanzig|dreißig|vierzig|fünfzig|sechzig|siebzig|achtzig|neunzig)")

    def tidy(self, words):
        words = self.SPLIT_UND.sub(r"\1 und ", self.SPLIT_AFTER.sub(r"\1 ", words))
        # a lone "ein" is an unstressed article to espeak-ng; "einund" keeps a stress
        return re.sub(r"\bein und ", "einund ", words)

    def cardinal(self, n, before=None):
        """n in words; a final "eins" becomes "ein"/"eine" before a noun."""
        w = self.words(n)
        if before and w.endswith("eins"):
            w = w[:-1] + ("e" if self.feminine(before) else "")
        return w

    def feminine(self, noun):
        return noun in self.FEMININE or noun.endswith(self.FEMININE_ENDINGS) or (
            noun.endswith("e") and noun not in self.MASCULINE_E)

    def ordinal(self, n, ending):
        """"dritte" + ending ("", "n", "r", "s")."""
        return self.words(n, "ordinal") + ending

    def ending(self, m, gender="m"):
        before = _word_before(m)
        if before in self.EN_AFTER:
            return "n"
        if before in self.E_AFTER:
            return "n" if before == "der" and gender == "f" else ""
        return {"m": "r", "n": "s", "f": ""}[gender]

    def year(self, digits):
        if len(digits) == 2:
            return self.digits(digits) if digits[0] == "0" else self.words(int(digits))
        return self.words(int(digits), "year")

    def decimal(self, intpart, frac):
        return self.words(intpart) + " Komma " + self.fraction(frac)

    # ---- rules --------------------------------------------------------

    def abbreviation(self, words, may_end):
        def repl(m):
            return words + (_keep_period(m) if may_end else "")
        return repl

    def weekdays(self, m):
        return self.WEEKDAYS[m.group("a").lower()] + " bis " + self.WEEKDAYS[m.group("b").lower()]

    def amount(self, m):
        written = m.groupdict().get("c1") or m.groupdict().get("c2")
        neg = "minus " if m.group("neg") else ""
        units, cents = _int(m.group("amt")), None
        dec = (m.group("dec") or ",")[1:]
        if written in self.MINOR or written.rstrip(".") in self.MINOR:
            if dec:
                return None
            minor = self.MINOR.get(written) or self.MINOR[written.rstrip(".")]
            return neg + self.cardinal(units, minor) + " " + minor
        unit, minor = self.CURRENCIES[written]
        if dec and dec.isdigit():
            if len(dec) > 2:
                return neg + self.decimal(units, dec) + " " + unit
            cents = int(dec.ljust(2, "0"))
        if units == 0 and cents:
            return neg + self.cardinal(cents, minor) + " " + minor
        words = neg + self.cardinal(units, unit) + " " + unit
        return words + (" " + self.words(cents) if cents else "")

    def clock(self, h, mm, uhr=True):
        out = self.cardinal(h, "Uhr") + (" Uhr" if uhr else "")
        return out + (" " + self.words(mm) if mm else "")

    def time(self, m):
        uhr = bool(m.group("uhr"))
        times = []
        for i in ("1", "2"):
            h = m.group("h" + i)
            if h is None:
                continue
            sep, mm = m.group("s" + i), m.group("m" + i)
            if not uhr and sep != ":":
                return None  # "14.30" without "Uhr" is a number, "9" just a number
            h, mm = int(h), int(mm) if mm else 0
            if h == 24 and mm:
                return None
            times.append((h, mm))
        if len(times) == 2 and not any(mm for _, mm in times):
            return self.cardinal(times[0][0], "Uhr") + " bis " + self.clock(times[1][0], 0)
        return " bis ".join(self.clock(h, mm) for h, mm in times)

    def date(self, m):
        d, mo = int(m.group("d")), int(m.group("mo"))
        out = self.ordinal(d, self.ending(m)) + " " + self.MONTHS[mo - 1]
        y = m.group("y")
        if y:
            out += " " + self.year(y)
        elif m.group(0).endswith("."):
            out += _keep_period(m)
        return out

    def month_date(self, m):
        mon = m.group("mon").rstrip(".")
        month = next(x for x in self.MONTHS if x.startswith(mon) or (mon == "Mrz" and x == "März"))
        out = self.ordinal(int(m.group("d")), self.ending(m)) + " " + month
        if m.group("y"):
            out += " " + self.year(m.group("y"))
        return out

    def noun_ordinal(self, m):
        noun = m.group("noun")
        return self.ordinal(int(m.group("n")), self.ending(m, self.ORDINAL_NOUNS[noun])) + " " + noun

    def range(self, m):
        return self.words(_int(m.group("a"))) + " bis " + self.words(_int(m.group("b")))

    def next_noun(self, m):
        nxt = re.match(S + r"([A-ZÄÖÜ][\wäöüß]*)", m.string[m.end():])
        return nxt.group(1) if nxt else None

    def number(self, m):
        neg = "minus " if m.group("neg") else ""
        digits, dec = m.group("int"), m.group("dec")
        n = _int(digits)
        unit, scale = m.group("unit"), m.group("scale")
        if dec:
            out = neg + self.decimal(n, dec[1:])
        elif scale and n == 1:
            return neg + "eine " + ("Million" if scale.strip().startswith("Million") else "Milliarde")
        elif (not neg and not unit and not scale and len(digits) == 4 and 1100 <= n <= 1999
              and _word_before(m) in self.YEAR_AFTER):
            out = self.year(digits)
        else:
            out = neg + self.cardinal(n, "Prozent" if unit else self.next_noun(m))
        if unit:
            out += " " + self.UNITS[unit.strip("   ")]
        elif scale:
            out += scale
        return out


# ---------------------------------------------------------------------------

class _English(_Language):
    CODE = "en"
    DIGITS = ("zero", "one", "two", "three", "four", "five", "six", "seven", "eight", "nine")
    PLUS = "plus"
    MONTHS = ("January", "February", "March", "April", "May", "June", "July", "August", "September",
              "October", "November", "December")
    MONTH_RE = (r"January|Jan\.?|February|Feb\.?|March|Mar\.?|April|Apr\.?|May|June|Jun\.?|July|Jul\.?|"
                r"August|Aug\.?|September|Sept\.?|Sep\.?|October|Oct\.?|November|Nov\.?|December|Dec\.?")
    NUM = r"\d{1,3}(?:,\d{3})+|\d+"
    CURRENCIES = {  # written -> (unit, units, minor, minors)
        "$": ("dollar", "dollars", "cent", "cents"), "USD": ("dollar", "dollars", "cent", "cents"),
        "€": ("euro", "euros", "cent", "cents"), "EUR": ("euro", "euros", "cent", "cents"),
        "£": ("pound", "pounds", "penny", "pence"), "GBP": ("pound", "pounds", "penny", "pence"),
        "CHF": ("franc", "francs", "centime", "centimes"),
    }
    UNITS = {"%": ("percent", "percent"), "‰": ("per mille", "per mille"),
             "°C": ("degree Celsius", "degrees Celsius"), "°F": ("degree Fahrenheit", "degrees Fahrenheit"),
             "°": ("degree", "degrees")}
    YEAR_AFTER = frozenset(("in", "since", "until", "till", "from", "year", "before", "after", "during",
                            "around", "circa", "by"))
    ABBREVIATIONS = [
        (r"\b[Nn]o\.(?=" + SP + r"\d)", "number", False),
        (r"\bext\.(?=" + SP + r"\d)", "extension", False),
        (r"\bapprox\.", "approximately", False),
        (r"\be\.g\.", "for example", False),
        (r"\bi\.e\.", "that is", False),
        (r"\betc\.", "et cetera", True),
        (r"\bvs\.", "versus", False),
        (r"\bMrs\b\.?", "Missus", False),
        (r"\bMr\b\.?", "Mister", False),
        (r"\bMs\b\.?(?=" + S + "[A-Z])", "Miz", False),
        (r"\bDr\.?(?=" + S + "[A-Z])", "Doctor", False),
    ]
    WEEKDAYS = {"mon": "Monday", "tue": "Tuesday", "wed": "Wednesday", "thu": "Thursday",
                "fri": "Friday", "sat": "Saturday", "sun": "Sunday"}

    def __init__(self, us):
        super().__init__()
        self.us = us

    def tidy(self, words):
        words = words.replace(",", "")
        return words.replace(" and ", " ") if self.us else words

    def letter(self, c):
        return "eigh" if c == "A" else c  # espeak-ng reads a lone "A" as the article

    def table(self):
        num, dec = self.NUM, r"(?:\.\d+)?"
        cur = r"\$|USD|€|EUR|£|GBP|CHF"
        time = (r"(?P<h{0}>[01]?\d|2[0-4])(?::(?P<m{0}>[0-5]\d))?(?:" + SP +
                r"(?P<ap{0}>[AaPp])(?:\.?" + SP + r"[Mm]\.|[Mm]\b))?")
        wd = (r"(?P<a>Mon|Tue|Wed|Thu|Fri|Sat|Sun)\.?" + SP + r"(?:[-–]|to)" + SP +
              r"(?P<b>Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b\.?")
        rows = [(p, 0, self.abbreviation(w, end)) for p, w, end in self.ABBREVIATIONS]
        rows += [
            (r"\bSt\.", 0, self.saint_street),
            (r"\b" + wd, 0, self.weekdays),
            (NB + r"(?P<neg>[-−])?(?P<c1>" + cur + ")" + SP + r"(?P<amt>" + num + ")(?P<dec>" + dec +
             r")(?:" + S + r"(?P<scale>million|billion|trillion)\b)?" + NA, 0, self.amount),
            (NB + r"(?P<neg>[-−])?(?P<amt>" + num + ")(?P<dec>" + dec + ")" + SP + "(?P<c2>USD|EUR|GBP|CHF)" +
             r"(?![\w])", 0, self.amount),
            (NB + time.format(1) + r"(?:" + SP + r"(?:[-–]|to|until)" + SP + time.format(2) + r")?" +
             r"(?![\w]|[.,:/]\d)", 0, self.time),
            (NB + r"(?P<y>\d{4})-(?P<mo>0[1-9]|1[0-2])-(?P<d>0[1-9]|[12]\d|3[01])" + NA, 0, self.iso_date),
            (NB + r"(?P<a>\d{1,2})/(?P<b>\d{1,2})/(?P<y>\d{4}|\d{2})" + NA, 0, self.slash_date),
            (r"\b(?P<mon>" + self.MONTH_RE + r")" + S + r"(?P<d>\d{1,2})(?:st|nd|rd|th)?\b" +
             r"(?:,?" + S + r"(?P<y>\d{4})" + NA + r")?(?![.,:/]\d)", 0, self.month_day),
            (NB + r"(?:(?P<the>[Tt]he)" + S + r")?(?P<d>\d{1,2})(?:st|nd|rd|th)?" + S + r"(?:of" + S + r")?(?P<mon>" +
             self.MONTH_RE + r")(?!\w)(?:,?" + S + r"(?P<y>\d{4})" + NA + ")?", 0, self.day_month),
            (NB + r"(?P<n>" + num + r")(?:st|nd|rd|th)\b", 0, self.ordinal_suffix),
            (self.PHONE, 0, self.phone),
            (self.CODE_RE, 0, self.code),
            (NB + r"(?P<a>" + num + r")" + SP + r"[-–]" + SP + r"(?P<b>" + num + ")" + NA, 0, self.range),
            (r"(?<![\w.,:/\-−])(?P<neg>[-−])?(?P<int>" + num + r")(?P<dec>\.\d+)?" +
             r"(?P<unit>" + SP + r"(?:%|‰|°C|°F|°))?" + r"(?![\w]|[.,:/]\d)", 0, self.number),
        ]
        return rows

    # ---- words --------------------------------------------------------

    def decimal(self, intpart, frac):
        return self.words(intpart) + " point " + self.fraction(frac)

    def year(self, digits):
        if len(digits) == 2:
            return ("oh " + self.DIGITS[int(digits[1])]) if digits[0] == "0" else self.words(int(digits))
        return self.words(int(digits), "year")

    def ordinal(self, n):
        return self.words(n, "ordinal")

    # ---- rules --------------------------------------------------------

    def abbreviation(self, words, may_end):
        def repl(m):
            return words + (_keep_period(m) if may_end else "")
        return repl

    def saint_street(self, m):
        """"St. Mary" -> Saint, "Main St." -> Street; both or neither: unchanged."""
        before = re.search(r"([A-Za-z]+)" + SP + r"$", m.string[max(0, m.start() - 30):m.start()])
        after = re.match(S + r"[A-Z]", m.string[m.end():])
        before_cap = bool(before) and before.group(1)[0].isupper()
        if after and not before_cap:
            return "Saint"
        if before_cap and not after:
            return "Street" + _keep_period(m)
        return None

    def weekdays(self, m):
        return self.WEEKDAYS[m.group("a").lower()] + " to " + self.WEEKDAYS[m.group("b").lower()]

    def count(self, n, one, many):
        return self.words(n) + " " + (one if n == 1 else many)

    def amount(self, m):
        unit, units, minor, minors = self.CURRENCIES[m.groupdict().get("c1") or m.groupdict().get("c2")]
        neg = "minus " if m.group("neg") else ""
        n, dec = _int(m.group("amt")), (m.group("dec") or ".")[1:]
        scale = m.groupdict().get("scale")
        if scale:
            return neg + (self.decimal(n, dec) if dec else self.words(n)) + " " + scale + " " + units
        if len(dec) > 2:
            return neg + self.decimal(n, dec) + " " + units
        cents = int(dec.ljust(2, "0")) if dec else 0
        if n == 0 and cents:
            return neg + self.count(cents, minor, minors)
        out = neg + self.count(n, unit, units)
        return out + (" and " + self.count(cents, minor, minors) if cents else "")

    def clock(self, h, mm, ap):
        if ap and not 1 <= h <= 12:
            return None
        out = self.words(h)
        if mm:
            out += (" oh " + self.words(mm)) if mm < 10 else (" " + self.words(mm))
        elif not ap:
            out += " o'clock" if 1 <= h <= 12 else " hundred"
        return out + (" " + ap.upper() + "M" if ap else "")

    def time(self, m):
        times = []
        for i in ("1", "2"):
            h = m.group("h" + i)
            if h is None:
                continue
            mm, ap = m.group("m" + i), m.group("ap" + i)
            if mm is None and not ap:
                return None  # a bare number
            h, mm = int(h), int(mm) if mm else 0
            if h == 24 and mm:
                return None
            words = self.clock(h, mm, ap)
            if words is None:
                return None
            times.append(words)
        out = " to ".join(times)
        return out + (_keep_period(m) if m.group(0).endswith(".") else "")

    def date_words(self, m, d, mo, y):
        if not (1 <= mo <= 12 and 1 <= d <= 31):
            return None
        if self.us:
            out = self.MONTHS[mo - 1] + " " + self.ordinal(d)
        else:
            the = "" if _word_before(m) == "the" else "the "
            out = the + self.ordinal(d) + " of " + self.MONTHS[mo - 1]
        return out + (", " + self.year(y) if y else "")

    def iso_date(self, m):
        return self.date_words(m, int(m.group("d")), int(m.group("mo")), m.group("y"))

    def slash_date(self, m):
        a, b = int(m.group("a")), int(m.group("b"))
        mo, d = (a, b) if self.us else (b, a)
        if mo > 12 and d <= 12:   # written the other way round
            mo, d = d, mo
        return self.date_words(m, d, mo, m.group("y"))

    def month(self, written):
        mon = written.rstrip(".").lower()
        return next(x for x in self.MONTHS if x.lower().startswith(mon))

    def month_day(self, m):
        d = int(m.group("d"))
        if not 1 <= d <= 31:
            return None
        out = self.month(m.group("mon")) + (" " if self.us else " the ") + self.ordinal(d)
        return out + (", " + self.year(m.group("y")) if m.group("y") else "")

    def day_month(self, m):
        d = int(m.group("d"))
        if not 1 <= d <= 31:
            return None
        the = m.group("the")
        the = (the + " ") if the else ("" if _word_before(m) == "the" else "the ")
        out = the + self.ordinal(d) + " of " + self.month(m.group("mon"))
        return out + (", " + self.year(m.group("y")) if m.group("y") else "")

    def ordinal_suffix(self, m):
        return self.ordinal(_int(m.group("n")))

    def range(self, m):
        return self.words(_int(m.group("a"))) + " to " + self.words(_int(m.group("b")))

    def number(self, m):
        neg = "minus " if m.group("neg") else ""
        digits, dec, unit = m.group("int"), m.group("dec"), m.group("unit")
        n = _int(digits)
        if dec:
            out = neg + self.decimal(n, dec[1:])
        elif (not neg and not unit and len(digits) == 4 and 1100 <= n <= 2099
              and _word_before(m) in self.YEAR_AFTER):
            out = self.year(digits)
        else:
            out = neg + self.words(n)
        if unit:
            one, many = self.UNITS[unit.strip("   ")]
            out += " " + (one if n == 1 and not dec and not neg else many)
        return out


# ---------------------------------------------------------------- Turkish
#
# EMA Lightning's own normaliser (normalizer-tr) reads numbers, dates and "TL"
# amounts itself; this pre-pass only fixes what it reads badly on the phone:
#   "1.234,50 lira"  -> "virgül beş sıfır lira"      => "1234 lira 50 kuruş"
#   "0,99 TL"        -> "sıfır türk lirası doksan…"  => "99 kuruş"
#   "1.234,50 TL"    -> "… türk lirası elli kuruş"   => "1234 lira 50 kuruş" (shorter)
#   "12,90 €", "$5,50", "3,99 £" likewise with avro/sent, dolar/sent, sterlin/peni
#   "0212 555 12 34" -> one long number list          => "0212, 555, 12, 34" (pauses)
# The digits stay digits: normalizer-tr turns them into words afterwards.

_TR_CURRENCY = {
    "₺": ("lira", "kuruş"), "tl": ("lira", "kuruş"), "try": ("lira", "kuruş"), "lira": ("lira", "kuruş"),
    "€": ("avro", "sent"), "eur": ("avro", "sent"), "euro": ("avro", "sent"), "avro": ("avro", "sent"),
    "$": ("dolar", "sent"), "usd": ("dolar", "sent"), "dolar": ("dolar", "sent"),
    "£": ("sterlin", "peni"), "gbp": ("sterlin", "peni"), "sterlin": ("sterlin", "peni"),
}
_TR_NUM = r"(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?"
_TR_WORD_CUR = r"(TL|TRY|EUR|USD|GBP|lira|euro|avro|dolar|sterlin)"
_TR_AFTER = re.compile(r"(?<![\d.,])" + _TR_NUM + r"\s?(₺|€|\$|£|" + _TR_WORD_CUR[1:-1] + r")(?![\wçğıöşüÇĞİÖŞÜ])", re.IGNORECASE)
_TR_BEFORE = re.compile(r"(₺|€|\$|£)\s?" + _TR_NUM + r"(?![\d,])")
_TR_PHONE = re.compile(r"(?<![\d+])(\+\d{1,3}|0\d{2,4})((?:[ \-/]\d{2,4}){2,5})(?![\d])")


def _tr_amount(whole, cents, cur):
    unit, sub = _TR_CURRENCY[cur.lower()]
    n = int(whole.replace(".", ""))
    c = int((cents or "0").ljust(2, "0"))
    if n == 0 and c:
        return f"{c} {sub}"
    if c == 0:
        return f"{n} {unit}"
    return f"{n} {unit} {c} {sub}"


def normalize_tr(text):
    """Turkish pre-pass before normalizer-tr (see above). Never raises."""
    try:
        if not isinstance(text, str) or not text:
            return text
        out = _TR_BEFORE.sub(lambda m: _tr_amount(m.group(2), m.group(3), m.group(1)), text)
        out = _TR_AFTER.sub(lambda m: _tr_amount(m.group(1), m.group(2), m.group(3)), out)
        out = _TR_PHONE.sub(lambda m: m.group(1) + "".join(", " + g for g in re.split(r"[ \-/]", m.group(2)) if g), out)
        return out
    except Exception:
        return text
