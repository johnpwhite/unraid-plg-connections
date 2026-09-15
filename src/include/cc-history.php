<?php
/**
 * <module_context>
 *   <name>cc-history</name>
 *   <description>History for Connected Clients (docs/specs/HISTORY.md): a SQLite database in
 *   RAM with sign-in events and sessions, an integrity check with quarantine
 *   (SELF_HEALING_SQLITE), restore from and copy to flash (HYBRID_PERSISTENCE), retention,
 *   and the queries the page uses. Every function takes explicit paths so tests can use
 *   temp files.</description>
 *   <dependencies>cc-common.php, SQLite3</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';

defined('CC_HISTORY_DB')    || define('CC_HISTORY_DB', '/tmp/unraid-connections/history.db');
defined('CC_HISTORY_FLASH') || define('CC_HISTORY_FLASH', '/boot/config/plugins/unraid-connections/history.db');
defined('CC_EVENTS_LOG')    || define('CC_EVENTS_LOG', '/var/log/unraid-connections/events.log');
defined('CC_EVENTS_LOG_MAX') || define('CC_EVENTS_LOG_MAX', 1048576);   // truncate events.log after reading past 1 MB

/** Integrity check. A corrupt file (and its -wal/-shm) is renamed <file>.corrupt.<time>. Returns true when the file is usable or absent. */
function cc_history_check(string $path): bool
{
    if (!is_file($path)) {
        return true;
    }
    $ok = false;
    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $ok = @$db->querySingle('PRAGMA quick_check') === 'ok';
        $db->close();
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        $ts = date('Ymd_His');
        foreach (['', '-wal', '-shm'] as $sfx) {
            if (is_file($path . $sfx)) {
                @rename($path . $sfx, "$path.corrupt.$ts$sfx");
            }
        }
        cc_run('logger -t unraid-connections ' . escapeshellarg("history database failed its integrity check; moved to $path.corrupt.$ts"));
    }
    return $ok;
}

/** Open (and create) the history database. */
function cc_history_open(string $path = CC_HISTORY_DB): SQLite3
{
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0700, true);
    }
    cc_history_check($path);
    $db = new SQLite3($path);
    $db->enableExceptions(true);
    $db->busyTimeout(3000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec("CREATE TABLE IF NOT EXISTS events (t INTEGER NOT NULL, proto TEXT NOT NULL, type TEXT NOT NULL, user TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '', UNIQUE (t, proto, type, user, ip))");
    $db->exec('CREATE INDEX IF NOT EXISTS events_t ON events (t)');
    $db->exec('CREATE INDEX IF NOT EXISTS events_ip ON events (ip)');
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (proto TEXT NOT NULL, skey TEXT NOT NULL, ip TEXT NOT NULL DEFAULT '', user TEXT, start INTEGER NOT NULL, last_seen INTEGER NOT NULL, end INTEGER, poll INTEGER NOT NULL DEFAULT 0, detail TEXT NOT NULL DEFAULT '{}', PRIMARY KEY (proto, skey))");
    $db->exec('CREATE INDEX IF NOT EXISTS sessions_ip ON sessions (ip)');
    $db->exec('CREATE INDEX IF NOT EXISTS sessions_last ON sessions (last_seen)');
    return $db;
}

/** At start: when there is no RAM copy, bring back the flash copy (after its integrity check). */
function cc_history_restore(string $ram = CC_HISTORY_DB, string $flash = CC_HISTORY_FLASH): bool
{
    if (is_file($ram) || !is_file($flash)) {
        return false;
    }
    if (!cc_history_check($flash)) {
        return false;
    }
    if (!is_dir(dirname($ram))) {
        @mkdir(dirname($ram), 0700, true);
    }
    return @copy($flash, $ram);
}

/** Copy the database to flash: checkpoint, SQLite backup to a temp file on flash, then rename. */
function cc_history_flush(SQLite3 $db, string $flash = CC_HISTORY_FLASH): bool
{
    try {
        if (!is_dir(dirname($flash)) && !@mkdir(dirname($flash), 0777, true)) {
            return false;
        }
        $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $tmp = $flash . '.tmp' . getmypid();
        @unlink($tmp);
        $dst = new SQLite3($tmp);
        $ok = $db->backup($dst);
        $dst->close();
        return $ok && @rename($tmp, $flash);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Store sign-in events. Duplicates (same time, protocol, type, user, IP) are ignored. Returns the
 * number of new rows. $inserted, when given, is set to the rows that were actually new (for
 * cc_ledger_signin_events()).
 */
function cc_history_record_events(SQLite3 $db, array $events, ?array &$inserted = null): int
{
    $new = 0;
    $inserted = [];
    $st = $db->prepare('INSERT OR IGNORE INTO events (t, proto, type, user, ip, detail) VALUES (:t, :p, :ty, :u, :ip, :d)');
    $db->exec('BEGIN');
    foreach ($events as $e) {
        $row = ['t' => (int) $e['t'], 'proto' => (string) $e['proto'], 'type' => (string) $e['type'],
            'user' => (string) ($e['user'] ?? ''), 'ip' => (string) ($e['ip'] ?? ''), 'detail' => (string) ($e['detail'] ?? '')];
        $st->bindValue(':t', $row['t'], SQLITE3_INTEGER);
        $st->bindValue(':p', $row['proto']);
        $st->bindValue(':ty', $row['type']);
        $st->bindValue(':u', $row['user']);
        $st->bindValue(':ip', $row['ip']);
        $st->bindValue(':d', $row['detail']);
        $st->execute();
        if ($db->changes() > 0) {
            $new++;
            $inserted[] = $row;
        }
        $st->reset();
    }
    $db->exec('COMMIT');
    return $new;
}

/**
 * Turn one snapshot into session items: [proto, skey, ip, user, start, last, end, raw].
 * web: skey = session tag; ssh: ip:port:start; smb: ip:pid; nfs: ip; vpn: wg:<peer> or ts:<name>.
 */
function cc_history_session_items(array $snap, int $now): array
{
    $items = [];
    foreach ($snap['web']['sessions'] ?? [] as $r) {
        $items[] = ['web', (string) $r['tag'], (string) ($r['ip'] ?? ''), $r['user'] ?? null,
            (int) ($r['login_at'] ?? $r['unraid_login'] ?? $now), (int) ($r['last_request'] ?? $now), null, $r];
    }
    foreach ($snap['ssh']['sessions'] ?? [] as $r) {
        if (($r['start'] ?? null) === null) {
            continue;   // a live socket whose Accepted line rotated out; nothing stable to key it by
        }
        $ended = ($r['state'] ?? '') !== 'active';
        $end = $ended ? (int) ($r['end'] ?? $r['start']) : null;
        $items[] = ['ssh', $r['ip'] . ':' . $r['port'] . ':' . $r['start'], (string) $r['ip'], $r['user'] ?? null,
            (int) $r['start'], $end ?? $now, $end, $r];
    }
    foreach ($snap['smb']['sessions'] ?? [] as $r) {
        $items[] = ['smb', $r['ip'] . ':' . $r['pid'], (string) $r['ip'], $r['user'] ?? null, $now, $now, null, $r];
    }
    foreach ($snap['nfs']['clients'] ?? [] as $r) {
        $items[] = ['nfs', (string) $r['ip'], (string) $r['ip'], null, $now, $now, null, $r];
    }
    foreach ($snap['wireguard']['peers'] ?? [] as $r) {
        if (!empty($r['endpoint']) && !empty($r['last_handshake']) && $now - (int) $r['last_handshake'] < 180) {
            $items[] = ['vpn', 'wg:' . $r['peer'], (string) $r['endpoint'], null, $now, $now, null, ['kind' => 'wg'] + $r];
        }
    }
    foreach ($snap['tailscale']['peers'] ?? [] as $r) {
        if (!empty($r['active'])) {
            $items[] = ['vpn', 'ts:' . $r['name'], (string) ($r['ips'][0] ?? ''), null, $now, $now, null, ['kind' => 'ts'] + $r];
        }
    }
    return $items;
}

/**
 * Upsert the sessions seen in this poll; a session that was open and is no longer seen gets end = last_seen.
 * The first start time of a session is kept.
 */
function cc_history_record_sessions(SQLite3 $db, array $items, int $poll): void
{
    $st = $db->prepare('INSERT INTO sessions (proto, skey, ip, user, start, last_seen, end, poll, detail)
        VALUES (:p, :k, :ip, :u, :s, :l, :e, :poll, :d)
        ON CONFLICT (proto, skey) DO UPDATE SET ip = excluded.ip, user = COALESCE(excluded.user, sessions.user),
            last_seen = MAX(sessions.last_seen, excluded.last_seen), end = excluded.end, poll = excluded.poll, detail = excluded.detail');
    $db->exec('BEGIN');
    foreach ($items as [$proto, $skey, $ip, $user, $start, $last, $end, $raw]) {
        $st->bindValue(':p', $proto);
        $st->bindValue(':k', $skey);
        $st->bindValue(':ip', $ip);
        $st->bindValue(':u', $user);
        $st->bindValue(':s', $start, SQLITE3_INTEGER);
        $st->bindValue(':l', $last, SQLITE3_INTEGER);
        $st->bindValue(':e', $end, $end === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $st->bindValue(':poll', $poll, SQLITE3_INTEGER);
        $st->bindValue(':d', (string) json_encode($raw, JSON_UNESCAPED_SLASHES));
        $st->execute();
        $st->reset();
    }
    $close = $db->prepare('UPDATE sessions SET end = last_seen WHERE end IS NULL AND poll <> :poll');
    $close->bindValue(':poll', $poll, SQLITE3_INTEGER);
    $close->execute();
    $db->exec('COMMIT');
}

/** Delete rows older than $days. */
function cc_history_prune(SQLite3 $db, int $days, int $now): void
{
    $cut = $now - $days * 86400;
    $db->exec('DELETE FROM events WHERE t < ' . $cut);
    $db->exec('DELETE FROM sessions WHERE COALESCE(end, last_seen) < ' . $cut);
}

/** Sign-in events since $since, newest first. */
function cc_history_events(SQLite3 $db, int $since, int $limit = 2000, ?string $ip = null): array
{
    $sql = 'SELECT t, proto, type, user, ip, detail FROM events WHERE t >= :s' . ($ip !== null ? ' AND ip = :ip' : '') . ' ORDER BY t DESC LIMIT :n';
    $st = $db->prepare($sql);
    $st->bindValue(':s', $since, SQLITE3_INTEGER);
    $st->bindValue(':n', $limit, SQLITE3_INTEGER);
    if ($ip !== null) {
        $st->bindValue(':ip', $ip);
    }
    $out = [];
    $res = $st->execute();
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $row['t'] = (int) $row['t'];
        if ($row['detail'] === '') {
            unset($row['detail']);
        }
        $out[] = $row;
    }
    return $out;
}

/** Ended sessions that touch the window [$since, now], optionally for one IP. Each row carries the original record in 'raw'. */
function cc_history_ended(SQLite3 $db, int $since, ?string $ip = null, int $limit = 5000): array
{
    $sql = 'SELECT proto, skey, ip, user, start, last_seen, end, detail FROM sessions WHERE end IS NOT NULL AND end >= :s' . ($ip !== null ? ' AND ip = :ip' : '') . ' ORDER BY end DESC LIMIT :n';
    $st = $db->prepare($sql);
    $st->bindValue(':s', $since, SQLITE3_INTEGER);
    $st->bindValue(':n', $limit, SQLITE3_INTEGER);
    if ($ip !== null) {
        $st->bindValue(':ip', $ip);
    }
    $out = [];
    $res = $st->execute();
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $out[] = ['proto' => $row['proto'], 'skey' => $row['skey'], 'ip' => $row['ip'], 'user' => $row['user'],
            'start' => (int) $row['start'], 'end' => (int) $row['end'], 'raw' => json_decode((string) $row['detail'], true) ?: []];
    }
    return $out;
}

/** New lines from events.log since the last read. $state['evlog'] keeps the inode and offset. Truncates the file after reading past CC_EVENTS_LOG_MAX. */
function cc_events_log_read(array &$state, string $path = CC_EVENTS_LOG): array
{
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return [];
    }
    $ino = (int) @fileinode($path);
    $size = (int) @filesize($path);
    $pos = $state['evlog'] ?? ['ino' => 0, 'off' => 0];
    if ($pos['ino'] !== $ino || $size < $pos['off']) {
        $pos = ['ino' => $ino, 'off' => 0];   // new or truncated file: read from the start
    }
    $lines = [];
    if ($size > $pos['off'] && ($fh = @fopen($path, 'r'))) {
        fseek($fh, $pos['off']);
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $lastNl = strrpos($chunk, "\n");
        if ($lastNl !== false) {   // keep a partial last line for the next read
            $lines = cc_lines(substr($chunk, 0, $lastNl + 1));
            $pos['off'] += $lastNl + 1;
        }
    }
    if ($pos['off'] >= CC_EVENTS_LOG_MAX && (int) @filesize($path) === $pos['off']) {
        $fh = @fopen($path, 'r+');
        if ($fh) {
            ftruncate($fh, 0);
            fclose($fh);
            $pos['off'] = 0;
        }
    }
    $state['evlog'] = $pos;
    return $lines;
}
