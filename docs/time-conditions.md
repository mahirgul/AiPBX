# Time Conditions and Holidays

**PBX → Time Plan** routes a call by the time of day: one destination in working hours, another outside them.

## Time groups

A time group is a working-hours rule: a start and end time and the weekdays it covers. A time condition checks
its groups in order and goes to the first one that matches; when none matches it goes to its "not matched"
destination. Each time condition can have its own time zone.

## Holidays

A holiday closes the day whatever weekday it is: the call goes to the "not matched" destination.

- **The group's own dates.** The **Public Holidays** field of a time group: dates (`YYYY-MM-DD`) separated by commas.
- **Holiday calendars.** **Time Plan → Holidays** keeps named lists of closed days that several groups can share.
  A time group ticks the calendars it closes on.

A calendar gets its days in one of three ways:

| Way | How |
| --- | --- |
| Typed in | One a line: a date and a name (`2026-01-01 New Year`), or a range (`2026-12-24..2026-12-26 Christmas`) |
| `.ics` file | **Import an .ics file**: its all-day events are added to the list. A yearly event is written out for this year and the next two |
| `.ics` address | A public holiday feed (`https://` or `webcal://`). The days come from it and are read again once a day |

A calendar read from an address takes effect without **Apply**, unless time-condition changes are already waiting
for Apply: then it waits with them. When the address cannot be read the calendar keeps its days and shows the error.
Only public addresses are allowed, as for webhooks.

Public holiday feeds are offered by calendar services, for example Google Calendar's
"Holidays in <country>" calendars (their public `.ics` address). A half day (for example the eve of a holiday) in
such a feed closes the whole day; type the calendar in if you need it open.
