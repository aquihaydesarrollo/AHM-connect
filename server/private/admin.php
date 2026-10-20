<?php
/**
 * AHM Connect — administración del servidor de actualizaciones (solo CLI).
 *
 * Uso (Terminal de cPanel, desde /home/aquihaymarketing/connect):
 *   php private/admin.php list [--all|pending|approved|revoked]
 *   php private/admin.php show <dominio>
 *   php private/admin.php approve <dominio> [--force]
 *   php private/admin.php revoke <dominio>
 *   php private/admin.php reset <dominio>
 *   php private/admin.php accept-candidate <dominio>
 *   php private/admin.php preapprove-sync
 *   php private/admin.php log [líneas]
 *   php private/admin.php release
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib.php';

ahmc_ensure_dirs();
ahmc_config();

$argv = $_SERVER['argv'] ?? [];
$cmd = $argv[1] ?? 'help';
$arg = $argv[2] ?? '';
$flags = array_slice($argv, 2);

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s, int $code = 1): never { fwrite(STDERR, $s . "\n"); exit($code); }

function need_domain(string $arg): string
{
    $d = ahmc_normalize_domain($arg);
    if ($d === '') {
        fail('Dominio no válido: ' . $arg);
    }
    return $d;
}

function row(array $s, string $domain): string
{
    $flags = [];
    if (empty($s['verified'])) { $flags[] = 'sin-verificar'; }
    if (ahmc_is_preapproved($domain)) { $flags[] = 'preaprobado'; }
    if (!empty($s['candidate'])) { $flags[] = 'CANDIDATO'; }
    return sprintf('%-34s %-9s %-9s %-20s %s',
        $domain, $s['status'] ?? '?', $s['plugin_version'] ?? '-',
        substr((string) ($s['last_seen'] ?? '-'), 0, 19), implode(',', $flags));
}

switch ($cmd) {
    case 'list':
        $filter = in_array($arg, ['pending', 'approved', 'revoked'], true) ? $arg : null;
        $sites = ahmc_sites();
        out(sprintf('%-34s %-9s %-9s %-20s %s', 'DOMINIO', 'ESTADO', 'VERSIÓN', 'ÚLTIMA VEZ (UTC)', 'NOTAS'));
        $n = 0;
        foreach ($sites as $d => $s) {
            if ($filter && ($s['status'] ?? '') !== $filter) { continue; }
            out(row($s, $d));
            $n++;
        }
        out();
        out($n . ' web(s). Preaprobados sin registrar: ' . count(array_diff(ahmc_config()['preapproved_domains'], array_keys($sites))));
        if ($arg === '--all') {
            foreach (array_diff(ahmc_config()['preapproved_domains'], array_keys($sites)) as $d) {
                out('  - ' . $d);
            }
        }
        break;

    case 'show':
        $d = need_domain($arg);
        $s = ahmc_sites()[$d] ?? fail('No registrado: ' . $d);
        unset($s['secret']);
        if (isset($s['candidate']['secret'])) { $s['candidate']['secret'] = '(oculto)'; }
        $s['preaprobado'] = ahmc_is_preapproved($d);
        out(json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        break;

    case 'approve':
        $d = need_domain($arg);
        $force = in_array('--force', $flags, true);
        $r = ahmc_sites(static function (array &$sites) use ($d, $force): string {
            if (!isset($sites[$d])) { return 'No registrado: ' . $d . ' (la web debe registrarse primero).'; }
            if (empty($sites[$d]['verified']) && !$force) {
                return 'La web no ha superado la verificación de dominio. Compruébalo y usa --force si es legítima.';
            }
            $sites[$d]['status'] = 'approved';
            $sites[$d]['approved_at'] = gmdate('c');
            $sites[$d]['approved_by'] = 'admin' . ($force ? ' (force)' : '');
            return 'OK: ' . $d . ' aprobada.';
        });
        str_starts_with($r, 'OK') ? out($r) : fail($r);
        ahmc_log('admin', 200, $d, 'approve');
        break;

    case 'revoke':
        $d = need_domain($arg);
        $r = ahmc_sites(static function (array &$sites) use ($d): string {
            if (!isset($sites[$d])) { return 'No registrado: ' . $d; }
            $sites[$d]['status'] = 'revoked';
            $sites[$d]['revoked_at'] = gmdate('c');
            return 'OK: ' . $d . ' revocada (ya no recibe ni descarga actualizaciones).';
        });
        str_starts_with($r, 'OK') ? out($r) : fail($r);
        ahmc_log('admin', 200, $d, 'revoke');
        break;

    case 'reset':
        $d = need_domain($arg);
        $r = ahmc_sites(static function (array &$sites) use ($d): string {
            if (!isset($sites[$d])) { return 'No registrado: ' . $d; }
            unset($sites[$d]);
            return 'OK: ' . $d . ' borrada. Se volverá a registrar sola en su próxima consulta (pending, o approved si está preaprobada y verifica).';
        });
        str_starts_with($r, 'OK') ? out($r) : fail($r);
        ahmc_log('admin', 200, $d, 'reset');
        break;

    case 'accept-candidate':
        // Una web aprobada reinstaló el plugin (nuevo secret) y el registro
        // quedó en conflicto: el nuevo secret, ya verificado, sustituye al viejo.
        $d = need_domain($arg);
        $r = ahmc_sites(static function (array &$sites) use ($d): string {
            $c = $sites[$d]['candidate'] ?? null;
            if (!is_array($c)) { return 'No hay candidato para ' . $d; }
            $sites[$d]['secret'] = $c['secret'];
            $sites[$d]['home_url'] = $c['home_url'];
            $sites[$d]['verified'] = true;
            $sites[$d]['secret_replaced_at'] = gmdate('c');
            unset($sites[$d]['candidate']);
            return 'OK: ' . $d . ' usa ahora el secret nuevo (estado ' . ($sites[$d]['status'] ?? '?') . ').';
        });
        str_starts_with($r, 'OK') ? out($r) : fail($r);
        ahmc_log('admin', 200, $d, 'accept_candidate');
        break;

    case 'preapprove-sync':
        // Aprueba las pendientes verificadas cuyo dominio está ahora en config.php.
        $done = ahmc_sites(static function (array &$sites): array {
            $done = [];
            foreach ($sites as $d => &$s) {
                if (($s['status'] ?? '') === 'pending' && !empty($s['verified']) && ahmc_is_preapproved($d)) {
                    $s['status'] = 'approved';
                    $s['approved_at'] = gmdate('c');
                    $s['approved_by'] = 'preapprove-sync';
                    $done[] = $d;
                }
            }
            return $done;
        });
        out($done ? "Aprobadas:\n  " . implode("\n  ", $done) : 'Nada que aprobar.');
        $pend = array_keys(array_filter(ahmc_sites(), static fn($s) => ($s['status'] ?? '') === 'pending' && empty($s['verified']) && ahmc_is_preapproved((string) ($s['domain'] ?? ''))));
        if ($pend) {
            out("Preaprobadas pero sin verificar (revisar y usar approve --force):\n  " . implode("\n  ", $pend));
        }
        ahmc_log('admin', 200, null, 'preapprove_sync ' . count($done));
        break;

    case 'log':
        $n = max(1, min(500, (int) ($arg ?: 40)));
        $f = AHMC_LOG_DIR . '/access-' . gmdate('Y-m') . '.log';
        $lines = is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [];
        foreach (array_slice($lines ?: [], -$n) as $l) { out($l); }
        break;

    case 'release':
        $m = ahmc_manifest();
        if (!$m) { fail('Sin manifest.json válido o falta el zip de esa versión en private/releases/.'); }
        out('Versión publicada: ' . $m['version'] . '  (' . basename((string) ahmc_release_path($m['version'])) . ', sha256 ' . hash_file('sha256', (string) ahmc_release_path($m['version'])) . ')');
        break;

    default:
        out(<<<TXT
        Uso: php private/admin.php <orden>
          list [pending|approved|revoked|--all]  webs registradas (--all añade preaprobadas sin registrar)
          show <dominio>                         ficha de una web (sin el secret)
          approve <dominio> [--force]            aprobar (--force si no verificó el dominio)
          revoke <dominio>                       quitar acceso
          reset <dominio>                        borrar el registro; la web se registra de nuevo sola
          accept-candidate <dominio>             aceptar el secret nuevo de una web aprobada en conflicto
          preapprove-sync                        aprobar pendientes verificadas que estén en preapproved_domains
          log [n]                                últimas n líneas del log del mes
          release                                versión publicada y su sha256
        TXT);
        exit($cmd === 'help' ? 0 : 1);
}
