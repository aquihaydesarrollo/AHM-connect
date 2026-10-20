<?php
/**
 * GET|POST /api/update.php — consulta de actualización firmada.
 *
 * Cabeceras: X-AHMC-Site (home_url), X-AHMC-Timestamp, X-AHMC-Signature
 * (HMAC-SHA256 con el site_secret de METODO|REQUEST_URI|timestamp|home_url),
 * X-AHMC-Version (informativa). También se aceptan home_url/timestamp/signature
 * como parámetros.
 *
 * Sin firma válida → 401 {"error":"unauthorized"} y nada más.
 * Firmada pero no aprobada → {"status":"pending"|"revoked"} sin descarga.
 * Aprobada → manifiesto con download_url.
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';

ahmc_boot('update');
ahmc_require_method(['GET', 'POST']);
ahmc_rate_limit('update');

[$domain, $site] = ahmc_authenticate('update');

$status = (string) ($site['status'] ?? 'pending');
if ($status !== 'approved') {
    ahmc_log('update', 200, $domain, 'status_' . $status);
    ahmc_json([
        'status'  => $status === 'revoked' ? 'revoked' : 'pending',
        'message' => $status === 'revoked'
            ? 'Esta web no tiene acceso a las actualizaciones de AHM Connect.'
            : 'Web registrada. Pendiente de aprobación por Aquí Hay Marketing.',
    ]);
}

$m = ahmc_manifest();
if ($m === null) {
    ahmc_log('update', 503, $domain, 'no_manifest');
    ahmc_json(['error' => 'no_release'], 503);
}

$zip = ahmc_release_path($m['version']);
$pick = static fn(string $k) => isset($m[$k]) && (is_string($m[$k]) || is_array($m[$k])) ? $m[$k] : '';

ahmc_log('update', 200, $domain, 'approved v' . $m['version']);
ahmc_json([
    'status'        => 'approved',
    'name'          => $pick('name') ?: 'AHM Connect',
    'slug'          => 'ahm-connect',
    'version'       => $m['version'],
    'author'        => $pick('author'),
    'homepage'      => ahmc_config()['public_base_url'],
    'requires'      => $pick('requires'),
    'requires_php'  => $pick('requires_php'),
    'tested'        => $pick('tested'),
    'last_updated'  => $pick('last_updated'),
    'sections'      => is_array($m['sections'] ?? null) ? $m['sections'] : [],
    'download_url'  => ahmc_download_url($m['version']),
    'package_sha256'=> hash_file('sha256', (string) $zip),
]);
