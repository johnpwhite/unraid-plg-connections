<?php
/**
 * <module_context>
 *   <name>cc-clients</name>
 *   <description>Names and kinds for client IPs: this server, Docker container, LAN,
 *   Tailscale or public. Uses the Tailscale peer list, docker inspect, /proc/net/arp and
 *   reverse DNS. Slow lookups are cached, because the collector runs every 5 seconds.</description>
 *   <dependencies>cc-common.php, cc-config.php, getent, optional: docker</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';
require_once __DIR__ . '/cc-config.php';

/** User label (by IP, then MAC, case-insensitive) and the proxy flag for one client. Pure. */
function cc_client_identity(string $ip, ?string $mac, array $labels, array $ranges): array
{
    $label = $labels[strtolower($ip)] ?? ($mac !== null ? ($labels[strtolower($mac)] ?? null) : null);
    return ['label' => $label, 'proxy' => cc_ip_in_ranges($ip, $ranges)];
}

function cc_client_info(array $ips, array $ifaces, array $tsPeers, array $config = []): array
{
    $labels = cc_labels_parse((string) ($config['labels'] ?? ''));
    $ranges = cc_ranges_parse((string) ($config['proxy_ranges'] ?? ''));
    static $dns = [];        // ip => [name, time]
    static $docker = [];     // ip => container name
    static $dockerAt = 0;
    $now = time();

    if ($now - $dockerAt > 60) {
        $docker = [];
        foreach (cc_lines(cc_run("docker ps -q | xargs -r docker inspect -f '{{.Name}} {{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}'")) as $l) {
            $c = preg_split('/\s+/', trim($l));
            foreach (array_slice($c, 1) as $ip) {
                if ($ip !== '') {
                    $docker[$ip] = ltrim($c[0], '/');
                }
            }
        }
        $dockerAt = $now;
    }
    $arp = [];
    foreach (array_slice(file('/proc/net/arp', FILE_IGNORE_NEW_LINES) ?: [], 1) as $l) {
        $c = preg_split('/\s+/', trim($l));
        if (count($c) >= 6 && $c[3] !== '00:00:00:00:00:00') {
            $arp[$c[0]] = $c[3];
        }
    }
    $tsNames = [];
    foreach ($tsPeers as $p) {
        foreach ($p['ips'] as $ip) {
            $tsNames[$ip] = $p['name'];
        }
    }

    $out = [];
    foreach (array_unique(array_filter($ips)) as $ip) {
        $kind = 'lan';
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            $kind = 'loopback';
        } elseif (isset($ifaces[$ip])) {
            $kind = 'self';
        } elseif (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip)) {
            $kind = 'docker';
        } elseif (preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip) || str_starts_with($ip, 'fd7a:115c:a1e0')) {
            $kind = 'tailscale';
        } elseif (!preg_match('/^(10\.|192\.168\.|169\.254\.)/', $ip)) {
            $kind = 'public';
        }
        if (!isset($dns[$ip]) || $now - $dns[$ip][1] > 600) {
            $dns[$ip] = [trim(cc_run('timeout 1 getent hosts ' . escapeshellarg($ip) . " | awk '{print \$2}'")), $now];
        }
        $name = $dns[$ip][0];
        $out[$ip] = [
            'kind'      => $kind,
            'name'      => $tsNames[$ip] ?? ($docker[$ip] ?? ($name !== '' ? $name : null)),
            'mac'       => $arp[$ip] ?? null,
            'container' => $docker[$ip] ?? null,
        ] + cc_client_identity($ip, $arp[$ip] ?? null, $labels, $ranges);
    }
    return $out;
}
