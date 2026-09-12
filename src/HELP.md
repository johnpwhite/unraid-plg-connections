# Help: Connected Clients

Open the page at **Tools → Connected Clients**, and the settings at **Settings → User Utilities → Connected Clients**.

- **"The collector is not running."** Start it on the server:
  `/usr/local/emhttp/plugins/unraid-connections/scripts/rc.unraid-connections start`.
  Then look for `unraid-connections` lines in `/var/log/syslog`.
- **A web session shows "IP inferred" or "IP unknown".** Unraid does not store the client address of a session. The plugin takes it from the sign-in line while the session is new. For a session that started before the plugin, the address is inferred or unknown.
- **The status line says "polling", not "nchan push".** A proxy in front of the webGUI can block websockets. The page still reads the data every few seconds.
- **No notification arrives.** Check **Settings → Notifications** (the agents), then the Notifications switches of the plugin. Use **Send a test notification** on the settings page.
- **An action button is missing.** Your own session has no Sign out button (it shows "This browser"). An ended session has no button. The setting **Actions** can hide all buttons.

## Diagnostics

Run this on the server and add the output to your post in the support thread:

```bash
bash /usr/local/emhttp/plugins/unraid-connections/scripts/diagnostics.sh
```

It only reads. It prints no session ID or password, and it hides your client labels. It shows client addresses; remove any that you do not want to post.

Support thread: https://forums.unraid.net/topic/200548-plugin-support-unraid-plugin-to-view-in-near-real-time-what-and-who-is-connected-to-your-core-host-services/
