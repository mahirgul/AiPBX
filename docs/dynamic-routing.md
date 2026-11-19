# Dynamic Routing

**PBX → Dynamic Routing** (`/dynamic-routing`, administrators) routes a call by data. A step takes an
input, asks a source for a value, and sends the call where the first matching rule says.

Use a step as a destination anywhere: an inbound route, an IVR key, a time condition, another step.
It shows as **Dynamic Routing** in every destination list. Steps made as "IVR data steps" in earlier
versions are the same steps; they keep working unchanged.

## 1. Input

| Input | What the step uses |
|---|---|
| Keys the caller presses | Plays the prompt and reads digits: minimum and maximum digits, wait time. With **Retries**, wrong or missing input plays the "wrong input" prompt and asks again. |
| Caller number | The caller's number, without asking anything. |
| Number that was called (DID) | The DID of the inbound route. |
| A channel variable | Any channel variable, for example the result of an earlier step. |

The input is in `${DR_INPUT}` for the rest of the call.

## 2. Source

| Source | How it is asked |
|---|---|
| **External database** | A read-only query on a connection from **Integration → Database Connections** ([external-databases.md](external-databases.md)). |
| **URL (HTTP)** | `GET`, or `POST` with a JSON body. Headers (a token) are stored encrypted, never shown again and never written into the dialplan. |
| **AGI script** | A script from **PBX → AGI Scripts** ([agi-scripts.md](agi-scripts.md)), or one in `agi-bin`. It gets the input as its first argument and writes its answer to `AGI_RESULT`. |
| **A value** | No query: a value built from variables, for example `{var_CUSTOMER_TYPE}`. |
| **Nothing** | The input itself is the value: route by the digits pressed or by the caller's number. |

Database and URL sources are asked by the portal on the same server. Asterisk calls a local address;
the portal then runs the query or the request.
- **Time limit:** 2.5 seconds. A slower source takes the "No answer or error" branch.
- **URL addresses:** only public ones. Internal addresses and cloud metadata are refused, and the checked
  address is pinned for the request.

### Variables

The same names work in every source, and in the auto-dialer and the other places that use external
data:

| Name | Value |
|---|---|
| `input` | The input of the step |
| `caller_id`, `caller_name` | Caller number and name |
| `did` | The number that was called |
| `uniqueid`, `linkedid` | IDs of this call and of all its legs |
| `lang` | Language of the call |
| `date`, `time` | Today (`YYYY-MM-DD`) and now (`HH:MM`) |
| `var_NAME` | Any channel variable `NAME` |

- In a URL, a body or a value write `{name}`. In a URL the value is URL-encoded; in a JSON body it is
  escaped for JSON.
- In SQL write `:name`. It is always sent as a bound parameter, never pasted into the query, so a
  caller cannot change the query by what they press.
- The form refuses unknown names.

Examples:

```sql
SELECT segment, name, balance FROM customers WHERE phone = :caller_id LIMIT 1
```

```
https://crm.example.com/api/lookup?phone={caller_id}&account={input}
```

```json
{"phone": "{caller_id}", "account": "{input}"}
```

### Result

The value the rules look at is:
- **Database source:** the first column of the first row.
- **URL source:** the field at the **JSON path** (`data.customer.segment`). Without a path it is
  `value`, `result` or the first field. A plain-text answer is the value itself.
- **AGI source:** what the script wrote to `AGI_RESULT`.

Every other column or JSON field becomes a channel variable with the prefix (default `DR_`). A column
`name` becomes `${DR_NAME}` and a column `balance` becomes `${DR_BALANCE}`. The page shows these
variables after **Test**.

The value is also in the **Result variable** of the step (default `IVR_DATA_VAL`) and in `${DR_VALUE}`.

Values are cleaned before they reach the dialplan:
- line breaks, control characters and `| ; ^` are removed;
- a value is cut to 128 characters;
- at most 20 extra variables are set.

## 3. Routing

**Rules** are checked from the top; the first match wins:

| Rule | Matches |
|---|---|
| Is | The value exactly, for example `VIP` |
| Starts with | For example `90532` |
| Number between | A number in the range, ends included, for example `0`–`99.99` |

Then three branches:

- **Found, no rule:** a value came back but no rule matched.
- **Not found:** no row, an empty value, HTTP 404, or wrong input after the last retry.
- **No answer or error:** the source did not answer in time or failed.

## 4. More

- **Caller name:** put text in front of the caller's name, or replace it. `{value}` is the result and
  `{NAME}` a result field, for example `VIP – {NAME}`. Desk phones and the web phone show it.
- **If the value is an extension, ring it:** a source that returns an agent's extension sends the call
  straight to that agent. When the agent does not answer, the call goes to the chosen destination, or
  to "Found, no rule".

## Test

**Test** runs the source as it is in the form, without saving and without a call. Give sample values
(`input`, `caller_id`, `did`). It shows:
- the status;
- the value;
- the extra variables;
- the time it took.

For an AGI script it also shows each AGI command the script sent. Scripts in `agi-bin` run only in a
real call.

## Worked example: VIP customers by caller number

1. Under **Integration → Database Connections** add the CRM's read replica. Use a read-only account.
2. Under **PBX → Dynamic Routing** click **Add new**:
   - **Input:** Caller number.
   - **Source:** External database, CRM.
   - **Query:**
     ```sql
     SELECT segment, name FROM customers WHERE phone = :caller_id LIMIT 1
     ```
   - **Rules:**
     - Is `VIP` → Queue *VIP Support*;
     - Is `blocked` → Announcement *Account closed*.
   - **Found, no rule:** Queue *Support*.
   - **Not found:** IVR *Main menu*.
   - **No answer or error:** Queue *Support*.
   - **Caller name:** Put text in front, `{NAME} –`.
3. **Test** with a known number, then save and **Apply changes**.
4. Under **Inbound Routes** set the DID's destination to **Dynamic Routing → VIP check**.

## Notes

- A step in use (an inbound route, an IVR key, …) cannot be deleted.
- A step may not point straight at itself. Use **Retries** to ask again.
