#!/usr/bin/env python3
# AGI script: reads what Asterisk sends, works out a value, writes it to AGI_RESULT.
# Runs in a sandbox: no network unless "Network access" is on, writes only to its own folder.
import sys

env = {}
for line in sys.stdin:
    line = line.strip()
    if not line:
        break
    key, _, value = line.partition(": ")
    env[key] = value


def agi(command):
    """Sends one AGI command and returns Asterisk's answer line."""
    sys.stdout.write(command + "\n")
    sys.stdout.flush()
    return sys.stdin.readline().strip()


def get_var(name):
    answer = agi("GET VARIABLE " + name)  # 200 result=1 (value)
    return answer.split("(", 1)[1].rstrip(")") if "(" in answer else ""


caller = env.get("agi_callerid", "")
first_arg = env.get("agi_arg_1", "")
digits = get_var("DR_INPUT")

# Your logic here; for example a VIP list.
result = "vip" if caller.endswith("1000") else "normal"

agi('SET VARIABLE AGI_RESULT "%s"' % result)
agi('SET VARIABLE AGI_NAME "%s"' % ("Customer " + caller))
