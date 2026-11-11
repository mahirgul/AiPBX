# Manual queue callbacks

Available in **AiPBX 1.75.0 and newer**. See [Queues and the agent screen](queues.md) for queue setup
and agent access. On an older installation, follow [Installation & updating](../INSTALL.md).

An alternative to holding: the caller presses **1** while waiting in an enabled queue,
the request is saved using their current caller number, and the call ends after confirmation.
Agents decide when to call back; the PBX does not automatically dial customers or reserve a
virtual place in the queue.

## Enable a queue

1. Record an announcement such as “To request a callback on the number you are calling from,
   press 1. Otherwise, please continue to hold.” Add it under [Sounds](sounds.md) (`/sounds`).
2. Open **Queues** (`/queues`), edit the queue, enable **Allow callers to press 1 to request a callback**, and select that
   announcement as the queue's periodic announcement with a repeat interval greater than zero.
3. Record and choose a separate **Callback confirmation announcement**, for example “Your callback
   request has been received. Our agents will call you on this number. Thank you.” It plays only
   after the request is saved; the call ends after playback. An announcement in use cannot be deleted.
4. Save and **Apply** the pending configuration.

The option is off by default. A withheld, missing or invalid caller number cannot create a request;
the caller hears a failure prompt rather than a confirmation. No alternative number is collected.
The periodic invitation and the confirmation have different purposes: the invitation repeats while
waiting; the confirmation plays only after a successful request. Disabling callbacks stops new
requests; existing requests stay visible while the queue remains active.

## Agent workflow

The **Callback requests** table on the Agent Screen lists pending requests for the agent's assigned
active queues, oldest first (up to 500 requests). **Call** rings the agent's phone first and then dials the customer using
that agent's ordinary outbound route group and dialing permissions. An accepted click temporarily
reserves the request for two minutes so simultaneous clicks do not start two calls. A failed call
setup releases it immediately; unanswered attempts remain listed and can be retried after the reservation.
The **Calling…** label reflects this temporary reservation; it is not proof that the customer answered.

A request is removed when an answered conversation with any assigned queue agent finishes.
This includes a customer who calls again and reaches an agent, a direct call to an agent,
or an outbound call made outside the callback list. Reaching only an IVR, waiting in a queue,
ringing an agent, or reaching an extension's voicemail does not remove the request.
As with any ordinary outbound call, the PBX cannot distinguish a customer's answering machine
from a person who answers.

The same caller has one pending request per queue. A successful conversation clears their requests
across queues. A new request made after that conversation was answered is retained. Requests survive
restarts. Deleting a queue deletes its pending requests.

Number matching treats `+` and `00` as equivalent international prefixes; it does not guess country
codes or compare only the last digits. Configure consistent caller-number normalization on trunks
if a carrier presents the same person with different national/international number formats.

The list refreshes every five seconds. No provider message or paid external call is made when a caller
requests a callback; the external call starts only when an agent presses **Call**.

## Verify the setup

1. Call the queue using a test number with visible caller ID and listen for the periodic invitation.
2. Press **1**. Check that the separate confirmation plays completely and the call ends.
3. Open an assigned agent's screen. The caller should appear once; a second request for the same
   number in that queue should keep the original entry and its place in the list.
4. Click **Call**, answer the agent's phone, and leave the customer unanswered. The request should
   remain; wait for the reservation to expire before retrying.
5. Call again as the customer, speak with a queue agent and hang up. The request should disappear
   after the next list refresh. Repeat with an agent-initiated callback if needed.

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Pressing 1 does nothing | Confirm the call is waiting in the enabled queue, not still in an IVR; save and Apply the setting. Check the phone/trunk's DTMF configuration. |
| Call leaves the queue before a request can be made | Check the queue's maximum wait, timeout destination, and empty-queue join/leave settings. |
| Error prompt instead of confirmation | Check that the caller number is present and valid. Review `/var/log/asterisk/messages.log` and PHP errors for a `queue_callback` failure. |
| Confirmation is silent or cut short | Check the selected recording and [sound paths and format](sounds.md#where-sounds-live). A caller who hangs up early interrupts playback. |
| Agent sees an empty list | Check the agent's extension is assigned to the active queue. An administrator or manager is not automatically assigned to every queue. |
| Clicking Call does not ring the agent | Check that agent's phone registration and ordinary click-to-call operation; the customer is called only after the agent answers. |
| Request remains after a conversation | Wait for hangup and the next five-second refresh; check the participating extension is assigned to an active queue and compare the number formats described above. |
| A recording cannot be deleted | Select a replacement on every queue using it, save and Apply, then delete the unused announcement. |
