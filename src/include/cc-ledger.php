<?php
/**
 * <module_context>
 *   <name>cc-ledger</name>
 *   <description>Event ledger (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md): one typed row for
 *   each change the collector sees, with a sequence number that is never reused. Builds the
 *   session, sign-in, source and action events; reads them back with a cursor contract
 *   (next_seq, head_seq, oldest_seq, gap, reset, truncated); publishes each poll's new events
 *   to the nchan channel `connections_events`.</description>
 *   <dependencies>cc-common.php, cc-notify.php (cc_notify_clean), cc-summary.php (cc_summary_name), curl extension</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';
require_once __DIR__ . '/cc-notify.php';
require_once __DIR__ . '/cc-summary.php';
require_once __DIR__ . '/cc-history.php';

defined('CC_LEDGER_KINDS') || define('CC_LEDGER_KINDS', [
    'session.started', 'session.ended', 'signin.ok', 'signin.failed', 'signout',
    'client.new', 'signin.threshold', 'signin.public', 'source.unavailable', 'source.available', 'action.taken',
]);
defined('CC_LEDGER_WHAT') || define('CC_LEDGER_WHAT', ['web' => 'Web UI', 'ssh' => 'SSH', 'smb' => 'SMB', 'nfs' => 'NFS', 'vpn' => 'VPN']);
defined('CC_LEDGER_PUBLISH_URL') || define('CC_LEDGER_PUBLISH_URL', 'http://localhost/pub/connections_events?buffer_length=1');
defined('CC_LEDGER_PUBLISH_SOCK') || define('CC_LEDGER_PUBLISH_SOCK', '/var/run/nginx.socket');

/** The ledger table and its indexes. */
function cc_ledger_schema(SQLite3 $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS ledger (seq INTEGER PRIMARY KEY AUTOINCREMENT, t INTEGER NOT NULL, kind TEXT NOT NULL,
        proto TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT '', user TEXT NOT NULL DEFAULT '',
        summary TEXT NOT NULL DEFAULT '', data TEXT NOT NULL DEFAULT '{}')");
    $db->exec('CREATE INDEX IF NOT EXISTS ledger_t ON ledger (t)');
    $db->exec('CREATE INDEX IF NOT EXISTS ledger_kind ON ledger (kind)');
}

/** Insert $events in one transaction. An unknown kind is skipped. Returns the inserted rows, oldest first, with 'seq' set. */
function cc_ledger_append(SQLite3 $db, array $events): array
{
    $out = [];
    $rows = array_values(array_filter($events, static fn($e) => in_array((string) ($e['kind'] ?? ''), CC_LEDGER_KINDS, true)));
    if ($rows === []) {
        return $out;
    }
    $st = $db->prepare('INSERT INTO ledger (t, kind, proto, ip, user, summary, data) VALUES (:t, :k, :p, :ip, :u, :s, :d)');
    $db->exec('BEGIN');
    foreach ($rows as $e) {
        $t = (int) ($e['t'] ?? time());
        $kind = (string) $e['kind'];
        $proto = (string) ($e['proto'] ?? '');
        $ip = (string) ($e['ip'] ?? '');
        $user = cc_notify_clean((string) ($e['user'] ?? ''), 64);
        $summary = cc_notify_clean((string) ($e['summary'] ?? ''), 200);
        $data = is_array($e['data'] ?? null) ? $e['data'] : [];
        $st->bindValue(':t', $t, SQLITE3_INTEGER);
        $st->bindValue(':k', $kind);
        $st->bindValue(':p', $proto);
        $st->bindValue(':ip', $ip);
        $st->bindValue(':u', $user);
        $st->bindValue(':s', $summary);
        $st->bindValue(':d', (string) json_encode($data, JSON_UNESCAPED_SLASHES));
        $st->execute();
        $st->reset();
        $out[] = ['seq' => (int) $db->lastInsertRowID(), 't' => $t, 'kind' => $kind, 'proto' => $proto,
            'ip' => $ip, 'user' => $user, 'summary' => $summary, 'data' => $data];
    }
    $db->exec('COMMIT');
    return $out;
}

/** Glob match: '*' matches any run of characters. An empty pattern list matches nothing. */
function cc_ledger_kind_matches(string $kind, array $patterns): bool
{
    if ($patterns === []) {
        return false;
    }
    foreach ($patterns as $p) {
        $p = (string) $p;
        if ($p === '*') {
            return true;
        }
        $re = '/^' . str_replace('\*', '.*', preg_quote($p, '/')) . '$/';
        if (preg_match($re, $kind) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * Events after $since, oldest first. An empty $kinds list means every kind (no filter).
 * 'reset' when $since is past the head: the read then starts from the oldest row. 'gap' when
 * rows after $since were pruned. 'truncated' when more rows matched than $limit (clamped 1-500).
 */
function cc_ledger_read(SQLite3 $db, int $since, array $kinds = [], int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $head = (int) $db->querySingle('SELECT COALESCE(MAX(seq), 0) FROM ledger');
    $oldest = (int) $db->querySingle('SELECT COALESCE(MIN(seq), 0) FROM ledger');
    $reset = $since > $head;
    $gap = $since > 0 && $oldest > 0 && $since < $oldest - 1;
    $from = $reset ? 0 : $since;

    $st = $db->prepare('SELECT seq, t, kind, proto, ip, user, summary, data FROM ledger WHERE seq > :s ORDER BY seq ASC');
    $st->bindValue(':s', $from, SQLITE3_INTEGER);
    $res = $st->execute();
    $events = [];
    $truncated = false;
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        if ($kinds !== [] && !cc_ledger_kind_matches((string) $row['kind'], $kinds)) {
            continue;
        }
        if (count($events) >= $limit) {
            $truncated = true;
            break;
        }
        $row['seq'] = (int) $row['seq'];
        $row['t'] = (int) $row['t'];
        $row['data'] = json_decode((string) $row['data'], true) ?: [];
        $events[] = $row;
    }
    $next = $events !== [] ? (int) $events[count($events) - 1]['seq'] : $head;
    return ['events' => $events, 'next_seq' => $next, 'head_seq' => $head, 'oldest_seq' => $oldest,
        'gap' => $gap, 'reset' => $reset, 'truncated' => $truncated];
}

/** The newest $limit rows (clamped 1-500), for the snapshot. */
function cc_ledger_recent(SQLite3 $db, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $st = $db->prepare('SELECT seq, t, kind, proto, ip, user, summary, data FROM ledger ORDER BY seq DESC LIMIT :n');
    $st->bindValue(':n', $limit, SQLITE3_INTEGER);
    $res = $st->execute();
    $out = [];
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $row['seq'] = (int) $row['seq'];
        $row['t'] = (int) $row['t'];
        $row['data'] = json_decode((string) $row['data'], true) ?: [];
        $out[] = $row;
    }
    return $out;
}

/** The head and oldest sequence numbers, and the row count of the last 24 hours. */
function cc_ledger_head(SQLite3 $db): array
{
    return [
        'head'      => (int) $db->querySingle('SELECT COALESCE(MAX(seq), 0) FROM ledger'),
        'oldest'    => (int) $db->querySingle('SELECT COALESCE(MIN(seq), 0) FROM ledger'),
        'count_24h' => (int) $db->querySingle('SELECT COUNT(*) FROM ledger WHERE t >= ' . (time() - 86400)),
    ];
}

/** Delete rows older than $days. The AUTOINCREMENT sequence is never reused. */
function cc_ledger_prune(SQLite3 $db, int $days, int $now): void
{
    $db->exec('DELETE FROM ledger WHERE t < ' . ($now - $days * 86400));
}

/** [proto|skey => item] for the items still open (cc_history_session_items() rows with end === null). */
function cc_ledger_live(array $items): array
{
    $out = [];
    foreach ($items as $i) {
        if (($i[6] ?? null) === null) {
            $out[$i[0] . '|' . $i[1]] = $i;
        }
    }
    return $out;
}

function cc_ledger_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm';
    }
    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
}

/** One session.started or session.ended event from a cc_history_session_items() row. */
function cc_ledger_session_event(array $item, array $clients, string $host, int $now, string $kind): array
{
    [$proto, $skey, $ip, $user, $start, , , $raw] = $item;
    $proto = (string) $proto;
    $ip = (string) $ip;
    $user = $user !== null ? (string) $user : '';
    $client = cc_notify_clean(cc_summary_name($ip, null, $clients, $host), 48);
    $what = CC_LEDGER_WHAT[$proto] ?? $proto;
    $ended = $kind === 'session.ended';
    $duration = $ended ? max(0, $now - (int) $start) : null;

    $who = $user !== '' ? "of $user " : '';
    $addr = $ip !== '' ? " ($ip)" : '';
    $summary = "$what session {$who}from $client$addr";
    if ($ended) {
        $summary .= ' ended after ' . cc_ledger_duration((int) $duration);
    }
    $summary = trim((string) preg_replace('/\s+/', ' ', $summary));

    $data = ['client' => $client, 'client_kind' => (string) ($clients[$ip]['kind'] ?? ($ip !== '' ? 'lan' : 'unknown'))];
    if (isset($raw['via'])) {
        $data['via'] = (string) $raw['via'];
    }
    switch ($proto) {
        case 'web':
            $data['tag'] = (string) ($raw['tag'] ?? $skey);
            break;
        case 'ssh':
            $data['pid'] = (int) ($raw['pid'] ?? 0);
            $data['detail'] = (string) ($raw['method'] ?? '');
            break;
        case 'smb':
            $data['pid'] = (int) ($raw['pid'] ?? 0);
            $data['shares'] = array_values(array_map(static fn($s) => (string) ($s['share'] ?? ''), (array) ($raw['shares'] ?? [])));
            $data['detail'] = (string) ($raw['dialect'] ?? '');
            break;
        case 'nfs':
            $data['detail'] = (string) ($raw['version'] ?? '');
            break;
        case 'vpn':
            $data['detail'] = (($raw['kind'] ?? '') === 'wg' ? 'WireGuard: ' . (string) ($raw['peer'] ?? '') : 'Tailscale: ' . (string) ($raw['name'] ?? ''));
            break;
    }
    if ($ended) {
        $data['duration'] = $duration;
    }
    return ['t' => $now, 'kind' => $kind, 'proto' => $proto, 'ip' => $ip, 'user' => $user, 'summary' => $summary, 'data' => $data];
}

/** session.started for a live key that was not live before; session.ended for one that no longer is. */
function cc_ledger_session_events(array $prevLive, array $nowLive, array $clients, string $host, int $now): array
{
    $out = [];
    foreach ($nowLive as $key => $item) {
        if (!isset($prevLive[$key])) {
            $out[] = cc_ledger_session_event($item, $clients, $host, $now, 'session.started');
        }
    }
    foreach ($prevLive as $key => $item) {
        if (!isset($nowLive[$key])) {
            $out[] = cc_ledger_session_event($item, $clients, $host, $now, 'session.ended');
        }
    }
    return $out;
}

/** signin.ok, signin.failed and signout events for history events ($inserted, from cc_history_record_events) at or after $since. */
function cc_ledger_signin_events(array $inserted, array $clients, string $host, int $since): array
{
    $kindOf = ['login' => 'signin.ok', 'login_failed' => 'signin.failed', 'logout' => 'signout'];
    $out = [];
    foreach ($inserted as $e) {
        $t = (int) ($e['t'] ?? 0);
        if ($t < $since) {
            continue;
        }
        $type = (string) ($e['type'] ?? '');
        $kind = $kindOf[$type] ?? null;
        if ($kind === null) {
            continue;
        }
        $proto = (string) ($e['proto'] ?? '');
        $ip = (string) ($e['ip'] ?? '');
        $user = (string) ($e['user'] ?? '');
        $detail = (string) ($e['detail'] ?? '');
        $client = cc_notify_clean(cc_summary_name($ip, null, $clients, $host), 48);
        $label = CC_LEDGER_WHAT[$proto] ?? $proto;
        $verb = $type === 'login' ? 'signed in' : ($type === 'login_failed' ? 'failed to sign in' : 'signed out');
        $who = $user !== '' ? "$user " : '';
        $addr = $ip !== '' ? " ($ip)" : '';
        $summary = trim((string) preg_replace('/\s+/', ' ', "$label: {$who}$verb from $client$addr"));
        $data = ['client' => $client, 'client_kind' => (string) ($clients[$ip]['kind'] ?? ($ip !== '' ? 'lan' : 'unknown'))];
        if ($detail !== '') {
            $data['detail'] = $detail;
        }
        $out[] = ['t' => $t, 'kind' => $kind, 'proto' => $proto, 'ip' => $ip, 'user' => $user, 'summary' => $summary, 'data' => $data];
    }
    return $out;
}

/** source.unavailable / source.available on a change of the 'unavailable' state. $prevSources = [] (first poll) raises nothing. */
function cc_ledger_source_events(array $prevSources, array $sources): array
{
    $out = [];
    if ($prevSources === []) {
        return $out;
    }
    $now = time();
    foreach ($sources as $name => $s) {
        $state = (string) ($s['state'] ?? '');
        $was = (string) ($prevSources[$name]['state'] ?? '');
        if ($state === $was) {
            continue;
        }
        $reason = (string) ($s['reason'] ?? '');
        if ($state === 'unavailable' && $was !== 'unavailable') {
            $out[] = ['t' => $now, 'kind' => 'source.unavailable', 'proto' => (string) $name, 'ip' => '', 'user' => '',
                'summary' => "$name is unavailable" . ($reason !== '' ? ": $reason" : ''), 'data' => ['reason' => $reason]];
        } elseif ($was === 'unavailable' && $state !== 'unavailable') {
            $out[] = ['t' => $now, 'kind' => 'source.available', 'proto' => (string) $name, 'ip' => '', 'user' => '',
                'summary' => "$name is available again", 'data' => ['reason' => $reason]];
        }
    }
    return $out;
}

/** One action.taken event. $plan is a cc_action_plan() result; $done is 'Signed out', 'Ended' or 'Closed'. */
function cc_ledger_action_event(array $plan, string $actor, string $done, int $now): array
{
    $what = (string) ($plan['what'] ?? '');
    $actor = cc_notify_clean($actor, 64);
    return [
        't' => $now, 'kind' => 'action.taken', 'proto' => '', 'ip' => '', 'user' => '',
        'summary' => trim("$done $what (asked by $actor)"),
        'data' => ['action' => (string) ($plan['action'] ?? ''), 'actor' => $actor, 'what' => $what, 'result' => $done],
    ];
}

/** The JSON body cc_ledger_publish() posts. Exposed so a test can check its shape without curl. */
function cc_ledger_publish_payload(array $events): string
{
    return (string) json_encode(['events' => $events], JSON_UNESCAPED_SLASHES);
}

/**
 * POST the poll's new events to the nchan channel `connections_events`. Never throws or blocks
 * the poll: returns false (and does nothing) when the curl extension or the nginx socket is missing.
 */
function cc_ledger_publish(array $events): bool
{
    if ($events === [] || !function_exists('curl_init') || !file_exists(CC_LEDGER_PUBLISH_SOCK)) {
        return false;
    }
    try {
        $ch = curl_init(CC_LEDGER_PUBLISH_URL);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => CC_LEDGER_PUBLISH_SOCK,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => cc_ledger_publish_payload($events),
            CURLOPT_HTTPHEADER     => ['Accept: text/json', 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $ok = curl_exec($ch) !== false;
        curl_close($ch);
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/** For action.php: open the database (3 s busy timeout), append, publish. Returns the appended events; [] on any failure. */
function cc_ledger_append_direct(array $events, string $db = CC_HISTORY_DB): array
{
    try {
        $conn = new SQLite3($db);
        $conn->busyTimeout(3000);
        cc_ledger_schema($conn);
        $out = cc_ledger_append($conn, $events);
        $conn->close();
        cc_ledger_publish($out);
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
