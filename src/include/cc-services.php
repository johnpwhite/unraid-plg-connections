<?php
/**
 * <module_context>
 *   <name>cc-services</name>
 *   <description>Adapters for SSH (syslog + live sockets), SMB (smbstatus --json), NFS v4
 *   (/proc/fs/nfsd/clients), WireGuard (wg dump), Tailscale (status --json) and FTP.</description>
 *   <dependencies>cc-common.php, smbstatus, optional: wg, tailscale</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';

function cc_ssh_sessions(array $ssh, array $sockets, array $ifaces, int $now): array
{
    $live = [];
    foreach ($sockets as $s) {
        if ($s['service'] === 'ssh') {
            $live[$s['peer'] . ':' . $s['pport']] = $s;
        }
    }
    foreach ($ssh as &$c) {
        $k = $c['ip'] . ':' . $c['port'];
        if (isset($live[$k])) {
            $c['state'] = 'active';
            $c['via'] = $ifaces[$live[$k]['local']] ?? $live[$k]['local'];
            unset($live[$k]);
        } else {
            $c['state'] = $c['end'] !== null ? 'ended' : 'lost';   // lost: no disconnect line and no socket
        }
        $c['type'] = $c['shell'] ? 'shell' : ($c['sftp'] && !$c['commands'] ? 'sftp' : ($c['commands'] || $c['sftp'] ? 'command' : 'unknown'));
    }
    unset($c);
    $cut = $now - CC_EVENT_DAYS * 86400;
    $ssh = array_values(array_filter($ssh, fn($c) => $c['state'] === 'active' || ($c['end'] ?? $c['start']) >= $cut));
    foreach ($live as $s) {   // live sockets whose Accepted line has rotated out of syslog
        $ssh[] = ['ip' => $s['peer'], 'port' => $s['pport'], 'user' => null, 'method' => null, 'key_type' => null, 'key' => null,
            'start' => null, 'end' => null, 'pid' => $s['pid'], 'shell' => 0, 'commands' => 0, 'sftp' => 0,
            'state' => 'active', 'via' => $ifaces[$s['local']] ?? $s['local'], 'type' => 'unknown'];
    }
    return ['sessions' => $ssh];
}

function cc_smb(): array
{
    $j = json_decode(cc_run('smbstatus --json'), true);
    if (!is_array($j)) {
        return ['available' => false, 'sessions' => [], 'open_files' => 0];
    }
    $tcons = [];
    foreach ($j['tcons'] ?? [] as $t) {
        $tcons[$t['session_id']][] = ['share' => $t['service'], 'connected_at' => $t['connected_at']];
    }
    $sessions = [];
    foreach ($j['sessions'] ?? [] as $s) {
        $sessions[] = [
            'user'        => $s['username'],
            'ip'          => $s['remote_machine'],
            'dialect'     => $s['session_dialect'],
            'encryption'  => $s['encryption']['degree'] ?? null,
            'signing'     => $s['signing']['degree'] ?? null,
            'created_raw' => $s['creation_time'] ?? null,   // the UTC offset is not reliable (research 5.3)
            'shares'      => array_values(array_filter($tcons[$s['session_id']] ?? [], fn($t) => $t['share'] !== 'IPC$')),
            'pid'         => (int) ($s['server_id']['pid'] ?? 0),
        ];
    }
    return ['available' => true, 'version' => $j['version'] ?? null, 'sessions' => $sessions, 'open_files' => count($j['open_files'] ?? [])];
}

function cc_nfs(array $sockets): array
{
    $clients = [];
    foreach (glob('/proc/fs/nfsd/clients/*/info') ?: [] as $f) {
        $kv = [];
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^([^:]+):\s*(.*)$/', $l, $m)) {
                $kv[$m[1]] = trim($m[2], '"');
            }
        }
        $clients[] = [
            'ip'         => cc_host_only($kv['address'] ?? ''),
            'name'       => $kv['name'] ?? null,
            'version'    => '4.' . ($kv['minor version'] ?? '?'),
            'status'     => $kv['status'] ?? null,
            'last_renew' => isset($kv['seconds from last renew']) ? (int) $kv['seconds from last renew'] : null,
        ];
    }
    $tcp = array_values(array_unique(array_column(array_filter($sockets, fn($s) => $s['service'] === 'nfs'), 'peer')));
    return ['available' => is_dir('/proc/fs/nfsd/clients'), 'clients' => $clients, 'tcp_peers' => $tcp];
}

function cc_wireguard(): array
{
    $peers = [];
    foreach (cc_lines(cc_run('wg show all dump')) as $l) {
        $c = explode("\t", $l);
        if (count($c) === 9) {
            $peers[] = ['iface' => $c[0], 'peer' => substr($c[1], 0, 8), 'endpoint' => $c[3] === '(none)' ? null : cc_host_only($c[3]),
                'last_handshake' => (int) $c[5] ?: null, 'rx' => (int) $c[6], 'tx' => (int) $c[7]];
        }
    }
    return ['available' => cc_run('command -v wg') !== '', 'peers' => $peers];
}

/** Tailscale peers. User login names are not output. */
function cc_tailscale(): array
{
    if (!is_executable(CC_TS_BIN)) {
        return ['available' => false, 'peers' => []];
    }
    $j = json_decode(cc_run(CC_TS_BIN . ' status --json'), true);
    $peers = [];
    foreach ($j['Peer'] ?? [] as $p) {
        $peers[] = [
            'name' => $p['HostName'] ?? null, 'os' => $p['OS'] ?? null, 'ips' => $p['TailscaleIPs'] ?? [],
            'online' => (bool) ($p['Online'] ?? false), 'active' => (bool) ($p['Active'] ?? false),
            'direct' => ($p['CurAddr'] ?? '') !== '', 'relay' => $p['Relay'] ?? null,
            'last_seen' => isset($p['LastSeen']) ? strtotime($p['LastSeen']) : null,
        ];
    }
    return ['available' => true, 'self' => ['name' => $j['Self']['HostName'] ?? null, 'ips' => $j['Self']['TailscaleIPs'] ?? []], 'peers' => $peers];
}

function cc_ftp(): array
{
    return ['enabled' => (bool) preg_match('/^ftp/m', (string) @file_get_contents('/etc/inetd.conf'))];
}
