# Reports

## Call reports

**Panel → Reports** lists calls from Asterisk's CDR, grouped by `linkedid` so that every
leg of one call — IVR, queue, ring group, transfer — is a single row with a step-by-step journey.

Each call is analysed from its legs:

| Column | Meaning |
|--------|---------|
| Caller | number and name, and the **inbound trunk** the call came in on |
| Dialled | the number dialled and the route it hit (DID, queue title) |
| Outbound trunk | the trunk the call left on and the number actually sent to it |
| Answered by | the AiPBX extension that answered and its device (desk phone, browser, app) |
| Direction | inbound, outbound, internal or **trunk to trunk**; transferred calls are marked |

The journey shows the path, e.g. `VOIP → 3001 → NEC: 8807` for a call that came in on the `VOIP` trunk,
was answered by 3001 and transferred out over the `NEC` trunk. Trunks are recognised from channel
names, so calls over renamed or deleted trunks still show them.

Direction and trunk are filters as well; the summary above the table counts calls per direction.
Recordings play and download from the row (see [Call recordings](recordings.md) for who may hear what).

## Queue Report Centre

**Call Center → Queue Report Centre** reports from Asterisk's queue log. Admins, read-only admins and
call-centre managers have it by default; agents do not, because it compares agents with each other.

| Tab | What it shows |
|-----|---------------|
| Overview | offered, answered and lost calls, **service level**, average wait (ASA) and talk time (AHT), longest wait; calls per hour and per day; wait-time distribution |
| Queues | the same figures per queue, side by side |
| Agent Performance | answered calls and share, total / average / longest talk, time to answer, missed rings and pickup rate, calls the agent ended, pauses, call notes |
| Lost Calls | every lost call with **call-back tracking**: resolved when the number was later called from an extension, or called again and was answered |
| Repeat Callers | numbers that called more than once, linked to the call report |
| Call Outcomes | the outcomes agents recorded, per agent |

**Service level** is the share of calls answered within the target you set on the page (20 seconds is a
common target). Callers who hang up within a few seconds are counted separately as *short abandons* and
excluded from the service level, so wrong numbers do not distort it. **Not Called Back** — lost calls
nobody has returned yet — is a headline figure and a filter.

The **queue log** page plays and downloads a call's recording with the same access rules as the call
report.

## PDF and Excel export

Call reports, the Queue Report Centre, the queue log and pause reports have **PDF** and **Excel**
buttons. Both export the report **as currently filtered, with all rows** (not just the visible page):

- **PDF** — the system's logo, name and brand colour in the header, page numbers and who generated it in
  the footer, headline figures, charts and tables. Wide tables are printed landscape with a readable
  subset of columns. At most 2,000 rows; a longer report notes that the full data is in the workbook.
- **Excel (.xlsx)** — a summary sheet (period, filters, figures) and one sheet per section with a frozen,
  filterable header. Dates, durations and percentages are real Excel values; phone numbers stay text,
  so leading zeros are kept.

Every export from the portal is written to the audit log.
