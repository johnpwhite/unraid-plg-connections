<?php
/**
 * <module_context>
 *   <name>summary</name>
 *   <description>GET endpoint: the dashboard tile summary, cc_summary() of the collector's
 *   latest snapshot. The tile reads it when nchan is silent. Read-only, so no CSRF token;
 *   nginx auth_request protects it. docs/specs/DASHBOARD_TILE.md.</description>
 *   <dependencies>include/cc-summary.php</dependencies>
 * </module_context>
 */

require_once __DIR__ . '/include/cc-summary.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$raw = @file_get_contents('/tmp/unraid-connections/state.json');
$snap = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($snap)) {
    http_response_code(503);
    echo json_encode(['error' => 'collector_not_running']);
    exit;
}
echo json_encode(cc_summary($snap), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
