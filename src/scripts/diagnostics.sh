#!/bin/bash
# diagnostics.sh — read-only diagnostic bundle for Connected Clients (src/HELP.md).
# Run it as root on the Unraid server and add the complete output to a support post.
# It prints no session ID, password or token, and it hides the client labels.
set -u
PLG="unraid-connections"
DEST="/usr/local/emhttp/plugins/$PLG"
RUN="/tmp/$PLG"
CFG="/boot/config/plugins/$PLG"
problems=0
sec() { printf '\n== %s ==\n' "$1"; }
bad() { printf '  PROBLEM: %s\n' "$1"; problems=$((problems + 1)); }

printf 'Connected Clients diagnostics, %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')"

sec "System"
grep -h '^version=' /etc/unraid-version 2>/dev/null || bad "/etc/unraid-version is missing"
uname -r
php -v 2>/dev/null | head -1

sec "Plugin"
grep -oE '<!ENTITY version[[:space:]]+"[^"]+"' "/var/log/plugins/$PLG.plg" 2>/dev/null || bad "the plugin is not registered in /var/log/plugins"
if [ -d "$DEST" ]; then echo "  installed at $DEST ($(find "$DEST" -type f | wc -l) files)"; else bad "$DEST is missing"; fi

sec "Collector"
if [ -x "$DEST/scripts/rc.$PLG" ]; then
    "$DEST/scripts/rc.$PLG" status || bad "the collector is not running"
fi
tail -5 "$RUN/collector.log" 2>/dev/null | sed 's/^/  /'

sec "Snapshot"
if [ -s "$RUN/state.json" ]; then
    age=$(( $(date +%s) - $(stat -c %Y "$RUN/state.json") ))
    echo "  state.json: $(stat -c %s "$RUN/state.json") bytes, $age s old"
    [ "$age" -le 20 ] || bad "state.json is $age s old (the collector writes it every few seconds)"
    php -r '
        $d = json_decode((string) file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        foreach ($d["sources"] ?? [] as $k => $s) {
            printf("  source %-4s %s%s\n", $k, $s["state"] ?? "?", isset($s["reason"]) ? " (" . $s["reason"] . ")" : "");
        }
        printf("  web sessions %d, SSH %d, SMB %d, NFS clients %d, events %d\n", count($d["web"]["sessions"] ?? []),
            count($d["ssh"]["sessions"] ?? []), count($d["smb"]["sessions"] ?? []), count($d["nfs"]["clients"] ?? []), count($d["events"] ?? []));
        printf("  collector: interval %s s, last poll %s ms\n", $d["collector"]["interval"] ?? "?", $d["collector"]["duration_ms"] ?? "?");
        printf("  history: %s, %s days, %d ended sessions in 72 h\n", !empty($d["history"]["ok"]) ? "ok" : "NOT AVAILABLE",
            $d["history"]["days"] ?? "?", count($d["history"]["ended"] ?? []));
        printf("  notifications on: %s; alerts in 24 h: %d\n", implode(", ", $d["notify"]["on"] ?? []) ?: "none", $d["notify"]["sent_24h"] ?? 0);
    ' "$RUN/state.json" || bad "state.json is not valid JSON"
else
    bad "state.json is missing"
fi

sec "History database"
for db in "$RUN/history.db" "$CFG/history.db"; do
    if [ -f "$db" ]; then
        php -r '$d = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
            printf("  %s: %s, %d events, %d sessions\n", $argv[1], $d->querySingle("PRAGMA quick_check"),
                $d->querySingle("SELECT COUNT(*) FROM events"), $d->querySingle("SELECT COUNT(*) FROM sessions"));' "$db" 2>/dev/null \
            || bad "$db cannot be read"
    else
        echo "  $db: not there"
    fi
done

sec "rsyslog feed"
ls -la /etc/rsyslog.d/40-unraid-connections.conf /var/log/unraid-connections/events.log 2>&1 | sed 's/^/  /'
if rsyslogd -N1 >/dev/null 2>&1; then echo "  rsyslog config: valid"; else bad "rsyslogd -N1 rejects the config"; fi

sec "Live updates (nchan)"
if grep -q "plugins/$PLG/nchan/" /var/run/nchan.pid 2>/dev/null; then
    grep "plugins/$PLG/nchan/" /var/run/nchan.pid | sed 's/^/  listed: /'
else
    echo "  no script listed (normal when no page and no Dashboard is open)"
fi
pgrep -fa "$DEST/nchan/" 2>/dev/null | sed 's/^/  running: /'

sec "Settings (labels hidden)"
if [ -f "$CFG/settings.ini" ]; then grep -v '^labels=' "$CFG/settings.ini" | sed 's/^/  /'; else echo "  no settings.ini (the defaults apply)"; fi

sec "Recent plugin log lines"
grep 'unraid-connections' /var/log/syslog 2>/dev/null | tail -20 | sed 's/^/  /'

sec "Result"
if [ "$problems" -eq 0 ]; then echo "  no problem found"; else echo "  $problems problem(s) found"; fi
exit 0
