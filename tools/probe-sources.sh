#!/bin/bash
# probe-sources.sh - read-only probe of each data source for the Connected Clients plugin.
#
# Run it as root on an Unraid server. It changes nothing.
# It never prints a full PHP session ID, because the ID is a login secret.
# It prints an 8-character SHA-256 tag instead.
#
# Usage: bash tools/probe-sources.sh
set -u

SYSLOG="${SYSLOG:-/var/log/syslog}"
SESS_DIR="${SESS_DIR:-/var/lib/php}"
NOW=$(date +%s)

[ "$(id -u)" -eq 0 ] || echo "warning: not root; some sources will be empty" >&2

# The current syslog plus the rotated copies (Unraid rotates at 1 MB).
mapfile -t LOGS < <(ls -1 "$SYSLOG" "$SYSLOG".[0-9] 2>/dev/null)

section() { printf '\n=== %s ===\n' "$1"; }
have() { command -v "$1" >/dev/null 2>&1; }
or_none() { local out; out=$(cat); if [ -n "$out" ]; then printf '%s\n' "$out"; else echo "(none)"; fi; }

# Print "peer via local" for each established TCP connection to local port $1.
# With a state filter, ss prints: Recv-Q Send-Q Local:Port Peer:Port [Process].
peers_on_port() {
  ss -Htn state established "( sport = :$1 )" 2>/dev/null |
    awk '{ l = $3; p = $4; sub(/:[0-9]+$/, "", l); sub(/:[0-9]+$/, "", p); print p, "via", l }'
}

section "Web UI: PHP session files in $SESS_DIR"
# unraid_login is the login time only for the first 5 minutes: auth-request.php
# sets it to time() when the old value is more than 300 s old. The file mtime is
# the time of the last request that used the session cookie.
for f in "$SESS_DIR"/sess_*; do
  [ -f "$f" ] || continue
  tag=$(basename "$f" | sha256sum | cut -c1-8)
  data=$(cat "$f" 2>/dev/null)
  login=$(printf '%s' "$data" | sed -n 's/.*unraid_login|i:\([0-9]*\);.*/\1/p')
  user=$(printf '%s' "$data" | sed -n 's/.*unraid_user|s:[0-9]*:"\([^"]*\)".*/\1/p')
  if [ -z "$login" ]; then
    printf 'session %s  no login data (not signed in)\n' "$tag"
    continue
  fi
  mtime=$(stat -c %Y "$f")
  # Join key: while the session is new, the webGUI login line in syslog has the
  # same second as unraid_login.
  stamp=$(date -d "@$login" '+%b %e %T')
  ip=""
  if [ "${#LOGS[@]}" -gt 0 ]; then
    ip=$(grep -hF "webgui: Successful login user $user from " "${LOGS[@]}" 2>/dev/null |
      grep -F "$stamp " | tail -1 | sed 's/.* from //')
  fi
  printf 'session %s  user=%s  unraid_login=%s  from=%s  last_request=%s  idle=%ss\n' \
    "$tag" "$user" "$(date -d "@$login" '+%F %T')" "${ip:-unknown}" \
    "$(date -d "@$mtime" '+%F %T')" "$((NOW - mtime))"
done

echo "(from=unknown: unraid_login moved forward after the login, or the login line rotated out of syslog)"

section "Web UI: recent login and logout events (syslog)"
grep -hE "webgui: (Successful|Unsuccessful) login|Successful logout" "${LOGS[@]}" 2>/dev/null | tail -10 | or_none

section "Web UI: live TCP connections to nginx (80, 443), count per client"
{ peers_on_port 443; peers_on_port 80; } | grep -vE '^(127\.0\.0\.1|\[::1\]) ' | sort | uniq -c | or_none

section "Web UI: nchan subscribers (open webGUI tabs, all clients together)"
curl -s --max-time 3 --unix-socket /var/run/nginx.socket http://localhost/nchan_stub_status 2>/dev/null |
  grep -E '^(subscribers|channels):' | or_none

section "SSH: live TCP connections to port 22, count per client"
peers_on_port 22 | sort | uniq -c | or_none

section "SSH: recent auth events (syslog)"
grep -hE "sshd(-session)?\[[0-9]+\]: (Accepted [a-z-]+ for|Failed [a-z-]+ for|Invalid user|Disconnected from user)" \
  "${LOGS[@]}" 2>/dev/null | sed 's/ ssh2:.*//' | tail -10 | or_none

section "SSH: utmp (records only sessions that have a TTY)"
who | or_none

section "SMB: sessions and share connections (smbstatus)"
if ! have smbstatus; then
  echo "(smbstatus not found)"
elif have jq; then
  smbstatus --json 2>/dev/null | jq -r '
    ((.sessions // {})[] | "session user=\(.username) client=\(.remote_machine) dialect=\(.session_dialect) encryption=\(.encryption.degree) signing=\(.signing.degree)"),
    ((.tcons // {})[] | "share   client=\(.machine) share=\(.service) since=\(.connected_at)")' | or_none
else
  smbstatus -b 2>/dev/null
  smbstatus -S 2>/dev/null
fi

section "NFS v4: clients known to the kernel NFS server"
found=0
for d in /proc/fs/nfsd/clients/*/; do
  [ -r "$d/info" ] || continue
  found=1
  addr=$(sed -n 's/^address: "\(.*\)"/\1/p' "$d/info")
  name=$(sed -n 's/^name: "\(.*\)"/\1/p' "$d/info")
  status=$(sed -n 's/^status: //p' "$d/info")
  renew=$(sed -n 's/^seconds from last renew: //p' "$d/info")
  printf 'client=%s  name=%s  status=%s  last_renew=%ss\n' "$addr" "$name" "$status" "$renew"
done
[ "$found" -eq 1 ] || echo "(none; NFS v3 clients never appear here)"

section "NFS: live TCP connections to port 2049, count per client"
peers_on_port 2049 | sort | uniq -c | or_none

section "WireGuard: peers (wg show all dump)"
if have wg; then
  wg show all dump 2>/dev/null |
    awk -F'\t' -v now="$NOW" 'NF == 9 {
      hs = ($6 > 0) ? (now - $6) "s ago" : "never"
      printf "iface=%s  peer=%.8s  endpoint=%s  last_handshake=%s\n", $1, $2, $4, hs }' | or_none
else
  echo "(wg not found)"
fi

section "Tailscale: peers with active sessions"
TS=$(command -v tailscale || echo /usr/local/sbin/tailscale)
if [ -x "$TS" ]; then
  # Line 1 is always this server itself, so skip it.
  "$TS" status --active 2>/dev/null | tail -n +2 | head -20 | or_none
else
  echo "(tailscale not installed)"
fi

section "FTP: service state"
if grep -qE '^ftp' /etc/inetd.conf 2>/dev/null; then
  echo "FTP is enabled in /etc/inetd.conf"
else
  echo "FTP is disabled (no active ftp line in /etc/inetd.conf)"
fi
peers_on_port 21 | sort | uniq -c | or_none

printf '\nProbe done. Nothing was changed.\n'
