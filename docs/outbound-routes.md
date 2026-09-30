# Outbound routes

An outbound route matches a dialled number with a pattern and sends it to one or more trunks.

## Patterns

Asterisk pattern syntax (a leading `_` marks a pattern):

| Symbol | Matches |
|--------|---------|
| `X` | any digit 0–9 |
| `Z` | 1–9 |
| `N` | 2–9 |
| `[2-5]` | one digit from the set/range |
| `.` | one or more remaining characters |
| `!` | zero or more remaining characters |

Examples: `112`, `_[4-9]XXX` (4-digit internal numbers), `_[2-9]XXXXXX` (7-digit local numbers),
`_0[2-5]XXXXXXXXX` (national with one leading 0), `_00[1-9]x.` (international).

**Which route wins:** when several patterns match, Asterisk uses the most specific one, not the first
in the list. `_77XX` beats `_[4-9]XXX` for `7712`, because `7` is narrower than `[4-9]`. Two routes in
the same group cannot have the same pattern — the portal refuses the second one.

## Number manipulation

Applied in this order before dialling: **strip from front**, **strip from back**, then **prepend**
and **append**. Example: pattern `_00[2-5].`, strip front `1` → `00370…` is sent as `0370…`.

## Trunks and failover

A route lists one or more trunks. They are tried in order; if one is unavailable, congested or at its
channel limit, the next is tried. Each entry can override the caller ID for that trunk. The trunk's own
[outbound caller ID normalization](trunks.md#outbound-caller-id-normalization) is applied as well.

**Internal** routes (to another PBX) keep the internal caller ID; external routes send the user's
external caller ID or the trunk's.

## Route groups

Every route belongs to a group (default `1`). Users and trunks with transit routing use the routes of
one group only. Groups solve conflicting number formats, e.g. two PBXes connected to AiPBX:

| PBX | National call arrives as | International call arrives as |
|-----|--------------------------|-------------------------------|
| NEC A | `00370…` | `000…` |
| NEC B | `0370…` | `00…` |

In one group, `00…` would mean national for A and international for B. Put B's trunk in group 2
(**Trunks → Routing → Route group**) and give group 2 its own routes.

## Copy and order

**Copy** opens a new route with the same settings; change the pattern before saving. Drag rows to
order the list (see [Portal basics](portal.md)).
