<?php
/**
 * <module_context>
 *   <name>action</name>
 *   <description>Actions endpoint (docs/specs/ACTIONS.md). GET: the viewer's own session tag
 *   and whether actions are on. POST (action, target): sign out a web UI session, end an SSH
 *   session or close an SMB session. local_prepend.php checks the csrf_token of each POST
 *   before this file runs; nginx auth_request protects the URL. Every request is checked
 *   against the latest snapshot and the process table (cc_action_plan).</description>
 *   <dependencies>include/cc-actions.php, include/cc-config.php, include/cc-ledger.php</dependencies>
 * </module_context>
 */

require_once __DIR__ . '/include/cc-actions.php';
require_once __DIR__ . '/include/cc-config.php';
require_once __DIR__ . '/include/cc-ledger.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$ownTag = cc_action_own_tag($_COOKIE, session_name());
$enabled = cc_config_read()['actions'] === 'yes';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['own_tag' => $ownTag, 'enabled' => $enabled]);
    exit;
}
if (!$enabled) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'disabled', 'message' => 'Actions are turned off in the settings.']);
    exit;
}
$raw = @file_get_contents('/tmp/unraid-connections/state.json');
$snap = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($snap)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'collector_not_running', 'message' => 'The collector is not running.']);
    exit;
}
$plan = cc_action_plan((string) ($_POST['action'] ?? ''), (string) ($_POST['target'] ?? ''), $snap, $ownTag, 'cc_action_proc');
if (!$plan['ok']) {
    http_response_code($plan['error'] === 'not_found' ? 404 : 400);
    echo json_encode($plan);
    exit;
}
$actor = 'the webGUI from ' . (filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'the server');
$res = cc_action_run($plan, $actor);
if (!$res['ok']) {
    http_response_code(409);
} else {
    try {
        $done = ['web_logout' => 'Signed out', 'ssh_end' => 'Ended', 'smb_close' => 'Closed'][$plan['action']] ?? 'Done';
        cc_ledger_append_direct([cc_ledger_action_event($plan, $actor, $done, time())]);
    } catch (Throwable $e) {
        // Best-effort: the action already succeeded; the ledger is not on the critical path.
    }
}
echo json_encode($res);
