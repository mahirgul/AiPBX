# Bundled plugin transition packages

`ai-voicemail-1.1.0.zip` is a frozen migration snapshot of community plugin 1.1.0,
kept so existing installations can move off the bundled plugin without a network download.
Fresh installations do not install it. The maintained source lives in
[the plugin repository](https://github.com/mahirgul/aipbx-plugins/tree/main/plugins/community/ai-voicemail).
Do not develop features in this snapshot. Subsequent updates come from the community catalog.

`auto-dialer-1.1.0.zip` provides the same offline transition for Auto-dialer. Campaign and number tables are kept in place; subsequent updates come from the [community plugin source](https://github.com/mahirgul/aipbx-plugins/tree/main/plugins/community/auto-dialer).

`ai-assistant-1.1.0.zip` moves the legacy community assistant from the bundled catalog to the custom plugin directory. Settings, API token, drafts and history remain under the same slug. Gregory Merriweather (shido6) retains author credit in the manifest; maintained source is in the community plugin repository.
