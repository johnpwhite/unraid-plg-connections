#!/usr/bin/php -q
<?php
/**
 * <module_context>
 *   <name>history-flush</name>
 *   <description>Copy history.db to flash now. event/stopping runs it when the array stops
 *   (also part of a shutdown), so the last minutes of history survive the reboot.</description>
 *   <dependencies>../include/cc-history.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/include/cc-history.php';

if (!is_file(CC_HISTORY_DB)) {
    exit(0);
}
$db = new SQLite3(CC_HISTORY_DB);
$db->busyTimeout(5000);
$ok = cc_history_flush($db);
$db->close();
exec('logger -t unraid-connections ' . escapeshellarg($ok ? 'history copied to flash' : 'history copy to flash failed'));
exit($ok ? 0 : 1);
