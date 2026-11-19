"""Languages of the AI jobs.

The administrator keeps the list in AI -> Cloud services (any language, not
only Turkish, German and English); the portal sends the code with its locale
and name. These helpers check what was sent and fill in the three built-in
languages when an older portal sends only the code.
"""
import re

KNOWN = {"tr": ("tr-TR", "Turkish"), "de": ("de-DE", "German"), "en": ("en-US", "English")}
_CODE = re.compile(r"^[a-z]{2,3}$")
_LOCALE = re.compile(r"^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$")
_NAME = re.compile(r"^[^\x00-\x1f\x7f<>\"]{1,40}$")


def code(value, field="lang"):
    """The language code (2-3 lower-case letters), or ValueError."""
    if not isinstance(value, str) or not _CODE.match(value.strip().lower()):
        raise ValueError(f"{field} must be a language code (2-3 letters)")
    return value.strip().lower()


def locale(lang, given=None):
    """Locale such as fr-FR: the one sent, else the built-in one, else the code."""
    if isinstance(given, str) and _LOCALE.match(given.strip()) and given.strip().lower().startswith(lang):
        return given.strip()
    return KNOWN.get(lang, (lang, ""))[0]


def name(lang, given=None):
    """Name of the language for a language model: the one sent, else the built-in one, else the code."""
    if isinstance(given, str) and _NAME.match(given.strip()):
        return given.strip()
    return KNOWN.get(lang, ("", lang))[1] or lang
