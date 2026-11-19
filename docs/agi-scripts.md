# AGI scripts

**PBX → AGI Scripts** (`/agi-scripts`, administrators only) is where you write AGI scripts in Python,
PHP or Bash. [Dynamic Routing](dynamic-routing.md) can use them as a source.

A script can do what an AGI script does:
- read the call's variables;
- play prompts and read keys;
- set channel variables;
- with **Network access**, ask an outside service.

## How a script runs

Asterisk never starts a portal script itself. The dialplan calls the **aipbx-agi** service (FastAGI,
`127.0.0.1:4573` only), and for each call the service:

1. reads the AGI environment Asterisk sends;
2. starts the script with `systemd-run` as the unprivileged user `aipbx-task`, in the sandbox of
   [scheduled tasks](scheduled-tasks.md):
   - the system is read-only;
   - home folders, the portal, Asterisk files, recordings and keys are hidden;
   - memory, CPU and time are limited;
   - there is no network unless the script has **Network access**;
3. passes the AGI lines between Asterisk and the script until either side ends.

The only place a script can write is its own folder, `/var/lib/aipbx-tasks/agi-<id>`, which is kept between
calls.

Starting the sandbox adds about 0.1–0.3 seconds to each call. A script's errors (its stderr) go to the
service log: `journalctl -u aipbx-agi`.

Scripts copied into `/var/lib/asterisk/agi-bin` by hand are listed on the page and in Dynamic Routing
too. Asterisk runs those directly, without the sandbox, as before.

## Writing a script

**Load example** gives a working script for the chosen language. It:
1. reads the environment (`agi_callerid`, `agi_arg_1`, …);
2. reads a channel variable with `GET VARIABLE`;
3. writes its answer with `SET VARIABLE AGI_RESULT "…"`.

```python
import sys

env = {}
for line in sys.stdin:
    line = line.strip()
    if not line:
        break
    key, _, value = line.partition(": ")
    env[key] = value

def agi(command):
    sys.stdout.write(command + "\n")
    sys.stdout.flush()
    return sys.stdin.readline().strip()

caller = env.get("agi_callerid", "")
agi('SET VARIABLE AGI_RESULT "%s"' % ("vip" if caller.endswith("1000") else "normal"))
```

In Dynamic Routing:
- the script gets the step's input as its first argument (`agi_arg_1`) and in `${DR_INPUT}`;
- `AGI_RESULT` is the value the rules look at;
- any other variable it sets stays on the call.

Settings of a script:

| Setting | Range |
|---|---|
| Name | Lower-case letters, digits, `-` and `_`; it is the address `agi://127.0.0.1:4573/<name>` |
| Language | Python 3, PHP, Bash |
| Time limit | 1–60 seconds (default 10) |
| Memory | 32–512 MB (default 128) |
| Network access | Off by default |

## Versions

Each save that changes the code keeps the earlier code as a version (the last 20).
- **Show** puts an old version in the editor.
- **Use this version** makes it the current one again, as a new save.

Saving, going back and deleting are written to the audit log.

## Test

**Test** runs the saved script as a call would. The portal plays Asterisk:
- `GET VARIABLE` returns the sample values you give (`caller_id`, `agi_arg_1`, `DR_INPUT`, …);
- `SET VARIABLE` is shown;
- any other command is answered `200 result=0`.

The test shows each command the script sent, the variables it set and the time it took.

## Notes

- A script used by Dynamic Routing cannot be deleted. Change those steps first.
- When the service is not running, scripts cannot run. The test then says so; check with
  `systemctl status aipbx-agi`.
