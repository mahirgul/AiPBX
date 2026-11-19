# External databases

AiPBX can query your own SQL databases during a call: a CRM, a customer list, an order system.
Connections are made once under **Integration → Database Connections** (administrators only) and
used by:

| Where | What it does |
|---|---|
| [Dynamic Routing](dynamic-routing.md) | Routes a call by a query's result (VIP, debtor, language, the customer's own agent) |
| [Auto-dialer](auto-dialer.md) (plugin) | Takes a campaign's numbers from a query and can write each call's result back |
| Plugins | Read, and write where allowed, through `PluginData` ([plugin-development.md](plugin-development.md)) |

## Database connections

Store encrypted credentials once; features name the connection and never see the password.

### Supported engines and security

- **Engines:** MySQL / MariaDB (default port 3306) and PostgreSQL (default port 5432). SQLite is
  intentionally not supported because SQLite points to local file paths on the server, which could expose
  internal system or PBX files.
- **Encrypted credentials:** Passwords are encrypted on disk and decrypted only in memory when
  establishing a connection.
- **Read-only sessions:** Whatever rights the account has, queries run in read-only transactions
  (writing is a separate, opt-in path; see below):
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

A database connection that is currently in use by a Dynamic Routing step cannot be deleted.

### Allow writing

Off by default. When it is on, features may write to this connection: the auto-dialer's result
write-back, for example. Writing is limited:
- **One statement only:** a single `INSERT` or `UPDATE` that you write yourself. Several statements,
  DDL (`DROP`, `ALTER`, `CREATE`, …) and file access are refused.
- **Variables are parameters:** they are bound, never pasted into the statement.
- **Unknown names are refused.**

What can really change is decided by the database account's own rights. Give it only what the
statement needs, for example `UPDATE` on one table. A connection that may write shows a **Writable**
badge.

---

## Saved queries

Open **Database Connections → Saved Queries** to register read-only SQL once.
Choose a permanent query ID, a connection, a description and the caller access mode.
Disable a query to remove it from tool catalogs and call routing.

- **AI Applications** accepts one saved query ID per line in **Database lookups**.
- **AI Agent** accepts `get_db_value | fatura_odeme_durumu` in its
  `agent-tools.txt` file to expose one saved query. Use `db_connection | 2`
  to expose the active saved queries registered under connection ID 2.
  Each agent can use up to ten database queries.
- **Dynamic Routing** can choose a saved query under **Source**. It lists
  active queries with **Any caller** access.

The model gets query IDs, descriptions and parameter names. The server binds
values to the SQL. Use `:input` for a DTMF or voice input and `:var_name` for a
named input. Context variables such as `:caller_id` come from the call. A
private query needs the `:var_verified_customer_id` ownership predicate and
the **Verified customer** access mode. Applying changes refreshes the tool
catalog and routing parameters.

### Variables

Every feature uses the same variable names (`input`, `caller_id`, `did`, `var_NAME`, …):
- `:name` in SQL;
- `{name}` in URLs and bodies.

The list and examples are in [Dynamic Routing → Variables](dynamic-routing.md#variables).
