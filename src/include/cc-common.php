<?php
/**
 * <module_context>
 *   <name>cc-common</name>
 *   <description>Shared constants and helpers for the Connected Clients adapters: shell
 *   run, address parsing, syslog time, interfaces, live TCP sockets, atomic writes.</description>
 *   <dependencies>ip, ss</dependencies>
 * </module_context>
 */

declare(strict_types=1);

defined('CC_SESS_DIR')      || define('CC_SESS_DIR', '/var/lib/php');
defined('CC_IDLE_LIMIT')    || define('CC_IDLE_LIMIT', 1800);   // seconds from the last request: Idle -> Stale
defined('CC_RECENT')        || define('CC_RECENT', 120);        // seconds: "in use now", for the inferred web match
defined('CC_EVENT_DAYS')    || define('CC_EVENT_DAYS', 7);      // days of sign-in events to keep in the snapshot
defined('CC_TS_BIN')        || define('CC_TS_BIN', '/usr/local/sbin/tailscale');
defined('CC_SERVICE_PORTS') || define('CC_SERVICE_PORTS', [80 => 'web', 443 => 'web', 22 => 'ssh', 445 => 'smb', 139 => 'smb', 2049 => 'nfs', 21 => 'ftp']);

function cc_run(string $cmd): string
{
    return (string) shell_exec($cmd . ' 2>/dev/null');
}

function cc_lines(string $raw): array
{
    return $raw === '' ? [] : explode("\n", rtrim($raw));
}

/** Short one-way tag for a secret value. A PHP session ID is the login cookie value. */
function cc_tag(string $s): string
{
    return substr(hash('sha256', $s), 0, 8);
}

/** "1.2.3.4:5" or "[v6]:5" -> "1.2.3.4" or "v6". */
function cc_host_only(string $addr): string
{
    if (preg_match('/^\[(.+)\]:\d+$/', $addr, $m)) {
        $addr = $m[1];
    } elseif (preg_match('/^([^:]+):\d+$/', $addr, $m)) {
        $addr = $m[1];
    }
    return (string) preg_replace('/^::ffff:/', '', $addr);
}

function cc_port_of(string $addr): int
{
    return (int) substr((string) strrchr($addr, ':'), 1);
}

/** Syslog time "Sep 11 08:53:05" -> epoch. The year is not in the line. */
function cc_ts(string $stamp, int $now): int
{
    $t = strtotime($stamp);
    if ($t === false) {
        return 0;
    }
    return $t > $now + 86400 ? (int) strtotime('-1 year', $t) : $t;
}

/** Write a file atomically: write a temporary file, then rename it. */
function cc_write_atomic(string $path, string $data): bool
{
    $tmp = $path . '.tmp' . getmypid();
    if (file_put_contents($tmp, $data) === false) {
        return false;
    }
    return rename($tmp, $path);
}

/** Local address -> interface name. veth and link-local addresses are left out. */
function cc_interfaces(): array
{
    $map = [];
    foreach (cc_lines(cc_run('ip -o addr show')) as $line) {
        if (!preg_match('/^\d+:\s+(\S+)\s+inet6?\s+([^\/\s]+)/', $line, $m)) {
            continue;
        }
        if (str_starts_with($m[1], 'veth') || str_starts_with($m[2], 'fe80')) {
            continue;
        }
        if (isset($map[$m[2]]) && str_starts_with($m[1], 'shim-')) {
            continue;   // keep br0, not its macvlan shim
        }
        $map[$m[2]] = $m[1];
    }
    return $map;
}

/** Established TCP connections to the service ports on this server. */
function cc_sockets(): array
{
    $filter = implode(' or ', array_map(fn($p) => "sport = :$p", array_keys(CC_SERVICE_PORTS)));
    $out = [];
    foreach (cc_lines(cc_run("ss -Htnp state established '( $filter )'")) as $line) {
        $c = preg_split('/\s+/', trim($line));
        if (count($c) < 4) {
            continue;
        }
        $proc = null;
        $pid = null;
        if (isset($c[4]) && preg_match('/\("([^"]+)",pid=(\d+)/', $c[4], $p)) {
            $proc = $p[1];
            $pid = (int) $p[2];
        }
        $lport = cc_port_of($c[2]);
        $out[] = [
            'service' => CC_SERVICE_PORTS[$lport] ?? 'other',
            'local'   => cc_host_only($c[2]),
            'lport'   => $lport,
            'peer'    => cc_host_only($c[3]),
            'pport'   => cc_port_of($c[3]),
            'proc'    => $proc,
            'pid'     => $pid,
        ];
    }
    return $out;
}
