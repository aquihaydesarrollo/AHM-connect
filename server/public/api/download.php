<?php
/**
 * GET /api/download.php?v=<versión> — descarga firmada del zip.
 *
 * Mismas cabeceras firmadas que update.php (la ruta firmada incluye ?v=...).
 * Solo webs aprobadas. Nunca expone la ruta real del fichero.
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';

ahmc_boot('download');
ahmc_require_method(['GET']);
ahmc_rate_limit('download');

$denied = static function (string $note, ?string $domain = null): never {
    ahmc_log('download', 403, $domain, $note);
    ahmc_json(['error' => 'forbidden'], 403);
};

$c = ahmc_request_credentials();
if ($c['signature'] === '') {
    $denied('unsigned');
}

[$domain, $site] = ahmc_authenticate('download');
if (($site['status'] ?? '') !== 'approved') {
    $denied('status_' . ($site['status'] ?? 'unknown'), $domain);
}

$version = is_string($_GET['v'] ?? null) ? $_GET['v'] : '';
$file = ahmc_release_path($version);
if ($file === null) {
    ahmc_log('download', 404, $domain, 'no_version');
    ahmc_json(['error' => 'not_found'], 404);
}

ahmc_log('download', 200, $domain, 'v' . $version);
ahmc_nocache_headers();
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="ahm-connect.zip"');
header('X-AHMC-SHA256: ' . hash_file('sha256', $file));
readfile($file);
exit;
