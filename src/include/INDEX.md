# include/ — read-only data adapters

| File | Reads |
| :-- | :-- |
| `cc-common.php` | Constants, helpers, `ip -o addr`, `ss` live sockets. |
| `cc-logs.php` | syslog (current, rotated, previous boot): sign-in events, SSH sessions. |
| `cc-web.php` | PHP session files + sign-in lines + nginx sockets → webGUI sessions. |
| `cc-services.php` | SSH, `smbstatus --json`, `/proc/fs/nfsd/clients`, `wg`, `tailscale`, FTP. |
| `cc-clients.php` | Client names and kinds (Tailscale, Docker, ARP, reverse DNS). |
| `cc-snapshot.php` | `cc_snapshot()`: joins every adapter into one array. |
