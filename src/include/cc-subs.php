<?php
/**
 * <module_context>
 *   <name>cc-subs</name>
 *   <description>Subscriptions (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md): a rule matches
 *   ledger event kinds (with globs), an optional filter (protocols, client kinds, address
 *   ranges, user), and delivers to one sink (an Unraid notification, a syslog line, or a user
 *   script under event.d). The three built-in rules come from the existing notify_* settings.
 *   Local traffic never fires a user rule unless the rule's client filter names it.</description>
 *   <dependencies>cc-common.php, cc-config.php (cc_ranges_parse, cc_ip_in_ranges), cc-notify.php (cc_notify_due, cc_notify_send, cc_notify_clean), cc-ledger.php (CC_LEDGER_KINDS, cc_ledger_kind_matches)</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';
require_once __DIR__ . '/cc-config.php';
require_once __DIR__ . '/cc-notify.php';
require_once __DIR__ . '/cc-ledger.php';

defined('CC_SUBS_FILE')      || define('CC_SUBS_FILE', '/boot/config/plugins/unraid-connections/subscriptions.json');
defined('CC_SUBS_SCRIPT_DIR')|| define('CC_SUBS_SCRIPT_DIR', '/boot/config/plugins/unraid-connections/event.d');
defined('CC_SUBS_LOG')       || define('CC_SUBS_LOG', '/var/log/unraid-connections/scripts.log');
defined('CC_SUBS_STATE')     || define('CC_SUBS_STATE', '/tmp/unraid-connections/subs-state.json');
defined('CC_SUBS_PROTOS')       || define('CC_SUBS_PROTOS', ['web', 'ssh', 'smb', 'nfs', 'vpn']);
defined('CC_SUBS_CLIENT_KINDS') || define('CC_SUBS_CLIENT_KINDS', ['lan', 'public', 'tailscale', 'docker', 'self', 'loopback', 'unknown']);
defined('CC_SUBS_LOCAL_KINDS')  || define('CC_SUBS_LOCAL_KINDS', ['self', 'docker', 'loopback']);   // R5: excluded from an empty client filter

/** The three built-in rules, on the current notify_* settings. */
function cc_subs_defaults(array $switches = []): array
{
    $rule = static fn(string $id, string $name, string $switch, string $kind, string $importance): array => [
        'id' => $id, 'name' => $name, 'on' => ($switches[$switch] ?? 'yes') !== 'no', 'kinds' => [$kind],
        'protos' => [], 'clients' => [], 'ranges' => [], 'user' => '', 'sink' => 'notify',
        'importance' => $importance, 'script' => '', 'cooldown' => 0,
    ];
    return [
        $rule('def00001', 'New client', 'notify_new_client', 'client.new', 'normal'),
        $rule('def00002', 'Failed sign-ins over the limit', 'notify_failed', 'signin.threshold', 'warning'),
        $rule('def00003', 'SSH from the internet', 'notify_ssh_public', 'signin.public', 'alert'),
    ];
}

/**
 * Put the three default rules into the file once. $switches are the raw settings.ini values
 * (the notify_* keys of releases before 2026-09-15 decide whether a default starts on or off).
 * A file that carries "seeded": true is left alone, so a deleted default stays deleted.
 * Returns true when it wrote the file.
 */
/** A short version tag of the rules file ("mtime:size"; "0:0" when there is no file). The settings form carries it. */
function cc_subs_version(string $file = CC_SUBS_FILE): string
{
    clearstatcache(true, $file);
    return is_file($file) ? ((int) @filemtime($file)) . ':' . ((int) @filesize($file)) : '0:0';
}

/** Put back any default rule that is missing (by id), in front of the others. Returns the number added. */
function cc_subs_restore_defaults(string $file = CC_SUBS_FILE): int
{
    $rules = cc_subs_read($file);
    $ids = array_column($rules, 'id');
    $missing = array_values(array_filter(cc_subs_defaults(), static fn(array $d): bool => !in_array($d['id'], $ids, true)));
    if ($missing === []) {
        return 0;
    }
    return cc_subs_write(array_merge($missing, $rules), $file) ? count($missing) : 0;
}

function cc_subs_seed(array $switches = [], string $file = CC_SUBS_FILE): bool
{
    $raw = is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (is_array($raw) && !empty($raw['seeded'])) {
        return false;
    }
    $existing = cc_subs_read($file);
    $ids = array_column($existing, 'id');
    $defaults = array_filter(cc_subs_defaults($switches), static fn(array $d): bool => !in_array($d['id'], $ids, true));
    return cc_subs_write(array_merge(array_values($defaults), $existing), $file);
}

/** Every field checked and normalised. Null when the name is empty, no kind is valid, or the sink is unknown. */
function cc_subs_sanitize(array $rule): ?array
{
    $name = trim((string) ($rule['name'] ?? ''));
    if ($name === '' || !preg_match('/^[A-Za-z0-9 ._\-()]{1,40}$/', $name)) {
        return null;
    }
    $kinds = [];
    foreach ((array) ($rule['kinds'] ?? []) as $k) {
        $k = (string) $k;
        if ($k === '*' || in_array($k, CC_LEDGER_KINDS, true) || preg_match('/^[a-z]+\.\*$/', $k)) {
            $kinds[] = $k;
        }
    }
    $kinds = array_values(array_unique($kinds));
    if ($kinds === []) {
        return null;
    }
    $sink = (string) ($rule['sink'] ?? '');
    if (!in_array($sink, ['notify', 'syslog', 'script'], true)) {
        return null;
    }
    $protos = array_values(array_intersect(CC_SUBS_PROTOS, (array) ($rule['protos'] ?? [])));
    $clients = array_values(array_intersect(CC_SUBS_CLIENT_KINDS, (array) ($rule['clients'] ?? [])));
    $rangesIn = $rule['ranges'] ?? [];
    $rangesStr = is_array($rangesIn) ? implode(' ', $rangesIn) : (string) $rangesIn;
    $ranges = cc_ranges_parse($rangesStr);
    $user = cc_notify_clean(trim((string) ($rule['user'] ?? '')), 64);
    $importance = (string) ($rule['importance'] ?? 'normal');
    if (!in_array($importance, ['normal', 'warning', 'alert'], true)) {
        $importance = 'normal';
    }
    $script = (string) ($rule['script'] ?? '');
    $script = preg_match('/^[A-Za-z0-9._-]{1,64}$/', $script) === 1 ? $script : '';
    $cooldown = max(0, min(1440, (int) ($rule['cooldown'] ?? 0)));
    $id = (string) ($rule['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}$/', $id)) {
        $id = bin2hex(random_bytes(4));
    }
    return ['id' => $id, 'name' => $name, 'on' => !empty($rule['on']), 'kinds' => $kinds, 'protos' => $protos,
        'clients' => $clients, 'ranges' => $ranges, 'user' => $user, 'sink' => $sink, 'importance' => $importance,
        'script' => $script, 'cooldown' => $cooldown];
}

/** The user rules, each through cc_subs_sanitize(). A missing or broken file gives []. */
function cc_subs_read(string $file = CC_SUBS_FILE): array
{
    $raw = is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;
    $rules = is_array($raw) && is_array($raw['rules'] ?? null) ? $raw['rules'] : [];
    $out = [];
    foreach ($rules as $r) {
        if (is_array($r)) {
            $s = cc_subs_sanitize($r);
            if ($s !== null) {
                $out[] = $s;
            }
        }
    }
    return $out;
}

/** Atomic write. */
function cc_subs_write(array $rules, string $file = CC_SUBS_FILE): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    return cc_write_atomic($file, (string) json_encode(['seeded' => true, 'rules' => array_values($rules)], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
}

/**
 * The settings form: $post['rule'][<id or 'new'>][field]. A row with remove = 1 is dropped;
 * the 'new' row is kept only when it has a name. Each surviving row goes through cc_subs_sanitize().
 */
function cc_subs_from_post(array $post): array
{
    $out = [];
    foreach ((array) ($post['rule'] ?? []) as $id => $row) {
        if (!is_array($row) || !empty($row['remove'])) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ((string) $id === 'new' && $name === '') {
            continue;
        }
        $rule = [
            'id'       => (string) $id === 'new' ? '' : (string) $id,
            'name'     => $name,
            'on'       => !empty($row['on']),
            'kinds'    => (array) ($row['kinds'] ?? []),
            'protos'   => (array) ($row['protos'] ?? []),
            'clients'  => (array) ($row['clients'] ?? []),
            'ranges'   => (string) ($row['ranges'] ?? ''),
            'user'     => (string) ($row['user'] ?? ''),
            'sink'     => (string) ($row['sink'] ?? 'notify'),
            'importance' => (string) ($row['importance'] ?? 'normal'),
            'script'   => (string) ($row['script'] ?? ''),
            'cooldown' => (string) ($row['cooldown'] ?? '0'),
        ];
        $s = cc_subs_sanitize($rule);
        if ($s !== null) {
            $out[] = $s;
        }
    }
    return $out;
}

/** on, kind glob, protocol, client kind (R5), ranges, user. A built-in rule matches on the kind only. */
function cc_subs_match(array $rule, array $event, array $clients): bool
{
    if (empty($rule['on'])) {
        return false;
    }
    if (!cc_ledger_kind_matches((string) ($event['kind'] ?? ''), (array) ($rule['kinds'] ?? []))) {
        return false;
    }
    $protos = (array) ($rule['protos'] ?? []);
    if ($protos !== [] && !in_array((string) ($event['proto'] ?? ''), $protos, true)) {
        return false;
    }
    $ip = (string) ($event['ip'] ?? '');
    $kind = $ip !== '' ? (string) ($clients[$ip]['kind'] ?? 'lan') : 'unknown';
    $clientsFilter = (array) ($rule['clients'] ?? []);
    if ($clientsFilter === []) {
        if (in_array($kind, CC_SUBS_LOCAL_KINDS, true)) {
            return false;
        }
    } elseif (!in_array($kind, $clientsFilter, true)) {
        return false;
    }
    $ranges = (array) ($rule['ranges'] ?? []);
    if ($ranges !== [] && (!$ip || !cc_ip_in_ranges($ip, $ranges))) {
        return false;
    }
    $user = strtolower((string) ($rule['user'] ?? ''));
    if ($user !== '' && strtolower((string) ($event['user'] ?? '')) !== $user) {
        return false;
    }
    return true;
}

/** cooldown 0 -> always due. Else the alerts table, kind 'rule:<id>', key the event address. */
function cc_subs_due(SQLite3 $db, array $rule, array $event, int $now): bool
{
    $cooldown = (int) ($rule['cooldown'] ?? 0);
    if ($cooldown <= 0) {
        return true;
    }
    return cc_notify_due($db, 'rule:' . (string) ($rule['id'] ?? ''), (string) ($event['ip'] ?? ''), $now, $cooldown * 60);
}

/** The notify sink shape for cc_notify_send(): subject, description, importance. */
function cc_subs_alert(array $rule, array $event): array
{
    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    $description = isset($data['description']) && $data['description'] !== ''
        ? (string) $data['description']
        : trim((string) ($event['kind'] ?? '') . ': ' . (string) ($event['summary'] ?? ''));
    return [
        'subject'     => (string) ($event['summary'] ?? ''),
        'description' => $description,
        'importance'  => (string) ($rule['importance'] ?? 'normal'),
    ];
}

/**
 * The script command: pipes the event JSON to `timeout 30 /bin/bash <path>` (the script folder
 * is on flash, so the exec bit is not reliable) with the CC_EVENT_* environment, output appended
 * to CC_SUBS_LOG, in the background. Null when the script is not a regular file in the folder.
 */
function cc_subs_script_command(array $rule, array $event): ?string
{
    $script = (string) ($rule['script'] ?? '');
    if ($script === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $script)) {
        return null;
    }
    $path = CC_SUBS_SCRIPT_DIR . '/' . $script;
    if (!is_file($path)) {
        return null;
    }
    $json = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
    $env = implode(' ', [
        'CC_EVENT_SEQ=' . escapeshellarg((string) ($event['seq'] ?? '')),
        'CC_EVENT_TIME=' . escapeshellarg((string) ($event['t'] ?? '')),
        'CC_EVENT_KIND=' . escapeshellarg((string) ($event['kind'] ?? '')),
        'CC_EVENT_PROTO=' . escapeshellarg((string) ($event['proto'] ?? '')),
        'CC_EVENT_IP=' . escapeshellarg((string) ($event['ip'] ?? '')),
        'CC_EVENT_USER=' . escapeshellarg((string) ($event['user'] ?? '')),
        'CC_EVENT_SUMMARY=' . escapeshellarg((string) ($event['summary'] ?? '')),
    ]);
    return 'echo ' . escapeshellarg($json) . ' | ' . $env . ' timeout 30 /bin/bash ' . escapeshellarg($path)
        . ' >> ' . escapeshellarg(CC_SUBS_LOG) . ' 2>&1 &';
}

/** The sink: notify -> cc_notify_send(); syslog -> logger; script -> the script command. $run is the executor (tests inject one). */
function cc_subs_deliver(array $rule, array $event, ?callable $run = null): bool
{
    $sink = (string) ($rule['sink'] ?? 'notify');
    if ($sink === 'notify') {
        return cc_notify_send(cc_subs_alert($rule, $event));
    }
    if ($sink === 'syslog') {
        $cmd = 'logger -t unraid-connections ' . escapeshellarg('event ' . (string) ($event['kind'] ?? '') . ': ' . (string) ($event['summary'] ?? ''));
        $run !== null ? $run($cmd) : cc_run($cmd);
        return true;
    }
    if ($sink === 'script') {
        $cmd = cc_subs_script_command($rule, $event);
        if ($cmd === null) {
            return false;
        }
        $run !== null ? $run($cmd) : exec($cmd);
        return true;
    }
    return false;
}

/** For each event and rule that matches and is due: deliver, and record $state[id] = ['last' => t, 'fired' => n + 1]. Returns the number of deliveries. */
function cc_subs_dispatch(SQLite3 $db, array $rules, array $events, array $clients, int $now, array &$state, ?callable $run = null): int
{
    $count = 0;
    foreach ($events as $event) {
        foreach ($rules as $rule) {
            if (!cc_subs_match($rule, $event, $clients) || !cc_subs_due($db, $rule, $event, $now)) {
                continue;
            }
            if (!cc_subs_deliver($rule, $event, $run)) {
                continue;
            }
            $id = (string) ($rule['id'] ?? '');
            $fired = (int) ($state[$id]['fired'] ?? 0) + 1;
            $state[$id] = ['last' => $now, 'fired' => $fired];
            $count++;
        }
    }
    return $count;
}

/** The RAM state file: {id: {last: t, fired: n}}. A missing or broken file gives []. */
function cc_subs_state_read(): array
{
    $j = json_decode((string) @file_get_contents(CC_SUBS_STATE), true);
    return is_array($j) ? $j : [];
}

function cc_subs_state_write(array $state): bool
{
    $dir = dirname(CC_SUBS_STATE);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    return cc_write_atomic(CC_SUBS_STATE, (string) json_encode($state));
}

/** A synthetic event of the rule's first concrete kind (a glob gives session.started), for the settings page "Test" button. */
function cc_subs_test_event(array $rule, int $now): array
{
    $kind = 'session.started';
    foreach ((array) ($rule['kinds'] ?? []) as $k) {
        if (in_array($k, CC_LEDGER_KINDS, true)) {
            $kind = (string) $k;
            break;
        }
    }
    $summary = "Test: $kind from Connected Clients";
    return ['seq' => 0, 't' => $now, 'kind' => $kind, 'proto' => (string) (($rule['protos'][0] ?? '') ?: 'web'),
        'ip' => '192.0.2.1', 'user' => 'test', 'summary' => $summary, 'data' => ['description' => $summary]];
}

/** The file names in the script folder (the settings page offers them in a select). */
function cc_subs_scripts(string $dir = CC_SUBS_SCRIPT_DIR): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if (is_file("$dir/$f") && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $f)) {
            $out[] = $f;
        }
    }
    sort($out);
    return $out;
}
