**Connected Clients** shows who is connected to your Unraid server now, and who was connected in the last 30 days.
- **Sessions**: web UI, SSH, SMB, NFS v4, WireGuard and Tailscale, with the client address and the connection path.
- **History**: a 24-hour or 72-hour timeline, the sign-in events (including failed sign-ins), and 30 days of history for each client that stays after a reboot.
- **Dashboard tile**: the sessions in use now for each protocol and the busiest clients, updated live.
- **Actions**: sign out a web UI session, end an SSH session or close an SMB session, after a confirmation.
- **Events and subscriptions**: every session start and end, sign-in, alert and action goes into an event log. Add a rule to get an Unraid notification, a syslog line or run your own script when it happens. Other tools can pull the events since a sequence number.
- **Safe**: a small collector reads these sources every 5 seconds and changes nothing. Only an action that you confirm ends a session.
