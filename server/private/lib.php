<?php
/**
 * AHM Connect — servidor de actualizaciones (connect.aquihaymarketing.es)
 *
 * Bootstrap común. Lo incluyen los scripts de public/api con
 *     require __DIR__ . '/../../private/lib.php';
 * y admin.php (CLI). Vive FUERA del docroot: nunca se sirve por web.
 *
 * PHP 8.3 puro, sin dependencias. Extensiones: openssl, json (y curl si está;
 * si no, la verificación de dominio usa streams).
 */

declare(strict_types=1);

if (!defined('AHMC_PRIVATE')) {
    define('AHMC_PRIVATE', __DIR__);
}
define('AHMC_DATA', AHMC_PRIVATE . '/data');
define('AHMC_RELEASES', AHMC_PRIVATE . '/releases');
define('AHMC_LOG_DIR', AHMC_DATA . '/log');
define('AHMC_SITES_FILE', AHMC_DATA . '/sites.json');
define('AHMC_SIG_VERSION', 'v1');

// Nunca enseñar errores PHP por pantalla; van al log privado.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ini_set('error_log', AHMC_LOG_DIR . '/php-error.log');
umask(0077); // ficheros 600 y carpetas 700 por defecto

// ─────────────────────────────────────────────────────────────────────────────
// Configuración
// ─────────────────────────────────────────────────────────────────────────────

function ahmc_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = AHMC_PRIVATE . '/config.php';
    $loaded = is_file($file) ? require $file : null;
    if (!is_array($loaded) || !is_string($loaded['server_secret'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $loaded['server_secret'])) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Falta private/config.php o server_secret no es válido (64 caracteres hex).\n");
            exit(2);
        }
        ahmc_json(['error' => 'not_configured'], 503);
    }
    $defaults = [
        'public_base_url'        => 'https://connect.aquihaymarketing.es',
        'preapproved_domains'    => [],
        'contact_to'             => '',
        'contact_from'           => 'AHM Connect <no-reply@aquihaymarketing.es>',
        'contact_subject'        => 'Contacto desde connect.aquihaymarketing.es',
        'trusted_proxies'        => [],
        'rate_limits'            => ['update' => 60, 'download' => 30, 'register' => 10, 'contact' => 5],
        'signature_window'       => 300,
        'verify_callbacks'       => true,
        'callback_timeout'       => 10,
        'callback_allow_http'    => false, // solo pruebas locales
        'callback_allow_private' => false, // solo pruebas locales
        'max_unverified'         => 200,
    ];
    $cfg = array_replace($defaults, $loaded);
    $cfg['rate_limits'] = array_replace($defaults['rate_limits'], (array) ($loaded['rate_limits'] ?? []));
    $cfg['public_base_url'] = rtrim((string) $cfg['public_base_url'], '/');
    $cfg['preapproved_domains'] = array_values(array_unique(array_filter(array_map(
        static fn($d) => ahmc_normalize_domain((string) $d),
        (array) $cfg['preapproved_domains']
    ))));
    return $cfg;
}

function ahmc_ensure_dirs(): void
{
    foreach ([AHMC_DATA, AHMC_LOG_DIR, AHMC_DATA . '/ratelimit', AHMC_RELEASES] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Respuestas
// ─────────────────────────────────────────────────────────────────────────────

function ahmc_nocache_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header_remove('X-Powered-By');
}

/** Envía JSON y termina. */
function ahmc_json(array $data, int $status = 200): never
{
    ahmc_nocache_headers();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** 401 genérico: no da pistas de por qué (dominio, firma o reloj). */
function ahmc_deny(string $endpoint, string $reason, ?string $domain = null): never
{
    ahmc_log($endpoint, 401, $domain, $reason);
    ahmc_json(['error' => 'unauthorized'], 401);
}

function ahmc_require_method(array $allowed): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        ahmc_json(['error' => 'method_not_allowed'], 405);
    }
    return $method;
}

/** Lanza el manejador común de errores: 500 genérico y detalle al log. */
function ahmc_boot(string $endpoint): void
{
    ahmc_ensure_dirs();
    set_exception_handler(static function (Throwable $e) use ($endpoint): void {
        error_log('[' . $endpoint . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            ahmc_nocache_headers();
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":"server_error"}';
        }
        exit(1);
    });
    ahmc_config();
}

// ─────────────────────────────────────────────────────────────────────────────
// IP del cliente (Cloudflare) y límite de peticiones
// ─────────────────────────────────────────────────────────────────────────────

function ahmc_ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton((string) $net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
    $bytes = intdiv($bits, 8);
    if (strncmp($ipBin, $netBin, $bytes) !== 0) {
        return false;
    }
    $rem = $bits % 8;
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

/**
 * IP real. Solo se cree CF-Connecting-IP / X-Forwarded-For si REMOTE_ADDR es
 * un proxy de confianza (rangos de Cloudflare en config.php). Si el servidor
 * ya traduce la IP (mod_remoteip / LiteSpeed), REMOTE_ADDR ya es la del cliente.
 */
function ahmc_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trusted = false;
    foreach ((array) ahmc_config()['trusted_proxies'] as $cidr) {
        if (ahmc_ip_in_cidr($remote, (string) $cidr)) {
            $trusted = true;
            break;
        }
    }
    if ($trusted) {
        $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $xff = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if ($xff !== '' && filter_var($xff, FILTER_VALIDATE_IP)) {
            return $xff;
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

/** Ventana fija de 60 s (o $window) por IP y cubo, en ficheros con flock. */
function ahmc_rate_limit(string $bucket, ?int $limit = null, int $window = 60, ?callable $onExceeded = null): void
{
    $limit ??= (int) (ahmc_config()['rate_limits'][$bucket] ?? 60);
    if ($limit <= 0) {
        return;
    }
    $dir = AHMC_DATA . '/ratelimit';
    $file = $dir . '/' . hash('sha256', $bucket . '|' . ahmc_client_ip()) . '.json';
    $now = time();
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return; // sin disco no se bloquea el servicio
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $st = json_decode($raw ?: '', true);
    if (!is_array($st) || ($st['start'] ?? 0) + $window <= $now) {
        $st = ['start' => $now, 'count' => 0];
    }
    $st['count']++;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($st));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    // Limpieza ocasional de contadores viejos.
    if (random_int(1, 200) === 1) {
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < $now - 3600) {
                @unlink($f);
            }
        }
    }

    if ($st['count'] > $limit) {
        $retry = max(1, $st['start'] + $window - $now);
        if ($onExceeded) {
            $onExceeded($retry); // registra él (p. ej. contacto, con IP anonimizada)
        }
        ahmc_log($bucket, 429, null, 'rate_limit');
        header('Retry-After: ' . $retry);
        ahmc_json(['error' => 'rate_limited', 'retry_after' => $retry], 429);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Log mínimo (sin datos personales: ni secretos, ni emails, ni mensajes)
// ─────────────────────────────────────────────────────────────────────────────

function ahmc_log(string $endpoint, int $status, ?string $domain = null, string $note = '', ?string $ip = null): void
{
    $ip ??= PHP_SAPI === 'cli' ? 'cli' : ahmc_client_ip();
    $line = sprintf(
        "%s\t%s\t%s\t%d\t%s\t%s\n",
        gmdate('Y-m-d\TH:i:s\Z'),
        $ip,
        $endpoint,
        $status,
        $domain ?? '-',
        preg_replace('/[^\w .:\/=,+-]/u', '', $note) ?: '-'
    );
    @file_put_contents(AHMC_LOG_DIR . '/access-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/** IP sin el último octeto / últimos 80 bits, para el log del formulario. */
function ahmc_anon_ip(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return preg_replace('/\.\d+$/', '.0', $ip);
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return '-';
    }
    return (string) inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
}

// ─────────────────────────────────────────────────────────────────────────────
// Dominios y URLs
// ─────────────────────────────────────────────────────────────────────────────

/** host en minúsculas, sin www. ni punto final. '' si no es un host válido. */
function ahmc_normalize_domain(string $hostOrUrl): string
{
    $s = trim(strtolower($hostOrUrl));
    if (str_contains($s, '://')) {
        $s = (string) parse_url($s, PHP_URL_HOST);
    }
    $s = rtrim($s, '.');
    if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $s)) {
        $s = (string) idn_to_ascii($s, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    }
    if (str_starts_with($s, 'www.')) {
        $s = substr($s, 4);
    }
    if ($s === 'localhost' || filter_var($s, FILTER_VALIDATE_IP)) {
        return $s; // solo útil en pruebas; las reglas de callback lo filtran
    }
    if (strlen($s) > 253 || !preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $s)) {
        return '';
    }
    return $s;
}

/**
 * Valida el home_url que manda la web: http(s), sin usuario, query ni
 * fragmento, ruta solo con caracteres seguros. Devuelve la URL canónica
 * (sin barra final) o '' si no vale.
 */
function ahmc_clean_home_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 300 || preg_match('/[\s\x00-\x1f\\\\]/', $url)) {
        return '';
    }
    $p = parse_url($url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    $scheme = strtolower($p['scheme']);
    if (!in_array($scheme, ['https', 'http'], true) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
        return '';
    }
    $path = rtrim((string) ($p['path'] ?? ''), '/');
    if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path)) {
        return '';
    }
    $host = strtolower((string) $p['host']);
    $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
    return $scheme . '://' . $host . $port . $path;
}

// ─────────────────────────────────────────────────────────────────────────────
// Registro de webs (data/sites.json) y cifrado del site_secret
// ─────────────────────────────────────────────────────────────────────────────

/**
 * El servidor necesita el site_secret en claro para recalcular el HMAC, así
 * que no puede guardarse un hash. Se guarda cifrado con AES-256-GCM usando
 * una clave derivada de server_secret (config.php). sites.json y config.php
 * están fuera del docroot con permisos 600; el cifrado añade que una copia
 * suelta de sites.json (backup, descarga por error) no sirve por sí sola.
 * Cambiar server_secret invalida todos los registros (habría que resetearlos).
 */
function ahmc_crypto_key(): string
{
    return hash_hmac('sha256', 'ahmc-sites-' . AHMC_SIG_VERSION, (string) ahmc_config()['server_secret'], true);
}

function ahmc_encrypt_secret(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', ahmc_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ahmc', 16);
    if ($ct === false) {
        throw new RuntimeException('openssl_encrypt falló');
    }
    return AHMC_SIG_VERSION . ':' . base64_encode($iv . $tag . $ct);
}

function ahmc_decrypt_secret(string $stored): ?string
{
    if (!str_starts_with($stored, AHMC_SIG_VERSION . ':')) {
        return null;
    }
    $bin = base64_decode(substr($stored, strlen(AHMC_SIG_VERSION) + 1), true);
    if ($bin === false || strlen($bin) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', ahmc_crypto_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16), 'ahmc');
    return $plain === false ? null : $plain;
}

/**
 * Lee y, si $mutator no es null, modifica sites.json bajo bloqueo exclusivo.
 * El mutador recibe el array por referencia; lo que devuelva se devuelve.
 */
function ahmc_sites(?callable $mutator = null): mixed
{
    $fh = fopen(AHMC_SITES_FILE, 'c+');
    if ($fh === false) {
        throw new RuntimeException('No se puede abrir sites.json');
    }
    @chmod(AHMC_SITES_FILE, 0600);
    flock($fh, $mutator ? LOCK_EX : LOCK_SH);
    $raw = stream_get_contents($fh);
    $sites = json_decode($raw ?: '{}', true);
    if (!is_array($sites)) {
        flock($fh, LOCK_UN);
        fclose($fh);
        throw new RuntimeException('sites.json corrupto');
    }
    $result = $sites;
    if ($mutator) {
        $before = $sites;
        $result = $mutator($sites);
        if ($sites !== $before) {
            ksort($sites);
            $json = json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json . "\n");
            fflush($fh);
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function ahmc_is_preapproved(string $domain): bool
{
    return in_array($domain, ahmc_config()['preapproved_domains'], true);
}

// ─────────────────────────────────────────────────────────────────────────────
// Firma de peticiones
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Mensaje firmado: METODO|ruta|timestamp|home_url
 * ruta = REQUEST_URI tal cual (ruta + query), p. ej. /api/download.php?v=3.8.1
 */
function ahmc_signature_message(string $method, string $path, string $ts, string $homeUrl): string
{
    return strtoupper($method) . '|' . $path . '|' . $ts . '|' . $homeUrl;
}

/** Credenciales de la petición: cabeceras X-AHMC-* o, en su defecto, parámetros. */
function ahmc_request_credentials(): array
{
    $h = static fn(string $n) => trim((string) ($_SERVER['HTTP_' . $n] ?? ''));
    $p = static fn(string $n) => is_string($_POST[$n] ?? $_GET[$n] ?? null) ? trim($_POST[$n] ?? $_GET[$n]) : '';
    return [
        'home_url'  => $h('X_AHMC_SITE') !== '' ? $h('X_AHMC_SITE') : $p('home_url'),
        'timestamp' => $h('X_AHMC_TIMESTAMP') !== '' ? $h('X_AHMC_TIMESTAMP') : $p('timestamp'),
        'signature' => $h('X_AHMC_SIGNATURE') !== '' ? $h('X_AHMC_SIGNATURE') : $p('signature'),
        'version'   => $h('X_AHMC_VERSION'),
    ];
}

/**
 * Verifica la firma y devuelve [dominio, entrada] de una web registrada.
 * Cualquier fallo → 401 genérico (el motivo solo va al log).
 */
function ahmc_authenticate(string $endpoint): array
{
    $c = ahmc_request_credentials();
    $home = ahmc_clean_home_url($c['home_url']);
    if ($home === '' || !preg_match('/^\d{9,11}$/', $c['timestamp']) || !preg_match('/^[a-f0-9]{64}$/', $c['signature'])) {
        ahmc_deny($endpoint, 'missing_or_malformed');
    }
    $domain = ahmc_normalize_domain($home);
    if ($domain === '') {
        ahmc_deny($endpoint, 'bad_domain');
    }
    if (abs(time() - (int) $c['timestamp']) > (int) ahmc_config()['signature_window']) {
        ahmc_deny($endpoint, 'stale_timestamp', $domain);
    }
    $sites = ahmc_sites();
    $entry = $sites[$domain] ?? null;
    $secret = is_array($entry) ? ahmc_decrypt_secret((string) ($entry['secret'] ?? '')) : null;
    // Se calcula siempre un HMAC para no distinguir por tiempo dominio desconocido.
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $expected = hash_hmac('sha256', ahmc_signature_message($method, $path, $c['timestamp'], $c['home_url']), $secret ?? random_bytes(32));
    if ($secret === null) {
        ahmc_deny($endpoint, 'unknown_site', $domain);
    }
    if (!hash_equals($expected, $c['signature'])) {
        ahmc_deny($endpoint, 'bad_signature', $domain);
    }

    // Huella de actividad (sin bloquear la respuesta si falla).
    $version = preg_match('/^[0-9][0-9a-z.\-]{0,30}$/i', $c['version']) ? $c['version'] : null;
    try {
        ahmc_sites(static function (array &$s) use ($domain, $version, $endpoint): void {
            if (!isset($s[$domain])) {
                return;
            }
            $s[$domain]['last_seen'] = gmdate('c');
            $s[$domain]['last_ip'] = ahmc_client_ip();
            $s[$domain]['last_endpoint'] = $endpoint;
            if ($version) {
                $s[$domain]['plugin_version'] = $version;
            }
        });
    } catch (Throwable $e) {
        error_log('last_seen: ' . $e->getMessage());
    }
    return [$domain, $entry];
}

// ─────────────────────────────────────────────────────────────────────────────
// Releases
// ─────────────────────────────────────────────────────────────────────────────

function ahmc_valid_version(string $v): bool
{
    return (bool) preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}(?:-[a-z0-9.]{1,20})?$/i', $v);
}

function ahmc_release_path(string $version): ?string
{
    if (!ahmc_valid_version($version)) {
        return null;
    }
    $file = AHMC_RELEASES . '/ahm-connect-' . $version . '.zip';
    return is_file($file) ? $file : null;
}

/** Manifiesto de la última versión publicada, o null si no hay o está incompleto. */
function ahmc_manifest(): ?array
{
    $raw = @file_get_contents(AHMC_RELEASES . '/manifest.json');
    $m = json_decode($raw ?: '', true);
    if (!is_array($m) || !is_string($m['version'] ?? null) || !ahmc_release_path($m['version'])) {
        return null;
    }
    return $m;
}

function ahmc_download_url(string $version): string
{
    return ahmc_config()['public_base_url'] . '/api/download.php?v=' . rawurlencode($version);
}

// ─────────────────────────────────────────────────────────────────────────────
// Verificación de propiedad del dominio (callback a la web)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Comprueba que quien registra controla de verdad la web: el servidor llama a
 *   <home_url>/wp-json/ahm-connect/v1/update-verify?challenge=<nonce>
 * (y a ?rest_route=... si no hay enlaces permanentes) y la web responde
 *   {"proof": HMAC-SHA256(site_secret, "verify|<nonce>|<home_url>")}
 * Sin esto, cualquiera podría registrar primero un dominio preaprobado con
 * su propio secret y descargarse el plugin.
 *
 * Mitigación SSRF: solo https (salvo pruebas), puertos por defecto, IP
 * pública fijada con CURLOPT_RESOLVE, sin seguir redirecciones, tope de
 * tamaño y tiempo.
 */
function ahmc_verify_ownership(string $homeUrl, string $secret): bool
{
    $cfg = ahmc_config();
    $p = parse_url($homeUrl);
    $scheme = $p['scheme'] ?? '';
    $host = $p['host'] ?? '';
    if ($scheme !== 'https' && !($scheme === 'http' && $cfg['callback_allow_http'])) {
        return false;
    }
    if (isset($p['port']) && !$cfg['callback_allow_private']) {
        return false;
    }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));

    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        return false;
    }
    if (!$cfg['callback_allow_private']) {
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
    }

    $challenge = bin2hex(random_bytes(32));
    $expected = hash_hmac('sha256', 'verify|' . $challenge . '|' . $homeUrl, $secret);
    $urls = [
        $homeUrl . '/wp-json/ahm-connect/v1/update-verify?challenge=' . $challenge,
        $homeUrl . '/?rest_route=/ahm-connect/v1/update-verify&challenge=' . $challenge,
    ];
    foreach ($urls as $url) {
        $body = ahmc_http_get($url, $host, $port, $ips[0], (int) $cfg['callback_timeout']);
        $data = json_decode((string) $body, true);
        if (is_array($data) && is_string($data['proof'] ?? null) && hash_equals($expected, $data['proof'])) {
            return true;
        }
    }
    return false;
}

/** GET con IP fijada y sin redirecciones. Devuelve el cuerpo si 200, si no null. */
function ahmc_http_get(string $url, string $host, int $port, string $ip, int $timeout): ?string
{
    $ua = 'AHM-Connect-Updates/1 (+https://connect.aquihaymarketing.es)';
    $max = 65536;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $buf = '';
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$buf, $max): int {
                $buf .= $chunk;
                return strlen($buf) > $max ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code === 200 ? $buf : null;
    }
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true,
                   'header' => "Accept: application/json\r\nUser-Agent: $ua\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx, 0, $max);
    $status = isset($http_response_header[0]) && preg_match('#\s200\s#', $http_response_header[0] . ' ');
    return $body !== false && $status ? $body : null;
}
