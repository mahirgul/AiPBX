# Manual queue callbacks

An alternative to holding: the caller presses **1** while waiting in an enabled queue,
the request is saved using their current caller number, and the call ends after confirmation.
Agents decide when to call back; the PBX does not automatically dial customers or reserve a
virtual place in the queue.

## Enable a queue

1. Record an announcement such as “To request a callback on the number you are calling from,
   press 1. Otherwise, please continue to hold.” Add it under **Announcements**.
2. Edit the queue, enable **Allow callers to press 1 to request a callback**, and select that
   announcement as the queue's periodic announcement with a repeat interval greater than zero.
3. Record and choose a separate **Callback confirmation announcement**, for example “Your callback
   request has been received. Our agents will call you on this number. Thank you.” It plays only
   after the request is saved; the call ends after playback. An announcement in use cannot be deleted.
4. Save and **Apply** the pending configuration.

The option is off by default. A withheld, missing or invalid caller number cannot create a request;
the caller hears a failure prompt rather than a confirmation. No alternative number is collected.

## Agent workflow

The **Callback requests** table on the Agent Screen lists pending requests for the agent's assigned
active queues, oldest first. **Call** rings the agent's phone first and then dials the customer using
that agent's ordinary outbound route group and dialing permissions. An accepted click temporarily
reserves the request for two minutes so simultaneous clicks do not start two calls. A failed call
setup releases it immediately; unanswered attempts remain listed and can be retried after the reservation.

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
