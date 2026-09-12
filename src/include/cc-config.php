<?php
/**
 * <module_context>
 *   <name>cc-config</name>
 *   <description>Plugin settings: defaults, read (merged and sanitized), atomic write under
 *   a non-blocking lock, form parsing, client labels, and proxy CIDR ranges.
 *   See docs/specs/SETTINGS.md, CLIENT_IDENTITY.md, NOTIFICATIONS.md and ACTIONS.md.</description>
 *   <dependencies>cc-common.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';

defined('CC_CONFIG_FILE') || define('CC_CONFIG_FILE', '/boot/config/plugins/unraid-connections/settings.ini');
defined('CC_CONFIG_LOCK') || define('CC_CONFIG_LOCK', '/tmp/unraid-connections/config.lock');
defined('CC_ADAPTERS')    || define('CC_ADAPTERS', ['web', 'ssh', 'smb', 'nfs', 'vpn']);
defined('CC_DEFAULTS')    || define('CC_DEFAULTS', [
    'poll_interval' => '5',              // seconds, 2-5
    'idle_limit'    => '30',             // minutes, 5-1440: web session Idle -> Stale
    'history_days'  => '30',             // days, 1-365
    'adapter_web'   => 'yes',
    'adapter_ssh'   => 'yes',
    'adapter_smb'   => 'yes',
    'adapter_nfs'   => 'yes',
    'adapter_vpn'   => 'yes',
    'proxy_ranges'  => '172.16.0.0/12',  // Docker bridge ranges
    'labels'        => '',               // "ip-or-mac=name" pairs separated by ';'
    'notify_new_client'    => 'yes',     // NOTIFICATIONS.md
    'notify_failed'        => 'yes',
    'notify_failed_count'  => '5',       // failed sign-ins, 2-100
    'notify_failed_window' => '10',      // minutes, 1-1440
    'notify_ssh_public'    => 'yes',
    'actions'              => 'yes',     // ACTIONS.md: the sign-out, end and close buttons
]);

/** Settings merged with defaults and sanitized. A missing or broken file gives the defaults. */
function cc_config_read(): array
{
    $raw = is_readable(CC_CONFIG_FILE) ? @parse_ini_file(CC_CONFIG_FILE) : [];
    return cc_config_sanitize(is_array($raw) ? $raw : []);
}

/** Keep only known keys, clamp numbers, normalise yes/no, ranges and labels. */
function cc_config_sanitize(array $in): array
{
    $out = [];
    foreach (CC_DEFAULTS as $k => $default) {
        $out[$k] = array_key_exists($k, $in) ? trim((string) $in[$k]) : $default;
    }
    $clamp = static function (string $v, int $min, int $max, string $default): string {
        return preg_match('/^-?\d+$/', $v) ? (string) max($min, min($max, (int) $v)) : $default;
    };
    $out['poll_interval'] = $clamp($out['poll_interval'], 2, 5, CC_DEFAULTS['poll_interval']);
    $out['idle_limit']    = $clamp($out['idle_limit'], 5, 1440, CC_DEFAULTS['idle_limit']);
    $out['history_days']  = $clamp($out['history_days'], 1, 365, CC_DEFAULTS['history_days']);
    $out['notify_failed_count']  = $clamp($out['notify_failed_count'], 2, 100, CC_DEFAULTS['notify_failed_count']);
    $out['notify_failed_window'] = $clamp($out['notify_failed_window'], 1, 1440, CC_DEFAULTS['notify_failed_window']);
    foreach (['notify_new_client', 'notify_failed', 'notify_ssh_public', 'actions'] as $k) {
        $out[$k] = in_array($out[$k], ['yes', 'no'], true) ? $out[$k] : CC_DEFAULTS[$k];
    }
    foreach (CC_ADAPTERS as $a) {
        $out["adapter_$a"] = in_array($out["adapter_$a"], ['yes', 'no'], true) ? $out["adapter_$a"] : 'yes';
    }
    $out['proxy_ranges'] = implode(' ', cc_ranges_parse($out['proxy_ranges']));
    $out['labels']       = cc_labels_serialize(cc_labels_parse($out['labels']));
    return $out;
}

/** Merge $updates into the settings and write them atomically. Returns false if the lock or the write fails. */
function cc_config_write(array $updates): bool
{
    foreach ([dirname(CC_CONFIG_FILE), dirname(CC_CONFIG_LOCK)] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
    $fp = @fopen(CC_CONFIG_LOCK, 'c');
    if ($fp === false) {
        return false;
    }
    // Non-blocking lock in a poll loop with a deadline (never a blocking flock).
    $deadline = microtime(true) + 3.0;
    while (!($locked = flock($fp, LOCK_EX | LOCK_NB)) && microtime(true) < $deadline) {
        usleep(50000);
    }
    if (!$locked) {
        fclose($fp);
        return false;
    }
    $merged = cc_config_sanitize(array_merge(cc_config_read(), $updates));
    $lines = [];
    foreach ($merged as $k => $v) {
        $lines[] = $k . '="' . str_replace('"', '', $v) . '"';
    }
    $ok = cc_write_atomic(CC_CONFIG_FILE, implode("\n", $lines) . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

/** Settings form fields -> sanitized settings. An unticked checkbox is absent from the POST, so it means "no". */
function cc_settings_from_post(array $post): array
{
    $in = [
        'poll_interval' => (string) ($post['poll_interval'] ?? ''),
        'idle_limit'    => (string) ($post['idle_limit'] ?? ''),
        'history_days'  => (string) ($post['history_days'] ?? ''),
        'proxy_ranges'  => (string) ($post['proxy_ranges'] ?? ''),
        'labels'        => (string) ($post['labels'] ?? ''),
        'notify_failed_count'  => (string) ($post['notify_failed_count'] ?? ''),
        'notify_failed_window' => (string) ($post['notify_failed_window'] ?? ''),
    ];
    foreach (CC_ADAPTERS as $a) {
        $in["adapter_$a"] = (($post["adapter_$a"] ?? '') === 'yes') ? 'yes' : 'no';
    }
    foreach (['notify_new_client', 'notify_failed', 'notify_ssh_public', 'actions'] as $k) {
        $in[$k] = (($post[$k] ?? '') === 'yes') ? 'yes' : 'no';
    }
    return cc_config_sanitize($in);
}

/** "ip-or-mac=name" pairs (separated by ';' or new lines) -> [lower-case key => clean name]. */
function cc_labels_parse(string $raw): array
{
    $out = [];
    foreach (preg_split('/[;\r\n]+/', $raw) ?: [] as $pair) {
        if (!str_contains($pair, '=')) {
            continue;
        }
        [$key, $name] = array_map('trim', explode('=', $pair, 2));
        $key = strtolower($key);
        $isMac = (bool) preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $key);
        if (!$isMac && filter_var($key, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        $name = trim(substr((string) preg_replace('/[^A-Za-z0-9 ._()\-]/', '', $name), 0, 40));
        if ($name !== '') {
            $out[$key] = $name;
        }
    }
    return $out;
}

function cc_labels_serialize(array $labels): string
{
    $pairs = [];
    foreach ($labels as $k => $v) {
        $pairs[] = "$k=$v";
    }
    return implode(';', $pairs);
}

/** Labels as one pair per line, for the settings textarea. */
function cc_labels_to_text(string $raw): string
{
    return str_replace(';', "\n", cc_labels_serialize(cc_labels_parse($raw)));
}

/** A CIDR list (space, comma or ';' separated) -> the valid entries as "ip/len". A bare IP becomes /32 or /128. */
function cc_ranges_parse(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $item) {
        if ($item === '') {
            continue;
        }
        [$net, $len] = array_pad(explode('/', $item, 2), 2, null);
        if (filter_var($net, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        $max = str_contains($net, ':') ? 128 : 32;
        if ($len === null) {
            $len = (string) $max;
        }
        if (!preg_match('/^\d{1,3}$/', $len) || (int) $len > $max) {
            continue;
        }
        $out[] = "$net/" . (int) $len;
    }
    return array_values(array_unique($out));
}

/** True when $ip is inside one of the CIDR ranges (IPv4 or IPv6). */
function cc_ip_in_ranges(string $ip, array $cidrs): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($cidrs as $cidr) {
        [$net, $len] = array_pad(explode('/', (string) $cidr, 2), 2, '');
        $nb = @inet_pton($net);
        if ($nb === false || strlen($nb) !== strlen($bin)) {
            continue;
        }
        $len = (int) $len;
        $bytes = intdiv($len, 8);
        $bits = $len % 8;
        if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) {
            continue;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;
        if ((ord($bin[$bytes]) & $mask) === (ord($nb[$bytes]) & $mask)) {
            return true;
        }
    }
    return false;
}
