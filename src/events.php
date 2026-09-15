<?php
/**
 * <module_context>
 *   <name>events</name>
 *   <description>GET endpoint and CLI: "what happened since seq N" (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md).
 *   Read-only, no CSRF token; nginx auth_request protects the URL on the web. The same JSON goes
 *   to stdout on the CLI (`php events.php --since=N --kinds=a,b --limit=N`), so a script or an
 *   agent needs no session cookie.</description>
 *   <dependencies>include/cc-history.php, include/cc-ledger.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/include/cc-history.php';
require_once __DIR__ . '/include/cc-ledger.php';

$isWeb = isset($_SERVER['REQUEST_METHOD']);
if ($isWeb) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $since = max(0, (int) ($_GET['since'] ?? 0));
    $kindsRaw = (string) ($_GET['kinds'] ?? '');
    $kinds = $kindsRaw !== '' ? explode(',', $kindsRaw) : [];
    $limit = (int) ($_GET['limit'] ?? 100);
} else {
    $since = 0;
    $kinds = [];
    $limit = 100;
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (preg_match('/^--since=(\d+)$/', (string) $arg, $m)) {
            $since = (int) $m[1];
        } elseif (preg_match('/^--kinds=(.+)$/', (string) $arg, $m)) {
            $kinds = explode(',', $m[1]);
        } elseif (preg_match('/^--limit=(\d+)$/', (string) $arg, $m)) {
            $limit = (int) $m[1];
        }
    }
}
$limit = max(1, min(500, $limit));

if (!is_file(CC_HISTORY_DB)) {
    if ($isWeb) {
        http_response_code(503);
    }
    echo json_encode(['error' => 'no_history']);
    exit;
}
try {
    $db = new SQLite3(CC_HISTORY_DB, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(2000);
    $result = cc_ledger_read($db, $since, $kinds, $limit);
    $result['generated_at'] = time();
    $db->close();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
} catch (Throwable $e) {
    if ($isWeb) {
        http_response_code(500);
    }
    echo json_encode(['error' => 'read_failed']);
}
