<?php
/**
 * <module_context>
 *   <name>test</name>
 *   <description>Test endpoint for the settings page (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md).
 *   POST rule=<id>: deliver one synthetic event through that saved rule's sink. Returns JSON {ok, message} and saves nothing, so the
 *   settings page needs no reload. local_prepend.php checks the csrf_token of each POST
 *   before this file runs; nginx auth_request protects the URL.</description>
 *   <dependencies>include/cc-notify.php, include/cc-subs.php</dependencies>
 * </module_context>
 */

require_once __DIR__ . '/include/cc-notify.php';
require_once __DIR__ . '/include/cc-subs.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$out = static function (bool $ok, string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message]);
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $out(false, 'POST only.', 405);
    return;
}
$id = (string) ($_POST['rule'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,32}$/', $id)) {
    $out(false, 'The rule id is not valid.', 400);
    return;
}
$rule = null;
foreach (cc_subs_read() as $candidate) {
    if (($candidate['id'] ?? '') === $id) {
        $rule = $candidate;
        break;
    }
}
if ($rule === null) {
    $out(false, 'No saved rule has this id. Press Apply first.', 404);
    return;
}
$sink = (string) ($rule['sink'] ?? 'notify');
$done = cc_subs_deliver($rule, cc_subs_test_event($rule, time()));
if (!$done) {
    $out(false, $sink === 'script' ? 'The script is missing from the event.d folder.' : "Unraid's notify script is missing.", 409);
    return;
}
$out(true, ['notify' => 'A test notification was sent.', 'syslog' => 'A test line was written to syslog.', 'script' => 'The script was started. See scripts.log for its output.'][$sink] ?? 'The test event was delivered.');
