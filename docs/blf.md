# Busy lamps (BLF) on desk phones

Multi-key SIP phones (Yealink, Grandstream, Fanvil, Snom …) and their expansion modules can show
the state of colleagues on their keys: a receptionist typically watches 10–15 people, an operator
console many more. AiPBX publishes these states; the keys are set on the phone (by hand today,
by the provisioning page later — see the [roadmap](roadmap.md)).

## What a key shows

Set the key type to **BLF** and the value to what it should watch:

| Key value | Lamp | Pressing the key |
|-----------|------|------------------|
| `1001` (an extension) | off/green: free · red: on the phone · blinking: ringing | calls 1001; while it blinks, picks up the ringing call |
| `DND1001` | red while 1001 has do-not-disturb on | on 1001's own phone: switches do-not-disturb on/off |
| `CF1001` | red while 1001 forwards all calls | on 1001's own phone: cancels the forwarding (set it with `*72` or in *My Phone*) |
| `QUEUE1001` | red while 1001 is logged in to a queue and not on a break | on 1001's own phone: logs in to / out of all its queues |

An extension's state covers all its devices (desk phone, web phone, app). The `DND`, `CF` and
`QUEUE` keys of another user can be watched by anyone, but only that user's own devices can switch
them; pressing someone else's gives two beeps.

**Pickup from a blinking key:** the phone dials the pickup code and the extension in one go
(`*21` + `1001` = `*211001`). Set the phone's *directed pickup code* to `*21` (or the code set on
*PBX → Feature codes*).

**Voicemail lamp:** phones of users with a voicemail box show new messages (message-waiting lamp
and count); the voicemail key should dial `*97`.

## Setting a key by hand

The SIP account on the phone must be the user's desk phone account (extension, password and the
portal's address). Then:

- **Yealink:** *DSS Key* (or *Ext Key* for the expansion module) → *Type* **BLF** → *Value*
  `1001` → *Line* the account → *Label* the name. Pickup: *Features → Call Pickup → Directed Call
  Pickup Code* `*21`.
- **Grandstream:** *Programmable Keys* (or *Multi-Purpose Keys*) → *Key Mode* **Busy Lamp Field
  (BLF)** → *Account* → *Value* `1001`. Pickup: *Accounts → Call Settings → BLF Call-pickup
  Prefix* `*21`.
- **Fanvil:** *Function Key* → *Type* **Memory Key** → *Subtype* **BLF** → *Value* `1001`.
  Pickup: *Line → Advanced → BLF Pickup Code* `*21`.

Menu names differ slightly between firmware versions.

## Checking it

On the server:

```bash
asterisk -rx "core show hints"          # 1001, DND1001 … with their state and number of watchers
asterisk -rx "pjsip show subscriptions inbound"
```

A key that stays dark: check that the phone is registered with its desk phone account (not the
web phone account) and that the value is exactly the extension number. *Watchers 0* on
`core show hints` means the phone has not subscribed.
