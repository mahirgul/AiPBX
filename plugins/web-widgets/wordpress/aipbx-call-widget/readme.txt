=== AiPBX Call Widget ===
Tags: click to call, webrtc, call back, voip, pbx
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0-or-later

Adds the AiPBX "Call us" button to your WordPress site.

== Description ==

Visitors click the button and talk to your company from the browser (WebRTC),
or leave their number and your AiPBX calls them back. Where the call goes,
which websites may use the widget and all limits are set in AiPBX, not here.

== Installation ==

1. In AiPBX open Integrations → Web widgets, create a widget and add your
   site (e.g. www.example.com) to its allowed websites. Press Apply.
2. Copy the widget's embed code.
3. Upload the `aipbx-call-widget` folder to `wp-content/plugins/` (or zip it
   and use Plugins → Add New → Upload) and activate it.
4. Settings → AiPBX Call Widget: paste the embed code and save.

The button shows on every page. To show it only on some pages, untick
"Show the button on every page" and put the `[aipbx_call_widget]` shortcode
on those pages.
