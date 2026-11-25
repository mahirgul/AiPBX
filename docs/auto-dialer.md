# Auto dialer & AMD campaigns (Plugin)

> 🔌 **Plugin (`auto-dialer`)**: Community plugin (AiPBX 1.80+) from the community catalog. Install and enable it under **Integration → Plugin Catalog** (see [Plugin system & developer guide](plugin-development.md)).

**Call Center → Auto Dialer & AMD Campaigns** (`/plugins/auto-dialer`) runs automated outbound calling campaigns with Asterisk **Answering Machine Detection (`AMD()`)**, queue free-agent pacing, optional voicemail audio drop, and real-time call result tracking.

---

## 🗄️ Database Tables (`plg_` Sandbox)

When the `auto-dialer` plugin is enabled, `PluginManager` automatically applies [`plugins/auto-dialer/schema.sql`](https://github.com/mahirgul/aipbx-plugins/blob/main/plugins/community/auto-dialer/schema.sql) and creates four isolated `plg_*` tables:

| Table | Purpose |
|---|---|
| 📋 `plg_autodial_campaigns` | Campaign configuration (name, status, caller ID, max concurrency, queue free-agent pacing flag, retry count/interval, AMD on/off, machine action `hangup` or `play_sound`, target destination type & ID) |
| 📞 `plg_autodial_numbers` | Phone numbers and per-call results (`status`, `attempts`, `call_result`, `amd_status` [`HUMAN`/`MACHINE`/`NOTSURE`/`BYPASSED`], `amd_cause`, `duration`, `uniqueid`, `last_attempt_at`, `next_attempt_at`) |
| ⚙️ `plg_autodial_campaign_ext` | Number source (list or database query, refresh), managers (users, roles), webhook and write-back of a campaign |
| 🔑 `plg_autodial_number_ext` | Per number: duplicate key (`external_id` or number), agent, whether its result was delivered and any write-back error |

Disabling the plugin keeps the tables and their campaign history intact; uninstalling the plugin drops its `plg_*` tables.

---

## 📇 Numbers from a database, managers and results (1.3.0, AiPBX 1.80+)

The plugin is under **Call Center** in the menu.

### Who manages a campaign

- **Who chooses:** whoever creates a campaign chooses who manages it: users, roles, or both. Change
  it later in the campaign's **Source, managers and results**.
- **Where managers work:** under **Apps → Campaigns** (`/apps/auto-dialer`). They see only their
  campaigns and can:
  - start and pause them;
  - add numbers and retry failed ones;
  - read the source again;
  - download the results.
- **What only administrators do:** see every campaign, create and delete campaigns.

### Numbers from a list or a database

- **List / CSV** works as before.
- **External database:** choose a connection from **Integration → Database Connections**
  ([external-databases.md](external-databases.md)) and a query. Columns, any case:
  - `phone`, or the first column;
  - `name`;
  - `external_id`;
  - `notes`.
  `:campaign` in the query is the campaign's id.

  ```sql
  SELECT phone, name, id AS external_id FROM leads WHERE called_at IS NULL AND region = 'west'
  ```
- **When it is read:** when the campaign starts, and with **Read again while running** every N
  minutes. **Read the source now** reads it at once.
- **Duplicates:** a row whose `external_id` is already in the campaign is skipped (its number, when
  there is no id), so reading again adds only new rows.
- **Errors:** the last read and its error show above the numbers.

The plugin never sees the connection's host, user or password. It asks for the `external_db.read`
and `external_db.write` permissions, which the plugin page shows.

### Results

Every finished number (completed or failed after its last try) is delivered once:

1. **In the campaign:** status, attempts, result, AMD, the agent who took the call, duration and time.
   The CSV download has all of them, with `external_id`.
2. **Webhook** `autodial.result` (on by default): to **Integration → Connections**, n8n or a CRM. Its
   data has `result`, `attempts`, `duration`, `amd`, `agent`, `called_at`, `campaign`,
   `campaign_name`, `phone`, `name`, `external_id` and `number_id`.
3. **Write-back (optional):** on a connection with **Allow writing**, one `INSERT` or `UPDATE` you
   write:

   ```sql
   UPDATE leads SET result = :result, attempts = :attempts, called_at = :called_at, agent = :agent
   WHERE id = :external_id
   ```

   - **Variables:** `:result :attempts :duration :amd :agent :called_at :campaign :phone :name
     :external_id`, plus `:date` and `:time`.
   - **If it fails:** the number shows **Write-back failed** with the reason. It is not retried on
     every tick; **Retry failed numbers** calls the number again and reports its new result.

Numbers added before 1.3.0 are not reported, so an upgrade sends no old results.

---

## 🎛️ Global & Per-Campaign AMD Settings

1. ⚙️ **Global AMD Timings (`System → PBX Settings → AMD`):**
   - Configures the 8 parameters passed to Asterisk's `AMD(initial_silence,greeting,after_greeting_silence,total_analysis_time,min_word_length,between_words_silence,maximum_number_of_words,silence_threshold)` application (defaults: `2500,1500,800,5000,100,50,2,256`).
2. 🤖 **Per-Campaign AMD Toggle & Machine Behavior:**
   - Each campaign can enable or disable AMD independently.
   - 🧑 When `AMDSTATUS` is **`HUMAN`** (or `NOTSURE`), the call is transferred to the campaign's chosen destination (**Call Queue**, **IVR**, **Extension**, **Ring Group**, **Announcement** or **AI Application**).
   - 📼 When `AMDSTATUS` is **`MACHINE`**, the campaign either:
     - 📴 **Hangs up immediately (`hangup`)**, or
     - 🔊 **Waits for the voicemail beep (`WaitForSilence`) and plays a selected custom sound (`play_sound`)** before hanging up.

---

## 👥 Queue Free-Agent Pacing

When a campaign's target destination is a **Call Queue (`queue`)** and **Queue Free-Agent Pacing** is checked:
- 🔍 Before originating new calls, the worker inspects `queue show <queue_name>` in Asterisk.
- 🧮 It counts logged-in, unpaused, idle (`Not in use`) agents and subtracts any callers already waiting in that queue.
- 🛡️ Concurrency is automatically capped to the number of free agents so customers are never called when no agent is available to talk to them.


## AI campaigns

*(plugin 1.2.0)* With an **AI Application**
as the destination, the AI talks with the person called: an appointment confirmation, a payment
reminder, a short survey.

- The AI sees the **person called** as the caller: an AI application's lookup address gets their
  number (`caller=`) and can answer with their name, appointment or balance.
- What a voice-requests application understood is stored as the number's result: `AI:<request id>`
  (for example `AI:confirm`), `AI:none` when it understood nothing, `AI:ended` when a
  [conversation](ai-apps.md#conversation) said goodbye. It shows in the number list and the export.

Apply the PBX configuration after updating the plugin, so the dialplan carries the number to the
answered leg.

## Active calls and callback correlation

Answered calls retain their concurrency slot until hangup; they use the `answered` status while the
conversation is active. The generated outbound leg installs a hangup handler so routing into another
context does not lose the completion callback. Callbacks carry the attempt number from the generated
Local channel and are ignored when it differs from the current attempt. Failure updates also check
the current status and attempt before rescheduling a number.

After updating, apply the PBX configuration before starting campaigns so the dialplan supplies the new
callback parameter. Older callbacks without an attempt number are ignored. Recovery checks live Local
channels before releasing a stale slot and retains slots when the Asterisk CLI cannot be queried.


## Moving from the bundled version

Existing bundled installations move to `/var/lib/aipbx/plugins/auto-dialer` during the next core
upgrade, using an offline package. The migration preserves campaign and number tables, settings,
API tokens and enabled/worker flags. It does not reset campaigns or place calls itself. The installer
stops the event/campaign worker before migration and restarts it after configuration generation.
New installations install the plugin from the community catalog. Disabling preserves campaign data;
uninstalling drops the plugin tables. Later updates come from the plugin catalog.

Callback authentication requires exact loopback origin and per-installation HTTP Basic credentials. Apply configuration before starting campaigns after upgrading. The installer performs file migration as `www-data`, avoiding privileged writes into plugin-controlled directories.

## Before a campaign: caller reputation and the law

Answer rates depend far more on the calling number than on the dialer. Carriers label or block
numbers that place many short or unanswered calls; use numbers you own, attest them through your
trunk provider where it applies (STIR/SHAKEN in North America), pace campaigns to the agents that
are free and give every call a way to opt out. Automated calls are regulated almost everywhere
(TCPA and do-not-call lists in the United States, İYS and BTK rules in Turkey, consent rules under
GDPR): dialling only numbers you may call, at the hours you may call them, is the operator's
responsibility.
