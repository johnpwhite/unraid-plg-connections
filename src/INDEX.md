# src/ — installed to /usr/local/emhttp/plugins/unraid-connections/

| Path | What |
| :-- | :-- |
| `ConnectedClients.page` | Tools → Connected Clients page shell. |
| `ConnectedClientsSettings.page` | Settings → User Utilities → Connected Clients (settings form). |
| `state.php` | GET endpoint: the collector's latest snapshot as JSON. |
| `history.php` | GET endpoint: one client's history (ended sessions, sign-in events). |
| `summary.php` | GET endpoint: the dashboard tile summary (when nchan is silent). |
| `action.php` | Actions endpoint: GET the own session tag; POST sign out, end SSH, close SMB (checked; webGUI CSRF check). |
| `HELP.md` | Help for users: common questions and the diagnostics command. |
| `ConnectedClientsDash.page` | Dashboard tile (`Menu="Dashboard:0"`, `Nchan="connections_dash"`). |
| `nchan/` | nchan scripts the webGUI starts: `connections_publisher` (page), `connections_dash` (tile). |
| `event/` | `stopping`: copy the history database to flash when the array stops. |
| `include/` | Read-only data adapters (see `include/INDEX.md`). |
| `scripts/` | Collector daemon and its rc script (see `scripts/INDEX.md`). |
| `assets/` | Page CSS and JS (see `assets/INDEX.md`). |
