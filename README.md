# Connected Clients

Connected Clients shows who is connected to your Unraid server now, and who was connected in the last three days.

Open it at **Tools → Connected Clients**.

## What it shows

- **Web UI** — each signed-in webGUI session: the client address, the connection path (LAN, Tailscale, WireGuard), the sign-in time and the last request.
- **SSH** — each SSH session: user, client address, key type, and the number of commands.
- **SMB** — each SMB session: user, client address, shares, protocol version, signing and encryption.
- **NFS** — each NFS v4 client: address, client name and lease state.
- **VPN** — active WireGuard and Tailscale peers.
- A 24-hour or 72-hour timeline, and a list of sign-in events (successful and failed).

Use the toggles on the page to show or hide local traffic (this server and its Docker containers) and stale web sessions. Your browser remembers your choice.

## How it works

A small collector reads these sources every 5 seconds: PHP session files, syslog, `ss`, `smbstatus`, `/proc/fs/nfsd`, `wg` and `tailscale`. It only reads. It does not change nginx, PHP, Samba or any other Unraid setting.

The data stays in RAM (`/tmp/unraid-connections`). Session IDs never appear; the page shows a short hash tag instead.

To control the collector:

```bash
/usr/local/emhttp/plugins/unraid-connections/scripts/rc.unraid-connections status
/usr/local/emhttp/plugins/unraid-connections/scripts/rc.unraid-connections restart
```

## Limits

- Unraid does not record the client address in a webGUI session. The plugin takes it from the syslog sign-in line while the session is new. For a session that started before the plugin, the address is inferred or unknown.
- A sign-in through a reverse proxy or a container shows the proxy address.
- NFS v3 clients appear only as live TCP connections.
