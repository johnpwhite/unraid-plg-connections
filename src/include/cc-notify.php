<?php
/**
 * <module_context>
 *   <name>cc-notify</name>
 *   <description>Notifications (docs/specs/NOTIFICATIONS.md): a new client, many failed
 *   sign-ins from one address, and an SSH sign-in from the internet. Detection uses the
 *   snapshot and the history database. The alerts table keeps the time of each alert, so a
 *   restart or a reboot does not send it again. Unraid's notify script delivers the alert
 *   through the agents the user set up (browser, email and others).</description>
 *   <dependencies>cc-config.php (cc_ip_in_ranges), cc-summary.php (client names), Unraid webGui/scripts/notify</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-config.php';
require_once __DIR__ . '/cc-summary.php';

defined('CC_NOTIFY_BIN')   || define('CC_NOTIFY_BIN', '/usr/local/emhttp/webGui/scripts/notify');
defined('CC_NOTIFY_LINK')  || define('CC_NOTIFY_LINK', '/Tools/ConnectedClients');
defined('CC_NOTIFY_EVENT') || define('CC_NOTIFY_EVENT', 'Connected Clients');
defined('CC_NOTIFY_WHAT')  || define('CC_NOTIFY_WHAT', ['web' => 'web UI sign-in', 'ssh' => 'SSH sign-in', 'smb' => 'SMB session', 'nfs' => 'NFS mount', 'vpn' => 'VPN peer']);
// Not "the internet": private, loopback, link-local, carrier-grade NAT (Tailscale) and unique-local ranges.
defined('CC_NOTIFY_PRIVATE') || define('CC_NOTIFY_PRIVATE', ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10', '::1/128', 'fe80::/10', 'fc00::/7']);

/** The alerts table: one row for each alert kind and key, with the time it was last sent. */
function cc_notify_schema(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS alerts (kind TEXT NOT NULL, key TEXT NOT NULL, t INTEGER NOT NULL, PRIMARY KEY (kind, key))');
}

/** Client addresses that the history database knows (sessions and sign-in events), as [ip => true]. */
function cc_notify_known_ips(SQLite3 $db): array
{
    $out = [];
    $res = $db->query("SELECT ip FROM sessions WHERE ip <> '' UNION SELECT ip FROM events WHERE ip <> '' AND type = 'login'");
    while ($res && ($row = $res->fetchArray(SQLITE3_NUM))) {
        $out[(string) $row[0]] = true;
    }
    return $out;
}

/** True when the alert is due: never sent, or sent $cooldown seconds ago or more. A due alert is recorded as sent now. */
function cc_notify_due(SQLite3 $db, string $kind, string $key, int $now, int $cooldown): bool
{
    $st = $db->prepare('SELECT t FROM alerts WHERE kind = :k AND key = :key');
    $st->bindValue(':k', $kind);
    $st->bindValue(':key', $key);
    $res = $st->execute();
    $row = $res ? $res->fetchArray(SQLITE3_NUM) : false;
    if (is_array($row) && $now - (int) $row[0] < $cooldown) {
        return false;
    }
    $up = $db->prepare('INSERT INTO alerts (kind, key, t) VALUES (:k, :key, :t) ON CONFLICT (kind, key) DO UPDATE SET t = excluded.t');
    $up->bindValue(':k', $kind);
    $up->bindValue(':key', $key);
    $up->bindValue(':t', $now, SQLITE3_INTEGER);
    $up->execute();
    return true;
}

/** Delete alert records older than 400 days. */
function cc_notify_prune(SQLite3 $db, int $now): void
{
    $db->exec('DELETE FROM alerts WHERE t < ' . ($now - 400 * 86400));
}

/** True for an address on the internet. The snapshot's client kind wins; without it, the address ranges decide. */
function cc_notify_is_public(string $ip, array $clients): bool
{
    if (isset($clients[$ip]['kind'])) {
        return $clients[$ip]['kind'] === 'public';
    }
    return filter_var($ip, FILTER_VALIDATE_IP) !== false && !cc_ip_in_ranges($ip, CC_NOTIFY_PRIVATE);
}

/**
 * Addresses in use now that were not known: [ip => ['proto' => p, 'user' => u]]. $items are
 * cc_history_session_items(); only 'login' events count. Local traffic (this server, its
 * containers, loopback) never counts, and VPN peers do not count: the admin sets up each peer,
 * and a WireGuard endpoint changes with the network of the peer.
 */
function cc_notify_new_clients(array $items, array $events, array $known, array $clients): array
{
    $out = [];
    $add = static function (string $ip, string $proto, ?string $user) use (&$out, $known, $clients): void {
        if ($ip === '' || isset($known[$ip]) || isset($out[$ip])) {
            return;
        }
        if (in_array($clients[$ip]['kind'] ?? 'lan', ['self', 'docker', 'loopback'], true)) {
            return;
        }
        $out[$ip] = ['proto' => $proto, 'user' => $user];
    };
    foreach ($items as $i) {
        if ($i[0] === 'vpn') {
            continue;
        }
        $add((string) $i[2], (string) $i[0], $i[3] !== null ? (string) $i[3] : null);
    }
    foreach ($events as $e) {
        if (($e['type'] ?? '') === 'login') {
            $add((string) ($e['ip'] ?? ''), (string) ($e['proto'] ?? ''), isset($e['user']) ? (string) $e['user'] : null);
        }
    }
    return $out;
}

/** Addresses with $count or more failed sign-ins (web UI and SSH) since $since: [ip => ['n', 'users', 'protos']]. */
function cc_notify_failed(SQLite3 $db, int $since, int $count): array
{
    $st = $db->prepare("SELECT ip, COUNT(*) AS n, GROUP_CONCAT(DISTINCT user) AS users, GROUP_CONCAT(DISTINCT proto) AS protos
        FROM events WHERE type = 'login_failed' AND t >= :s AND ip <> '' GROUP BY ip HAVING COUNT(*) >= :c ORDER BY n DESC");
    $st->bindValue(':s', $since, SQLITE3_INTEGER);
    $st->bindValue(':c', $count, SQLITE3_INTEGER);
    $out = [];
    $res = $st->execute();
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $out[(string) $row['ip']] = ['n' => (int) $row['n'], 'users' => (string) $row['users'], 'protos' => (string) $row['protos']];
    }
    return $out;
}

/** SSH sign-ins from an internet address at or after $since. */
function cc_notify_ssh_public(array $events, array $clients, int $since): array
{
    $out = [];
    foreach ($events as $e) {
        if (($e['proto'] ?? '') === 'ssh' && ($e['type'] ?? '') === 'login' && (int) ($e['t'] ?? 0) >= $since
            && cc_notify_is_public((string) ($e['ip'] ?? ''), $clients)) {
            $out[] = $e;
        }
    }
    return $out;
}

/** Text from a log line (a user name can come from an attacker): keep safe characters only, and cut it. */
function cc_notify_clean(string $s, int $max = 64): string
{
    return substr(trim((string) preg_replace('/[^A-Za-z0-9 ._@:()\/,+\-]/', '', $s)), 0, $max);
}

/**
 * The three detections (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md) as ledger events (`client.new`,
 * `signin.threshold`, `signin.public`), with the cooldown recorded now. Every detection always
 * runs: the default rules (cc_subs_defaults) decide whether a notification goes out.
 * cc_notify_alerts() is a thin map from these events to the old alert shape.
 */
function cc_notify_events(SQLite3 $db, array $config, array $newIps, array $events, array $clients, int $now, int $since): array
{
    $out = [];
    $name = static function (string $ip) use ($clients): string {
        $n = cc_notify_clean(cc_summary_name($ip, null, $clients, ''), 48);
        return $n === $ip || $n === '' ? $ip : "$n ($ip)";
    };
    $days = (int) ($config['history_days'] ?? 30);
    {
        foreach ($newIps as $ip => $n) {
            if (!cc_notify_due($db, 'new', (string) $ip, $now, $days * 86400)) {
                continue;
            }
            $what = CC_NOTIFY_WHAT[$n['proto']] ?? 'connection';
            $userTxt = $n['user'] !== null && $n['user'] !== '' ? ' as ' . cc_notify_clean((string) $n['user'], 32) : '';
            $out[] = [
                't' => $now, 'kind' => 'client.new', 'proto' => (string) $n['proto'], 'ip' => (string) $ip,
                'user' => $n['user'] !== null ? (string) $n['user'] : '',
                'summary' => 'New client: ' . $name((string) $ip),
                'data' => ['description' => "First $what from $ip in $days days$userTxt.", 'importance' => 'normal',
                    'client' => $name((string) $ip), 'first_proto' => (string) $n['proto']],
            ];
        }
    }
    {
        $window = (int) ($config['notify_failed_window'] ?? 10) * 60;
        foreach (cc_notify_failed($db, $now - $window, (int) ($config['notify_failed_count'] ?? 5)) as $ip => $f) {
            if (!cc_notify_due($db, 'failed', (string) $ip, $now, $window)) {
                continue;
            }
            $protos = str_replace(['web', 'ssh', ','], ['web UI', 'SSH', ', '], $f['protos']);
            $out[] = [
                't' => $now, 'kind' => 'signin.threshold', 'proto' => '', 'ip' => (string) $ip, 'user' => '',
                'summary' => "{$f['n']} failed sign-ins from " . $name((string) $ip),
                'data' => ['description' => "{$f['n']} failed sign-ins ($protos) from $ip in " . ($window / 60) . ' minutes. Users: ' . cc_notify_clean($f['users']) . '.',
                    'importance' => 'warning', 'count' => $f['n'], 'window' => (int) ($window / 60),
                    'users' => cc_notify_clean($f['users']), 'protos' => $f['protos']],
            ];
        }
    }
    {
        foreach (cc_notify_ssh_public($events, $clients, $since) as $e) {
            $key = $e['t'] . '|' . $e['ip'] . '|' . ($e['user'] ?? '');
            if (!cc_notify_due($db, 'ssh_public', $key, $now, 7 * 86400)) {
                continue;
            }
            $detail = (string) ($e['detail'] ?? '');
            $how = $detail !== '' ? ' with ' . cc_notify_clean($detail, 24) : '';
            $out[] = [
                't' => (int) $e['t'], 'kind' => 'signin.public', 'proto' => 'ssh', 'ip' => (string) $e['ip'],
                'user' => (string) ($e['user'] ?? ''),
                'summary' => 'SSH sign-in from the internet: ' . $e['ip'],
                'data' => ['description' => 'User ' . cc_notify_clean((string) ($e['user'] ?? '?'), 32) . " signed in over SSH from {$e['ip']}$how at " . date('H:i', (int) $e['t']) . '.',
                    'importance' => 'alert', 'detail' => $detail],
            ];
        }
    }
    return $out;
}

/**
 * The alerts that are due for this poll, as [['subject', 'description', 'importance'], ...]. A
 * thin map over cc_notify_events(): the settings and the cooldown gate is already applied there.
 */
function cc_notify_alerts(SQLite3 $db, array $config, array $newIps, array $events, array $clients, int $now, int $since): array
{
    $out = [];
    foreach (cc_notify_events($db, $config, $newIps, $events, $clients, $now, $since) as $e) {
        $data = is_array($e['data'] ?? null) ? $e['data'] : [];
        $out[] = ['subject' => (string) $e['summary'], 'description' => (string) ($data['description'] ?? ''),
            'importance' => (string) ($data['importance'] ?? 'normal')];
    }
    return $out;
}

/** The notify command for one alert. Each argument is shell-escaped. */
function cc_notify_command(array $a): string
{
    return implode(' ', [
        escapeshellarg(CC_NOTIFY_BIN),
        '-e', escapeshellarg(CC_NOTIFY_EVENT),
        '-s', escapeshellarg((string) $a['subject']),
        '-d', escapeshellarg((string) $a['description']),
        '-i', escapeshellarg((string) $a['importance']),
        '-l', escapeshellarg(CC_NOTIFY_LINK),
    ]);
}

/** Send one alert in the background, so that the collector does not wait. Returns false when Unraid's notify script is missing. */
function cc_notify_send(array $a): bool
{
    if (!is_executable(CC_NOTIFY_BIN)) {
        return false;
    }
    exec(cc_notify_command($a) . ' >/dev/null 2>&1 &');
    return true;
}
