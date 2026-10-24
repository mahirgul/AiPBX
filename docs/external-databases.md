# External databases & IVR data steps

AiPBX can connect to external SQL databases and HTTP endpoints to look up caller information in real
time during an incoming call and branch the call flow accordingly.

- Database connections: **Integrations → Database connections** (administrators only).
- IVR data steps: **IVR page → Data steps** button (administrators only).

## Database connections

Store encrypted credentials to query external SQL databases from IVR call flows or custom plugins.

### Supported engines and security

- **Engines:** MySQL / MariaDB (default port 3306) and PostgreSQL (default port 5432). SQLite is
  intentionally not supported because SQLite points to local file paths on the server, which could expose
  internal system or PBX files.
- **Encrypted credentials:** Passwords are encrypted on disk and decrypted only in memory when
  establishing a connection.
- **Strict read-only sessions:** Regardless of user account privileges granted on the remote database,
  AiPBX enforces read-only transactions on every connection:
  - MySQL / MariaDB: `SET SESSION TRANSACTION READ ONLY`
  - PostgreSQL: `SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY`
- **Time-boxed execution:** A hard statement timeout of **2.5 seconds** is enforced at the session level
  (`SET statement_timeout = 2500` on PostgreSQL; `SET SESSION max_statement_time = 2.5` on MariaDB or
  `max_execution_time = 2500` on MySQL).
- **Single query enforcement:** Multi-statement queries separated by semicolons are rejected. Only
  read-only `SELECT` or `WITH ... SELECT` queries are allowed.
- **No file access:** SQL statements containing `INTO OUTFILE`, `INTO DUMPFILE`, or `LOAD_FILE` are
  refused.

### Connection settings

| Setting | Description | Range / Options |
|---|---|---|
| **Connection Name** | System label | 1–100 characters |
| **Driver** | Database system | MySQL / MariaDB, PostgreSQL |
| **Host / IP Address** | Server hostname or IP address | Letters, digits, dots, dashes |
| **Port** | Listening port | 1–65535 (defaults: MySQL 3306, PostgreSQL 5432) |
| **Database Name** | Name of the database | Letters, digits, `_`, `$`, `.`, `-` |
| **Username** | Database user | 1–128 characters |
| **Password** | Account password | Encrypted on save; leave empty to keep existing |
| **Timeout** | Connection timeout | 1–10 seconds (default 3 s) |
| **Charset** | Character encoding | `utf8mb4`, `utf8`, `latin1`, `latin5` |
| **SSL Mode** | TLS connection security | Prefer, Require, Disable |

### Test connection

Click **Test Connection** on the form or in the connection list to verify network connectivity,
credentials, and latency. The test runs a lightweight `SELECT 1` ping query and displays the round-trip
latency in milliseconds.

A database connection that is currently in use by an IVR data step cannot be deleted.

---

## IVR data steps

An IVR data step executes a fast data lookup during an active call, stores the retrieved value in an
Asterisk channel variable, and routes the call to different destinations depending on the result.

### Input sources

- **Keypad digits (DTMF):** Prompts the caller with an optional audio announcement and collects digits
  (with configurable maximum digits and timeout).
- **Caller ID:** Uses the incoming caller's phone number without asking for user input.

### Data sources

- **External database:** Selects a configured database connection and executes a parameterized query.
  The query can use `:input` (the collected DTMF digits) and/or `:caller_id` (the caller's number)
  named parameters.
- **HTTP request (REST API):** Sends an HTTP `GET` or `POST` request to an external endpoint:
  - Supports `{input}` and `{caller_id}` placeholders in the URL, automatically URL-encoded.
  - On `POST`, sends a JSON payload: `{"input": "...", "caller_id": "..."}`.
  - Accepts a plain text response or JSON (extracts `value` or `result`).
  - **Internal address guard:** Requests to private or loopback addresses (`127.0.0.1`, RFC 1918 private
    ranges, link-local, cloud metadata endpoints) are blocked to prevent Server-Side Request Forgery
    (SSRF).
  - Hard network timeout of 2.5 seconds.

### Channel variable and cleaning

The returned result is cleaned to prevent dialplan injection: line breaks, control characters, and pipe
symbols (`|`) are removed, and the value is truncated to at most 64 characters. It is stored in the
chosen channel variable (default `IVR_DATA_VAL`), accessible downstream in dialplans, queue scripts, or
CRM popups.

### Routing branches

Each data step routes the call to one of three destination branches:

1. **Found (Match):** A row was found in the database, or the HTTP request returned HTTP 2xx with a
   non-empty value.
2. **Not Found:** The database returned 0 rows, or the HTTP request returned HTTP 404.
3. **Timeout / Error:** The remote server did not respond within 2.5 seconds, could not be reached, or
   returned an error.

Each branch can route to any destination (extension, ring group, queue, another IVR, announcement, or
hang up).

A data step currently selected as a destination in any IVR key, inbound route, time condition, or ring
group cannot be deleted.

---

## Worked example: VIP customer routing

Look up incoming callers in a CRM database and route VIP customers directly to a priority queue:

1. **Create the database connection:**
   - Under **Integrations → Database connections**, click **New Connection**.
   - Set Name to `CRM Read Replica`, choose `MySQL / MariaDB`, enter the replica server host, database
     name `crm`, and read-only credentials.
   - Click **Test Connection** to confirm connectivity, then save.

2. **Create the IVR data step:**
   - Go to **PBX → IVRs**, click **Data Steps**, then click **New Data Step**.
   - **Step Name:** `VIP Customer Check`
   - **Input Source:** `The caller's number`
   - **Data Source:** `External database`
   - **Database Connection:** Select `CRM Read Replica`.
   - **SQL Query:**
     ```sql
     SELECT tier FROM customers WHERE phone = :caller_id LIMIT 1
     ```
   - **Channel Variable Name:** `CUSTOMER_TIER`
   - **Found (Match):** Queue → `VIP Support`
   - **Not Found:** Queue → `General Support`
   - **Timeout / Error:** Queue → `General Support`
   - Save the step and click **Apply Changes**.

3. **Route calls to the step:**
   - On **Inbound Routes**, select your main incoming DID.
   - Set Destination to **IVR Data Step → VIP Customer Check**.

When a customer calls, AiPBX checks their number in the CRM. If their record exists, the call is routed
instantly to VIP Support, while new numbers fall back to General Support without caller delay.
