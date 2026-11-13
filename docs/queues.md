# Queues and the agent screen

Queues distribute incoming calls to assigned extensions. Configure them on **Queues** (`/queues`);
agents work on **Agent Screen** (`/cc-agent`). Queue configuration is an administrator task.

## Configure a queue

1. Add a queue with a unique queue name and a title agents will recognise.
2. Choose the distribution strategy, ringing timeout, retry delay and wrap-up time.
3. Choose the music on hold and announcements, maximum waiting time and timeout destination. The
   music on hold class must exist (**Sounds → Music on hold**).
4. Assign extensions on the agents tab. Choose **dynamic** for an agent who joins and leaves,
   or **static** for membership that stays in the queue. Static agents can still pause.
5. Save and **Apply** the pending changes. Point an [inbound route](inbound-routes.md), IVR option
   or other destination at the queue. An optional internal number lets extensions call it directly.

An agent must have an extension. Administrators and queue managers with extensions can be assigned
as agents too. Their role alone does not assign them to a queue.

A call that enters a queue is answered, so the caller hears the music and the position and hold-time
announcements; charging for the caller starts then. An agent's own voicemail never takes a queue
call (the same holds for ring groups): an agent who does not answer is skipped.

Queue membership and phone registration are separate: an assigned or joined agent can have an
offline phone. Check both the queue status and the extension's registered devices when calls do
not ring. Settings for joining or leaving an empty queue can send callers to the fallback instead
of leaving them on hold.

## Agent workflow

Open **Agent Screen** and check the queues shown for your extension. Join a dynamic queue when
ready, pause with a break reason when unavailable, and resume when ready to take calls. Static
membership is managed by the administrator; the agent pauses rather than leaving that queue.

The screen shows waiting callers, active calls and recent calls, with call notes. The built-in
Agent role can open the screen; an administrator or queue manager must also be assigned to an
active queue to use it. Callback requests are always limited to assigned active queues.

## Let callers request a callback

Available since **1.75.0**, [manual callbacks](queue-callbacks.md) let callers press **1** while
waiting. Prepare two announcements: the periodic invitation to press 1 and a separate confirmation
played after the request is saved. The confirmation is followed by hangup.

Agents see requests on their screen and choose when to call. Their phone rings first, followed by
the customer. A completed answered conversation with a queue agent removes the request, including
when the customer calls again. Unanswered calls leave it pending. Follow the
[setup and verification checklist](queue-callbacks.md#verify-the-setup) before enabling it for callers.

For historical statistics, recordings and exports, see [Reports](reports.md) and
[Call recordings](recordings.md). For access and roles, see [Users and roles](users-and-roles.md).
