<?php
/**
 * <module_context>
 *   <name>cc-snapshot</name>
 *   <description>Joins every adapter into one snapshot array. It honours the per-adapter
 *   switches in the settings, guards each adapter so one failure never stops the others,
 *   and reports a status for each source (docs/specs/SOURCE_STATUS.md). The collector
 *   daemon calls cc_snapshot() every poll; tools/snapshot.php calls it once.</description>
 *   <dependencies>cc-common.php, cc-config.php, cc-logs.php, cc-web.php, cc-services.php, cc-clients.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';
require_once __DIR__ . '/cc-config.php';
require_once __DIR__ . '/cc-logs.php';
require_once __DIR__ . '/cc-web.php';
require_once __DIR__ . '/cc-services.php';
require_once __DIR__ . '/cc-clients.php';

/**
 * Status of each source. Pure: every fact comes in through $probe.
 * States: ok, disabled (plugin setting), off (service not running or not installed; no banner),
 * unavailable (a real failure; the page shows a banner).
 */
function cc_source_states(array $config, array $probe): array
{
    $out = [];
    foreach (CC_ADAPTERS as $name) {
        if (($config["adapter_$name"] ?? 'yes') === 'no') {
            $out[$name] = ['state' => 'disabled', 'reason' => 'Turned off in the plugin settings.'];
            continue;
        }
        if (isset($probe['errors'][$name])) {
            $out[$name] = ['state' => 'unavailable', 'reason' => 'The adapter failed: ' . $probe['errors'][$name]];
            continue;
        }
        $out[$name] = match ($name) {
            'web' => !empty($probe['sess_dir_ok']) ? ['state' => 'ok'] : ['state' => 'unavailable', 'reason' => 'The PHP session folder is not readable.'],
            'ssh' => !empty($probe['ssh_listening']) ? ['state' => 'ok'] : ['state' => 'off', 'reason' => 'The SSH server is not running.'],
            'smb' => !empty($probe['smb_available']) ? ['state' => 'ok']
                : (!empty($probe['smbd_running']) ? ['state' => 'unavailable', 'reason' => 'smbstatus failed while Samba is running.']
                    : ['state' => 'off', 'reason' => 'Samba is not running. The array may be stopped.']),
            'nfs' => !empty($probe['nfs_available']) ? ['state' => 'ok'] : ['state' => 'off', 'reason' => 'The NFS server is not running.'],
            'vpn' => !empty($probe['vpn_available']) ? ['state' => 'ok'] : ['state' => 'off', 'reason' => 'Neither WireGuard nor Tailscale is installed.'],
        };
    }
    return $out;
}

/**
 * @param array      $state  Kept between calls by the caller: 'known' (web session IPs) and 'previous' (previous-boot log lines).
 * @param array|null $config Settings; read from settings.ini when null.
 */
function cc_snapshot(array &$state, ?array $config = null): array
{
    $config ??= cc_config_read();
    $now = time();
    $state['known'] ??= [];
    $on = static fn(string $a): bool => ($config["adapter_$a"] ?? 'yes') !== 'no';
    $errors = [];
    $guard = static function (string $name, callable $fn, array $fallback) use (&$errors): array {
        try {
            return $fn();
        } catch (Throwable $e) {
            $errors[$name] = $e->getMessage();
            return $fallback;
        }
    };

    $ifaces = cc_interfaces();
    $sockets = cc_sockets();
    [$events, $sshRaw] = cc_parse_syslog(cc_syslog_lines($state), $now);

    $noWeb = ['sessions' => [], 'anonymous_sessions' => 0, 'live' => [], 'nchan' => []];
    $web = $on('web') ? $guard('web', function () use ($events, $sockets, $ifaces, $now, &$state, $config): array {
        return cc_web_sessions($events, $sockets, $ifaces, $now, $state['known'], (int) $config['idle_limit'] * 60);
    }, $noWeb) : $noWeb;
    $ssh = $on('ssh') ? $guard('ssh', fn() => cc_ssh_sessions($sshRaw, $sockets, $ifaces, $now), ['sessions' => []]) : ['sessions' => []];
    $noSmb = ['available' => false, 'sessions' => [], 'open_files' => 0];
    $smb = $on('smb') ? $guard('smb', fn() => cc_smb(), $noSmb) : $noSmb;
    $noNfs = ['available' => false, 'clients' => [], 'tcp_peers' => []];
    $nfs = $on('nfs') ? $guard('nfs', fn() => cc_nfs($sockets), $noNfs) : $noNfs;
    $noVpn = ['available' => false, 'peers' => []];
    $wg = $on('vpn') ? $guard('vpn', fn() => cc_wireguard(), $noVpn) : $noVpn;
    $ts = $on('vpn') ? $guard('vpn', fn() => cc_tailscale(), $noVpn) : $noVpn;

    $cut = $now - CC_EVENT_DAYS * 86400;
    $events = array_values(array_filter($events, fn($e) => $e['t'] >= $cut && $on($e['proto'])));

    $sources = cc_source_states($config, [
        'errors'        => $errors,
        'sess_dir_ok'   => is_dir(CC_SESS_DIR) && is_readable(CC_SESS_DIR),
        'ssh_listening' => cc_run("ss -Hltn '( sport = :22 )'") !== '',
        'smb_available' => (bool) $smb['available'],
        'smbd_running'  => cc_run('pgrep -x smbd') !== '',
        'nfs_available' => (bool) $nfs['available'],
        'vpn_available' => $wg['available'] || $ts['available'],
    ]);

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
        'config' => [
            'poll_interval' => (int) $config['poll_interval'],
            'idle_limit'    => (int) $config['idle_limit'],
            'history_days'  => (int) $config['history_days'],
            'proxy_ranges'  => cc_ranges_parse((string) $config['proxy_ranges']),
            'actions'       => (string) ($config['actions'] ?? 'yes'),
        ],
        'sources'   => $sources,
        'web'       => $web,
        'ssh'       => $ssh,
        'smb'       => $smb,
        'nfs'       => $nfs,
        'wireguard' => $wg,
        'tailscale' => $ts,
        'ftp'       => cc_ftp(),
        'events'    => $events,
        'clients'   => cc_client_info($ips, $ifaces, $ts['peers'], $config),
    ];
}
