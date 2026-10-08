# Website call widget and call-back form

**Integrations → Web widgets** puts a "Call us" button on any website. A visitor either talks to the
company from the browser over WebRTC, without a phone or an app, or leaves a number and the PBX
calls them back. The website only needs one line of code; a WordPress plugin inserts the same line.

The page is admin-only, like *Phones* and *Firewall*: an external destination or the call-back
form can cost money.

## A widget is a virtual trunk

Each widget enters the PBX like a call from a trunk, with its own **number** acting as the DID.
Reports and the CDR show these calls with that number as the called number and `widget-<id>` as
the inbound trunk, so routing, time conditions, queues and reports work as for carrier calls.

The **destination** is fixed by the administrator: an extension, ring group, queue, IVR or time
condition. The visitor never chooses or types where the call goes.

**External number** (destination type): the call leaves through the chosen outbound route, e.g. to
the duty phone of a support team. The outside party sees the *caller ID shown to the visitor*
(or the widget number), never what the visitor typed. This invites toll fraud, so an outbound
route and a **daily limit are required**.

## Settings

| Setting | Meaning |
|---------|---------|
| Name, number | Shown in the list; the number is the DID of these calls (2-20 digits, unique) |
| Destination | Where calls go (see above) |
| Browser call (WebRTC) | The visitor talks from the browser |
| Call-back form | The visitor leaves a number; the PBX calls them through the outbound route and, once they answer, sends them to the destination |
| Allowed number prefixes | Call-back only: a number is called only when it starts with one of these (e.g. `05` for Turkish mobiles). Required |
| Caller ID shown to the visitor | Call-back and external destination; empty means the widget number |
| Outbound route | Call-back and external destination |
| Allowed websites | One host name per line (`www.example.com`); `*.example.com` also allows sub-domains. Requests from other sites are refused. The portal itself is always allowed (*Try it*) |
| Concurrent calls | Calls of this widget at the same time; more get a busy signal |
| Max. call length | Seconds; the call is hung up after it |
| Requests per IP per hour | Requests from one visitor address; more are refused (not logged) |
| Calls per day | Connected calls and call-backs since midnight; 0 = no limit (only without an outside line) |
| Look | Button text, colour, corner (bottom right / left), language (English, Turkish, German, French, Spanish), asking for the visitor's name (shown as the caller name) |

Save, then press **Apply** (pending changes): the widget's WebRTC endpoint and dialplan are written
then. **Switch off** (the power button) refuses new calls at once, before Apply.

## Putting it on a website

**Copy code** gives one line:

```html
<script src="https://pbx.example.com/widget.js" data-widget="w_8f3k2a9c1d7e" async></script>
```

Paste it before `</body>` on the website, and add the site to the widget's allowed websites.
**Try it** (▶) opens a page on the portal with the same code.

**WordPress**: the plugin is in [`integrations/wordpress/aipbx-call-widget`](../integrations/wordpress/aipbx-call-widget).
Zip the folder, upload it under *Plugins → Add New → Upload*, activate it, and paste the embed code
under *Settings → AiPBX Call Widget*. The button shows on every page, or only on pages with the
`[aipbx_call_widget]` shortcode.

## How a call works

1. `widget.js` asks the portal for the widget's look (`/widget-api/config`). The portal answers only
   an allowed website (Origin header, CORS).
2. On *Call now* the browser asks for the microphone, then for a call (`/widget-api/call`). The
   portal checks the website and the limits and hands out the widget's SIP account and a
   **one-time token** valid for 5 minutes.
3. The browser loads JsSIP from the PBX and calls `w<token>` over the WebSocket on port 443 (TURNS
   for the media, as the web phone does). The account is not registered.
4. The endpoint's context (`[widget-<id>]`) holds nothing but the token check:
   `bin/widget_claim.php` (linked to `/usr/local/bin`) spends the token once. Without a valid token
   the call is refused, so copying the account from the page lets nobody call, and even with a
   token the call can only reach the widget's own destination.
5. `[widget-<id>-route]` applies the concurrent-call limit and the maximum length and sends the
   call to the destination.

The call-back form posts the number to `/widget-api/callback`. After the same checks and the
prefix check, a call file dials `Local/<number>@widget-<id>-out` (the outbound route, with the
caller ID above); when the visitor answers, the call continues in `[widget-<id>-callback]` and
reaches the destination like a widget call.

Every request (call or call-back, served or refused) is in the **Recent widget requests** list
with the website, the visitor's address and browser: the abuse log. Rows older than 90 days are
removed.

## Notes

- The visitor's browser needs WebRTC and a secure page (HTTPS) for the microphone; on sites without
  it only the call-back form is shown.
- The widget's media uses the TURN server like the web phone; see
  [WebRTC, NAT and TURN](webrtc-nat.md) when calls connect but stay silent.
- Requests over the per-IP limit are refused without a log row, so a flood cannot fill the log.
- Not done yet: a message outside office hours (use a time condition as the destination for now),
  guest chat and video.
