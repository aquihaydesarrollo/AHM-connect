<?php
/**
 * POST /api/contact.php — formulario de la landing.
 *
 * Campos: name, email, company (opcional), message, privacy (=1, obligatorio)
 * y website (honeypot: si viene relleno se responde OK y se descarta).
 *
 * Con fetch (Accept: application/json o X-Requested-With) responde JSON
 * {"ok":true} | {"ok":false,"error":"..."}; sin JS redirige (303) a
 * /?enviado=1 o /?error=1.
 *
 * No guarda datos personales: el log solo anota fecha, IP anonimizada y
 * resultado. El contenido viaja únicamente en el email a contact_to.
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';

ahmc_boot('contact');

$wantsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== '';

$finish = static function (bool $ok, string $error = '', int $code = 200) use ($wantsJson): never {
    ahmc_log('contact', $ok ? 200 : $code, null, $ok ? 'ok' : $error, ahmc_anon_ip(ahmc_client_ip()));
    if ($wantsJson) {
        ahmc_json($ok ? ['ok' => true] : ['ok' => false, 'error' => $error], $ok ? 200 : $code);
    }
    ahmc_nocache_headers();
    header('Location: ' . ($ok ? '/?enviado=1' : '/?error=1') . '#contacto', true, 303);
    exit;
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $wantsJson ? ahmc_json(['ok' => false, 'error' => 'method_not_allowed'], 405) : $finish(false, 'method_not_allowed', 405);
}

ahmc_rate_limit('contact', null, 600, static function () use ($finish): void {
    $finish(false, 'rate_limited', 429);
});

// Solo desde la propia web (si el navegador manda Origin).
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && $origin !== 'null') {
    $oh = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $allowed = [strtolower((string) parse_url(ahmc_config()['public_base_url'], PHP_URL_HOST)), strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0])];
    if (!in_array($oh, $allowed, true)) {
        $finish(false, 'bad_origin', 403);
    }
}

$field = static function (string $k, int $max): string {
    $v = $_POST[$k] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim(str_replace("\0", '', $v));
    return mb_substr($v, 0, $max);
};

// Honeypot: un bot lo rellena; se le dice que sí y se descarta.
if ($field('website', 200) !== '') {
    $finish(true);
}

$name    = preg_replace('/[\r\n\t]+/', ' ', $field('name', 120));
$email   = $field('email', 160);
$company = preg_replace('/[\r\n\t]+/', ' ', $field('company', 160));
$message = $field('message', 5000);
$privacy = $field('privacy', 5);

if (mb_strlen($name) < 2) {
    $finish(false, 'name', 422);
}
if (preg_match('/[\r\n]/', $email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $finish(false, 'email', 422);
}
if (mb_strlen($message) < 10) {
    $finish(false, 'message', 422);
}
if ($privacy !== '1') {
    $finish(false, 'privacy', 422);
}
if (!mb_check_encoding($name . $email . $company . $message, 'UTF-8')) {
    $finish(false, 'encoding', 422);
}

$cfg = ahmc_config();
$to = (string) $cfg['contact_to'];
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    error_log('contact: contact_to no configurado');
    $finish(false, 'not_configured', 503);
}

// From del dominio propio (SPF/DKIM) y Reply-To del usuario.
$from = (string) $cfg['contact_from'];
$fromAddr = preg_match('/<([^>]+)>/', $from, $mm) ? $mm[1] : $from;
if (!filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
    $finish(false, 'not_configured', 503);
}
$fromName = trim(preg_replace('/<[^>]*>/', '', $from), " \"");
$enc = static fn(string $s): string => mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");

$subject = $enc((string) $cfg['contact_subject'] . ' · ' . mb_substr($name, 0, 60));
$body = "Nuevo mensaje desde el formulario de connect.aquihaymarketing.es\n\n"
    . "Nombre:  {$name}\n"
    . "Email:   {$email}\n"
    . "Empresa: " . ($company !== '' ? $company : '-') . "\n"
    . "Fecha:   " . gmdate('Y-m-d H:i') . " UTC\n"
    . "Privacidad aceptada: sí\n\n"
    . "Mensaje:\n" . $message . "\n";

$headers = [
    'From: ' . ($fromName !== '' ? $enc($fromName) . ' ' : '') . '<' . $fromAddr . '>',
    'Reply-To: ' . $enc($name) . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: AHM-Connect',
];

$sent = mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $fromAddr);
$sent ? $finish(true) : $finish(false, 'send_failed', 502);
