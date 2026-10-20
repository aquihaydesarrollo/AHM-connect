<?php
/**
 * AHM Connect — configuración del servidor de actualizaciones.
 *
 * Copia este fichero a private/config.php EN EL SERVIDOR (nunca al repo) y
 * rellena server_secret y contact_to. Permisos: chmod 600 private/config.php
 *
 * Generar server_secret (Terminal de cPanel):
 *     php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
 * NO lo cambies después: cifra los site_secret de data/sites.json; si cambia,
 * todas las webs quedan sin poder autenticarse hasta hacer reset de cada una.
 */

return [

    // 64 caracteres hex. Obligatorio.
    'server_secret' => 'CAMBIA-ESTO-por-64-hex-generados-con-random_bytes',

    // URL pública (sin barra final). Con ella se construye download_url.
    'public_base_url' => 'https://connect.aquihaymarketing.es',

    // Dominios (host sin www.) que se aprueban solos al registrarse, siempre
    // que superen la verificación de dominio. Generado a partir de los "url"
    // de ~/Claude/clientes/_claves-ahm-connect/clients (27 dominios).
    // Tras añadir alguno: php private/admin.php preapprove-sync
    'preapproved_domains' => [
        'ala30.com',
        'aqui2.tufactory.es',
        'aquihaymarketing.es',
        'audidata-demo.tufactory.es',
        'autolis.es',
        'balneariovillavieja.com',
        'bas5.es',
        'boomerangpark.es',
        'catalogo.grupoheleo.com',
        'dalmaunatura.com',
        'dulceslacartuja.com',
        'dulceslacartuja.tufactory.es',
        'frutastorero.com',
        'gys.es',
        'ilunion.grupoheleo.com',
        'issealt.com',
        'jnc.es',
        'ndautomatismos.com',
        'nexiagp.com',
        'panesyco.es',
        'perezymateu.com',
        'primazyspain.com',
        'protegetutrastero.com',
        'roldan.tufactory.es',
        'santiagodecodesign.com',
        'secla.es',
        'setienabogada.com',
    ],

    // Formulario de la landing.
    'contact_to'      => 'CAMBIA-ESTO@aquihaymarketing.es',
    'contact_from'    => 'AHM Connect <no-reply@aquihaymarketing.es>', // buzón/alias del propio dominio (SPF/DKIM)
    'contact_subject' => 'Contacto desde connect.aquihaymarketing.es',

    // Proxies cuya cabecera CF-Connecting-IP / X-Forwarded-For se cree.
    // Rangos de Cloudflare (https://www.cloudflare.com/ips/). Revisar de vez en cuando.
    'trusted_proxies' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

    // Peticiones por IP: update/download/register por minuto; contact por 10 minutos.
    'rate_limits' => ['update' => 60, 'download' => 30, 'register' => 10, 'contact' => 5],

    // Ventana de validez de la firma (segundos, ±).
    'signature_window' => 300,

    // Verificación de dominio llamando a la web al registrarse. No desactivar en producción.
    'verify_callbacks'       => true,
    'callback_timeout'       => 10,
    'callback_allow_http'    => false, // true solo en pruebas locales
    'callback_allow_private' => false, // true solo en pruebas locales (127.0.0.1, puertos)

    // Tope de registros sin verificar (protege sites.json de spam).
    'max_unverified' => 200,
];
