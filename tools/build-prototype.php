#!/usr/bin/php -q
<?php
/**
 * build-prototype.php - take a fresh snapshot and write the design prototype page.
 *
 * It runs tools/snapshot.php (read-only), removes the veth and link-local
 * interfaces, and puts the JSON into prototype/connected-clients.template.html.
 *
 * Output: prototype/connected-clients.html. Git ignores it, because it holds
 * real client addresses, MAC addresses and device names.
 *
 * Usage (as root on the Unraid server): php tools/build-prototype.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$data = json_decode((string) shell_exec('php ' . escapeshellarg("$root/tools/snapshot.php")), true);
if (!is_array($data)) {
    fwrite(STDERR, "tools/snapshot.php did not return JSON.\n");
    exit(1);
}

// veth and link-local interfaces are noise for the page.
$data['host']['interfaces'] = array_filter(
    $data['host']['interfaces'] ?? [],
    fn($name, $addr) => !str_starts_with((string) $name, 'veth') && !str_starts_with((string) $addr, 'fe80'),
    ARRAY_FILTER_USE_BOTH
);

// JSON_HEX_TAG escapes < and >, so the data cannot close the <script> element.
$payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

$template = (string) file_get_contents("$root/prototype/connected-clients.template.html");
if (substr_count($template, '__SNAPSHOT_JSON__') !== 1) {
    fwrite(STDERR, "The template must contain __SNAPSHOT_JSON__ exactly once.\n");
    exit(1);
}

$out = "$root/prototype/connected-clients.html";
file_put_contents($out, str_replace('__SNAPSHOT_JSON__', (string) $payload, $template));
echo "Wrote $out\n";
