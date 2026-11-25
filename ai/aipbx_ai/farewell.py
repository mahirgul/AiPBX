"""When a conversation is over: the caller says goodbye, or the agent does.

A small model often answers "Thank you" with "Anything else I can help with?" and the call stays
open until the caller hangs up. This is a safety net in code, deliberately narrow: only a short
utterance made up entirely of farewell, thanks and closing words counts, so "thanks, and could you
also check my balance" goes on to the model. Words are compared without case, accents or
punctuation (Turkish dotted i, German umlauts and ß included). A language without a list uses English.
"""
import re
import unicodedata

# phrases that end a call by themselves
STRONG = {
    "tr": ["gorusuruz", "hadi gorusuruz", "gorusmek uzere", "hosca kal", "hosca kalin", "iyi gunler",
           "iyi gunler dilerim", "iyi aksamlar", "iyi geceler", "kolay gelsin", "gule gule", "bay bay",
           "kapatiyorum", "kapatayim"],
    "en": ["goodbye", "good bye", "bye", "bye bye", "see you", "see you later", "talk to you later",
           "take care", "have a good day", "have a great day", "have a nice day", "good night"],
    "de": ["auf wiedersehen", "wiedersehen", "tschuss", "tschues", "ciao", "bis bald", "bis spater",
           "schonen tag", "einen schonen tag noch", "schonen abend", "gute nacht"],
}
# thanks: end the call unless the agent just asked a question that expects an answer
THANKS = {
    "tr": ["tesekkurler", "tesekkur ederim", "cok tesekkurler", "cok tesekkur ederim", "sagol", "sag ol",
           "sag olun", "eyvallah"],
    "en": ["thanks", "thank you", "thank you very much", "thanks a lot", "cheers"],
    "de": ["danke", "danke schon", "vielen dank", "dankeschon"],
}
# "no, that is all": end the call only after the agent asked whether there is anything else
CLOSERS = {
    "tr": ["hayir", "yok", "bu kadar", "hepsi bu kadar", "hepsi bu", "baska bir sey yok", "baska sorum yok",
           "baska bir seye ihtiyacim yok", "yeterli"],
    "en": ["no", "nope", "that is all", "thats all", "nothing else", "no thanks", "no thank you", "i am good",
           "im good", "all good", "that will be all"],
    "de": ["nein", "nein danke", "das war alles", "das ist alles", "das wars", "sonst nichts", "nichts weiter"],
}
# harmless words around them
FILLER = {
    "tr": ["tamam", "peki", "o zaman", "size de", "sana da", "rica ederim", "simdilik", "tamamdir"],
    "en": ["ok", "okay", "alright", "great", "perfect", "sounds good", "you too", "same to you", "so", "well"],
    "de": ["ok", "okay", "gut", "alles klar", "in ordnung", "ihnen auch", "dir auch", "also"],
}
# the agent asked whether there is more to do: "no" and "thanks" then close the call
CLOSING_QUESTIONS = {
    "tr": ["baska", "yardimci olabilir"],
    "en": ["anything else", "else i can", "help you with", "can i help"],
    "de": ["noch etwas", "sonst noch", "weiterhelfen"],
}
MAX_WORDS = 8
MAX_ASSISTANT_WORDS = 25


def fold(text):
    """Lower case, no accents, no punctuation: "Teşekkürler!" -> "tesekkurler"."""
    text = (text or "").replace("ß", "ss").replace("ı", "i").replace("İ", "i")
    text = unicodedata.normalize("NFKD", text)
    text = "".join(c for c in text if not unicodedata.combining(c)).lower()
    return " ".join(re.sub(r"[^a-z0-9 ]+", " ", text).split())


def _lang(lang):
    lang = (lang or "").lower()[:2]
    return lang if lang in STRONG else "en"


def _alternation(phrases):
    return "|".join(re.escape(p) for p in sorted(phrases, key=len, reverse=True))


def _compile(lang):
    groups = {}
    for name, table in (("strong", STRONG), ("thanks", THANKS), ("closer", CLOSERS), ("filler", FILLER)):
        groups[name] = _alternation(table[lang])
    everything = "|".join(g for g in groups.values() if g)
    return {name: re.compile(rf"(?:^| )(?:{alt})(?= |$)") for name, alt in groups.items()} | {
        "all": re.compile(rf"(?:(?:{everything})(?: |$))+")}


_COMPILED = {lang: _compile(lang) for lang in STRONG}


def caller_says_goodbye(text, lang, assistant_last=""):
    """True for a short utterance of nothing but farewell, thanks and closing words that fits the situation."""
    lang = _lang(lang)
    words = fold(text)
    if not words or len(words.split()) > MAX_WORDS:
        return False
    rx = _COMPILED[lang]
    if not rx["all"].fullmatch(words + " " if not words.endswith(" ") else words):
        return False
    if rx["strong"].search(words):
        return True
    thanks, closer = bool(rx["thanks"].search(words)), bool(rx["closer"].search(words))
    if not (thanks or closer):
        return False                                  # only filler words: "tamam" says nothing
    last = fold(assistant_last)
    closing = any(q in last for q in CLOSING_QUESTIONS[lang])
    asked = (assistant_last or "").rstrip().endswith("?")
    if closer:
        return closing                                # "no" answers the agent's question, so it has to be the closing one
    return closing or not asked                       # thanks: after a statement, or after "anything else?"


# words that may follow a farewell at the very end of the agent's reply
TRAILING = {"tr": ["dilerim", "diliyorum", "dileriz"], "en": ["to you", "now"], "de": ["noch", "ihnen", "dir"]}


def assistant_says_goodbye(text, lang):
    """True when the agent's reply ENDS with a farewell and is not a question: "Rica ederim, iyi günler dilerim."
    A farewell word inside a reply ("I'll take care of that", "İyi günler, kaydınıza bakıyorum") does not count."""
    text = (text or "").strip()
    if not text or text.endswith("?") or len(text.split()) > MAX_ASSISTANT_WORDS:
        return False
    lang = _lang(lang)
    tail = _alternation(TRAILING[lang])
    return bool(re.search(rf"(?:^| )(?:{_alternation(STRONG[lang])})(?: (?:{tail}))*$", fold(text)))
