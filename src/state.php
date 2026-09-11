<?php
/**
 * <module_context>
 *   <name>state</name>
 *   <description>GET endpoint for the Connected Clients page. Returns the collector's latest
 *   snapshot as JSON. It reads one file and changes nothing, so it needs no CSRF token.
 *   nginx auth_request protects it like every webGUI path.</description>
 *   <dependencies>/tmp/unraid-connections/state.json (written by scripts/collector.php)</dependencies>
 * </module_context>
 */

$file = '/tmp/unraid-connections/state.json';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!is_readable($file)) {
    http_response_code(503);
    echo json_encode(['error' => 'collector_not_running']);
    exit;
}
readfile($file);
