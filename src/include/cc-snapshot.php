<?php
/**
 * <module_context>
 *   <name>cc-snapshot</name>
 *   <description>Joins every adapter into one snapshot array. The collector daemon calls
 *   cc_snapshot() every 5 seconds; tools/snapshot.php calls it once.</description>
 *   <dependencies>cc-common.php, cc-logs.php, cc-web.php, cc-services.php, cc-clients.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';
require_once __DIR__ . '/cc-logs.php';
require_once __DIR__ . '/cc-web.php';
require_once __DIR__ . '/cc-services.php';
require_once __DIR__ . '/cc-clients.php';

/**
 * @param array $state Kept between calls by the caller: 'known' (web session IPs) and 'previous' (previous-boot log lines).
 */
function cc_snapshot(array &$state): array
{
    $now = time();
    $state['known'] ??= [];
    $ifaces = cc_interfaces();
    $sockets = cc_sockets();
    [$events, $sshRaw] = cc_parse_syslog(cc_syslog_lines($state), $now);
    $web = cc_web_sessions($events, $sockets, $ifaces, $now, $state['known']);
    $ssh = cc_ssh_sessions($sshRaw, $sockets, $ifaces, $now);
    $smb = cc_smb();
    $nfs = cc_nfs($sockets);
    $wg = cc_wireguard();
    $ts = cc_tailscale();
    $cut = $now - CC_EVENT_DAYS * 86400;
    $events = array_values(array_filter($events, fn($e) => $e['t'] >= $cut));

    $ips = array_merge(
        array_column($web['sessions'], 'ip'), array_column($web['live'], 'ip'),
        array_column($ssh['sessions'], 'ip'), array_column($smb['sessions'], 'ip'),
        array_column($nfs['clients'], 'ip'), $nfs['tcp_peers'], array_column($events, 'ip'),
        array_filter(array_column($wg['peers'], 'endpoint'))
    );

    return [
        'generated_at' => $now,
        'host' => [
            'name'       => trim(cc_run('hostname')),
            'version'    => @parse_ini_file('/etc/unraid-version')['version'] ?? null,
            'tz'         => preg_replace('#^.*/zoneinfo/#', '', (string) @readlink('/etc/localtime')) ?: null,
            'uptime'     => (int) (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0],
            'interfaces' => $ifaces,
        ],
        'web'       => $web,
        'ssh'       => $ssh,
        'smb'       => $smb,
        'nfs'       => $nfs,
        'wireguard' => $wg,
        'tailscale' => $ts,
        'ftp'       => cc_ftp(),
        'events'    => $events,
        'clients'   => cc_client_info($ips, $ifaces, $ts['peers']),
    ];
}
