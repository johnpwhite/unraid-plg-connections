<?php
/**
 * <module_context>
 *   <name>cc-summary</name>
 *   <description>Dashboard tile summary (docs/specs/DASHBOARD_TILE.md): from one snapshot, the
 *   sessions in use now for each protocol (local traffic counted apart), the clients with the
 *   most sessions, and the failed sign-ins of the last 24 hours. The rules match the page
 *   model (assets/cc-model.js). Pure: no I/O.</description>
 *   <dependencies>none</dependencies>
 * </module_context>
 */

declare(strict_types=1);

defined('CC_SUMMARY_PROTOS') || define('CC_SUMMARY_PROTOS', ['web', 'ssh', 'smb', 'nfs', 'vpn']);
defined('CC_SUMMARY_LOCAL')  || define('CC_SUMMARY_LOCAL', ['self', 'docker', 'loopback']);

/**
 * Sessions in use now, as [proto, ip, name hint]: web Active or Idle, SSH active, each SMB
 * session and NFS client, a WireGuard peer with a handshake, an active Tailscale peer.
 */
function cc_summary_now(array $snap): array
{
    $now = [];
    foreach ($snap['web']['sessions'] ?? [] as $r) {
        if (in_array($r['state'] ?? '', ['active', 'idle'], true)) {
            $now[] = ['web', (string) ($r['ip'] ?? ''), null];
        }
    }
    foreach ($snap['ssh']['sessions'] ?? [] as $r) {
        if (($r['state'] ?? '') === 'active') {
            $now[] = ['ssh', (string) ($r['ip'] ?? ''), null];
        }
    }
    foreach ($snap['smb']['sessions'] ?? [] as $r) {
        $now[] = ['smb', (string) ($r['ip'] ?? ''), null];
    }
    foreach ($snap['nfs']['clients'] ?? [] as $r) {
        $now[] = ['nfs', (string) ($r['ip'] ?? ''), null];
    }
    foreach ($snap['wireguard']['peers'] ?? [] as $r) {
        if (!empty($r['endpoint']) && !empty($r['last_handshake'])) {
            $now[] = ['vpn', (string) $r['endpoint'], null];
        }
    }
    foreach ($snap['tailscale']['peers'] ?? [] as $r) {
        if (!empty($r['active'])) {
            $now[] = ['vpn', (string) ($r['ips'][0] ?? ''), (string) ($r['name'] ?? '')];
        }
    }
    return $now;
}

/** The display name of a client, as on the page: Tailscale name, user label, this server, container, DNS name, address. */
function cc_summary_name(string $ip, ?string $hint, array $clients, string $host): string
{
    if ($hint !== null && $hint !== '') {
        return $hint;
    }
    if ($ip === '') {
        return 'Unknown client';
    }
    $c = $clients[$ip] ?? [];
    if (!empty($c['label'])) {
        return (string) $c['label'];
    }
    $kind = $c['kind'] ?? 'lan';
    if ($kind === 'self') {
        return ($host !== '' ? $host : 'Server') . ' (this server)';
    }
    if ($kind === 'docker') {
        return (string) (($c['container'] ?? '') ?: 'Docker container');
    }
    if (!empty($c['name'])) {
        return (string) preg_replace('/\.(localdomain|local|lan|home\.arpa)$/i', '', (string) $c['name']);
    }
    return $ip;
}

/** The tile data. $limit is the number of clients in the list. */
function cc_summary(array $snap, int $limit = 6): array
{
    $clients = is_array($snap['clients'] ?? null) ? $snap['clients'] : [];
    $host = (string) ($snap['host']['name'] ?? '');
    $t = (int) ($snap['generated_at'] ?? time());
    $protos = [];
    foreach (CC_SUMMARY_PROTOS as $p) {
        $protos[$p] = ['n' => 0, 'local' => 0, 'state' => (string) ($snap['sources'][$p]['state'] ?? 'ok')];
    }
    $byClient = [];
    foreach (cc_summary_now($snap) as [$p, $ip, $hint]) {
        $kind = (string) ($clients[$ip]['kind'] ?? ($ip !== '' ? 'lan' : 'unknown'));
        if (in_array($kind, CC_SUMMARY_LOCAL, true)) {
            $protos[$p]['local']++;
            continue;
        }
        $protos[$p]['n']++;
        $key = $ip !== '' ? $ip : '?';
        $byClient[$key] ??= ['ip' => $ip, 'name' => cc_summary_name($ip, $hint, $clients, $host), 'kind' => $kind, 'sessions' => 0, 'protos' => []];
        $byClient[$key]['sessions']++;
        $byClient[$key]['protos'][$p] = true;
    }
    $list = [];
    foreach ($byClient as $c) {
        $c['protos'] = array_values(array_intersect(CC_SUMMARY_PROTOS, array_keys($c['protos'])));
        $list[] = $c;
    }
    usort($list, static fn(array $a, array $b): int => [$b['sessions'], $a['name']] <=> [$a['sessions'], $b['name']]);
    $failed = 0;
    foreach ($snap['events'] ?? [] as $e) {
        if (($e['type'] ?? '') === 'login_failed' && (int) ($e['t'] ?? 0) >= $t - 86400) {
            $failed++;
        }
    }
    return [
        'generated_at'  => $t,
        'host'          => $host,
        'protos'        => $protos,
        'clients'       => array_slice($list, 0, max(0, $limit)),
        'client_count'  => count($list),
        'session_count' => array_sum(array_column($protos, 'n')),
        'local_count'   => array_sum(array_column($protos, 'local')),
        'failed_24h'    => $failed,
        'public'        => count(array_filter($list, static fn(array $c): bool => $c['kind'] === 'public')),
    ];
}
