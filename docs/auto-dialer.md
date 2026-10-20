# Auto dialer & AMD campaigns (Plugin)

> 🔌 **Plugin (`auto-dialer`)**: Optional plugin from the official catalog. Enable it under **Integrations → Plugins** (see [Plugin system & developer guide](plugin-development.md)).

**Integrations → Auto Dialer & AMD Campaigns** (`/plugins/auto-dialer`) runs automated outbound calling campaigns with Asterisk **Answering Machine Detection (`AMD()`)**, queue free-agent pacing, optional voicemail audio drop, and real-time call result tracking.

---

## 🗄️ Database Tables (`plg_` Sandbox)

When the `auto-dialer` plugin is enabled, `PluginManager` automatically applies [`plugins/auto-dialer/schema.sql`](../plugins/auto-dialer/schema.sql) and creates two isolated `plg_*` tables:

| Table | Purpose |
|---|---|
| 📋 `plg_autodial_campaigns` | Campaign configuration (name, status, caller ID, max concurrency, queue free-agent pacing flag, retry count/interval, AMD on/off, machine action `hangup` or `play_sound`, target destination type & ID) |
| 📞 `plg_autodial_numbers` | Phone numbers and per-call results (`status`, `attempts`, `call_result`, `amd_status` [`HUMAN`/`MACHINE`/`NOTSURE`/`BYPASSED`], `amd_cause`, `duration`, `uniqueid`, `last_attempt_at`, `next_attempt_at`) |

Disabling the plugin keeps both tables and their campaign history intact; uninstalling the plugin drops its `plg_*` tables.

---

## 🎛️ Global & Per-Campaign AMD Settings

1. ⚙️ **Global AMD Timings (`System → PBX Settings → AMD`):**
   - Configures the 8 parameters passed to Asterisk's `AMD(initial_silence,greeting,after_greeting_silence,total_analysis_time,min_word_length,between_words_silence,maximum_number_of_words,silence_threshold)` application (defaults: `2500,1500,800,5000,100,50,2,256`).
2. 🤖 **Per-Campaign AMD Toggle & Machine Behavior:**
   - Each campaign can enable or disable AMD independently.
   - 🧑 When `AMDSTATUS` is **`HUMAN`** (or `NOTSURE`), the call is transferred to the campaign's chosen destination (**Call Queue**, **IVR**, **Extension**, **Ring Group**, **Announcement**, or **AI Application**).
   - 📼 When `AMDSTATUS` is **`MACHINE`**, the campaign either:
     - 📴 **Hangs up immediately (`hangup`)**, or
     - 🔊 **Waits for the voicemail beep (`WaitForSilence`) and plays a selected custom sound (`play_sound`)** before hanging up.

---

## 👥 Queue Free-Agent Pacing

When a campaign's target destination is a **Call Queue (`queue`)** and **Queue Free-Agent Pacing** is checked:
- 🔍 Before originating new calls, the worker inspects `queue show <queue_name>` in Asterisk.
- 🧮 It counts logged-in, unpaused, idle (`Not in use`) agents and subtracts any callers already waiting in that queue.
- 🛡️ Concurrency is automatically capped to the number of free agents so customers are never called when no agent is available to talk to them.
