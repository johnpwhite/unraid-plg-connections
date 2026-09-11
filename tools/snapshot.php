#!/usr/bin/php -q
<?php
/**
 * snapshot.php - one read-only snapshot of each Connected Clients data source, as JSON.
 *
 * It uses the same adapters as the plugin collector (src/include/cc-snapshot.php).
 * It changes nothing. It never outputs a full PHP session ID, because the ID is the
 * login cookie value; it outputs an 8-character SHA-256 tag instead.
 *
 * Usage (as root on the Unraid server): php tools/snapshot.php > snapshot.json
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/include/cc-snapshot.php';

$state = [];
echo json_encode(cc_snapshot($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
