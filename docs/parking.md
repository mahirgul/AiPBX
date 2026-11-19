# Call Parking

**PBX → Call Parking** puts a call on hold in a numbered slot where anyone can pick it up: the receptionist parks
a call, says "call on 701", and the person it is for takes it from any phone.

## Set up

| Field | Meaning |
| --- | --- |
| Enable Call Parking | Off by default. On, the park number and the slots are reserved: no extension or other internal number can use them |
| Park Extension | Transfer (blind) or dial here to park a call; default 700 |
| First Slot / Last Slot | The slots, at most 50; default 701–710 |
| Timeout | How long a call waits in its slot (10–600 s) |
| Slot Allocation | *Next*: the slot after the last one used; *First*: the lowest free one |
| Slot Announcement | Who hears the slot number: the one who parked, the parked caller, both or nobody |
| Music on Hold Class | What the parked caller hears |
| Ring back the user who parked the call | On timeout the parker's phone rings for *Callback Ring Time*; when nobody answers, or with this off, the call goes to the timeout destination |

Save and **Apply**.

## Use

- **Park:** during a call, blind-transfer it to 700. The slot number is announced.
- **Pick up:** dial the slot number (for example 701) from any extension.
- **Watch the slots:** a desk phone key with BLF on a slot number lights while a call waits there.

The page lists the calls parked now with their slot and who parked them.

Parking needs Asterisk's `res_parking` module. An installation updated from a version before parking loads it on the
first **Apply** and from its next update on at every start.
