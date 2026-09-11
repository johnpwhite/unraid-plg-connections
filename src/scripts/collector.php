#!/usr/bin/php -q
<?php
/**
 * <module_context>
 *   <name>collector</name>
 *   <description>Connected Clients collector daemon. Every 5 seconds it takes one read-only
 *   snapshot and writes it atomically to /tmp/unraid-connections/state.json. It keeps the
 *   client IP of each webGUI session from the time the session is new, in session-ips.json.
 *   A non-blocking lock on /tmp (never FUSE) stops a second copy.</description>
 *   <dependencies>../include/cc-snapshot.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/include/cc-snapshot.php';

$run = '/tmp/unraid-connections';
$interval = 5;

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
$lastError = '';
exec('logger -t unraid-connections ' . escapeshellarg('collector started (PID ' . getmypid() . ')'));

// Run until the plugin files are gone (an uninstall that did not stop this process).
while (is_file(__FILE__)) {
    $t0 = microtime(true);
    try {
        $snap = cc_snapshot($state);
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
exec('logger -t unraid-connections ' . escapeshellarg('collector stopped: the plugin files are gone'));
