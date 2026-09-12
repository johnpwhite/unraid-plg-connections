# include/ — read-only data adapters

| File | Reads |
| :-- | :-- |
| `cc-history.php` | History: SQLite database, integrity check, flash copy and restore, retention, queries, events.log reader. |
| `cc-actions.php` | Actions: checks (snapshot, process names, own session), sign-out, SIGTERM, syslog line. |
| `cc-notify.php` | Notifications: new client, failed sign-ins, SSH from the internet; cooldown in `history.db`; Unraid `notify`. |
| `cc-summary.php` | Dashboard tile summary: sessions now for each protocol, busiest clients, failed sign-ins (pure). |
| `cc-config.php` | Settings: defaults, read, sanitize, atomic write, labels, proxy CIDR ranges. |
| `cc-common.php` | Constants, helpers, `ip -o addr`, `ss` live sockets. |
| `cc-logs.php` | syslog (current, rotated, previous boot): sign-in events, SSH sessions. |
| `cc-web.php` | PHP session files + sign-in lines + nginx sockets → webGUI sessions. |
| `cc-services.php` | SSH, `smbstatus --json`, `/proc/fs/nfsd/clients`, `wg`, `tailscale`, FTP. |
| `cc-clients.php` | Client names and kinds (Tailscale, Docker, ARP, reverse DNS). |
| `cc-snapshot.php` | `cc_snapshot()`: joins every adapter into one array. |
