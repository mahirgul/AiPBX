#!/bin/bash
# AGI script: reads what Asterisk sends, works out a value, writes it to AGI_RESULT.
# Runs in a sandbox: no network unless "Network access" is on, writes only to its own folder.

declare -A env
while IFS= read -r line && [ -n "$line" ]; do
    env["${line%%: *}"]="${line#*: }"
done

agi() { echo "$1"; read -r answer; }

caller="${env[agi_callerid]}"
first_arg="${env[agi_arg_1]}"

agi "GET VARIABLE DR_INPUT"
digits=$(sed -n 's/.*(\(.*\))$/\1/p' <<< "$answer")

# Your logic here; for example a VIP list.
if [[ "$caller" == *1000 ]]; then result=vip; else result=normal; fi

agi "SET VARIABLE AGI_RESULT \"$result\""
agi "SET VARIABLE AGI_NAME \"Customer $caller\""
