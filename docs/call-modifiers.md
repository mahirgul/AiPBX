# Call Modifiers

**PBX → Call Modifiers** are small steps a call passes through on its way to a destination. They change the call
and then send it on:

| Type | What it does |
| --- | --- |
| Set language | The prompts the caller hears from here on (IVR, voicemail, queue announcements) are in this language |
| Caller ID name prefix | Puts a word before the caller's name, so the person answering sees where the call came from, e.g. `VIP` or `EN` |

Each modifier has a next destination: any destination an inbound route can have, also another modifier (at most
five in a row; a loop ends the call).

Use one wherever a destination is chosen: an inbound route, an IVR key, a time condition and so on. Example: the
English line of a DID goes to "Set language: English → Sales queue", and agents see `EN` before the caller's
name. Save and **Apply**.
