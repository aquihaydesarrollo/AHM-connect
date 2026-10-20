<?php
/**
 * Genera private/releases/manifest.json a partir de ahm-connect.json y del zip.
 * Lo usa el workflow publish-updates.yml (no se sube al servidor).
 *
 *   php server/bin/build-manifest.php ahm-connect.json dist/ahm-connect.zip > manifest.json
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc < 3) {
    fwrite(STDERR, "Uso: php build-manifest.php ahm-connect.json ahm-connect.zip\n");
    exit(2);
}

$src = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($src) || !preg_match('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/i', (string) ($src['version'] ?? ''))) {
    fwrite(STDERR, "ahm-connect.json no válido\n");
    exit(1);
}
if (!is_file($argv[2])) {
    fwrite(STDERR, "No existe el zip\n");
    exit(1);
}

$manifest = [
    'name'         => $src['name'] ?? 'AHM Connect',
    'slug'         => 'ahm-connect',
    'version'      => $src['version'],
    'author'       => $src['author'] ?? '',
    'requires'     => $src['requires'] ?? '',
    'requires_php' => $src['requires_php'] ?? '',
    'tested'       => $src['tested'] ?? '',
    'last_updated' => $src['last_updated'] ?? gmdate('Y-m-d H:i:s'),
    'sections'     => is_array($src['sections'] ?? null) ? $src['sections'] : [],
    'file'         => 'ahm-connect-' . $src['version'] . '.zip',
    'sha256'       => hash_file('sha256', $argv[2]),
    'built_at'     => gmdate('c'),
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
