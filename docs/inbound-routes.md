# Inbound routes (DIDs)

An inbound route sends calls for a DID (the called number the trunk delivers) to a destination such
as an extension, ring group, queue, IVR, [IVR data step](external-databases.md#ivr-data-steps), time condition, announcement, conference or fax.
Optional per route: call recording and the channel language.

## Matching

- **A leading `+`** is kept. A carrier such as Twilio can deliver `+1844…`, and you can write the route that way or trim the number on the trunk.
- The DID is compared with the number the trunk sends, **after** the trunk's
  [DID trimming](trunks.md#inbound-did-normalization). Define routes in exactly that format — if a NEC
  system sends `9391`, the route is `9391`, not `19391`.
- **Pattern Matching (Wildcards)**: Inbound routes support standard Asterisk dialplan patterns starting with an underscore `_`:
  - `X`: Matches any single digit from `0` to `9` (e.g., `_0850XXXXXXX`).
  - `Z`: Matches any single digit from `1` to `9`.
  - `N`: Matches any single digit from `2` to `9`.
  - `.`: Matches one or more remaining characters (e.g., `_905XXXXXXXXX`).
  - `!`: Matches zero or more remaining characters.
- **Dynamic DID Routing (`__did__`)**: When configuring an inbound route for a block of numbers, destination **Extension → Dialed Number (`__did__`)** dynamically matches the dialed DID suffix to existing local extensions.
- A call from a trunk tries exact inbound routes first, then pattern routes; with transit routing enabled it then tries the trunk's
  outbound route group and local extensions; unmatched calls fall through to `from-trunk-notfound` (which also checks extension number length fallbacks) or end with congestion.
- To see what a trunk sends, capture SIP (see [Troubleshooting](troubleshooting.md#capturing-sip)) and
  read the number in the `INVITE` line.

## Fax DIDs

A route with destination **Fax** answers with fax detection and stores the document for the fax unit
that has that number (**Fax → Fax Units**). The unit uses the same number as the route; when
you renumber fax DIDs, update both, otherwise faxes are received but not delivered to the right inbox.

## One number for voice and fax

A route whose destination is a queue, an IVR or anything but fax can still take faxes. Turn on **Also take faxes on this number**:

1. The call is answered, and the caller hears ringing while AiPBX listens for a fax tone. The default is 10 seconds (5 before 1.70.0; some fax machines start their tone late), from 3 to 15.
2. With a tone, the call goes to fax. Without one, it goes to the route's destination.

**File faxes under number** decides which fax number the fax belongs to; empty means this route's number.

Some limits:

- **Every caller waits those seconds,** and the call counts as answered from the start, which can matter for carrier billing.
- **A fax machine needs to send its calling tone.** One dialled by hand often does not, and goes to the destination.
- **Listening happens only in those seconds,** not later in an IVR or a queue, because Asterisk can only move a call from where it has a fax extension to jump to.
