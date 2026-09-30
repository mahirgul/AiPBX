# Inbound routes (DIDs)

An inbound route sends calls for a DID (the called number the trunk delivers) to a destination such
as an extension, ring group, queue, IVR, time condition, announcement, conference or fax.
Optional per route: call recording and the channel language.

## Matching

- The DID is compared with the number the trunk sends, **after** the trunk's
  [DID trimming](trunks.md#inbound-did-normalization). Define routes in exactly that format — if a NEC
  system sends `9391`, the route is `9391`, not `19391`.
- A call from a trunk tries inbound routes first; with transit routing enabled it then tries the trunk's
  outbound route group and local extensions; otherwise it ends with congestion.
- To see what a trunk sends, capture SIP (see [Troubleshooting](troubleshooting.md#capturing-sip)) and
  read the number in the `INVITE` line.

## Fax DIDs

A route with destination **Fax** answers with fax detection and stores the document for the department
mapped to that DID (**Fax Settings**). The department mapping uses the same number as the route; when
you renumber fax DIDs, update both, otherwise faxes are received but not delivered to the right inbox.
