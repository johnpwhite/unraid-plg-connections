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
 *   Each poll also goes into the history database (HISTORY.md), is checked for alerts
 *   (NOTIFICATIONS.md), and is turned into ledger events that user subscriptions can match
 *   (EVENTS_AND_SUBSCRIPTIONS.md).</description>
 *   <dependencies>../include/cc-snapshot.php, ../include/cc-history.php, ../include/cc-notify.php, ../include/cc-ledger.php, ../include/cc-subs.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/include/cc-snapshot.php';
require_once dirname(__DIR__) . '/include/cc-history.php';
require_once dirname(__DIR__) . '/include/cc-notify.php';
require_once dirname(__DIR__) . '/include/cc-ledger.php';
require_once dirname(__DIR__) . '/include/cc-subs.php';

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
    cc_ledger_schema($db);
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
// Events and subscriptions (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md). The first poll after a
// start only seeds the live-session and source sets (R9): it raises no session/source event.
$firstPoll = true;
$prevLive = [];
$prevSources = [];
cc_subs_seed(is_readable(CC_CONFIG_FILE) ? (@parse_ini_file(CC_CONFIG_FILE) ?: []) : []);   // the three default rules, once
$rules = cc_subs_read();
$rulesMtime = (int) @filemtime(CC_SUBS_FILE);
$subsState = cc_subs_state_read();
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
    clearstatcache(true, CC_SUBS_FILE);
    $rm = (int) @filemtime(CC_SUBS_FILE);
    if ($rm !== $rulesMtime) {   // subscriptions.json saved: reload without a restart
        $rules = cc_subs_read();
        $rulesMtime = $rm;
    }
    $interval = (int) $config['poll_interval'];
    try {
        $snap = cc_snapshot($state, $config);
        if ($db instanceof SQLite3) {
            $poll++;
            $now = (int) $snap['generated_at'];
            $host = (string) ($snap['host']['name'] ?? '');
            $clients = $snap['clients'] ?? [];
            [$logEvents] = cc_parse_syslog(cc_events_log_read($state), $now);
            $newEvents = array_merge($snap['events'], $logEvents);
            $inserted = null;
            cc_history_record_events($db, $newEvents, $inserted);
            $items = cc_history_session_items($snap, $now);
            cc_history_record_sessions($db, $items, $poll);
            $newIps = cc_notify_new_clients($items, $newEvents, $known, $clients);
            $known += array_fill_keys(array_keys($newIps), true);

            // Events and subscriptions (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md). The seed poll
            // (first after a start, or the first with a brand-new database) raises no
            // session/source/detection event, so a restart never re-fires them (R9).
            $nowLive = cc_ledger_live($items);
            $sessionEvents = $firstPoll ? [] : cc_ledger_session_events($prevLive, $nowLive, $clients, $host, $now);
            $signinEvents = cc_ledger_signin_events($inserted ?? [], $clients, $host, $alertSince);
            $notifyEvents = $seedOnly ? [] : cc_notify_events($db, $config, $newIps, $newEvents, $clients, $now, $alertSince);
            $sourceEvents = cc_ledger_source_events($prevSources, $snap['sources'] ?? []);
            $pollEvents = array_merge($sessionEvents, $signinEvents, $notifyEvents, $sourceEvents);
            $appended = cc_ledger_append($db, $pollEvents);
            if ($appended !== []) {
                cc_ledger_publish($appended);
            }
            $subsBefore = json_encode($subsState);
            cc_subs_dispatch($db, $rules, $appended, $clients, $now, $subsState);
            if (json_encode($subsState) !== $subsBefore) {
                cc_subs_state_write($subsState);
            }
            $prevLive = $nowLive;
            $prevSources = $snap['sources'] ?? [];
            $firstPoll = false;

            $seedOnly = false;
            $alertSince = $now - 60;   // overlap one minute; the alerts table stops a second copy
            $days = (int) $config['history_days'];
            if ($now - $lastPrune >= 3600) {
                cc_history_prune($db, $days, $now);
                cc_notify_prune($db, $now);
                cc_ledger_prune($db, $days, $now);
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
            $ledgerHead = cc_ledger_head($db);
            $snap['ledger'] = [
                'ok'        => true,
                'head'      => $ledgerHead['head'],
                'oldest'    => $ledgerHead['oldest'],
                'count_24h' => $ledgerHead['count_24h'],
                'recent'    => cc_ledger_recent($db, 200),
            ];
            $snap['notify'] = [
                'sent_24h' => (int) $db->querySingle('SELECT COUNT(*) FROM alerts WHERE t >= ' . ($now - 86400)),
                'rules'    => array_map(static function (array $r) use ($subsState): array {
                    $st = $subsState[$r['id']] ?? [];
                    return ['id' => $r['id'], 'name' => $r['name'], 'on' => $r['on'], 'sink' => $r['sink'],
                        'last' => $st['last'] ?? null, 'fired' => (int) ($st['fired'] ?? 0)];
                }, $rules),
            ];
            if ($now - $lastFlush >= 1800) {
                $flush();
            }
        } else {
            $snap['history'] = ['ok' => false, 'ended' => []];
            $snap['ledger'] = ['ok' => false, 'head' => 0, 'oldest' => 0, 'count_24h' => 0, 'recent' => []];
            $snap['notify'] = ['sent_24h' => 0, 'rules' => []];   // the alerts table is in the database
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
