<?php
/**
 * POST /api/register.php
 * Cuerpo JSON (o formulario): { home_url, site_secret, plugin_version, wp_version }
 *
 * Alta de una web. El dominio (host sin www) es la identidad. La propiedad se
 * comprueba llamando de vuelta a la web (ver ahmc_verify_ownership). Reglas:
 *   - nuevo + verificado + preaprobado        → approved
 *   - nuevo (resto)                           → pending
 *   - mismo secret                            → refresca datos y devuelve su estado
 *   - otro secret y approved/revoked          → 409 conflict, no se sobrescribe
 *     (anti-secuestro; el admin decide con admin.php reset)
 *   - otro secret y pending                   → solo se sustituye si el nuevo
 *     está verificado o el anterior no lo estaba
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';

ahmc_boot('register');
ahmc_require_method(['POST']);
ahmc_rate_limit('register');

$raw = file_get_contents('php://input', false, null, 0, 8192) ?: '';
$in = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') ? json_decode($raw, true) : $_POST;
if (!is_array($in)) {
    ahmc_json(['error' => 'bad_request'], 400);
}
$str = static fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';

$home   = ahmc_clean_home_url($str('home_url'));
$secret = strtolower($str('site_secret'));
$pv     = $str('plugin_version');
$wv     = $str('wp_version');
$domain = $home !== '' ? ahmc_normalize_domain($home) : '';

if ($domain === '' || !preg_match('/^[a-f0-9]{64}$/', $secret)
    || ($pv !== '' && !preg_match('/^[0-9][0-9a-z.\-]{0,30}$/i', $pv))
    || ($wv !== '' && !preg_match('/^[0-9][0-9a-z.\-]{0,30}$/i', $wv))) {
    ahmc_log('register', 400, $domain ?: null, 'bad_request');
    ahmc_json(['error' => 'bad_request'], 400);
}

$cfg = ahmc_config();
$preapproved = ahmc_is_preapproved($domain);
$current = ahmc_sites()[$domain] ?? null;
$currentSecret = is_array($current) ? ahmc_decrypt_secret((string) ($current['secret'] ?? '')) : null;
$sameSecret = $currentSecret !== null && hash_equals($currentSecret, $secret);

// La verificación (petición saliente) se hace fuera del bloqueo de sites.json.
$needVerify = !$sameSecret || empty($current['verified']);
$verified = $sameSecret && !empty($current['verified']);
if ($needVerify) {
    $verified = $cfg['verify_callbacks'] ? ahmc_verify_ownership($home, $secret) : false;
}

$ip = ahmc_client_ip();
$now = gmdate('c');

$result = ahmc_sites(static function (array &$sites) use ($domain, $home, $secret, $pv, $wv, $verified, $preapproved, $ip, $now, $cfg): array {
    $cur = $sites[$domain] ?? null;
    $curSecret = is_array($cur) ? ahmc_decrypt_secret((string) ($cur['secret'] ?? '')) : null;

    $fresh = static fn(): array => [
        'domain'         => $domain,
        'home_url'       => $home,
        'secret'         => ahmc_encrypt_secret($secret),
        'status'         => ($verified && $preapproved) ? 'approved' : 'pending',
        'verified'       => $verified,
        'plugin_version' => $pv ?: null,
        'wp_version'     => $wv ?: null,
        'registered_at'  => $now,
        'register_ip'    => $ip,
        'approved_at'    => ($verified && $preapproved) ? $now : null,
        'approved_by'    => ($verified && $preapproved) ? 'preapproved' : null,
        'last_seen'      => $now,
        'last_ip'        => $ip,
    ];

    // Alta nueva.
    if (!is_array($cur)) {
        if (!$verified) {
            $unverified = count(array_filter($sites, static fn($s) => empty($s['verified'])));
            if ($unverified >= (int) $cfg['max_unverified']) {
                return [503, ['error' => 'busy'], 'too_many_unverified'];
            }
        }
        $sites[$domain] = $fresh();
        return [200, ['status' => $sites[$domain]['status'], 'verified' => $verified], 'new'];
    }

    // Misma web (mismo secret): refrescar datos.
    if ($curSecret !== null && hash_equals($curSecret, $secret)) {
        $sites[$domain]['home_url'] = $home;
        $sites[$domain]['plugin_version'] = $pv ?: ($cur['plugin_version'] ?? null);
        $sites[$domain]['wp_version'] = $wv ?: ($cur['wp_version'] ?? null);
        $sites[$domain]['last_seen'] = $now;
        $sites[$domain]['last_ip'] = $ip;
        if ($verified && empty($cur['verified'])) {
            $sites[$domain]['verified'] = true;
            if (($cur['status'] ?? '') === 'pending' && $preapproved) {
                $sites[$domain]['status'] = 'approved';
                $sites[$domain]['approved_at'] = $now;
                $sites[$domain]['approved_by'] = 'preapproved';
            }
        }
        return [200, ['status' => $sites[$domain]['status'], 'verified' => (bool) $sites[$domain]['verified']], 'same_secret'];
    }

    // Otro secret sobre una web aprobada o revocada: no se toca (anti-secuestro).
    if (in_array($cur['status'] ?? '', ['approved', 'revoked'], true)) {
        if ($verified) {
            // Se guarda como candidato para que el admin lo vea en "list"/"show".
            $sites[$domain]['candidate'] = [
                'secret' => ahmc_encrypt_secret($secret), 'home_url' => $home,
                'plugin_version' => $pv ?: null, 'at' => $now, 'ip' => $ip,
            ];
        }
        return [409, ['status' => 'conflict'], 'conflict_' . ($verified ? 'verified' : 'unverified')];
    }

    // Otro secret sobre una web pendiente.
    if ($verified || empty($cur['verified'])) {
        $sites[$domain] = $fresh();
        return [200, ['status' => $sites[$domain]['status'], 'verified' => $verified], 'replaced_pending'];
    }
    return [409, ['status' => 'conflict'], 'conflict_pending_verified'];
});

[$code, $body, $note] = $result;
ahmc_log('register', $code, $domain, $note . ($verified ? ' verified' : ' unverified'));
ahmc_json($body + ['server_time' => time()], $code);
