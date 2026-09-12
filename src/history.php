<?php
/**
 * <module_context>
 *   <name>history</name>
 *   <description>GET endpoint: the history of one client (ended sessions and sign-in events
 *   from history.db) for the client history panel. Read-only, so no CSRF token; nginx
 *   auth_request protects it. docs/specs/HISTORY.md.</description>
 *   <dependencies>include/cc-history.php, include/cc-config.php</dependencies>
 * </module_context>
 */

require_once '/usr/local/emhttp/plugins/unraid-connections/include/cc-history.php';
require_once '/usr/local/emhttp/plugins/unraid-connections/include/cc-config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$ip = (string) ($_GET['ip'] ?? '');
if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_ip']);
    exit;
}
if (!is_file(CC_HISTORY_DB)) {
    http_response_code(503);
    echo json_encode(['error' => 'no_history']);
    exit;
}
$since = time() - (int) cc_config_read()['history_days'] * 86400;
try {
    $db = new SQLite3(CC_HISTORY_DB, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);
    echo json_encode([
        'ip'       => $ip,
        'since'    => $since,
        'sessions' => cc_history_ended($db, $since, $ip, 500),
        'events'   => cc_history_events($db, $since, 500, $ip),
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    $db->close();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'read_failed']);
}
