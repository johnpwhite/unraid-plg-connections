<?php
/**
 * <module_context>
 *   <name>save</name>
 *   <description>Save endpoint for the settings page (docs/specs/SETTINGS_PAGE_DESIGN.md). The page
 *   posts its form here in the background and shows the JSON answer {ok, message}, so the page
 *   itself is never the result of a POST and a browser refresh never asks to resend the form.
 *   POST cc_restore_defaults=1 puts back any missing default rule.
 *   local_prepend.php checks the csrf_token of each POST before this file runs; nginx
 *   auth_request protects the URL.</description>
 *   <dependencies>include/cc-settings.php</dependencies>
 * </module_context>
 */

require_once __DIR__ . '/include/cc-settings.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST only.']);
    return;
}
if (isset($_POST['cc_restore_defaults'])) {   // put back any missing default rule; the page then loads again
    $n = cc_subs_restore_defaults();
    echo json_encode(['ok' => true, 'message' => $n > 0 ? "$n default rule(s) restored." : 'Every default rule is already there.', 'added' => $n]);
    return;
}
$res = cc_settings_apply($_POST);
if (!$res['ok']) {
    http_response_code(500);
}
echo json_encode(['ok' => $res['ok'], 'message' => $res['message']]);
