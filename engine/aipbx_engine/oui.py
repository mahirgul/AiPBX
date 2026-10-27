"""Which vendor made a MAC address.

The table below is the portal's, not a copy of the public OUI registry:
`PhoneModels::OUI` in `web/src/services/phones/PhoneModels.php` is the one list
of vendor prefixes AiPBX knows, because it is also what decides which vendor
template a phone that asks without a useful User-Agent gets. Two lists would
drift, so `engine/tests/test_parity.py` reads that PHP file and fails when this
one no longer matches it.

A phone whose prefix is not in the table is not a phone as far as the engine is
concerned: the table is what keeps laptops, printers and the PBX itself out of
the discovery list.
"""

from __future__ import annotations

#: vendor key -> MAC prefixes (the first three bytes, lowercase hex).
OUI: dict[str, tuple[str, ...]] = {
    "yealink": ("001565", "805ec0", "249ad8", "44dbd2", "805e0c", "c4fc22"),
    "grandstream": ("000b82", "c074ad", "ec74d7"),
    "fanvil": ("0c383e", "7c2f80", "0c1105"),
    "snom": ("000413",),
    "poly": ("0004f2", "64167f", "482567"),
    "nec": ("0060b9",),
}

#: The vendors of the table above, key -> the name shown on the page.
VENDORS: dict[str, str] = {
    "yealink": "Yealink",
    "grandstream": "Grandstream",
    "fanvil": "Fanvil",
    "snom": "Snom",
    "cisco": "Cisco SPA",
    "poly": "Poly",
    "nec": "NEC",
}

#: prefix -> vendor, built once from OUI.
_BY_PREFIX: dict[str, str] = {
    prefix: vendor for vendor, prefixes in OUI.items() for prefix in prefixes
}


def normalize_mac(raw: str) -> str:
    """Lowercase 12-digit MAC, or '' when $raw is not a MAC.

    The same rule as `PhoneModels::normalizeMac`: separators are dropped, and
    the all-zero and broadcast addresses are not MACs.
    """
    mac = "".join(c for c in str(raw).lower() if c in "0123456789abcdef")
    if len(mac) != 12 or mac in ("000000000000", "ffffffffffff"):
        return ""
    return mac


def format_mac(mac: str) -> str:
    """001565aabbcc -> 00:15:65:AA:BB:CC (the portal's display form)."""
    return ":".join(mac[i:i + 2] for i in range(0, len(mac), 2)).upper()


def vendor_of(mac: str) -> str:
    """The vendor of a normalized MAC ('' when the prefix is not a phone's)."""
    return _BY_PREFIX.get(mac[:6], "")
