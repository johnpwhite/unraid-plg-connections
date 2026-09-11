<?php
/**
 * <module_context>
 *   <name>cc-web</name>
 *   <description>webGUI session adapter. Joins PHP session files to syslog sign-in lines
 *   and live nginx sockets, and remembers each session's client IP while the session is
 *   new (Unraid moves unraid_login forward every 5 minutes, so the join fails later).</description>
 *   <dependencies>cc-common.php, curl (nchan status)</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';

/**
 * @param array $known session tag => ['ip', 'login_at', 'match' (exact|inferred)]. Updated in place.
 */
function cc_web_sessions(array $events, array $sockets, array $ifaces, int $now, array &$known): array
{
    $loginAt = [];
    foreach ($events as $e) {
        if ($e['proto'] === 'web' && $e['type'] === 'login') {
            $loginAt[$e['user'] . '|' . $e['t']][] = $e['ip'];
        }
    }
    $live = [];
    foreach ($sockets as $s) {
        if ($s['service'] === 'web') {
            $live[$s['peer']] ??= ['ip' => $s['peer'], 'connections' => 0, 'via' => $ifaces[$s['local']] ?? $s['local']];
            $live[$s['peer']]['connections']++;
        }
    }

    $sessions = [];
    $seen = [];
    $anonymous = 0;
    foreach (glob(CC_SESS_DIR . '/sess_*') ?: [] as $f) {
        $raw = (string) @file_get_contents($f);
        if (!preg_match('/unraid_login\|i:(\d+);/', $raw, $a) || !preg_match('/unraid_user\|s:\d+:"([^"]*)"/', $raw, $u)) {
            $anonymous++;
            continue;
        }
        $tag = cc_tag(basename($f));
        $seen[$tag] = true;
        $ips = array_values(array_unique($loginAt[$u[1] . '|' . $a[1]] ?? []));
        $s = ['tag' => $tag, 'user' => $u[1], 'unraid_login' => (int) $a[1], 'last_request' => (int) filemtime($f),
            'ip' => null, 'match' => 'none', 'login_at' => null];
        $k = $known[$tag] ?? null;
        if ($k !== null && $k['match'] === 'exact') {
            [$s['ip'], $s['login_at'], $s['match']] = [$k['ip'], $k['login_at'], 'recorded'];
        } elseif (count($ips) === 1) {
            [$s['ip'], $s['login_at'], $s['match']] = [$ips[0], (int) $a[1], 'exact'];
            $known[$tag] = ['ip' => $ips[0], 'login_at' => (int) $a[1], 'match' => 'exact'];
        } elseif ($k !== null) {
            [$s['ip'], $s['login_at'], $s['match']] = [$k['ip'], $k['login_at'], 'inferred'];
        } elseif (count($ips) > 1) {
            [$s['ip'], $s['login_at'], $s['match'], $s['candidates']] = [$ips[0], (int) $a[1], 'ambiguous', $ips];
        }
        $sessions[] = $s;
    }

    // Fallback: one unmatched session in use now + one unclaimed live client -> inferred.
    $claimed = array_filter(array_column($sessions, 'ip'));
    $unclaimed = array_values(array_diff(array_keys($live), $claimed, ['127.0.0.1', '::1']));
    $recent = array_keys(array_filter($sessions, fn($s) => $s['match'] === 'none' && $now - $s['last_request'] < CC_RECENT));
    if (count($recent) === 1 && count($unclaimed) === 1) {
        $i = $recent[0];
        $prior = array_filter($events, fn($e) => $e['proto'] === 'web' && $e['type'] === 'login' && $e['ip'] === $unclaimed[0]);
        [$sessions[$i]['ip'], $sessions[$i]['match']] = [$unclaimed[0], 'inferred'];
        $sessions[$i]['login_at'] = $prior ? max(array_column($prior, 't')) : null;
        $known[$sessions[$i]['tag']] = ['ip' => $unclaimed[0], 'login_at' => $sessions[$i]['login_at'], 'match' => 'inferred'];
    }
    $known = array_intersect_key($known, $seen);   // forget sessions whose file is gone

    // State: Active = the newest session of a client that has a live connection.
    $newest = [];
    foreach ($sessions as $i => $s) {
        if ($s['ip'] !== null && (!isset($newest[$s['ip']]) || $s['last_request'] > $sessions[$newest[$s['ip']]]['last_request'])) {
            $newest[$s['ip']] = $i;
        }
    }
    foreach ($sessions as $i => &$s) {
        if ($s['ip'] !== null && ($newest[$s['ip']] ?? null) === $i && isset($live[$s['ip']])) {
            $s['state'] = 'active';
            $s['via'] = $live[$s['ip']]['via'];
            $s['connections'] = $live[$s['ip']]['connections'];
        } else {
            $s['state'] = $now - $s['last_request'] < CC_IDLE_LIMIT ? 'idle' : 'stale';
        }
    }
    unset($s);
    usort($sessions, fn($a, $b) => $b['last_request'] <=> $a['last_request']);

    $nchan = [];
    foreach (cc_lines(cc_run('curl -s --max-time 3 --unix-socket /var/run/nginx.socket http://localhost/nchan_stub_status')) as $l) {
        if (preg_match('/^(subscribers|channels): (\d+)/', $l, $m)) {
            $nchan[$m[1]] = (int) $m[2];
        }
    }
    return ['sessions' => $sessions, 'anonymous_sessions' => $anonymous, 'live' => array_values($live), 'nchan' => $nchan];
}
