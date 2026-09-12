#!/usr/bin/php -q
<?php
/**
 * <module_context>
 *   <name>collector</name>
 *   <description>Connected Clients collector daemon. Every 5 seconds it takes one read-only
 *   snapshot and writes it atomically to /tmp/unraid-connections/state.json. It keeps the
 *   client IP of each webGUI session from the time the session is new, in session-ips.json.
 *   A non-blocking lock on /tmp (never FUSE) stops a second copy. It reloads settings.ini
 *   when the file changes, so a settings save needs no restart (docs/specs/SETTINGS.md).
 *   Each poll also goes into the history database (HISTORY.md) and is checked for alerts
 *   (NOTIFICATIONS.md).</description>
 *   <dependencies>../include/cc-snapshot.php, ../include/cc-history.php, ../include/cc-notify.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/include/cc-snapshot.php';
require_once dirname(__DIR__) . '/include/cc-history.php';
require_once dirname(__DIR__) . '/include/cc-notify.php';

$run = '/tmp/unraid-connections';

if (!is_dir($run) && !mkdir($run, 0700, true) && !is_dir($run)) {
    fwrite(STDERR, "Cannot create $run.\n");
    exit(1);
}
$lock = fopen("$run/collector.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another collector is running.\n");
    exit(0);
}
file_put_contents("$run/collector.pid", (string) getmypid());

$state = ['known' => json_decode((string) @file_get_contents("$run/session-ips.json"), true) ?: []];
$config = cc_config_read();
$cfgMtime = (int) @filemtime(CC_CONFIG_FILE);
$lastError = '';

// History (docs/specs/HISTORY.md): bring back the flash copy after a reboot, then open the RAM database.
$restored = cc_history_restore();
$db = null;
try {
    $db = cc_history_open();
    cc_notify_schema($db);
} catch (Throwable $e) {
    exec('logger -t unraid-connections ' . escapeshellarg('history database unavailable: ' . $e->getMessage()));
}
// Notifications (docs/specs/NOTIFICATIONS.md): the addresses the history knows. With a new
// database, the first poll only learns the clients that are connected (no flood of alerts).
$known = $db instanceof SQLite3 ? cc_notify_known_ips($db) : [];
$seedOnly = $known === [];
$alertSince = time();
$poll = 0;
$lastFlush = time();
$lastPrune = 0;
$flushSig = '';
$flushWarned = false;
// Copy to flash only when sessions or events changed (last_seen-only updates do not count: flash wear).
// A failed copy (flash full or read-only) is logged once; history stays in RAM.
$flush = static function () use (&$db, &$lastFlush, &$flushSig, &$flushWarned): void {
    if (!$db instanceof SQLite3) {
        return;
    }
    $why = '';
    try {   // also runs from the signal handler and after the loop, outside the loop's try
        $sig = $db->querySingle("SELECT COUNT(*) || ':' || COALESCE(MAX(start), 0) || ':' || COUNT(end) FROM sessions") . '/' . $db->querySingle('SELECT COUNT(*) FROM events');
        if ($sig !== $flushSig) {
            if (cc_history_flush($db)) {
                $flushSig = $sig;
                $flushWarned = false;
            } else {
                $why = 'the copy to ' . CC_HISTORY_FLASH . ' failed';
            }
        }
    } catch (Throwable $e) {
        $why = $e->getMessage();
    }
    if ($why !== '' && !$flushWarned) {
        exec('logger -t unraid-connections ' . escapeshellarg("history not copied to flash ($why); it stays in RAM"));
        $flushWarned = true;
    }
    $lastFlush = time();
};
if (function_exists('pcntl_async_signals')) {   // rc stop (upgrade, uninstall) sends SIGTERM: save history first
    pcntl_async_signals(true);
    $stopNow = static function () use (&$flush): void {
        $flush();
        exit(0);
    };
    pcntl_signal(SIGTERM, $stopNow);
    pcntl_signal(SIGINT, $stopNow);
}
exec('logger -t unraid-connections ' . escapeshellarg('collector started (PID ' . getmypid() . ')' . ($restored ? '; history restored from flash' : '')));

// Run until the plugin files are gone (an uninstall that did not stop this process).
while (is_file(__FILE__)) {
    $t0 = microtime(true);
    clearstatcache(true, CC_CONFIG_FILE);
    $m = (int) @filemtime(CC_CONFIG_FILE);
    if ($m !== $cfgMtime) {   // settings saved: reload without a restart
        $config = cc_config_read();
        $cfgMtime = $m;
        exec('logger -t unraid-connections ' . escapeshellarg('collector reloaded the settings'));
    }
    $interval = (int) $config['poll_interval'];
    try {
        $snap = cc_snapshot($state, $config);
        if ($db instanceof SQLite3) {
            $poll++;
            $now = (int) $snap['generated_at'];
            [$logEvents] = cc_parse_syslog(cc_events_log_read($state), $now);
            $newEvents = array_merge($snap['events'], $logEvents);
            cc_history_record_events($db, $newEvents);
            $items = cc_history_session_items($snap, $now);
            cc_history_record_sessions($db, $items, $poll);
            $newIps = cc_notify_new_clients($items, $newEvents, $known, $snap['clients'] ?? []);
            $known += array_fill_keys(array_keys($newIps), true);
            if (!$seedOnly) {
                foreach (cc_notify_alerts($db, $config, $newIps, $newEvents, $snap['clients'] ?? [], $now, $alertSince) as $alert) {
                    cc_notify_send($alert);
                }
            }
            $seedOnly = false;
            $alertSince = $now - 60;   // overlap one minute; the alerts table stops a second copy
            $days = (int) $config['history_days'];
            if ($now - $lastPrune >= 3600) {
                cc_history_prune($db, $days, $now);
                cc_notify_prune($db, $now);
                $lastPrune = $now;
            }
            $snap['events'] = array_values(array_filter(cc_history_events($db, $now - $days * 86400, 2000),
                static fn($e) => ($config['adapter_' . $e['proto']] ?? 'yes') !== 'no'));
            $present = array_flip(array_map(static fn($i) => $i[0] . '|' . $i[1], $items));
            $snap['history'] = [
                'ok'         => true,
                'days'       => $days,
                'restored'   => $restored,
                'flushed_at' => $lastFlush,
                'ended'      => array_values(array_filter(cc_history_ended($db, $now - 72 * 3600, null, 2000),
                    static fn($h) => !isset($present[$h['proto'] . '|' . $h['skey']]))),
            ];
            $snap['notify'] = [
                'on'       => array_values(array_filter(['new_client', 'failed', 'ssh_public'], static fn($k) => ($config["notify_$k"] ?? 'yes') === 'yes')),
                'sent_24h' => (int) $db->querySingle('SELECT COUNT(*) FROM alerts WHERE t >= ' . ($now - 86400)),
            ];
            if ($now - $lastFlush >= 1800) {
                $flush();
            }
        } else {
            $snap['history'] = ['ok' => false, 'ended' => []];
            $snap['notify'] = ['on' => [], 'sent_24h' => 0];   // the alerts table is in the database
        }
        $snap['collector'] = ['pid' => getmypid(), 'interval' => $interval, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000)];
        cc_write_atomic("$run/state.json", (string) json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
        cc_write_atomic("$run/session-ips.json", (string) json_encode($state['known']));
        $lastError = '';
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if ($msg !== $lastError) {   // log each new error once, not every 5 seconds
            exec('logger -t unraid-connections ' . escapeshellarg("collector error: $msg"));
            $lastError = $msg;
        }
    }
    $sleep = $interval - (microtime(true) - $t0);
    if ($sleep > 0) {
        usleep((int) ($sleep * 1000000));
    }
}
$flush();
exec('logger -t unraid-connections ' . escapeshellarg('collector stopped: the plugin files are gone'));
