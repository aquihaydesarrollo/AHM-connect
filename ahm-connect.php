<?php
/**
 * Plugin Name: AHM Connect
 * Plugin URI:  https://aquihaymarketing.es
 * Description: API REST segura para gestionar contenido, SEO con Rank Math, atributos y productos WooCommerce, y metadatos de páginas desde herramientas externas de automatización.
 * Version:     3.8.0
 * Update URI:  https://github.com/aquihaydesarrollo/AHM-connect
 * Author:      Aquí Hay Marketing
 * Author URI:  https://aquihaymarketing.es
 * License:     GPL-2.0+
 * Text Domain: ahm-connect
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'RMAI_VERSION',         '3.8.0' );
define( 'RMAI_OPTION_API_KEY',  'rmai_api_key' );
define( 'RMAI_OPTION_SETTINGS', 'rmai_settings' );
define( 'RMAI_OPTION_ENABLED',  'rmai_api_enabled' );
define( 'RMAI_NAMESPACE',       'ahm-connect/v1' );
// Límite de peticiones por IP y minuto. Si se define en wp-config.php manda
// sobre el panel (el campo sale bloqueado); si no, 60 por defecto y se
// cambia en Ajustes → AHM Connect → Seguridad y límites.
if ( defined( 'RMAI_RATE_LIMIT' ) ) {
    define( 'RMAI_RATE_LIMIT_LOCKED', true );
} else {
    define( 'RMAI_RATE_LIMIT', 60 );
    define( 'RMAI_RATE_LIMIT_LOCKED', false );
}
define( 'RMAI_RATE_LIMIT_MAX',  1000 );
define( 'RMAI_LOG_OPTION',      'rmai_request_log' );
define( 'RMAI_LOG_MAX',         100 );

// ── AHM Sites (conexión con el panel central) ────────────
define( 'RMAI_OPTION_AHM_SITES_ENABLED',   'rmai_ahm_sites_enabled' );   // bool: toggle on/off
define( 'RMAI_OPTION_AHM_SITES_PAIRING',   'rmai_ahm_sites_pairing' );   // array: hash/expires/status del código de emparejamiento (nunca el código en claro)
define( 'RMAI_OPTION_AHM_SITES_SITE_ID',   'rmai_ahm_sites_site_id' );   // int: id de sitio asignado por el panel
define( 'RMAI_OPTION_AHM_SITES_SECRET',    'rmai_ahm_sites_secret' );    // string: secret HMAC (64 hex), sensible, autoload=no
define( 'RMAI_OPTION_AHM_SITES_PANEL_URL', 'rmai_ahm_sites_panel_url' ); // string: URL base del panel
define( 'RMAI_AHM_SITES_CRON_HOOK',        'rmai_ahm_sites_checkin_event' );

// ── Power Tools (v3.8.0) — bloque no-RCE: Gutenberg, Skills, Design, ──────
//    Elementor clásico, subida de medios y lectura de ficheros.
define( 'RMAI_SKILL_CPT',          'ahm_skill' );              // CPT privado para los playbooks Markdown
define( 'RMAI_OPTION_DESIGN',      'rmai_design_direction' );  // array: dirección de diseño global del sitio
define( 'RMAI_OPTION_SKILL_SEED',  'rmai_skill_builtin_seed' ); // flag: skill integrada ya sembrada
define( 'RMAI_FILES_READ_MAX',     1048576 );                  // 1 MB: tope de lectura de un fichero
define( 'RMAI_FILES_SCAN_MAX',     524288 );                   // 512 KB: tope para buscar dentro de un fichero
define( 'RMAI_FILES_NODE_MAX',     3000 );                     // tope de nodos en /files/tree y /files/search
if ( ! defined( 'RMAI_MEDIA_MAX_MB' ) ) {
    define( 'RMAI_MEDIA_MAX_MB',   20 );                       // tope de /media/upload (MB); se puede bajar/subir en wp-config.php
}

// ═══════════════════════════════════════════════════════
// 1. ACTIVACIÓN / DESACTIVACIÓN / DESINSTALACIÓN
// ═══════════════════════════════════════════════════════

register_activation_hook( __FILE__, 'rmai_activate' );
function rmai_activate(): void {
    if ( ! get_option( RMAI_OPTION_API_KEY ) ) {
        update_option( RMAI_OPTION_API_KEY, rmai_generate_key() );
    }
    if ( ! get_option( RMAI_OPTION_SETTINGS ) ) {
        update_option( RMAI_OPTION_SETTINGS, rmai_default_settings() );
    }
    if ( get_option( RMAI_OPTION_ENABLED ) === false ) {
        update_option( RMAI_OPTION_ENABLED, 1 );
    }
}

register_uninstall_hook( __FILE__, 'rmai_uninstall' );
function rmai_uninstall(): void {
    delete_option( RMAI_OPTION_API_KEY );
    delete_option( RMAI_OPTION_SETTINGS );
    delete_option( RMAI_OPTION_ENABLED );
    delete_option( RMAI_LOG_OPTION );
    delete_option( RMAI_OPTION_AHM_SITES_ENABLED );
    delete_option( RMAI_OPTION_AHM_SITES_PAIRING );
    delete_option( RMAI_OPTION_AHM_SITES_SITE_ID );
    delete_option( RMAI_OPTION_AHM_SITES_SECRET );
    delete_option( RMAI_OPTION_AHM_SITES_PANEL_URL );
    delete_option( RMAI_OPTION_DESIGN );
    delete_option( RMAI_OPTION_SKILL_SEED );
}

function rmai_generate_key(): string {
    return bin2hex( random_bytes( 24 ) );
}

function rmai_default_settings(): array {
    return [
        'rate_limit_enabled' => true,
        'rate_limit'         => (int) RMAI_RATE_LIMIT,
        'log_enabled'        => true,
        'ip_whitelist'       => '',
        // Grupos de rutas (v3.8.0). Power Tools activo; Ficheros (lectura de
        // ficheros del servidor) desactivado por defecto: con la clave de la
        // API basta para usarlo y es lo único que expone código y
        // configuración de la web, así que se enciende a propósito y por web.
        'group_power'        => true,
        'group_files'        => false,
    ];
}

/**
 * Grupos de rutas que se pueden apagar por web:
 *   power → Gutenberg, Skills, Design, Elementor clásico (insertar sección) y /media/upload
 *   files → /files/list, /files/tree, /files/read, /files/search (solo lectura)
 * Se decide con el ajuste del panel y, por encima, con el filtro
 * 'rmai_route_groups' (p. ej. desde un mu-plugin o un plugin envoltorio):
 *     add_filter( 'rmai_route_groups', fn( $g ) => array_merge( $g, [ 'files' => true ] ) );
 */
function rmai_route_groups(): array {
    $settings = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    $groups   = [
        'power' => (bool) $settings['group_power'],
        'files' => (bool) $settings['group_files'],
    ];
    $filtered = apply_filters( 'rmai_route_groups', $groups );
    foreach ( array_keys( $groups ) as $g ) {
        if ( is_array( $filtered ) && array_key_exists( $g, $filtered ) ) {
            $groups[ $g ] = (bool) $filtered[ $g ];
        }
    }
    return $groups;
}

function rmai_route_group_enabled( string $group ): bool {
    $groups = rmai_route_groups();
    return ! empty( $groups[ $group ] );
}

/** Límite efectivo de peticiones por minuto e IP. */
function rmai_rate_limit(): int {
    if ( RMAI_RATE_LIMIT_LOCKED ) {
        return max( 1, (int) RMAI_RATE_LIMIT );
    }
    $settings = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    $limit    = (int) $settings['rate_limit'];
    return $limit > 0 ? min( $limit, RMAI_RATE_LIMIT_MAX ) : (int) RMAI_RATE_LIMIT;
}

// ── Inyectar JSON-LD personalizado (FAQPage, BreadcrumbList, etc.) ────────
add_action( 'wp_head', 'rmai_output_custom_jsonld', 5 );
function rmai_output_custom_jsonld(): void {
    if ( ! is_singular() ) return;
    $schemas = get_post_meta( get_the_ID(), '_ahm_jsonld', true );
    if ( empty( $schemas ) || ! is_array( $schemas ) ) return;
    foreach ( $schemas as $schema ) {
        if ( ! empty( $schema ) ) {
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
        }
    }
}

// ═══════════════════════════════════════════════════════
// 2. ADMIN BAR: TOGGLE ON/OFF
// ═══════════════════════════════════════════════════════

add_action( 'admin_bar_menu', 'rmai_admin_bar_toggle', 100 );
function rmai_admin_bar_toggle( WP_Admin_Bar $wp_admin_bar ): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $enabled      = (bool) get_option( RMAI_OPTION_ENABLED, 1 );
    $toggle_url   = wp_nonce_url( admin_url( 'admin-post.php?action=rmai_toggle_api' ), 'rmai_toggle_api' );
    $settings_url = admin_url( 'options-general.php?page=ahm-connect' );

    $track_bg  = $enabled ? '#22c55e' : '#ef4444';
    $thumb_pos = $enabled ? '18px' : '2px';

    // Span con onclick — evita <a> anidados
    $toggle_span = '<span
        onclick="event.stopPropagation();event.preventDefault();window.location.href=\'' . esc_js( $toggle_url ) . '\'"
        title="' . ( $enabled ? 'Desactivar API' : 'Activar API' ) . '"
        style="
            display:inline-block;
            width:36px;height:20px;
            background:' . $track_bg . ';
            border-radius:20px;
            position:relative;
            box-shadow:inset 0 1px 3px rgba(0,0,0,.35);
            flex-shrink:0;
            vertical-align:middle;
            margin-left:8px;
            cursor:pointer;
        ">
        <span style="
            position:absolute;
            top:2px;left:' . $thumb_pos . ';
            width:16px;height:16px;
            background:#fff;
            border-radius:50%;
            box-shadow:0 1px 3px rgba(0,0,0,.4);
        "></span>
    </span>';

    $wp_admin_bar->add_node( [
        'id'    => 'rmai-toggle',
        'title' => '<span style="font-weight:600;letter-spacing:.02em;vertical-align:middle">AHM Connect</span>' . $toggle_span,
        'href'  => $settings_url,
        'meta'  => [ 'title' => 'Ajustes AHM Connect' ],
    ] );
}

add_action( 'wp_head', 'rmai_admin_bar_styles' );
add_action( 'admin_head', 'rmai_admin_bar_styles' );
function rmai_admin_bar_styles(): void {
    if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) return;
    echo '<style>#wp-admin-bar-rmai-toggle > .ab-item{display:flex!important;align-items:center!important;gap:6px!important;padding-top:0!important;padding-bottom:0!important}#wp-admin-bar-rmai-toggle > .ab-item:hover{background:rgba(255,255,255,.1)!important}</style>';
}

add_action( 'admin_post_rmai_toggle_api', 'rmai_handle_toggle_api' );
function rmai_handle_toggle_api(): void {
    if ( ! check_admin_referer( 'rmai_toggle_api' ) || ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No autorizado.' );
    }

    $current = (bool) get_option( RMAI_OPTION_ENABLED, 1 );
    update_option( RMAI_OPTION_ENABLED, $current ? 0 : 1 );
    update_option( RMAI_OPTION_API_KEY, rmai_generate_key() );

    wp_safe_redirect( wp_get_referer() ?: admin_url() );
    exit;
}

add_action( 'admin_post_rmai_toggle_ahm_sites', 'rmai_handle_toggle_ahm_sites' );
function rmai_handle_toggle_ahm_sites(): void {
    if ( ! check_admin_referer( 'rmai_toggle_ahm_sites' ) || ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No autorizado.' );
    }

    $current = (bool) get_option( RMAI_OPTION_AHM_SITES_ENABLED, false );
    $next    = ! $current;
    update_option( RMAI_OPTION_AHM_SITES_ENABLED, $next );

    if ( $next ) {
        rmai_ahm_sites_maybe_schedule_checkin();
        $code = rmai_ahm_sites_start_pairing();
        set_transient( 'rmai_ahm_sites_code_display_' . get_current_user_id(), $code, 60 );
        wp_safe_redirect( admin_url( 'options-general.php?page=ahm-connect&rmai_msg=ahm_sites_code_generated' ) );
        exit;
    }

    rmai_ahm_sites_maybe_schedule_checkin();
    wp_safe_redirect( admin_url( 'options-general.php?page=ahm-connect&rmai_msg=ahm_sites_disabled' ) );
    exit;
}

add_action( 'admin_post_rmai_ahm_sites_generate_code', 'rmai_handle_ahm_sites_generate_code' );
function rmai_handle_ahm_sites_generate_code(): void {
    if ( ! check_admin_referer( 'rmai_ahm_sites_generate_code_action' ) || ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No autorizado.' );
    }

    $code = rmai_ahm_sites_start_pairing();
    set_transient( 'rmai_ahm_sites_code_display_' . get_current_user_id(), $code, 60 );

    wp_safe_redirect( admin_url( 'options-general.php?page=ahm-connect&rmai_msg=ahm_sites_code_generated' ) );
    exit;
}

// ═══════════════════════════════════════════════════════
// 3. ADMIN: MENÚ Y AJUSTES
// ═══════════════════════════════════════════════════════

add_action( 'admin_menu', 'rmai_admin_menu' );
function rmai_admin_menu(): void {
    add_options_page(
        'AHM Connect',
        'AHM Connect',
        'manage_options',
        'ahm-connect',
        'rmai_settings_page'
    );
}

add_action( 'admin_init', 'rmai_handle_admin_actions' );
function rmai_handle_admin_actions(): void {
    if (
        isset( $_POST['rmai_regenerate_key'] ) &&
        check_admin_referer( 'rmai_regenerate_key_action' ) &&
        current_user_can( 'manage_options' )
    ) {
        update_option( RMAI_OPTION_API_KEY, rmai_generate_key() );
        wp_safe_redirect( admin_url( 'options-general.php?page=ahm-connect&rmai_msg=key_regenerated' ) );
        exit;
    }

    if (
        isset( $_POST['rmai_clear_log'] ) &&
        check_admin_referer( 'rmai_clear_log_action' ) &&
        current_user_can( 'manage_options' )
    ) {
        delete_option( RMAI_LOG_OPTION );
        wp_safe_redirect( admin_url( 'options-general.php?page=ahm-connect&tab=log&rmai_msg=log_cleared' ) );
        exit;
    }

    if (
        isset( $_POST['rmai_save_settings'] ) &&
        check_admin_referer( 'rmai_save_settings_action' ) &&
        current_user_can( 'manage_options' )
    ) {
        $current  = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
        $limit    = isset( $_POST['rate_limit'] ) ? absint( $_POST['rate_limit'] ) : (int) $current['rate_limit'];
        $settings = [
            'rate_limit_enabled' => isset( $_POST['rate_limit_enabled'] ),
            'rate_limit'         => max( 1, min( RMAI_RATE_LIMIT_MAX, $limit ?: (int) RMAI_RATE_LIMIT ) ),
            'log_enabled'        => isset( $_POST['log_enabled'] ),
            'ip_whitelist'       => sanitize_text_field( $_POST['ip_whitelist'] ?? '' ),
            'group_power'        => isset( $_POST['group_power'] ),
            'group_files'        => isset( $_POST['group_files'] ),
        ];
        update_option( RMAI_OPTION_SETTINGS, $settings );
        wp_safe_redirect( admin_url( 'options-general.php?page=ahm-connect&rmai_msg=settings_saved' ) );
        exit;
    }
}

/**
 * Iconos SVG de línea (lucide, licencia ISC) para el panel. Sin emojis.
 */
function rmai_admin_icon( string $name, int $size = 16 ): string {
    $p = [
        'plug'     => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'key'      => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        'copy'     => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'eye'      => '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
        'refresh'  => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'power'    => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'shield'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'zap'      => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
        'folder'   => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'gauge'    => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
        'list'     => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
        'code'     => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        'book'     => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>',
        'chart'    => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'home'     => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'check'    => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'x'        => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'alert'    => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'info'     => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'server'   => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><path d="M6 6h.01"/><path d="M6 18h.01"/>',
        'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
        'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'bulb'     => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
    ];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $p[ $name ] ?? '' ) . '</svg>';
}

/** URL de una pestaña del panel. */
function rmai_admin_tab_url( string $tab ): string {
    return admin_url( 'options-general.php?page=ahm-connect' . ( 'resumen' === $tab ? '' : '&tab=' . rawurlencode( $tab ) ) );
}

/** Fila con interruptor accesible (checkbox real con role="switch"). */
function rmai_admin_switch_row( string $name, bool $checked, string $label, string $desc, string $icon = '' ): void {
    $id = 'ahmc-' . sanitize_key( $name );
    ?>
    <div class="ahmc-row">
        <?php if ( $icon ) : ?><span class="ahmc-row-ic"><?php echo rmai_admin_icon( $icon, 16 ); ?></span><?php endif; ?>
        <div class="ahmc-grow">
            <label class="ahmc-row-t" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
            <div class="ahmc-mu" id="<?php echo esc_attr( $id ); ?>-d"><?php echo wp_kses( $desc, [ 'strong' => [], 'code' => [] ] ); ?></div>
        </div>
        <span class="ahmc-switch">
            <input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-d" <?php checked( $checked ); ?>>
            <span class="ahmc-track" aria-hidden="true"></span>
        </span>
    </div>
    <?php
}

function rmai_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $api_key     = get_option( RMAI_OPTION_API_KEY, '' );
    $settings    = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    $base_url    = rest_url( RMAI_NAMESPACE );
    $msg         = isset( $_GET['rmai_msg'] ) ? sanitize_key( wp_unslash( $_GET['rmai_msg'] ) ) : '';
    $api_enabled = (bool) get_option( RMAI_OPTION_ENABLED, 1 );
    $log         = (array) get_option( RMAI_LOG_OPTION, [] );
    $rm_ver      = defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null;
    $wc_ver      = defined( 'WC_VERSION' ) ? WC_VERSION : null;
    $el_ver      = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
    $toggle_url  = wp_nonce_url( admin_url( 'admin-post.php?action=rmai_toggle_api' ), 'rmai_toggle_api' );
    $log_count   = count( $log );
    $groups      = rmai_route_groups();
    $notices     = [
        'key_regenerated'    => 'API Key regenerada. Actualiza la clave en tus herramientas.',
        'log_cleared'        => 'Log de peticiones eliminado.',
        'settings_saved'     => 'Ajustes guardados correctamente.',
        'ahm_sites_disabled' => 'Conexión con AHM Sites desactivada.',
    ];

    $tabs = [
        'resumen'   => [ 'home', 'Resumen' ],
        'seo'       => [ 'chart', 'SEO / GEO' ],
        'endpoints' => [ 'code', 'Endpoints' ],
        'log'       => [ 'list', 'Log' ],
        'docs'      => [ 'book', 'Documentación' ],
    ];
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'resumen';
    if ( ! isset( $tabs[ $tab ] ) ) {
        $tab = 'resumen';
    }

    // ── AHM Sites: estado de la conexión con el panel ──────────────────────
    $ahm_sites_enabled    = (bool) get_option( RMAI_OPTION_AHM_SITES_ENABLED, false );
    $ahm_sites_site_id    = (int) get_option( RMAI_OPTION_AHM_SITES_SITE_ID, 0 );
    $ahm_sites_panel_url  = (string) get_option( RMAI_OPTION_AHM_SITES_PANEL_URL, '' );
    $ahm_sites_connected  = $ahm_sites_enabled && $ahm_sites_site_id && get_option( RMAI_OPTION_AHM_SITES_SECRET, '' );
    $ahm_sites_toggle_url = wp_nonce_url( admin_url( 'admin-post.php?action=rmai_toggle_ahm_sites' ), 'rmai_toggle_ahm_sites' );

    // El código de emparejamiento en claro solo existe en un transient de un solo uso
    // (60s, puente entre el redirect POST->GET tras generarlo). Al leerlo aquí se borra:
    // si se recarga la página ya no está disponible en ningún sitio (solo queda su hash).
    $ahm_sites_new_code = null;
    if ( 'ahm_sites_code_generated' === $msg ) {
        $display_key        = 'rmai_ahm_sites_code_display_' . get_current_user_id();
        $ahm_sites_new_code = get_transient( $display_key );
        delete_transient( $display_key );
    }
    ?>
    <style>
    .ahmc{--b:#e4e4e7;--b2:#f4f4f5;--fg:#09090b;--fg2:#3f3f46;--mu:#71717a;--bg:#fff;--ok:#16a34a;--warn:#d97706;--bad:#dc2626;--ring:#2563eb;
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;color:var(--fg);max-width:1180px;margin:24px 20px 48px 2px;font-size:13px;line-height:1.5}
    .ahmc *{box-sizing:border-box}
    .ahmc svg{display:inline-block;vertical-align:-3px;flex-shrink:0}
    .ahmc a{color:var(--fg)}
    .ahmc code,.ahmc .ahmc-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}
    .ahmc code{background:var(--b2);border-radius:6px;padding:2px 6px;color:var(--fg2)}
    .ahmc :focus-visible{outline:2px solid var(--ring);outline-offset:2px;border-radius:6px}
    .ahmc .screen-reader-text{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px)}
    /* Cabecera */
    .ahmc-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}
    .ahmc-top h1{font-size:22px;font-weight:600;letter-spacing:-.02em;margin:0;padding:0;display:flex;align-items:center;gap:10px;line-height:1.2;color:var(--fg)}
    .ahmc-logo{display:grid;place-items:center;width:34px;height:34px;border-radius:9px;background:var(--fg);color:#fff}
    .ahmc-sp{flex:1}
    .ahmc-badge{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--b);border-radius:999px;padding:3px 10px;font-size:12px;font-weight:500;background:var(--bg);color:var(--fg);white-space:nowrap}
    .ahmc-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0;background:#a1a1aa}
    .ahmc-dot.ok{background:var(--ok)}.ahmc-dot.warn{background:var(--warn)}.ahmc-dot.bad{background:var(--bad)}
    /* Botones */
    .ahmc-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border-radius:8px;border:1px solid var(--b);background:var(--bg);color:var(--fg);font-size:13px;font-weight:500;text-decoration:none;cursor:pointer;line-height:1;white-space:nowrap;font-family:inherit}
    .ahmc-btn:hover{background:var(--b2);color:var(--fg)}
    .ahmc-btn.pri{background:var(--fg);border-color:var(--fg);color:#fff}.ahmc-btn.pri:hover{background:#27272a;color:#fff}
    .ahmc-btn.danger{color:#b91c1c}.ahmc-btn.danger:hover{background:#fef2f2;color:#991b1b}
    .ahmc-btn.sm{height:30px;padding:0 10px;font-size:12.5px}
    .ahmc-btn[disabled]{opacity:.55;cursor:not-allowed}
    /* Pestañas */
    .ahmc-seg{display:inline-flex;flex-wrap:wrap;background:var(--b2);border-radius:10px;padding:4px;gap:2px;margin-bottom:20px}
    .ahmc-seg a{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:7px;font-size:13px;font-weight:500;color:var(--mu);text-decoration:none}
    .ahmc-seg a:hover{color:var(--fg)}
    .ahmc-seg a[aria-current="page"]{background:var(--bg);color:var(--fg);box-shadow:0 1px 2px rgba(0,0,0,.06)}
    .ahmc-seg .ahmc-n{font-size:11px;background:var(--bg);border:1px solid var(--b);border-radius:999px;padding:0 6px;color:var(--mu)}
    /* Rejilla y tarjetas */
    .ahmc-grid{display:grid;gap:16px}
    .ahmc-g3{grid-template-columns:repeat(3,minmax(0,1fr))}.ahmc-g2{grid-template-columns:repeat(2,minmax(0,1fr))}
    @media(max-width:1180px){.ahmc-g3{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:782px){.ahmc-g3,.ahmc-g2{grid-template-columns:1fr}.ahmc{margin-right:10px}}
    .ahmc-card{background:var(--bg);border:1px solid var(--b);border-radius:12px;padding:18px 20px;box-shadow:0 1px 2px rgba(0,0,0,.03);min-width:0}
    .ahmc-card h2{margin:0 0 4px;font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px;color:var(--fg)}
    .ahmc-card h3{margin:0 0 8px;font-size:12px;font-weight:500;color:var(--mu);text-transform:none}
    .ahmc-mu{color:var(--mu);font-size:12.5px;line-height:1.5}
    .ahmc-lead{color:var(--mu);font-size:12.5px;margin:0 0 14px}
    .ahmc-big{font-size:26px;font-weight:600;letter-spacing:-.02em;line-height:1.15}
    .ahmc-flex{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .ahmc-grow{flex:1;min-width:0}
    .ahmc-sep{height:1px;background:var(--b2);margin:14px 0}
    /* Avisos */
    .ahmc-alert{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--b);border-radius:12px;padding:12px 14px;background:var(--bg);margin-bottom:16px;font-size:13px}
    .ahmc-alert.good{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
    .ahmc-alert.warn{border-color:#fde68a;background:#fffbeb;color:#92400e}
    .ahmc-alert.bad{border-color:#fecaca;background:#fef2f2;color:#991b1b}
    /* Campos */
    .ahmc-field{display:flex;gap:8px;align-items:center}
    .ahmc input[type=text],.ahmc input[type=number],.ahmc input[type=password]{border:1px solid var(--b);border-radius:8px;height:34px;padding:0 10px;font-size:13px;color:var(--fg);background:var(--bg);box-shadow:none;margin:0;min-width:0}
    .ahmc input[type=text]:focus,.ahmc input[type=number]:focus,.ahmc input[type=password]:focus{border-color:var(--ring);box-shadow:0 0 0 1px var(--ring);outline:none}
    .ahmc .ahmc-key{flex:1;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;letter-spacing:.02em}
    .ahmc-label{display:block;font-size:13px;font-weight:500;color:var(--fg);margin:0 0 2px}
    /* Filas con interruptor */
    .ahmc-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--b2)}
    .ahmc-row:last-child{border-bottom:0}
    .ahmc-row-ic{display:grid;place-items:center;width:30px;height:30px;border-radius:8px;background:var(--b2);color:var(--fg2);flex-shrink:0}
    .ahmc-row-t{display:block;font-size:13px;font-weight:500;color:var(--fg);cursor:pointer}
    .ahmc-switch{position:relative;display:inline-block;width:38px;height:22px;flex-shrink:0}
    .ahmc-switch input{position:absolute;inset:0;width:100%;height:100%;margin:0;opacity:0;cursor:pointer;z-index:1}
    .ahmc-track{position:absolute;inset:0;background:#d4d4d8;border-radius:999px;transition:background .15s}
    .ahmc-track:before{content:"";position:absolute;width:18px;height:18px;left:2px;top:2px;background:#fff;border-radius:50%;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:transform .15s}
    .ahmc-switch input:checked + .ahmc-track{background:var(--fg)}
    .ahmc-switch input:checked + .ahmc-track:before{transform:translateX(16px)}
    .ahmc-switch input:focus-visible + .ahmc-track{outline:2px solid var(--ring);outline-offset:2px}
    /* Tablas y pastillas */
    .ahmc-table{width:100%;border-collapse:collapse;font-size:12.5px}
    .ahmc-table th{text-align:left;font-weight:500;color:var(--mu);padding:8px 10px;border-bottom:1px solid var(--b);white-space:nowrap}
    .ahmc-table td{padding:8px 10px;border-bottom:1px solid var(--b2);vertical-align:middle}
    .ahmc-table tr:last-child td{border-bottom:0}
    .ahmc-scroll{overflow-x:auto}
    .ahmc-pill{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:500;border-radius:6px;padding:2px 8px;background:var(--b2);color:var(--fg2);white-space:nowrap}
    .ahmc-pill.ok{background:#dcfce7;color:#166534}.ahmc-pill.warn{background:#fef3c7;color:#92400e}.ahmc-pill.bad{background:#fee2e2;color:#991b1b}.ahmc-pill.info{background:#dbeafe;color:#1e40af}
    .ahmc-m{display:inline-block;min-width:52px;text-align:center;font:600 10.5px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;border-radius:5px;padding:1px 6px;background:var(--b2);color:var(--fg2)}
    .ahmc-m.get{background:#dcfce7;color:#166534}.ahmc-m.post{background:#dbeafe;color:#1e40af}.ahmc-m.put{background:#fef3c7;color:#92400e}.ahmc-m.delete{background:#fee2e2;color:#991b1b}
    .ahmc-kv{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0;font-size:12.5px}
    .ahmc-kv dt{color:var(--mu)}.ahmc-kv dd{margin:0;color:var(--fg);text-align:right;min-width:0;overflow-wrap:anywhere}
    .ahmc details{border:1px solid var(--b);border-radius:10px;background:var(--bg)}
    .ahmc summary{cursor:pointer;padding:10px 14px;font-size:13px;font-weight:500;color:var(--fg2);list-style-position:inside}
    .ahmc details > div{padding:0 14px 14px}
    .ahmc pre{font-size:11.5px;background:#fafafa;border:1px solid var(--b);border-radius:8px;padding:10px;max-height:220px;overflow:auto;white-space:pre-wrap;margin:0}
    .ahmc-ok-ic{color:var(--ok)}.ahmc-bad-ic{color:var(--bad)}.ahmc-warn-ic{color:var(--warn)}
    .ahmc-check{display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid var(--b);border-radius:8px}
    .ahmc-bar{height:8px;border-radius:99px;overflow:hidden;display:flex;background:var(--b2)}
    .ahmc-empty{color:var(--mu);font-size:13px;padding:6px 0}
    .ahmc-copied{font-size:12px;color:var(--ok);min-width:60px}
    </style>

    <div class="ahmc">

    <div class="ahmc-top">
        <h1><span class="ahmc-logo"><?php echo rmai_admin_icon( 'plug', 18 ); ?></span> AHM Connect</h1>
        <span class="ahmc-badge">v<?php echo esc_html( RMAI_VERSION ); ?></span>
        <span class="ahmc-badge"><span class="ahmc-dot <?php echo $api_enabled ? 'ok' : 'bad'; ?>"></span>API <?php echo $api_enabled ? 'activa' : 'desactivada'; ?></span>
        <?php if ( $ahm_sites_connected ) : ?>
            <span class="ahmc-badge"><span class="ahmc-dot ok"></span>AHM Sites</span>
        <?php endif; ?>
        <span class="ahmc-sp"></span>
        <?php if ( $api_enabled ) : ?>
            <a href="<?php echo esc_url( $toggle_url ); ?>" class="ahmc-btn danger" onclick="return confirm('¿Desactivar la API? La clave se rotará automáticamente.')"><?php echo rmai_admin_icon( 'power', 15 ); ?> Desactivar API</a>
        <?php else : ?>
            <a href="<?php echo esc_url( $toggle_url ); ?>" class="ahmc-btn pri" onclick="return confirm('¿Activar la API? La clave se rotará automáticamente.')"><?php echo rmai_admin_icon( 'power', 15 ); ?> Activar API</a>
        <?php endif; ?>
    </div>

    <?php if ( isset( $notices[ $msg ] ) ) : ?>
        <div class="ahmc-alert good" role="status"><?php echo rmai_admin_icon( 'check', 18 ); ?><div><?php echo esc_html( $notices[ $msg ] ); ?></div></div>
    <?php endif; ?>
    <?php if ( ! $api_enabled ) : ?>
        <div class="ahmc-alert bad" role="status"><?php echo rmai_admin_icon( 'alert', 18 ); ?><div><strong>API desactivada.</strong> Ninguna herramienta externa puede conectarse (responde 503). Al activarla se rota la clave.</div></div>
    <?php endif; ?>

    <nav class="ahmc-seg" aria-label="Secciones de AHM Connect">
        <?php foreach ( $tabs as $k => [ $ic, $label ] ) : ?>
            <a href="<?php echo esc_url( rmai_admin_tab_url( $k ) ); ?>"<?php echo $k === $tab ? ' aria-current="page"' : ''; ?>><?php echo rmai_admin_icon( $ic, 15 ); ?> <?php echo esc_html( $label ); ?><?php if ( 'log' === $k && $log_count ) : ?> <span class="ahmc-n"><?php echo (int) $log_count; ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ( 'resumen' === $tab ) : ?>

    <div class="ahmc-grid ahmc-g3">

        <!-- Clave del día -->
        <section class="ahmc-card" aria-labelledby="ahmc-h-key">
            <h2 id="ahmc-h-key"><?php echo rmai_admin_icon( 'key' ); ?> Clave del día</h2>
            <p class="ahmc-lead">Cabecera <code>X-RMAI-Key</code> en cada petición. Se renueva sola cada medianoche.</p>
            <label class="screen-reader-text" for="ahm-api-key">API Key</label>
            <div class="ahmc-field">
                <input type="password" class="ahmc-key" id="ahm-api-key" value="<?php echo esc_attr( $api_key ); ?>" readonly autocomplete="off" spellcheck="false" onfocus="this.select()">
                <button type="button" class="ahmc-btn sm" onclick="ahmcReveal('ahm-api-key',this)" aria-pressed="false" title="Mostrar u ocultar la clave"><?php echo rmai_admin_icon( 'eye', 15 ); ?><span class="screen-reader-text">Mostrar clave</span></button>
            </div>
            <div class="ahmc-flex" style="margin-top:10px">
                <button type="button" class="ahmc-btn sm pri" onclick="ahmcCopy('ahm-api-key','ahm-copied')"><?php echo rmai_admin_icon( 'copy', 15 ); ?> Copiar</button>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field( 'rmai_regenerate_key_action' ); ?>
                    <button type="submit" name="rmai_regenerate_key" class="ahmc-btn sm" onclick="return confirm('¿Regenerar la clave? Los clientes actuales perderán acceso.')"><?php echo rmai_admin_icon( 'refresh', 15 ); ?> Regenerar</button>
                </form>
                <span id="ahm-copied" class="ahmc-copied" aria-live="polite"></span>
            </div>
            <div class="ahmc-sep"></div>
            <div class="ahmc-mu" style="margin-bottom:4px">URL base</div>
            <div class="ahmc-field">
                <input type="text" id="ahmc-base-url" class="ahmc-key" value="<?php echo esc_attr( $base_url ); ?>" readonly onfocus="this.select()" aria-label="URL base de la API">
                <button type="button" class="ahmc-btn sm" onclick="ahmcCopy('ahmc-base-url','ahmc-base-copied')" title="Copiar URL base"><?php echo rmai_admin_icon( 'copy', 15 ); ?><span class="screen-reader-text">Copiar URL base</span></button>
            </div>
            <span id="ahmc-base-copied" class="ahmc-copied" aria-live="polite"></span>
        </section>

        <!-- AHM Sites -->
        <section class="ahmc-card" aria-labelledby="ahmc-h-sites">
            <h2 id="ahmc-h-sites"><?php echo rmai_admin_icon( 'link' ); ?> AHM Sites</h2>
            <p class="ahmc-lead">Gestiona esta web desde el panel central (actualizaciones, seguridad, monitorización) con un emparejamiento de un solo uso.</p>
            <div class="ahmc-flex" style="justify-content:space-between">
                <span class="ahmc-badge"><span class="ahmc-dot <?php echo $ahm_sites_connected ? 'ok' : ( $ahm_sites_enabled ? 'warn' : '' ); ?>"></span><?php echo $ahm_sites_connected ? 'Emparejado' : ( $ahm_sites_enabled ? 'Pendiente de emparejar' : 'Desactivado' ); ?></span>
                <a href="<?php echo esc_url( $ahm_sites_toggle_url ); ?>" class="ahmc-btn sm <?php echo $ahm_sites_enabled ? 'danger' : 'pri'; ?>" onclick="return confirm('<?php echo $ahm_sites_enabled ? '¿Desactivar la conexión con AHM Sites?' : '¿Activar la conexión? Se generará un código de emparejamiento de un solo uso.'; ?>')"><?php echo rmai_admin_icon( 'power', 15 ); ?> <?php echo $ahm_sites_enabled ? 'Desactivar' : 'Activar'; ?></a>
            </div>
            <p class="ahmc-mu" style="margin:10px 0 0"><?php echo $ahm_sites_enabled ? 'El plugin reporta al panel cada 15 min.' : 'Actívalo para generar un código de emparejamiento.'; ?></p>

            <?php if ( $ahm_sites_new_code ) : ?>
                <div class="ahmc-alert warn" style="margin:12px 0 0;display:block">
                    <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px"><?php echo rmai_admin_icon( 'alert', 16 ); ?><strong>Código de emparejamiento: cópialo ahora. Caduca en 10 minutos y no se volverá a mostrar.</strong></div>
                    <label class="screen-reader-text" for="ahm-sites-code">Código de emparejamiento</label>
                    <div class="ahmc-field">
                        <input type="text" class="ahmc-key" id="ahm-sites-code" value="<?php echo esc_attr( $ahm_sites_new_code ); ?>" readonly onfocus="this.select()">
                        <button type="button" class="ahmc-btn sm" onclick="ahmcCopy('ahm-sites-code','ahm-sites-copied')"><?php echo rmai_admin_icon( 'copy', 15 ); ?> Copiar</button>
                    </div>
                    <span id="ahm-sites-copied" class="ahmc-copied" aria-live="polite"></span>
                    <div style="font-size:12px;margin-top:4px">Pégalo en el panel AHM Sites para completar el emparejamiento de este sitio.</div>
                </div>
            <?php elseif ( $ahm_sites_enabled && ! $ahm_sites_connected ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
                    <input type="hidden" name="action" value="rmai_ahm_sites_generate_code">
                    <?php wp_nonce_field( 'rmai_ahm_sites_generate_code_action' ); ?>
                    <button type="submit" class="ahmc-btn sm"><?php echo rmai_admin_icon( 'refresh', 15 ); ?> Generar nuevo código</button>
                </form>
            <?php endif; ?>

            <?php if ( $ahm_sites_connected ) : ?>
                <div class="ahmc-sep"></div>
                <dl class="ahmc-kv">
                    <dt>Site ID</dt><dd><?php echo esc_html( $ahm_sites_site_id ); ?></dd>
                    <dt>Panel</dt><dd><?php echo esc_html( $ahm_sites_panel_url ); ?></dd>
                </dl>
            <?php endif; ?>

            <details style="margin-top:12px">
                <summary>Rotación del secret de AHM Sites</summary>
                <div>
                    <p class="ahmc-mu" style="margin-top:0">El secret de esta conexión (distinto de la API Key) lo rota automáticamente <strong>el panel AHM Sites</strong>, de forma firmada, sin intervención manual aquí.</p>
                    <button type="button" class="ahmc-btn sm" disabled title="La rotación la inicia el panel AHM Sites, no este plugin"><?php echo rmai_admin_icon( 'refresh', 15 ); ?> Rotar clave AHM Sites</button>
                </div>
            </details>
        </section>

        <!-- Sitio -->
        <section class="ahmc-card" aria-labelledby="ahmc-h-site">
            <h2 id="ahmc-h-site"><?php echo rmai_admin_icon( 'globe' ); ?> Sitio</h2>
            <p class="ahmc-lead"><a href="<?php echo esc_url( get_site_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> <?php echo rmai_admin_icon( 'external', 13 ); ?></a></p>
            <div class="ahmc-grid ahmc-g3" style="gap:8px;margin-bottom:14px">
                <div><div class="ahmc-big"><?php echo esc_html( number_format_i18n( wp_count_posts()->publish + wp_count_posts( 'page' )->publish ) ); ?></div><div class="ahmc-mu">Posts + páginas</div></div>
                <div><div class="ahmc-big"><?php echo esc_html( count( get_post_types( [ 'public' => true ] ) ) ); ?></div><div class="ahmc-mu">Tipos públicos</div></div>
                <div><div class="ahmc-big"><?php echo esc_html( $log_count ); ?></div><div class="ahmc-mu">En el log</div></div>
            </div>
            <dl class="ahmc-kv">
                <dt>WordPress</dt><dd><?php echo esc_html( get_bloginfo( 'version' ) ); ?></dd>
                <dt>PHP</dt><dd><?php echo esc_html( PHP_VERSION ); ?></dd>
                <dt>Rank Math</dt><dd><?php echo $rm_ver ? esc_html( $rm_ver ) : '<span class="ahmc-pill">No instalado</span>'; ?></dd>
                <dt>WooCommerce</dt><dd><?php echo $wc_ver ? esc_html( $wc_ver ) : '<span class="ahmc-pill">No instalado</span>'; ?></dd>
                <dt>Elementor</dt><dd><?php echo $el_ver ? esc_html( $el_ver ) : '<span class="ahmc-pill">No instalado</span>'; ?></dd>
            </dl>
        </section>

    </div>

    <!-- Ajustes -->
    <form method="post" class="ahmc-card" style="margin-top:16px" aria-labelledby="ahmc-h-set">
        <?php wp_nonce_field( 'rmai_save_settings_action' ); ?>
        <div class="ahmc-flex" style="justify-content:space-between;margin-bottom:6px">
            <h2 id="ahmc-h-set" style="margin:0"><?php echo rmai_admin_icon( 'shield' ); ?> Acceso y límites</h2>
            <button type="submit" name="rmai_save_settings" class="ahmc-btn pri">Guardar ajustes</button>
        </div>
        <div class="ahmc-grid ahmc-g2" style="gap:0 32px">
            <div>
                <h3 style="margin-top:10px">Grupos de rutas</h3>
                <?php
                rmai_admin_switch_row( 'group_power', (bool) $settings['group_power'], 'Power Tools',
                    'Gutenberg, Skills, Design, insertar secciones Elementor y subir medios' . ( $groups['power'] !== (bool) $settings['group_power'] ? ' · <strong>forzado por código (filtro rmai_route_groups)</strong>' : '' ), 'zap' );
                rmai_admin_switch_row( 'group_files', (bool) $settings['group_files'], 'Ficheros (solo lectura)',
                    '<code>/files/*</code>: listar y leer ficheros de la web. Desactivado por defecto; actívalo solo donde haga falta' . ( $groups['files'] !== (bool) $settings['group_files'] ? ' · <strong>forzado por código (filtro rmai_route_groups)</strong>' : '' ), 'folder' );
                rmai_admin_switch_row( 'log_enabled', (bool) $settings['log_enabled'], 'Log de accesos',
                    'Registrar las últimas ' . (int) RMAI_LOG_MAX . ' peticiones', 'list' );
                ?>
            </div>
            <div>
                <h3 style="margin-top:10px">Límites y red</h3>
                <?php
                rmai_admin_switch_row( 'rate_limit_enabled', (bool) $settings['rate_limit_enabled'], 'Límite de peticiones',
                    'Máx. ' . (int) rmai_rate_limit() . ' peticiones por minuto e IP (ventana fija de 60 s)', 'gauge' );
                ?>
                <div class="ahmc-row">
                    <span class="ahmc-row-ic"><?php echo rmai_admin_icon( 'gauge', 16 ); ?></span>
                    <div class="ahmc-grow">
                        <label class="ahmc-row-t" for="ahmc-rate-limit">Peticiones por minuto</label>
                        <?php if ( RMAI_RATE_LIMIT_LOCKED ) : ?>
                            <div class="ahmc-mu">Fijado en <code>wp-config.php</code> (RMAI_RATE_LIMIT = <?php echo esc_html( (int) RMAI_RATE_LIMIT ); ?>).</div>
                        <?php else : ?>
                            <div class="ahmc-mu">Entre 1 y <?php echo (int) RMAI_RATE_LIMIT_MAX; ?>.</div>
                        <?php endif; ?>
                    </div>
                    <?php if ( ! RMAI_RATE_LIMIT_LOCKED ) : ?>
                        <input type="number" id="ahmc-rate-limit" name="rate_limit" min="1" max="<?php echo esc_attr( RMAI_RATE_LIMIT_MAX ); ?>" value="<?php echo esc_attr( rmai_rate_limit() ); ?>" style="width:90px">
                    <?php endif; ?>
                </div>
                <div class="ahmc-row" style="align-items:flex-start">
                    <span class="ahmc-row-ic"><?php echo rmai_admin_icon( 'server', 16 ); ?></span>
                    <div class="ahmc-grow">
                        <label class="ahmc-row-t" for="ip_whitelist">IPs permitidas</label>
                        <div class="ahmc-mu" style="margin-bottom:6px">Vacío = todas. Separar por coma.</div>
                        <input type="text" id="ip_whitelist" name="ip_whitelist" value="<?php echo esc_attr( $settings['ip_whitelist'] ); ?>" placeholder="1.2.3.4, 5.6.7.8" style="width:100%">
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php elseif ( 'endpoints' === $tab ) :
        $ep_groups = [
            [ 'file', 'Contenido y SEO', '', [
                ['GET',    '/posts',                  'Listar entradas (?missing= ?seo_score_lt=)'],
                ['GET',    '/post/{id}',              'SEO + contenido de una entrada'],
                ['PUT',    '/post/{id}',              'Actualizar campos SEO'],
                ['PUT',    '/post/{id}/content',      'Actualizar post_content / excerpt'],
                ['POST',   '/post/{id}/elementor-replace', 'Sustituir texto dentro de _elementor_data'],
                ['POST',   '/bulk-update',            'Actualizar SEO en lote'],
                ['POST',   '/bulk-content',           'Actualizar contenido, título o slug en lote'],
                ['GET',    '/post/{id}/score',        'Puntuación SEO Rank Math'],
                ['POST',   '/create-post',            'Crear entrada/página/producto'],
                ['POST',   '/recalculate-scores',     'Recalcular scores Rank Math'],
                ['GET',    '/post-types',             'Tipos de contenido'],
                ['GET',    '/info',                   'Info del sitio + plugin'],
            ] ],
            [ 'chart', 'Auditoría SEO', '', [
                ['GET',    '/seo/report',             'Dashboard ejecutivo: keywords, scores, alt, 404s'],
                ['GET',    '/seo/images',             'Imágenes sin alt en todo el sitio'],
                ['GET',    '/seo/duplicates',         'Meta titles/descriptions duplicados'],
                ['GET',    '/seo/orphans',            'Páginas sin enlaces internos'],
                ['GET',    '/seo',                    'Audit SEO completo del sitio'],
                ['GET',    '/seo/post/{id}',          'Audit + sugerencias para un post'],
                ['POST',   '/seo/apply/{id}',         'Aplica optimizaciones automáticamente'],
                ['GET',    '/seo/sitemap',            'Audita el sitemap'],
                ['GET',    '/seo/404',                'Errores 404 detectados'],
                ['GET',    '/seo/noindex',            'Páginas que deberían ser noindex'],
            ] ],
            [ 'globe', 'GEO — buscadores IA', '', [
                ['GET',    '/seo/geo',                'Audit: robots.txt, llms.txt, schema, E-E-A-T'],
                ['GET',    '/seo/geo/generate',       'Genera llms.txt / llms-full.txt'],
                ['POST',   '/seo/geo/write-llms-txt', 'Escribe llms.txt en la raíz del servidor'],
            ] ],
            [ 'file', 'Imágenes y media', '', [
                ['GET',    '/post/{id}/images',       'Imágenes del post + check alt/keyword'],
                ['PUT',    '/post/{id}/images',       'Asignar imagen destacada'],
                ['GET',    '/media',                  'Biblioteca de imágenes (?missing_alt=true)'],
                ['PUT',    '/media/{id}',             'Actualizar alt, title, caption'],
                ['POST',   '/bulk-media-alt',         'Actualizar alt en lote [{id, alt}]'],
            ] ],
            [ 'link', 'Redirections', '', [
                ['GET',    '/redirections',           'Listar redirections Rank Math'],
                ['POST',   '/redirections',           'Crear 301/302/307 {from, to, code}'],
                ['DELETE', '/redirections/{id}',      'Eliminar una redirection'],
            ] ],
            [ 'code', 'Schema JSON-LD', '', [
                ['GET',    '/seo/schema/{id}',        'Leer schemas JSON-LD de un post'],
                ['POST',   '/seo/schema/{id}',        'Añadir FAQPage, BreadcrumbList, etc.'],
                ['DELETE', '/seo/schema/{id}',        'Eliminar schemas de un post'],
                ['GET',    '/seo/breadcrumbs',        'Estado breadcrumbs Rank Math'],
                ['POST',   '/seo/breadcrumbs',        'Activar breadcrumbs Rank Math'],
            ] ],
            [ 'server', 'WooCommerce y meta', '', [
                ['POST',  '/bulk-attributes',          'Merge atributos _product_attributes'],
                ['GET',   '/post/{id}/meta',           'Leer post meta (atributos, precio…)'],
                ['POST',  '/post/{id}/meta',           'Escribir post meta'],
            ] ],
            [ 'refresh', 'Herramientas', '', [
                ['POST',  '/tools/flush-rewrite',      'Regenerar reglas de rewrite (sitemap 404, etc.)'],
                ['POST',  '/tools/flush-cache',        'Purgar caché (Elementor + LiteSpeed…); post_id opcional, sin él global'],
            ] ],
            [ 'zap', 'Gutenberg', 'power', [
                ['GET',   '/gutenberg/blocks',        'Listar bloques registrados'],
                ['POST',  '/gutenberg/validate',      'Dry-run: parsear y devolver árbol de bloques'],
                ['POST',  '/gutenberg/post',          'Crear post con bloques (sin kses)'],
                ['PUT',   '/gutenberg/post/{id}',     'Reescribir bloques de un post'],
            ] ],
            [ 'book', 'Skills', 'power', [
                ['GET',    '/skills',       'Listar skills con descripción'],
                ['POST',   '/skills',       'Crear skill {name, description, content}'],
                ['GET',    '/skills/{id}',  'Leer skill completa (Markdown)'],
                ['PUT',    '/skills/{id}',  'Actualizar skill'],
                ['DELETE', '/skills/{id}',  'Borrar skill'],
            ] ],
            [ 'zap', 'Design', 'power', [
                ['GET',    '/design',                          'Leer dirección de diseño global'],
                ['PUT',    '/design',                          'Guardar paleta/tipo/espaciado/tono/reglas'],
                ['DELETE', '/design',                          'Restablecer dirección de diseño'],
                ['GET',    '/design/elementor-kit',            'Leer Kit clásico (colores, tipo, CSS)'],
                ['POST',   '/design/elementor-kit',            'Escribir Kit clásico + regenerar CSS'],
                ['POST',   '/design/elementor-regenerate-css', 'Regenerar CSS global de Elementor'],
            ] ],
            [ 'zap', 'Elementor clásico y medios', 'power', [
                ['POST',  '/post/{id}/elementor-section', 'Insertar sección Elementor clásico'],
                ['POST',  '/media/upload',               'Subir medio por URL o base64'],
            ] ],
            [ 'folder', 'Ficheros (solo lectura)', 'files', [
                ['GET',   '/files/list',                 'Listar directorio (bajo ABSPATH)'],
                ['GET',   '/files/tree',                 'Árbol con profundidad (solo lectura)'],
                ['GET',   '/files/read',                 'Leer fichero de texto (solo lectura)'],
                ['GET',   '/files/search',               'Buscar por nombre/contenido (lectura)'],
            ] ],
        ];
        ?>
        <div class="ahmc-card" style="margin-bottom:16px">
            <div class="ahmc-flex"><span class="ahmc-mu">Base</span><code><?php echo esc_html( $base_url ); ?></code><span class="ahmc-sp"></span><span class="ahmc-mu">Todas las rutas piden la cabecera <code>X-RMAI-Key</code>.</span></div>
        </div>
        <div class="ahmc-grid ahmc-g2">
        <?php foreach ( $ep_groups as [ $ic, $title, $grp, $rows ] ) :
            $off = $grp && empty( $groups[ $grp ] ); ?>
            <section class="ahmc-card" style="padding:14px 16px">
                <h2 style="font-size:14px;margin-bottom:8px"><?php echo rmai_admin_icon( $ic ); ?> <?php echo esc_html( $title ); ?>
                    <?php if ( $grp ) : ?><span class="ahmc-pill <?php echo $off ? '' : 'ok'; ?>" style="margin-left:auto"><?php echo esc_html( ( 'files' === $grp ? 'Ficheros' : 'Power Tools' ) . ( $off ? ' · desactivado' : ' · activo' ) ); ?></span><?php endif; ?>
                </h2>
                <div class="ahmc-scroll">
                <table class="ahmc-table"><tbody>
                <?php foreach ( $rows as [ $m, $p, $d ] ) : ?>
                    <tr<?php echo $off ? ' style="opacity:.55"' : ''; ?>><td style="width:60px"><span class="ahmc-m <?php echo esc_attr( strtolower( $m ) ); ?>"><?php echo esc_html( $m ); ?></span></td><td class="ahmc-mono" style="white-space:nowrap"><?php echo esc_html( $p ); ?></td><td class="ahmc-mu"><?php echo esc_html( $d ); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                </div>
            </section>
        <?php endforeach; ?>
        </div>

    <?php elseif ( 'log' === $tab ) : ?>

        <section class="ahmc-card" aria-labelledby="ahmc-h-log">
            <div class="ahmc-flex" style="justify-content:space-between;margin-bottom:10px">
                <h2 id="ahmc-h-log" style="margin:0"><?php echo rmai_admin_icon( 'list' ); ?> Últimas <?php echo (int) $log_count; ?> peticiones</h2>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field( 'rmai_clear_log_action' ); ?>
                    <button type="submit" name="rmai_clear_log" class="ahmc-btn sm"<?php echo $log_count ? '' : ' disabled'; ?>><?php echo rmai_admin_icon( 'trash', 15 ); ?> Borrar log</button>
                </form>
            </div>
            <?php if ( ! $settings['log_enabled'] ) : ?>
                <div class="ahmc-alert warn"><?php echo rmai_admin_icon( 'info', 16 ); ?><div>El log está desactivado. Actívalo en <a href="<?php echo esc_url( rmai_admin_tab_url( 'resumen' ) ); ?>">Resumen → Acceso y límites</a>.</div></div>
            <?php endif; ?>
            <?php if ( ! $log ) : ?>
                <div class="ahmc-empty">Sin peticiones registradas.</div>
            <?php else : ?>
                <div class="ahmc-scroll">
                <table class="ahmc-table">
                    <thead><tr><th scope="col">Fecha</th><th scope="col">Método</th><th scope="col">Ruta</th><th scope="col">Estado</th><th scope="col">IP</th></tr></thead>
                    <tbody>
                    <?php foreach ( array_reverse( $log ) as $entry ) :
                        $s  = (int) ( $entry['status'] ?? 0 );
                        $sc = $s >= 400 ? 'bad' : ( $s >= 300 ? 'warn' : 'ok' ); ?>
                        <tr>
                            <td class="ahmc-mu" style="white-space:nowrap"><?php echo esc_html( $entry['date'] ?? '' ); ?></td>
                            <td><span class="ahmc-m <?php echo esc_attr( strtolower( (string) ( $entry['method'] ?? '' ) ) ); ?>"><?php echo esc_html( $entry['method'] ?? '' ); ?></span></td>
                            <td class="ahmc-mono" style="overflow-wrap:anywhere"><?php echo esc_html( $entry['route'] ?? '' ); ?></td>
                            <td><span class="ahmc-pill <?php echo esc_attr( $sc ); ?>"><?php echo esc_html( $entry['status'] ?? '' ); ?></span></td>
                            <td class="ahmc-mu"><?php echo esc_html( $entry['ip'] ?? '' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ( 'docs' === $tab ) : ?>

        <div class="ahmc-grid ahmc-g2">
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'book' ); ?> Cómo conectar</h2>
                <p class="ahmc-lead">Todas las peticiones llevan la clave del día en la cabecera <code>X-RMAI-Key</code>. La clave rota cada medianoche: las integraciones deben leerla de nuevo cada día.</p>
                <pre>curl -s "<?php echo esc_html( $base_url ); ?>/info" \
  -H "X-RMAI-Key: &lt;clave&gt;"</pre>
                <div class="ahmc-sep"></div>
                <dl class="ahmc-kv" style="grid-template-columns:auto 1fr">
                    <dt>401</dt><dd style="text-align:left">Clave ausente o incorrecta</dd>
                    <dt>403</dt><dd style="text-align:left">IP fuera de la lista permitida, o fichero protegido</dd>
                    <dt>404</dt><dd style="text-align:left">Ruta inexistente o grupo desactivado</dd>
                    <dt>429</dt><dd style="text-align:left">Límite de peticiones superado (incluye <code>retry_after</code>)</dd>
                    <dt>503</dt><dd style="text-align:left">API desactivada</dd>
                </dl>
            </section>
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'code' ); ?> Ajustes por código</h2>
                <p class="ahmc-lead">Constantes para <code>wp-config.php</code> y filtros para un mu-plugin.</p>
                <table class="ahmc-table"><tbody>
                    <tr><td class="ahmc-mono">RMAI_RATE_LIMIT</td><td class="ahmc-mu">Peticiones por minuto; manda sobre el panel</td></tr>
                    <tr><td class="ahmc-mono">RMAI_MEDIA_MAX_MB</td><td class="ahmc-mu">Tope de <code>/media/upload</code> (20 por defecto)</td></tr>
                    <tr><td class="ahmc-mono">RMAI_DISABLE_UPDATES</td><td class="ahmc-mu">No actualizar desde el repositorio</td></tr>
                    <tr><td class="ahmc-mono">RMAI_TRUSTED_PROXIES</td><td class="ahmc-mu">IPs de proxy de confianza (cabeceras de IP real)</td></tr>
                    <tr><td class="ahmc-mono">rmai_route_groups</td><td class="ahmc-mu">Filtro: forzar grupos <code>power</code> / <code>files</code></td></tr>
                    <tr><td class="ahmc-mono">rmai_files_blocked</td><td class="ahmc-mu">Filtro: proteger más rutas en <code>/files/*</code></td></tr>
                    <tr><td class="ahmc-mono">ahm_frame_ancestors</td><td class="ahmc-mu">Filtro: orígenes que pueden enmarcar la web</td></tr>
                </tbody></table>
            </section>
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'shield' ); ?> Qué no hace, a propósito</h2>
                <p class="ahmc-lead" style="margin-bottom:0">No ejecuta PHP, no escribe ni borra ficheros, no usa WP-CLI, no instala plugins ni temas y no crea enlaces de acceso. El contenido de páginas Elementor solo se toca vía <code>_elementor_data</code> (<code>/post/{id}/meta</code>, <code>/elementor-replace</code>, <code>/elementor-section</code>), nunca con <code>post_content</code>. Tras escribir, la API purga sola la caché de Elementor y de página del post tocado.</p>
            </section>
        </div>

    <?php elseif ( 'seo' === $tab ) :
        // Datos GEO calculados en servidor llamando a las funciones internas.
        $geo_data     = null;
        $geo_error    = null;
        $geo_req      = new WP_REST_Request( 'GET', '/' . RMAI_NAMESPACE . '/seo/geo' );
        $geo_response = rmai_geo_audit( $geo_req );
        if ( is_wp_error( $geo_response ) ) {
            $geo_error = $geo_response->get_error_message();
        } elseif ( $geo_response instanceof WP_REST_Response ) {
            $geo_data = $geo_response->get_data();
        }

        // Resumen de puntuaciones SEO vía wpdb.
        global $wpdb;
        $scores_raw = $wpdb->get_results(
            "SELECT pm.meta_value AS score, COUNT(*) AS total
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'rank_math_seo_score'
               AND p.post_status = 'publish'
               AND p.post_type NOT IN ('attachment','revision','nav_menu_item')
             GROUP BY FLOOR(pm.meta_value / 10) * 10
             ORDER BY score ASC", ARRAY_A
        );
        $score_buckets = [ 'poor' => 0, 'ok' => 0, 'good' => 0 ];
        $scored_total  = 0;
        foreach ( (array) $scores_raw as $row ) {
            $s = (int) $row['score'];
            $scored_total += (int) $row['total'];
            if ( $s < 50 )       $score_buckets['poor'] += (int) $row['total'];
            elseif ( $s < 80 )   $score_buckets['ok']   += (int) $row['total'];
            else                 $score_buckets['good'] += (int) $row['total'];
        }
        $avg_score = $scored_total ? (int) $wpdb->get_var(
            "SELECT AVG(CAST(meta_value AS UNSIGNED))
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'rank_math_seo_score'
               AND p.post_status = 'publish'
               AND p.post_type NOT IN ('attachment','revision','nav_menu_item')"
        ) : 0;
        $missing_kw = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'rank_math_focus_keyword'
             WHERE p.post_status = 'publish'
               AND p.post_type NOT IN ('attachment','revision','nav_menu_item','wp_block')
               AND (pm.meta_value IS NULL OR pm.meta_value = '')"
        );
        $missing_desc = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'rank_math_description'
             WHERE p.post_status = 'publish'
               AND p.post_type NOT IN ('attachment','revision','nav_menu_item','wp_block')
               AND (pm.meta_value IS NULL OR pm.meta_value = '')"
        );
        $ok_ic  = '<span class="ahmc-ok-ic">' . rmai_admin_icon( 'check', 15 ) . '</span>';
        $bad_ic = '<span class="ahmc-bad-ic">' . rmai_admin_icon( 'x', 15 ) . '</span>';
        ?>

        <?php if ( $geo_error ) : ?>
            <div class="ahmc-alert bad"><?php echo rmai_admin_icon( 'alert', 16 ); ?><div><?php echo esc_html( $geo_error ); ?></div></div>
        <?php endif; ?>

        <!-- Scores SEO -->
        <section class="ahmc-card" style="margin-bottom:16px" aria-labelledby="ahmc-h-scores">
            <h2 id="ahmc-h-scores"><?php echo rmai_admin_icon( 'chart' ); ?> Scores SEO del sitio <span class="ahmc-mu" style="font-weight:400">Rank Math</span></h2>
            <div class="ahmc-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin:12px 0 14px">
                <div><div class="ahmc-big" style="color:<?php echo $avg_score >= 70 ? 'var(--ok)' : ( $avg_score >= 40 ? 'var(--warn)' : 'var(--bad)' ); ?>"><?php echo (int) $avg_score; ?></div><div class="ahmc-mu">Score medio</div></div>
                <div><div class="ahmc-big"><?php echo (int) $scored_total; ?></div><div class="ahmc-mu">Con score</div></div>
                <div><div class="ahmc-big"><?php echo (int) $score_buckets['good']; ?></div><div class="ahmc-mu">Buenos (80+)</div></div>
                <div><div class="ahmc-big"><?php echo (int) $score_buckets['ok']; ?></div><div class="ahmc-mu">Mejorables (50-79)</div></div>
                <div><div class="ahmc-big"><?php echo (int) $score_buckets['poor']; ?></div><div class="ahmc-mu">Pobres (&lt;50)</div></div>
                <div><div class="ahmc-big" style="color:<?php echo $missing_kw ? 'var(--bad)' : 'var(--ok)'; ?>"><?php echo (int) $missing_kw; ?></div><div class="ahmc-mu">Sin keyword</div><div class="ahmc-mu ahmc-mono" style="font-size:11px">?missing=focus_keyword</div></div>
                <div><div class="ahmc-big" style="color:<?php echo $missing_desc ? 'var(--bad)' : 'var(--ok)'; ?>"><?php echo (int) $missing_desc; ?></div><div class="ahmc-mu">Sin description</div><div class="ahmc-mu ahmc-mono" style="font-size:11px">?missing=seo_description</div></div>
            </div>
            <?php if ( $scored_total > 0 ) : ?>
                <div class="ahmc-bar" role="img" aria-label="<?php echo esc_attr( sprintf( 'Pobres %d, mejorables %d, buenos %d', $score_buckets['poor'], $score_buckets['ok'], $score_buckets['good'] ) ); ?>">
                    <?php foreach ( [ 'poor' => 'var(--bad)', 'ok' => 'var(--warn)', 'good' => 'var(--ok)' ] as $bk => $bc ) : if ( $score_buckets[ $bk ] ) : ?>
                        <div style="width:<?php echo esc_attr( round( $score_buckets[ $bk ] / $scored_total * 100 ) ); ?>%;background:<?php echo esc_attr( $bc ); ?>"></div>
                    <?php endif; endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ( $geo_data ) :
            $geo_score   = (int) ( $geo_data['geo_score'] ?? 0 );
            $geo_rating  = $geo_data['geo_rating'] ?? 'poor';
            $score_color = $geo_score >= 70 ? 'var(--ok)' : ( $geo_score >= 40 ? 'var(--warn)' : 'var(--bad)' );
            $checks      = $geo_data['checks'] ?? [];
            $recs        = $geo_data['recommendations'] ?? [];
            $robots      = $geo_data['robots_txt'] ?? [];
            $llms        = $geo_data['llms_txt'] ?? [];
            $schema      = $geo_data['schema'] ?? [];
            $eat         = $geo_data['eat_signals'] ?? [];
        ?>
        <div class="ahmc-grid" style="grid-template-columns:minmax(200px,240px) minmax(0,1fr);margin-bottom:16px">
            <section class="ahmc-card" style="text-align:center">
                <h3>GEO score</h3>
                <div style="width:104px;height:104px;border-radius:50%;background:conic-gradient(<?php echo esc_attr( $score_color ); ?> <?php echo esc_attr( $geo_score * 3.6 ); ?>deg,#f4f4f5 0);display:grid;place-items:center;margin:4px auto 10px" role="img" aria-label="GEO score <?php echo (int) $geo_score; ?> de 100">
                    <div style="width:82px;height:82px;background:#fff;border-radius:50%;display:grid;place-items:center"><div><div class="ahmc-big"><?php echo (int) $geo_score; ?></div><div class="ahmc-mu">/100</div></div></div>
                </div>
                <span class="ahmc-pill <?php echo $geo_score >= 70 ? 'ok' : ( $geo_score >= 40 ? 'warn' : 'bad' ); ?>"><?php echo esc_html( $geo_rating ); ?></span>
                <div class="ahmc-mu" style="margin-top:8px;overflow-wrap:anywhere"><?php echo esc_html( $geo_data['site_url'] ?? '' ); ?></div>
            </section>
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'check' ); ?> Checks GEO</h2>
                <div class="ahmc-grid ahmc-g2" style="gap:8px;margin-top:10px">
                    <?php foreach ( $checks as $check ) : $pass = ! empty( $check['pass'] ); ?>
                        <div class="ahmc-check"><?php echo $pass ? $ok_ic : $bad_ic; ?><div><div style="font-weight:500"><?php echo esc_html( $check['label'] ?? '' ); ?></div><div class="ahmc-mu">peso: <?php echo (int) ( $check['weight'] ?? 0 ); ?> pts · <?php echo $pass ? 'cumple' : 'no cumple'; ?></div></div></div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="ahmc-grid ahmc-g2" style="margin-bottom:16px">
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'file' ); ?> llms.txt</h2>
                <?php $llms_ok = ! empty( $llms['exists'] ); $llmsfull = ! empty( $llms['full_exists'] ); ?>
                <table class="ahmc-table" style="margin-top:6px"><tbody>
                    <tr><td style="width:28px"><?php echo $llms_ok ? $ok_ic : $bad_ic; ?></td><td><strong>llms.txt</strong> <span class="screen-reader-text"><?php echo $llms_ok ? 'existe' : 'no existe'; ?></span><?php if ( $llms_ok && ! empty( $llms['url'] ) ) : ?><div><a href="<?php echo esc_url( $llms['url'] ); ?>" target="_blank" rel="noopener" class="ahmc-mu"><?php echo esc_html( $llms['url'] ); ?></a></div><?php endif; ?></td></tr>
                    <tr><td><?php echo $llmsfull ? $ok_ic : $bad_ic; ?></td><td><strong>llms-full.txt</strong> <span class="screen-reader-text"><?php echo $llmsfull ? 'existe' : 'no existe'; ?></span><?php if ( $llmsfull && ! empty( $llms['full_url'] ) ) : ?><div><a href="<?php echo esc_url( $llms['full_url'] ); ?>" target="_blank" rel="noopener" class="ahmc-mu"><?php echo esc_html( $llms['full_url'] ); ?></a></div><?php endif; ?></td></tr>
                </tbody></table>
                <?php if ( ! empty( $llms['preview'] ) ) : ?>
                    <details style="margin-top:10px"><summary>Vista previa de llms.txt</summary><div><pre><?php echo esc_html( $llms['preview'] ); ?></pre></div></details>
                <?php endif; ?>
            </section>
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'users' ); ?> Bots IA en robots.txt</h2>
                <?php $blocked = $robots['ai_blocked'] ?? []; $allowed = $robots['ai_allowed'] ?? []; ?>
                <?php if ( $blocked ) : ?>
                    <h3 style="margin-top:10px">Bloqueados (<?php echo count( $blocked ); ?>)</h3>
                    <div class="ahmc-flex" style="gap:6px"><?php foreach ( $blocked as $bot ) : ?><span class="ahmc-pill bad"><?php echo esc_html( $bot['bot'] ?? '' ); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ( $allowed ) : ?>
                    <h3 style="margin-top:10px">Permitidos (<?php echo count( $allowed ); ?>)</h3>
                    <div class="ahmc-flex" style="gap:6px"><?php foreach ( $allowed as $bot ) : ?><span class="ahmc-pill ok"><?php echo esc_html( $bot['bot'] ?? '' ); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ( ! $blocked && ! $allowed ) : ?>
                    <p class="ahmc-flex" style="margin:10px 0 0"><?php echo $ok_ic; ?> Ningún bot IA bloqueado: acceso libre.</p>
                <?php endif; ?>
            </section>
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'code' ); ?> Schema.org detectado</h2>
                <?php $schema_ok = $schema['ok'] ?? []; $schema_missing = $schema['missing'] ?? []; ?>
                <?php if ( $schema_ok ) : ?>
                    <h3 style="margin-top:10px">Presentes</h3>
                    <div class="ahmc-flex" style="gap:6px"><?php foreach ( $schema_ok as $s ) : ?><span class="ahmc-pill ok"><?php echo esc_html( $s ); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ( $schema_missing ) : ?>
                    <h3 style="margin-top:10px">Faltan</h3>
                    <div class="ahmc-flex" style="gap:6px"><?php foreach ( $schema_missing as $s ) : ?><span class="ahmc-pill warn"><?php echo esc_html( $s ); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
            </section>
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'users' ); ?> Señales E-E-A-T</h2>
                <?php $authors = $eat['authors'] ?? []; $legal_pgs = $eat['legal_pages'] ?? []; ?>
                <?php if ( $authors ) : ?>
                    <h3 style="margin-top:10px">Autores</h3>
                    <table class="ahmc-table"><tbody>
                    <?php foreach ( $authors as $author ) : $bio_ok = ! empty( $author['has_bio'] ); $av_ok = ! empty( $author['has_avatar'] ); ?>
                        <tr><td><?php echo esc_html( $author['name'] ?? '' ); ?></td><td style="text-align:right"><span class="ahmc-pill <?php echo $bio_ok ? 'ok' : 'bad'; ?>">bio</span> <span class="ahmc-pill <?php echo $av_ok ? 'ok' : 'bad'; ?>">avatar</span></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
                <?php if ( $legal_pgs ) : ?>
                    <h3 style="margin-top:10px">Páginas legales</h3>
                    <?php foreach ( $legal_pgs as $pg ) : ?>
                        <a href="<?php echo esc_url( $pg['url'] ?? '' ); ?>" target="_blank" rel="noopener" style="display:block;margin-bottom:2px"><?php echo esc_html( $pg['slug'] ?? '' ); ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>

        <?php if ( $recs ) : ?>
            <section class="ahmc-card">
                <h2><?php echo rmai_admin_icon( 'bulb' ); ?> Recomendaciones GEO</h2>
                <table class="ahmc-table" style="margin-top:6px"><tbody>
                <?php foreach ( $recs as $rec ) :
                    $prio = $rec['priority'] ?? 'medium';
                    $pc   = [ 'critical' => 'bad', 'high' => 'warn', 'medium' => 'info', 'low' => 'ok' ][ $prio ] ?? 'info'; ?>
                    <tr><td style="width:90px"><span class="ahmc-pill <?php echo esc_attr( $pc ); ?>"><?php echo esc_html( $prio ); ?></span></td><td><?php echo esc_html( $rec['action'] ?? '' ); ?><?php if ( ! empty( $rec['file'] ) ) : ?> <code><?php echo esc_html( $rec['file'] ); ?></code><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </section>
        <?php endif; ?>

        <?php endif; // $geo_data ?>

    <?php endif; // pestañas ?>

    </div><!-- .ahmc -->

    <script>
    function ahmcCopy(inputId, feedbackId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        var done = function () {
            var el = document.getElementById(feedbackId);
            if (!el) return;
            el.textContent = 'Copiado';
            setTimeout(function () { el.textContent = ''; }, 2000);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done);
        } else {
            var type = input.type; input.type = 'text'; input.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            input.type = type;
        }
    }
    function ahmcReveal(inputId, btn) {
        var input = document.getElementById(inputId);
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    }
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════
// 3. REGISTRO DE RUTAS REST
// ═══════════════════════════════════════════════════════

add_action( 'rest_api_init', 'rmai_register_routes' );
function rmai_register_routes(): void {

    $perm = 'rmai_check_permission';

    register_rest_route( RMAI_NAMESPACE, '/posts', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_get_posts',
        'permission_callback' => $perm,
        'args'                => [
            'type'          => [ 'default' => 'post',     'sanitize_callback' => 'sanitize_text_field' ],
            'per_page'      => [ 'default' => 20,         'sanitize_callback' => 'absint' ],
            'page'          => [ 'default' => 1,          'sanitize_callback' => 'absint' ],
            'search'        => [ 'default' => '',         'sanitize_callback' => 'sanitize_text_field' ],
            'orderby'       => [ 'default' => 'modified', 'sanitize_callback' => 'sanitize_key' ],
            'missing'       => [ 'default' => '',         'sanitize_callback' => 'sanitize_key' ],
            'seo_score_lt'  => [ 'default' => 0,          'sanitize_callback' => 'absint' ],
            'seo_score_gt'  => [ 'default' => 0,          'sanitize_callback' => 'absint' ],
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/post/(?P<id>\d+)', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_get_post_seo',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'rmai_update_post_seo',
            'permission_callback' => $perm,
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/bulk-update', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_bulk_update',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/post/(?P<id>\d+)/score', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_get_post_score',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/post-types', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_get_post_types',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/info', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_get_site_info',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/post/(?P<id>\d+)/content', [
        'methods'             => 'PUT, PATCH',
        'callback'            => 'rmai_update_post_content',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/bulk-content', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_bulk_content',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/post/(?P<id>\d+)/elementor-replace', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_elementor_replace',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/bulk-attributes', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_bulk_attributes',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/post/(?P<id>\d+)/meta', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_get_post_meta',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'rmai_set_post_meta',
            'permission_callback' => $perm,
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/create-post', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_create_post',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/recalculate-scores', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_recalculate_scores',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/product/(?P<id>\d+)/variations', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_get_product_variations',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/bulk-variation-descriptions', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_bulk_variation_descriptions',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/bulk-delete-variations', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_bulk_delete_variations',
        'permission_callback' => $perm,
    ] );

    // ── Imágenes / Media ──────────────────────────────
    register_rest_route( RMAI_NAMESPACE, '/post/(?P<id>\d+)/images', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_get_post_images',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'rmai_update_post_images',
            'permission_callback' => $perm,
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/media', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_get_media',
        'permission_callback' => $perm,
        'args'                => [
            'per_page'    => [ 'default' => 20,    'sanitize_callback' => 'absint' ],
            'page'        => [ 'default' => 1,     'sanitize_callback' => 'absint' ],
            'search'      => [ 'default' => '',    'sanitize_callback' => 'sanitize_text_field' ],
            'missing_alt' => [ 'default' => false ],
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/media/(?P<id>\d+)', [
        'methods'             => 'PUT, PATCH',
        'callback'            => 'rmai_update_media',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/bulk-media-alt', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_bulk_media_alt',
        'permission_callback' => $perm,
    ] );
}

// ═══════════════════════════════════════════════════════
// 4. AUTENTICACIÓN, RATE LIMIT Y LOG
// ═══════════════════════════════════════════════════════

function rmai_check_permission( WP_REST_Request $request ) {
    if ( ! get_option( RMAI_OPTION_ENABLED, 1 ) ) {
        return new WP_Error( 'rmai_disabled', 'API desactivada temporalmente.', [ 'status' => 503 ] );
    }

    $settings    = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    $stored_key  = get_option( RMAI_OPTION_API_KEY, '' );
    $request_key = $request->get_header( 'X-RMAI-Key' );
    $ip          = rmai_get_ip();

    $whitelist = array_filter( array_map( 'trim', explode( ',', $settings['ip_whitelist'] ) ) );
    if ( ! empty( $whitelist ) && ! in_array( $ip, $whitelist, true ) ) {
        rmai_log( $request, 403, $ip );
        return new WP_Error( 'rmai_forbidden', 'IP no permitida.', [ 'status' => 403 ] );
    }

    if ( empty( $stored_key ) || ! hash_equals( $stored_key, (string) $request_key ) ) {
        rmai_log( $request, 401, $ip );
        return new WP_Error( 'rmai_unauthorized', 'Clave de API inválida o ausente.', [ 'status' => 401 ] );
    }

    if ( $settings['rate_limit_enabled'] ) {
        // Ventana fija de 60 s: se guarda cuándo empezó y cuántas van. La
        // caducidad se fija a lo que le queda a la ventana (antes se renovaba
        // en cada petición y el contador no se vaciaba nunca con tráfico
        // espaciado: 1 petición cada 30 s acababa en 429 a la media hora).
        $transient = 'rmai_rl_' . md5( $ip );
        $now       = time();
        $window    = get_transient( $transient );
        if ( ! is_array( $window ) || ! isset( $window['start'], $window['count'] ) || $now - (int) $window['start'] >= 60 ) {
            $window = [ 'start' => $now, 'count' => 0 ];
        }
        if ( (int) $window['count'] >= rmai_rate_limit() ) {
            $retry = max( 1, 60 - ( $now - (int) $window['start'] ) );
            rmai_log( $request, 429, $ip );
            return new WP_Error( 'rmai_rate_limit', 'Demasiadas peticiones. Reintenta en ' . $retry . ' s.', [ 'status' => 429, 'retry_after' => $retry ] );
        }
        $window['count'] = (int) $window['count'] + 1;
        set_transient( $transient, $window, max( 1, 60 - ( $now - (int) $window['start'] ) ) );
    }

    rmai_no_cache();
    rmai_log( $request, 200, $ip );
    return true;
}

/**
 * Impide que un plugin de caché (LiteSpeed, WP Rocket, etc.) sirva respuestas
 * de la API desde disco. Sin esto un GET repetido devuelve datos obsoletos y
 * una escritura recién hecha parece no haberse aplicado.
 */
function rmai_no_cache(): void {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }
    if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
        define( 'DONOTCACHEOBJECT', true );
    }
    if ( ! headers_sent() ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
    }
}

function rmai_get_ip(): string {
    // REMOTE_ADDR es el único valor no falsificable por el cliente.
    $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown';

    // Solo se confían cabeceras de proxy (X-Forwarded-For / CF-Connecting-IP) si
    // la petición llega desde un proxy declarado explícitamente por el admin.
    // Definir en wp-config.php:  define( 'RMAI_TRUSTED_PROXIES', '1.2.3.4,10.0.0.1' );
    if ( defined( 'RMAI_TRUSTED_PROXIES' ) && RMAI_TRUSTED_PROXIES ) {
        $trusted = array_filter( array_map( 'trim', explode( ',', (string) RMAI_TRUSTED_PROXIES ) ) );
        if ( in_array( $remote, $trusted, true ) ) {
            foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ] as $key ) {
                if ( ! empty( $_SERVER[ $key ] ) ) {
                    $candidate = sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
                    if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                        return $candidate;
                    }
                }
            }
        }
    }

    return $remote;
}

function rmai_log( WP_REST_Request $request, int $status, string $ip ): void {
    $settings = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    if ( ! $settings['log_enabled'] ) return;

    $log   = get_option( RMAI_LOG_OPTION, [] );
    $log[] = [
        'date'   => current_time( 'Y-m-d H:i:s' ),
        'method' => $request->get_method(),
        'route'  => $request->get_route(),
        'status' => $status,
        'ip'     => $ip,
    ];

    if ( count( $log ) > RMAI_LOG_MAX ) {
        $log = array_slice( $log, -RMAI_LOG_MAX );
    }

    update_option( RMAI_LOG_OPTION, $log );
}

// ═══════════════════════════════════════════════════════
// 5. MAPA DE CAMPOS DE RANK MATH
// ═══════════════════════════════════════════════════════

function rmai_field_map(): array {
    return [
        'seo_title'           => 'rank_math_title',
        'seo_description'     => 'rank_math_description',
        'focus_keyword'       => 'rank_math_focus_keyword',
        'canonical_url'       => 'rank_math_canonical_url',
        'robots'              => 'rank_math_robots',
        'og_title'            => 'rank_math_og_title',
        'og_description'      => 'rank_math_og_description',
        'og_image_url'        => 'rank_math_og_image_url',
        'twitter_title'       => 'rank_math_twitter_title',
        'twitter_description' => 'rank_math_twitter_description',
        'twitter_card_type'   => 'rank_math_twitter_card_type',
        'schema_type'         => 'rank_math_rich_snippet',
        'pillar_content'      => 'rank_math_pillar_content',
        'breadcrumb_title'    => 'rank_math_breadcrumb_title',
    ];
}

function rmai_read_seo_data( int $post_id ): array {
    $data = [];
    foreach ( rmai_field_map() as $key => $meta_key ) {
        $raw          = get_post_meta( $post_id, $meta_key, true );
        $data[ $key ] = ( $raw !== '' && $raw !== false ) ? $raw : null;
    }
    $score          = (int) get_post_meta( $post_id, 'rank_math_seo_score', true );
    $data['score']  = $score ?: null;
    $data['rating'] = $score ? rmai_score_rating( $score ) : null;
    return $data;
}

// FIX: incluye post_content y post_excerpt en la respuesta
function rmai_build_post_response( WP_Post $post ): array {
    return [
        'id'       => $post->ID,
        'title'    => $post->post_title,
        'slug'     => $post->post_name,
        'url'      => get_permalink( $post->ID ),
        'type'     => $post->post_type,
        'status'   => $post->post_status,
        'modified' => $post->post_modified,
        'content'  => $post->post_content,
        'excerpt'  => $post->post_excerpt,
        'seo'      => rmai_read_seo_data( $post->ID ),
    ];
}

// ═══════════════════════════════════════════════════════
// 6. CALLBACKS
// ═══════════════════════════════════════════════════════

/** GET /posts */
function rmai_get_posts( WP_REST_Request $request ) {
    $type         = $request->get_param( 'type' );
    $per_page     = min( $request->get_param( 'per_page' ), 100 );
    $paged        = $request->get_param( 'page' );
    $search       = $request->get_param( 'search' );
    $orderby_raw  = $request->get_param( 'orderby' );
    $missing      = $request->get_param( 'missing' );
    $score_lt     = (int) $request->get_param( 'seo_score_lt' );
    $score_gt     = (int) $request->get_param( 'seo_score_gt' );
    $allowed_ob   = [ 'modified', 'date', 'title', 'ID', 'menu_order' ];
    $orderby      = in_array( $orderby_raw, $allowed_ob, true ) ? $orderby_raw : 'modified';

    $allowed_types = array_keys( get_post_types( [ 'public' => true ] ) );
    // 'any' = todos los tipos públicos
    if ( $type === 'any' ) {
        $query_type = $allowed_types;
    } elseif ( ! in_array( $type, $allowed_types, true ) ) {
        return new WP_Error( 'rmai_invalid_type', "Tipo '{$type}' no válido. Disponibles: " . implode( ', ', $allowed_types ), [ 'status' => 400 ] );
    } else {
        $query_type = $type;
    }

    $args = [
        'post_type'      => $query_type,
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => $orderby,
        'order'          => 'DESC',
    ];

    if ( ! empty( $search ) ) {
        $args['s'] = $search;
    }

    // Filtros SEO
    $meta_query = [];
    $missing_map = [
        'focus_keyword'   => 'rank_math_focus_keyword',
        'seo_description' => 'rank_math_description',
        'seo_title'       => 'rank_math_title',
    ];

    if ( isset( $missing_map[ $missing ] ) ) {
        $meta_query[] = [
            'relation' => 'OR',
            [ 'key' => $missing_map[ $missing ], 'compare' => 'NOT EXISTS' ],
            [ 'key' => $missing_map[ $missing ], 'value'   => '',           'compare' => '=' ],
        ];
    }

    if ( $score_lt > 0 ) {
        $meta_query[] = [
            'relation' => 'OR',
            [ 'key' => 'rank_math_seo_score', 'compare' => 'NOT EXISTS' ],
            [ 'key' => 'rank_math_seo_score', 'value' => $score_lt, 'compare' => '<', 'type' => 'NUMERIC' ],
        ];
    }

    if ( $score_gt > 0 ) {
        $meta_query[] = [ 'key' => 'rank_math_seo_score', 'value' => $score_gt, 'compare' => '>', 'type' => 'NUMERIC' ];
    }

    if ( ! empty( $meta_query ) ) {
        $args['meta_query'] = count( $meta_query ) > 1 ? array_merge( [ 'relation' => 'AND' ], $meta_query ) : $meta_query[0];
    }

    $query = new WP_Query( $args );
    $items = [];

    foreach ( $query->posts as $post ) {
        $items[] = [
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'slug'     => $post->post_name,
            'url'      => get_permalink( $post->ID ),
            'type'     => $post->post_type,
            'status'   => $post->post_status,
            'modified' => $post->post_modified,
            'seo'      => [
                'seo_title'       => get_post_meta( $post->ID, 'rank_math_title', true )         ?: null,
                'seo_description' => get_post_meta( $post->ID, 'rank_math_description', true )   ?: null,
                'focus_keyword'   => get_post_meta( $post->ID, 'rank_math_focus_keyword', true ) ?: null,
                'score'           => (int) get_post_meta( $post->ID, 'rank_math_seo_score', true ) ?: null,
                'rating'          => rmai_score_rating( (int) get_post_meta( $post->ID, 'rank_math_seo_score', true ) ),
            ],
        ];
    }

    return new WP_REST_Response( [
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'page'        => $paged,
        'per_page'    => $per_page,
        'type'        => $type,
        'filters'     => array_filter( [ 'missing' => $missing ?: null, 'seo_score_lt' => $score_lt ?: null, 'seo_score_gt' => $score_gt ?: null ] ),
        'items'       => $items,
    ], 200 );
}

/** GET /post/{id} */
function rmai_get_post_seo( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }
    return new WP_REST_Response( rmai_build_post_response( $post ), 200 );
}

/** PUT/PATCH /post/{id} */
function rmai_update_post_seo( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    $body = $request->get_json_params();
    if ( empty( $body ) ) {
        return new WP_Error( 'rmai_empty_body', 'El cuerpo JSON está vacío.', [ 'status' => 400 ] );
    }

    $field_map = rmai_field_map();
    $updated   = [];
    $ignored   = [];

    foreach ( $body as $key => $value ) {
        if ( ! array_key_exists( $key, $field_map ) ) {
            $ignored[] = $key;
            continue;
        }
        update_post_meta( $post->ID, $field_map[ $key ], rmai_sanitize_value( $key, $value ) );
        $updated[] = $key;
    }

    return new WP_REST_Response( [
        'success' => true,
        'post_id' => $post->ID,
        'updated' => $updated,
        'ignored' => $ignored,
        'seo'     => rmai_read_seo_data( $post->ID ),
    ], 200 );
}

/** POST /bulk-update — formato: [{id, fields:{seo_title, seo_description, focus_keyword...}}] */
function rmai_bulk_update( WP_REST_Request $request ) {
    $body = $request->get_json_params();

    if ( empty( $body ) || ! is_array( $body ) ) {
        return new WP_Error( 'rmai_empty_body', 'Envía un array de objetos [{id, fields:{}}].', [ 'status' => 400 ] );
    }

    if ( count( $body ) > 50 ) {
        return new WP_Error( 'rmai_too_many', 'Máximo 50 entradas por petición.', [ 'status' => 400 ] );
    }

    $field_map = rmai_field_map();
    $results   = [];

    foreach ( $body as $item ) {
        $id     = isset( $item['id'] ) ? (int) $item['id'] : 0;
        $fields = $item['fields'] ?? [];
        $post   = $id ? get_post( $id ) : null;

        if ( ! $post ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'No encontrado' ];
            continue;
        }

        if ( empty( $fields ) || ! is_array( $fields ) ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'Sin campos' ];
            continue;
        }

        $updated = [];
        $ignored = [];
        foreach ( $fields as $key => $value ) {
            if ( ! array_key_exists( $key, $field_map ) ) {
                $ignored[] = $key;
                continue;
            }
            update_post_meta( $id, $field_map[ $key ], rmai_sanitize_value( $key, $value ) );
            $updated[] = $key;
        }

        $results[] = [
            'id'      => $id,
            'title'   => $post->post_title,
            'success' => true,
            'updated' => $updated,
            'ignored' => $ignored,
        ];
    }

    return new WP_REST_Response( [
        'processed' => count( $results ),
        'results'   => $results,
    ], 200 );
}

/** GET /post/{id}/score */
function rmai_get_post_score( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    $score   = (int) get_post_meta( $post->ID, 'rank_math_seo_score', true );
    $keyword = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );

    return new WP_REST_Response( [
        'post_id'       => $post->ID,
        'title'         => $post->post_title,
        'seo_score'     => $score,
        'rating'        => rmai_score_rating( $score ),
        'focus_keyword' => $keyword ?: null,
    ], 200 );
}

/** GET /post-types */
function rmai_get_post_types() {
    $types  = get_post_types( [ 'public' => true ], 'objects' );
    $result = [];
    foreach ( $types as $type ) {
        $result[] = [
            'slug'  => $type->name,
            'label' => $type->label,
        ];
    }
    return new WP_REST_Response( $result, 200 );
}

/** GET /info */
function rmai_get_site_info() {
    return new WP_REST_Response( [
        'site_name'      => get_bloginfo( 'name' ),
        'site_url'       => get_site_url(),
        'admin_email'    => get_option( 'admin_email' ),
        'language'       => get_locale(),
        'wp_version'     => get_bloginfo( 'version' ),
        'rank_math'      => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
        'woocommerce'    => defined( 'WC_VERSION' ) ? WC_VERSION : null,
        'elementor'      => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
        'plugin_version' => RMAI_VERSION,
        'post_types'     => array_keys( get_post_types( [ 'public' => true ] ) ),
        'route_groups'   => rmai_route_groups(),
        'rate_limit'     => rmai_rate_limit(),
    ], 200 );
}

/** GET /post/{id}/meta */
function rmai_get_post_meta( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    $keys_param = $request->get_param( 'keys' );
    $all_meta   = get_post_meta( $post->ID );
    $result     = [];

    if ( $keys_param ) {
        $keys = array_map( 'sanitize_text_field', explode( ',', $keys_param ) );
        foreach ( $keys as $key ) {
            $result[ $key ] = get_post_meta( $post->ID, $key, true );
        }
    } else {
        $wc_keys = [
            '_sku', '_price', '_regular_price', '_stock_status',
            '_product_attributes', 'attribute_ingredientes',
            'attribute_valor-nutricional', 'attribute_informacion-nutricional',
        ];
        foreach ( $wc_keys as $key ) {
            $val = get_post_meta( $post->ID, $key, true );
            if ( $val !== '' && $val !== false ) {
                $result[ $key ] = $val;
            }
        }
        foreach ( $all_meta as $key => $values ) {
            if ( stripos( $key, 'nutri' ) !== false || stripos( $key, 'ingred' ) !== false ) {
                $result[ $key ] = maybe_unserialize( $values[0] );
            }
        }
    }

    return new WP_REST_Response( [
        'post_id' => $post->ID,
        'title'   => $post->post_title,
        'meta'    => $result,
    ], 200 );
}

/** POST /post/{id}/meta */
function rmai_set_post_meta( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    $body = $request->get_json_params();
    if ( empty( $body ) || ! isset( $body['key'] ) ) {
        return new WP_Error( 'rmai_missing', 'Incluye {key, value} en el cuerpo.', [ 'status' => 400 ] );
    }

    $key   = sanitize_text_field( $body['key'] );
    $value = $body['value'];

    // Las claves que guardan un blob JSON no pueden quedar a medias: si el JSON
    // no parsea, Elementor deja de renderizar la plantilla entera. Se valida
    // antes de tocar la base de datos.
    if ( rmai_meta_expects_json( $key ) && is_string( $value ) && '' !== $value ) {
        json_decode( $value );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error(
                'rmai_invalid_json',
                sprintf( 'El valor de "%s" debe ser JSON válido: %s', $key, json_last_error_msg() ),
                [ 'status' => 400 ]
            );
        }
    }

    $existed  = metadata_exists( 'post', $post->ID, $key );
    $previous = $existed ? get_post_meta( $post->ID, $key, true ) : null;

    $is_elementor = ( '_elementor_data' === $key && is_string( $value ) );

    // update_metadata() aplica wp_unslash() al valor, así que hay que escaparlo antes.
    // Sin esto, cualquier JSON con barras invertidas (p. ej. _elementor_data) se corrompe.
    if ( $is_elementor ) {
        rmai_write_elementor_data( $post->ID, $value );
    } else {
        update_post_meta( $post->ID, $key, wp_slash( $value ) );
    }

    // Verificación de ida y vuelta: si lo almacenado no coincide con lo enviado
    // (barras invertidas comidas, sanitización de terceros, etc.) se revierte en
    // lugar de dejar el contenido corrupto.
    $stored = get_post_meta( $post->ID, $key, true );
    if ( ! rmai_meta_values_match( $value, $stored ) ) {
        if ( $existed && '_elementor_data' === $key && is_string( $previous ) ) {
            rmai_write_elementor_data( $post->ID, $previous );
        } elseif ( $existed ) {
            update_post_meta( $post->ID, $key, wp_slash( $previous ) );
        } else {
            delete_post_meta( $post->ID, $key );
        }
        return new WP_Error(
            'rmai_meta_roundtrip_failed',
            sprintf( 'El valor almacenado en "%s" no coincide con el enviado. Se revirtió al valor anterior.', $key ),
            [ 'status' => 500 ]
        );
    }

    if ( $is_elementor ) {
        rmai_clear_elementor_post_css( $post->ID );
    }

    return new WP_REST_Response( [
        'success'  => true,
        'post_id'  => $post->ID,
        'key'      => $key,
        'verified' => true,
        'stored'   => $stored,
    ], 200 );
}

/**
 * Escribe _elementor_data sin que pase por el sanitize_callback de Elementor.
 *
 * Elementor 4.x registra _elementor_data con register_meta() y un
 * sanitize_callback que, si el usuario actual no tiene unfiltered_html, hace
 * json_decode + kses_post_deep + wp_json_encode. Las peticiones de esta API se
 * autentican por clave, sin usuario WP, así que Elementor reescribía el JSON,
 * la verificación de ida y vuelta no cuadraba y todo se revertía (500).
 *
 * Solo durante esta escritura se engancha un filtro de prioridad máxima que
 * devuelve el JSON ya validado por el plugin. Los filtros se quitan siempre.
 */
function rmai_write_elementor_data( int $post_id, string $json ): bool {
    $keep_ours = static function () use ( $json ) {
        return $json;
    };

    $hooks     = [ 'sanitize_post_meta__elementor_data' ];
    $post_type = get_post_type( $post_id );
    if ( $post_type ) {
        $hooks[] = 'sanitize_post_meta__elementor_data_for_' . $post_type;
    }

    foreach ( $hooks as $hook ) {
        add_filter( $hook, $keep_ours, PHP_INT_MAX );
    }

    try {
        // update_metadata() aplica wp_unslash(): hay que escapar antes.
        return (bool) update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
    } finally {
        foreach ( $hooks as $hook ) {
            remove_filter( $hook, $keep_ours, PHP_INT_MAX );
        }
    }
}

/**
 * Invalida el CSS generado por Elementor solo para este post, para que los
 * ajustes que producen CSS (z_index, sticky, márgenes...) se apliquen. Elementor
 * lo regenera en la siguiente visita. Sin Elementor no hace nada.
 */
function rmai_clear_elementor_post_css( int $post_id ): void {
    if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
        return;
    }
    if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
        try {
            \Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
            return;
        } catch ( \Throwable $e ) {
            // Si la API interna de Elementor cambia, se cae al borrado del meta.
        }
    }
    delete_post_meta( $post_id, '_elementor_css' );
}

/** Claves cuyo valor debe ser un blob JSON parseable. */
function rmai_meta_expects_json( string $key ): bool {
    if ( '_elementor_data' === $key || '_ahm_jsonld' === $key ) {
        return true;
    }
    return (bool) preg_match( '/(_json|_jsonld)$/', $key );
}

/** Compara lo enviado con lo leído de vuelta sin falsos positivos de tipo. */
function rmai_meta_values_match( $sent, $stored ): bool {
    if ( is_string( $sent ) ) {
        return is_scalar( $stored ) && (string) $stored === $sent;
    }
    if ( is_array( $sent ) ) {
        return is_array( $stored ) && $stored == $sent; // phpcs:ignore WordPress.PHP.StrictComparisons
    }
    return $stored == $sent; // phpcs:ignore WordPress.PHP.StrictComparisons
}

/** POST /create-post */
function rmai_create_post( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    if ( empty( $body['title'] ) ) {
        return new WP_Error( 'rmai_missing_title', 'El campo "title" es obligatorio.', [ 'status' => 400 ] );
    }

    $allowed_types = array_keys( get_post_types( [ 'public' => true ] ) );
    $post_type     = isset( $body['post_type'] ) && in_array( $body['post_type'], $allowed_types, true )
        ? $body['post_type'] : 'post';

    $post_data = [
        'post_title'   => sanitize_text_field( $body['title'] ),
        'post_content' => isset( $body['post_content'] ) ? wp_kses_post( $body['post_content'] ) : '',
        'post_excerpt' => isset( $body['post_excerpt'] ) ? wp_kses_post( $body['post_excerpt'] ) : '',
        'post_status'  => isset( $body['status'] ) && in_array( $body['status'], [ 'publish', 'draft', 'private' ], true )
            ? $body['status'] : 'draft',
        'post_type'    => $post_type,
    ];

    if ( ! empty( $body['slug'] ) ) {
        $post_data['post_name'] = sanitize_title( $body['slug'] );
    }

    $post_id = wp_insert_post( $post_data, true );
    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'rmai_create_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    $field_map = rmai_field_map();
    $seo_set   = [];
    foreach ( $field_map as $key => $meta_key ) {
        if ( isset( $body[ $key ] ) ) {
            update_post_meta( $post_id, $meta_key, rmai_sanitize_value( $key, $body[ $key ] ) );
            $seo_set[] = $key;
        }
    }

    return new WP_REST_Response( [
        'success'  => true,
        'post_id'  => $post_id,
        'url'      => get_permalink( $post_id ),
        'status'   => $post_data['post_status'],
        'seo_set'  => $seo_set,
    ], 201 );
}

/** PUT/PATCH /post/{id}/content */
function rmai_update_post_content( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    if ( rmai_content_write_blocked( $post ) ) {
        return new WP_Error(
            'rmai_elementor_protected',
            'Esta página está construida con Elementor. Modifica el contenido manualmente desde el editor de Elementor para no romper el diseño.',
            [ 'status' => 403 ]
        );
    }

    $body = $request->get_json_params();
    if ( empty( $body ) ) {
        return new WP_Error( 'rmai_empty_body', 'El cuerpo JSON está vacío.', [ 'status' => 400 ] );
    }

    $update  = [ 'ID' => $post->ID ];
    $updated = [];

    if ( isset( $body['post_content'] ) ) {
        $update['post_content'] = wp_kses_post( $body['post_content'] );
        $updated[] = 'post_content';
    }

    if ( isset( $body['post_excerpt'] ) ) {
        $update['post_excerpt'] = wp_kses_post( $body['post_excerpt'] );
        $updated[] = 'post_excerpt';
    }

    if ( empty( $updated ) ) {
        return new WP_Error( 'rmai_no_fields', 'Incluye post_content y/o post_excerpt.', [ 'status' => 400 ] );
    }

    $result = wp_update_post( $update, true );
    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'rmai_update_failed', $result->get_error_message(), [ 'status' => 500 ] );
    }

    rmai_trigger_score_recalculation( $post->ID );

    return new WP_REST_Response( [
        'success' => true,
        'post_id' => $post->ID,
        'updated' => $updated,
    ], 200 );
}

/** POST /bulk-attributes — solo disponible con WooCommerce */
function rmai_bulk_attributes( WP_REST_Request $request ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'rmai_no_woocommerce', 'WooCommerce no está activo.', [ 'status' => 400 ] );
    }

    $body = $request->get_json_params();

    if ( empty( $body ) || ! is_array( $body ) ) {
        return new WP_Error( 'rmai_empty_body', 'Envía un array de {id, attributes}.', [ 'status' => 400 ] );
    }

    if ( count( $body ) > 20 ) {
        return new WP_Error( 'rmai_too_many', 'Máximo 20 entradas por petición.', [ 'status' => 400 ] );
    }

    $results = [];

    foreach ( $body as $item ) {
        $id   = isset( $item['id'] ) ? (int) $item['id'] : 0;
        $post = $id ? get_post( $id ) : null;

        if ( ! $post ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'No encontrado' ];
            continue;
        }

        $new_attrs = $item['attributes'] ?? [];
        if ( empty( $new_attrs ) || ! is_array( $new_attrs ) ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'Sin atributos' ];
            continue;
        }

        $existing = get_post_meta( $id, '_product_attributes', true );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        foreach ( $new_attrs as $slug => $attr_data ) {
            $slug = sanitize_title( $slug );
            $existing[ $slug ] = [
                'name'         => sanitize_text_field( $attr_data['name'] ?? $slug ),
                'value'        => wp_kses_post( $attr_data['value'] ?? '' ),
                'position'     => (int) ( $attr_data['position'] ?? count( $existing ) ),
                'is_visible'   => (int) ( $attr_data['is_visible'] ?? 1 ),
                'is_variation' => (int) ( $attr_data['is_variation'] ?? 0 ),
                'is_taxonomy'  => 0,
            ];
        }

        // wp_slash() recursivo: los valores llevan HTML y update_metadata()
        // les aplicaría un wp_unslash() que se come las barras invertidas.
        update_post_meta( $id, '_product_attributes', wp_slash( $existing ) );
        wc_delete_product_transients( $id );

        $results[] = [
            'id'      => $id,
            'title'   => $post->post_title,
            'success' => true,
            'attrs'   => array_keys( $new_attrs ),
        ];
    }

    return new WP_REST_Response( [
        'processed' => count( $results ),
        'results'   => $results,
    ], 200 );
}

// FIX: solo registrar el hook de WooCommerce si WooCommerce está activo
add_action( 'wp_head', 'rmai_fix_additional_info_italic' );
function rmai_fix_additional_info_italic(): void {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }
    echo '<style>.woocommerce-product-attributes-item__value,.woocommerce-product-attributes-item__value *{font-style:normal!important}</style>';
}

// FIX: guarda WooCommerce para el filtro de atributos
if ( class_exists( 'WooCommerce' ) ) {
    add_filter( 'woocommerce_attribute', 'rmai_render_html_in_product_attribute', 10, 3 );
}
function rmai_render_html_in_product_attribute( string $value, $attribute, array $values ): string {
    if ( ! method_exists( $attribute, 'is_taxonomy' ) || $attribute->is_taxonomy() ) {
        return $value;
    }
    $raw = implode( '', $attribute->get_options() );
    if ( strpos( $raw, '<' ) === false ) {
        return $value;
    }
    return wp_kses_post( $raw );
}

/** POST /bulk-content — formato: [{id, post_content?, post_excerpt?, title?, slug?}] */
function rmai_bulk_content( WP_REST_Request $request ) {
    $body = $request->get_json_params();

    if ( empty( $body ) || ! is_array( $body ) ) {
        return new WP_Error( 'rmai_empty_body', 'Envía un array de objetos [{id, post_content?, post_excerpt?, title?, slug?}].', [ 'status' => 400 ] );
    }

    if ( count( $body ) > 20 ) {
        return new WP_Error( 'rmai_too_many', 'Máximo 20 entradas por petición.', [ 'status' => 400 ] );
    }

    $results = [];

    foreach ( $body as $item ) {
        $id   = isset( $item['id'] ) ? (int) $item['id'] : 0;
        $post = $id ? get_post( $id ) : null;

        if ( ! $post ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'No encontrado' ];
            continue;
        }

        // Protección Elementor: bloquear post_content / post_excerpt en páginas Elementor
        // (excepto productos WooCommerce, ver rmai_content_write_blocked()).
        $is_elementor = rmai_content_write_blocked( $post );
        $content_fields_requested = isset( $item['post_content'] ) || isset( $item['post_excerpt'] );

        if ( $is_elementor && $content_fields_requested ) {
            $results[] = [
                'id'      => $id,
                'success' => false,
                'error'   => 'Página construida con Elementor: modifica el contenido manualmente desde Elementor para no romper el diseño.',
                'elementor_protected' => true,
            ];
            continue;
        }

        $update  = [ 'ID' => $id ];
        $updated = [];

        if ( ! $is_elementor && isset( $item['post_content'] ) ) {
            $update['post_content'] = wp_kses_post( $item['post_content'] );
            $updated[] = 'post_content';
        }

        if ( ! $is_elementor && isset( $item['post_excerpt'] ) ) {
            $update['post_excerpt'] = wp_kses_post( $item['post_excerpt'] );
            $updated[] = 'post_excerpt';
        }

        // El título (post_title) es una columna aparte de wp_posts, no forma
        // parte de _elementor_data: cambiarlo es seguro incluso en páginas
        // Elementor, donde el H1 suele leerlo con una etiqueta dinámica.
        if ( isset( $item['title'] ) ) {
            $update['post_title'] = sanitize_text_field( (string) $item['title'] );
            $updated[] = 'title';

            // Se fija el slug actual de forma explícita para blindarlo: un
            // cambio de título nunca debe arrastrar un cambio de URL, salvo
            // que el propio item pida un slug nuevo (se sobrescribe abajo).
            if ( ! isset( $item['slug'] ) ) {
                $update['post_name'] = $post->post_name;
            }
        }

        if ( isset( $item['slug'] ) ) {
            $new_slug = sanitize_title( $item['slug'] );
            $existing = get_page_by_path( $new_slug, OBJECT, $post->post_type );
            if ( $existing && $existing->ID !== $id ) {
                $results[] = [ 'id' => $id, 'success' => false, 'error' => "Slug '{$new_slug}' ya está en uso por ID {$existing->ID}" ];
                continue;
            }
            $update['post_name'] = $new_slug;
            $updated[] = 'slug';
        }

        if ( empty( $updated ) ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'Sin campos de contenido' ];
            continue;
        }

        $result = wp_update_post( $update, true );

        if ( is_wp_error( $result ) ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => $result->get_error_message() ];
            continue;
        }

        // Si se tocó el título sin pedir slug nuevo, se relee y se corrige
        // en caso de que algún hook lo haya desviado: el slug de un cambio
        // de título nunca debe moverse solo.
        if ( in_array( 'title', $updated, true ) && ! isset( $item['slug'] ) ) {
            $post_after = get_post( $id );
            if ( $post_after->post_name !== $post->post_name ) {
                wp_update_post( [ 'ID' => $id, 'post_name' => $post->post_name ] );
            }
        }

        $post = get_post( $id ); // refrescar para reportar el estado real

        rmai_trigger_score_recalculation( $id );

        $results[] = [
            'id'      => $id,
            'title'   => $post->post_title,
            'success' => true,
            'updated' => $updated,
        ];
    }

    return new WP_REST_Response( [
        'processed' => count( $results ),
        'results'   => $results,
    ], 200 );
}

/** POST /recalculate-scores */
function rmai_recalculate_scores( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    $ids  = isset( $body['ids'] ) ? array_map( 'absint', (array) $body['ids'] ) : [];

    if ( empty( $ids ) ) {
        return new WP_Error( 'rmai_empty', 'Envía {ids:[1,2,3]}.', [ 'status' => 400 ] );
    }

    if ( count( $ids ) > 50 ) {
        return new WP_Error( 'rmai_too_many', 'Máximo 50 IDs por petición.', [ 'status' => 400 ] );
    }

    $results = [];
    foreach ( $ids as $id ) {
        $post = get_post( $id );
        if ( ! $post ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'No encontrado' ];
            continue;
        }
        rmai_trigger_score_recalculation( $id );
        $score = (int) get_post_meta( $id, 'rank_math_seo_score', true );
        $results[] = [ 'id' => $id, 'success' => true, 'score' => $score, 'rating' => rmai_score_rating( $score ) ];
    }

    return new WP_REST_Response( [ 'processed' => count( $results ), 'results' => $results ], 200 );
}

function rmai_trigger_score_recalculation( int $post_id ): void {
    if ( ! class_exists( 'RankMath' ) ) return;

    if ( function_exists( 'rank_math' ) ) {
        do_action( 'rank_math/head', $post_id );
    }

    if ( class_exists( 'RankMath\\Analytics\\Stats' ) ) {
        do_action( 'rank_math/analytics/recalculate_score', $post_id );
    }
}

// ═══════════════════════════════════════════════════════
// 7. UTILIDADES
// ═══════════════════════════════════════════════════════

function rmai_sanitize_value( string $key, $value ) {
    $url_fields  = [ 'canonical_url', 'og_image_url' ];
    $bool_fields = [ 'pillar_content' ];

    if ( in_array( $key, $url_fields, true ) ) {
        return esc_url_raw( (string) $value );
    }

    if ( in_array( $key, $bool_fields, true ) ) {
        return $value ? 'on' : '';
    }

    if ( $key === 'robots' ) {
        if ( is_array( $value ) ) {
            return array_map( 'sanitize_key', $value );
        }
        return sanitize_text_field( (string) $value );
    }

    return sanitize_text_field( (string) $value );
}

function rmai_score_rating( int $score ): string {
    if ( $score >= 80 ) return 'good';
    if ( $score >= 51 ) return 'ok';
    if ( $score >= 1  ) return 'bad';
    return 'unknown';
}

// ═══════════════════════════════════════════════════════
// 7b. PROTECCIÓN ELEMENTOR
// ═══════════════════════════════════════════════════════

/**
 * Devuelve true si el post fue construido con Elementor.
 * En ese caso NUNCA se debe modificar post_content desde la API.
 * Los cambios de diseño Elementor siempre deben hacerse manualmente.
 */
function rmai_is_elementor_post( int $post_id ): bool {
    return get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';
}

/**
 * Gate de escritura de post_content / post_excerpt vía API.
 *
 * En páginas Elementor normales el texto visible vive en _elementor_data, así
 * que tocar post_content no cambia nada visible y puede desincronizar ambos.
 * En productos WooCommerce, en cambio, la plantilla de producto único suele
 * leer post_content a través de una etiqueta dinámica de Elementor
 * ("woocommerce-product-content-tag") que solo REFERENCIA el campo — no lo
 * embebe en _elementor_data — así que escribir ahí es seguro.
 */
function rmai_content_write_blocked( WP_Post $post ): bool {
    return rmai_is_elementor_post( $post->ID ) && 'product' !== $post->post_type;
}

/**
 * Cuenta recursivamente los nodos con clave "elType" dentro de un árbol
 * decodificado de _elementor_data. Se usa como huella de la estructura: si el
 * número de nodos cambia tras una sustitución de texto, algo más que el texto
 * se ha visto afectado y no es seguro escribir.
 */
function rmai_count_eltype_nodes( $data ): int {
    if ( ! is_array( $data ) ) {
        return 0;
    }
    $count = isset( $data['elType'] ) ? 1 : 0;
    foreach ( $data as $value ) {
        if ( is_array( $value ) ) {
            $count += rmai_count_eltype_nodes( $value );
        }
    }
    return $count;
}

/**
 * POST /post/{id}/elementor-replace
 *
 * Sustituye texto embebido en _elementor_data operando SIEMPRE sobre la
 * cadena JSON cruda, nunca decodificando el árbol completo a PHP y volviendo
 * a codificarlo: Elementor guarda los acentos tal cual (ñ) y las barras
 * escapadas ("\/"), y un decode+encode global cambia ese escapado sin romper
 * la validez del JSON — pero Elementor deja de renderizar la plantilla
 * (incidente real del 2026-09-02). Por eso cada "replacement" se localiza y
 * sustituye como subcadena literal dentro del blob original.
 */
function rmai_elementor_replace( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    $raw = get_post_meta( $post->ID, '_elementor_data', true );
    if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
        return new WP_Error( 'rmai_no_elementor_data', 'Esta entrada no tiene _elementor_data.', [ 'status' => 400 ] );
    }

    $body         = $request->get_json_params();
    $dry_run      = ! empty( $body['dry_run'] );
    $replacements = ( isset( $body['replacements'] ) && is_array( $body['replacements'] ) ) ? $body['replacements'] : [];

    if ( empty( $replacements ) ) {
        return new WP_Error(
            'rmai_empty_body',
            'Incluye un array "replacements": [{search, replace, expected?}].',
            [ 'status' => 400 ]
        );
    }

    if ( count( $replacements ) > 30 ) {
        return new WP_Error( 'rmai_too_many', 'Máximo 30 replacements por petición.', [ 'status' => 400 ] );
    }

    $working     = $raw;
    $report      = [];
    $any_applied = false;

    foreach ( $replacements as $item ) {
        $search   = isset( $item['search'] ) ? (string) $item['search'] : '';
        $replace  = isset( $item['replace'] ) ? (string) $item['replace'] : '';
        $expected = isset( $item['expected'] ) ? (int) $item['expected'] : 1;

        $entry = [
            'search'   => $search,
            'expected' => $expected,
            'applied'  => false,
        ];

        if ( '' === $search ) {
            $entry['reason'] = 'El campo "search" está vacío.';
            $report[] = $entry;
            continue;
        }

        // Elementor guarda los acentos escapados como ñ y las barras como
        // "\/" (escapado por defecto de json_encode, sin JSON_UNESCAPED_UNICODE).
        // Se busca primero en esa forma, que es la real dentro del blob; si
        // no aparece, se prueba la forma literal.
        $escaped_search  = substr( wp_json_encode( $search ), 1, -1 );
        $escaped_replace = substr( wp_json_encode( $replace ), 1, -1 );

        $count_escaped = substr_count( $working, $escaped_search );

        if ( $count_escaped > 0 ) {
            $form         = 'escaped';
            $found_count  = $count_escaped;
            $search_form  = $escaped_search;
            $replace_form = $escaped_replace;
        } else {
            $form         = 'literal';
            $found_count  = substr_count( $working, $search );
            $search_form  = $search;
            $replace_form = $replace;
        }

        $entry['form']  = $form;
        $entry['found'] = $found_count;

        if ( $found_count !== $expected ) {
            $entry['reason'] = ( 0 === $found_count )
                ? 'No se encontró la cadena buscada (ni en forma escapada ni literal).'
                : sprintf( 'Se encontraron %d apariciones, se esperaban %d.', $found_count, $expected );
            $report[] = $entry;
            continue;
        }

        // Misma forma (escapada o literal) para search y replace: nunca se
        // mezclan escapados distintos dentro de la misma cadena resultante.
        $working = str_replace( $search_form, $replace_form, $working );
        $entry['applied'] = true;
        $any_applied = true;
        $report[] = $entry;
    }

    if ( ! $any_applied ) {
        return new WP_REST_Response( [
            'success' => false,
            'post_id' => $post->ID,
            'report'  => $report,
        ], 200 );
    }

    $decoded_new = json_decode( $working, true );
    if ( JSON_ERROR_NONE !== json_last_error() ) {
        return new WP_Error(
            'rmai_broken_json',
            'La sustitución generaría un JSON inválido en _elementor_data. No se ha escrito nada: ' . json_last_error_msg(),
            [ 'status' => 500 ]
        );
    }

    $decoded_original = json_decode( $raw, true );
    $nodes_before      = rmai_count_eltype_nodes( $decoded_original );
    $nodes_after       = rmai_count_eltype_nodes( $decoded_new );

    if ( $nodes_before !== $nodes_after ) {
        return new WP_Error(
            'rmai_node_count_mismatch',
            sprintf(
                'La sustitución alteraría la estructura de Elementor (%d nodos antes, %d después), no solo el texto. No se ha escrito nada.',
                $nodes_before,
                $nodes_after
            ),
            [ 'status' => 500 ]
        );
    }

    $bytes_before = strlen( $raw );
    $bytes_after  = strlen( $working );

    if ( $dry_run ) {
        return new WP_REST_Response( [
            'success'      => true,
            'dry_run'      => true,
            'post_id'      => $post->ID,
            'report'       => $report,
            'bytes_before' => $bytes_before,
            'bytes_after'  => $bytes_after,
            'nodes_before' => $nodes_before,
            'nodes_after'  => $nodes_after,
        ], 200 );
    }

    // update_metadata() aplica wp_unslash() al valor: hay que escaparlo antes,
    // igual que en rmai_set_post_meta(), o las barras invertidas de Elementor
    // se corrompen al guardar.
    rmai_write_elementor_data( $post->ID, $working );

    // Verificación de ida y vuelta: si lo almacenado no coincide EXACTAMENTE
    // con lo que se intentó guardar, se revierte de inmediato al valor original.
    $stored = get_post_meta( $post->ID, '_elementor_data', true );
    if ( $stored !== $working ) {
        rmai_write_elementor_data( $post->ID, $raw );
        return new WP_Error(
            'rmai_meta_roundtrip_failed',
            'El valor almacenado no coincide exactamente con el generado. Se ha revertido _elementor_data al valor original.',
            [ 'status' => 500 ]
        );
    }

    rmai_clear_elementor_post_css( $post->ID );
    rmai_trigger_score_recalculation( $post->ID );

    return new WP_REST_Response( [
        'success'      => true,
        'dry_run'      => false,
        'post_id'      => $post->ID,
        'report'       => $report,
        'bytes_before' => $bytes_before,
        'bytes_after'  => $bytes_after,
        'nodes_before' => $nodes_before,
        'nodes_after'  => $nodes_after,
    ], 200 );
}

// ═══════════════════════════════════════════════════════
// 8. VARIACIONES DE PRODUCTO (solo con WooCommerce)
// ═══════════════════════════════════════════════════════

/** GET /product/{id}/variations */
function rmai_get_product_variations( WP_REST_Request $request ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'rmai_no_woocommerce', 'WooCommerce no está activo.', [ 'status' => 400 ] );
    }

    $parent_id = (int) $request->get_param( 'id' );
    $parent    = get_post( $parent_id );

    if ( ! $parent || $parent->post_type !== 'product' ) {
        return new WP_Error( 'rmai_not_found', 'Producto no encontrado.', [ 'status' => 404 ] );
    }

    $variations = get_posts( [
        'post_type'      => 'product_variation',
        'post_parent'    => $parent_id,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ] );

    $ean_keys = [
        '_ean', '_gtin', 'ean', 'gtin', '_barcode', 'barcode',
        '_global_unique_id', '_wpm_gtin_code', '_yith_barcode',
    ];

    $items = [];
    foreach ( $variations as $v ) {
        $all_meta = get_post_meta( $v->ID );
        $ean_data = [];
        foreach ( $ean_keys as $k ) {
            $val = get_post_meta( $v->ID, $k, true );
            if ( $val !== '' && $val !== false ) {
                $ean_data[ $k ] = $val;
            }
        }

        $attrs = [];
        foreach ( $all_meta as $meta_key => $meta_vals ) {
            if ( strpos( $meta_key, 'attribute_' ) === 0 ) {
                $attrs[ $meta_key ] = $meta_vals[0];
            }
        }

        $items[] = [
            'id'          => $v->ID,
            'description' => $v->post_excerpt,
            'status'      => $v->post_status,
            'attributes'  => $attrs,
            'ean_fields'  => $ean_data,
            'price'       => get_post_meta( $v->ID, '_price', true ),
            'sku'         => get_post_meta( $v->ID, '_sku', true ),
        ];
    }

    return new WP_REST_Response( [
        'product_id'   => $parent_id,
        'product_name' => $parent->post_title,
        'variations'   => $items,
    ], 200 );
}

/** POST /bulk-delete-variations */
function rmai_bulk_delete_variations( WP_REST_Request $request ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'rmai_no_woocommerce', 'WooCommerce no está activo.', [ 'status' => 400 ] );
    }

    $body = $request->get_json_params();
    $ids  = isset( $body['ids'] ) ? array_map( 'absint', (array) $body['ids'] ) : [];

    if ( empty( $ids ) ) {
        return new WP_Error( 'rmai_empty', 'Envía {ids:[1,2,3]}.', [ 'status' => 400 ] );
    }
    if ( count( $ids ) > 100 ) {
        return new WP_Error( 'rmai_too_many', 'Máximo 100 IDs por petición.', [ 'status' => 400 ] );
    }

    $results = [];
    foreach ( $ids as $id ) {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'product_variation' ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'No es una variación válida' ];
            continue;
        }
        $parent_id = $post->post_parent;
        $deleted   = wp_delete_post( $id, true );
        if ( ! $deleted ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'No se pudo eliminar' ];
            continue;
        }
        wc_delete_product_transients( $parent_id );
        $results[] = [ 'id' => $id, 'success' => true ];
    }

    $ok  = count( array_filter( $results, fn( $r ) => $r['success'] ) );
    $err = count( $results ) - $ok;
    return new WP_REST_Response( [
        'deleted' => $ok,
        'errors'  => $err,
        'results' => $results,
    ], 200 );
}

/** POST /bulk-variation-descriptions */
function rmai_bulk_variation_descriptions( WP_REST_Request $request ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'rmai_no_woocommerce', 'WooCommerce no está activo.', [ 'status' => 400 ] );
    }

    $body = $request->get_json_params();

    if ( empty( $body ) || ! is_array( $body ) ) {
        return new WP_Error( 'rmai_empty_body', 'Envía un array de {id, description}.', [ 'status' => 400 ] );
    }

    if ( count( $body ) > 200 ) {
        return new WP_Error( 'rmai_too_many', 'Máximo 200 variaciones por petición.', [ 'status' => 400 ] );
    }

    $results = [];

    foreach ( $body as $item ) {
        $id   = isset( $item['id'] ) ? (int) $item['id'] : 0;
        $desc = isset( $item['description'] ) ? wp_kses_post( (string) $item['description'] ) : null;

        if ( ! $id || $desc === null ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'Faltan id o description' ];
            continue;
        }

        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'product_variation' ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'Variación no encontrada' ];
            continue;
        }

        $result = wp_update_post( [
            'ID'           => $id,
            'post_excerpt' => $desc,
        ], true );

        if ( is_wp_error( $result ) ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => $result->get_error_message() ];
            continue;
        }

        wc_delete_product_transients( $post->post_parent );
        $results[] = [ 'id' => $id, 'success' => true, 'description' => $desc ];
    }

    return new WP_REST_Response( [
        'processed' => count( $results ),
        'results'   => $results,
    ], 200 );
}

// ═══════════════════════════════════════════════════════════════════════════
// 9. AUDITORÍA SEO COMPLETA — /seo/*
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', 'rmai_register_schema_routes' );
function rmai_register_schema_routes(): void {
    $perm = 'rmai_check_permission';

    register_rest_route( RMAI_NAMESPACE, '/seo/schema/(?P<id>\d+)', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_get_post_schemas',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'rmai_add_post_schema',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'rmai_delete_post_schemas',
            'permission_callback' => $perm,
        ],
    ] );

    // Activar/desactivar breadcrumbs Rank Math
    register_rest_route( RMAI_NAMESPACE, '/seo/breadcrumbs', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_get_breadcrumbs_status',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'rmai_set_breadcrumbs',
            'permission_callback' => $perm,
        ],
    ] );
}

add_action( 'rest_api_init', 'rmai_register_extra_routes' );
function rmai_register_extra_routes(): void {
    $perm = 'rmai_check_permission';

    // Informe ejecutivo SEO
    register_rest_route( RMAI_NAMESPACE, '/seo/report', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_report',
        'permission_callback' => $perm,
    ] );

    // Imágenes sin alt en todo el sitio
    register_rest_route( RMAI_NAMESPACE, '/seo/images', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_images_audit',
        'permission_callback' => $perm,
        'args'                => [
            'per_page'    => [ 'default' => 50,  'sanitize_callback' => 'absint' ],
            'page'        => [ 'default' => 1,   'sanitize_callback' => 'absint' ],
            'missing_alt' => [ 'default' => true ],
        ],
    ] );

    // Meta duplicados
    register_rest_route( RMAI_NAMESPACE, '/seo/duplicates', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_duplicates',
        'permission_callback' => $perm,
        'args'                => [
            'field' => [ 'default' => 'both', 'sanitize_callback' => 'sanitize_key' ],
        ],
    ] );

    // Páginas huérfanas
    register_rest_route( RMAI_NAMESPACE, '/seo/orphans', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_orphans',
        'permission_callback' => $perm,
        'args'                => [
            'type' => [ 'default' => 'page', 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    // Redirections Rank Math
    register_rest_route( RMAI_NAMESPACE, '/redirections', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_get_redirections',
            'permission_callback' => $perm,
            'args'                => [
                'per_page' => [ 'default' => 50,  'sanitize_callback' => 'absint' ],
                'page'     => [ 'default' => 1,   'sanitize_callback' => 'absint' ],
                'status'   => [ 'default' => 'active', 'sanitize_callback' => 'sanitize_key' ],
            ],
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'rmai_create_redirection',
            'permission_callback' => $perm,
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/redirections/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => 'rmai_delete_redirection',
        'permission_callback' => $perm,
    ] );
}

add_action( 'rest_api_init', 'rmai_register_geo_routes' );
function rmai_register_geo_routes(): void {
    $perm = 'rmai_check_permission';

    register_rest_route( RMAI_NAMESPACE, '/seo/geo', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_geo_audit',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/seo/geo/generate', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_geo_generate',
        'permission_callback' => $perm,
        'args'                => [
            'max_posts'  => [ 'default' => 50,  'sanitize_callback' => 'absint' ],
            'max_pages'  => [ 'default' => 30,  'sanitize_callback' => 'absint' ],
            'full'       => [ 'default' => false ],
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/seo/geo/write-llms-txt', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_geo_write_llms_txt',
        'permission_callback' => $perm,
    ] );
}

add_action( 'rest_api_init', 'rmai_register_seo_audit_routes' );
function rmai_register_seo_audit_routes(): void {
    $perm = 'rmai_check_permission';

    register_rest_route( RMAI_NAMESPACE, '/seo', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_audit',
        'permission_callback' => $perm,
        'args'                => [
            'type'     => [ 'default' => 'any',     'sanitize_callback' => 'sanitize_text_field' ],
            'per_page' => [ 'default' => 20,         'sanitize_callback' => 'absint' ],
            'page'     => [ 'default' => 1,          'sanitize_callback' => 'absint' ],
            'status'   => [ 'default' => 'publish',  'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/seo/post/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_post_audit',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/seo/apply/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_seo_apply_optimizations',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/seo/sitemap', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_sitemap_audit',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/seo/h1', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_h1_audit',
        'permission_callback' => $perm,
        'args'                => [
            'type'     => [ 'default' => 'any', 'sanitize_callback' => 'sanitize_text_field' ],
            'per_page' => [ 'default' => 50,    'sanitize_callback' => 'absint' ],
            'page'     => [ 'default' => 1,     'sanitize_callback' => 'absint' ],
        ],
    ] );

    register_rest_route( RMAI_NAMESPACE, '/seo/noindex', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_noindex_audit',
        'permission_callback' => $perm,
    ] );

    register_rest_route( RMAI_NAMESPACE, '/seo/404', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_seo_404_audit',
        'permission_callback' => $perm,
        'args'                => [
            'source' => [ 'default' => 'auto',  'sanitize_callback' => 'sanitize_key' ],
            'limit'  => [ 'default' => 100,     'sanitize_callback' => 'absint' ],
        ],
    ] );
}

// ───────────────────────────────────────────────────────
// 9a. ANÁLISIS SEO DE UN POST (núcleo)
// ───────────────────────────────────────────────────────

function rmai_seo_analyze_post( WP_Post $post ): array {
    $seo_title = get_post_meta( $post->ID, 'rank_math_title', true );
    $seo_title = $seo_title ?: $post->post_title;
    $seo_desc  = (string) get_post_meta( $post->ID, 'rank_math_description', true );
    $focus_kw  = (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
    $robots    = get_post_meta( $post->ID, 'rank_math_robots', true );
    $noindex   = is_array( $robots )
        ? in_array( 'noindex', $robots, true )
        : ( is_string( $robots ) && strpos( $robots, 'noindex' ) !== false );
    $rm_score  = (int) get_post_meta( $post->ID, 'rank_math_seo_score', true );

    $is_elementor = rmai_is_elementor_post( $post->ID );
    $html         = do_shortcode( $post->post_content );
    $plain_text   = rmai_seo_strip( $html );
    $word_count   = rmai_seo_word_count( $plain_text );
    $char_count   = mb_strlen( $plain_text );
    $kw           = $focus_kw;

    // ── SEO BÁSICO ──────────────────────────────────────────────────────────
    $basic = [
        [
            'check'  => 'keyword_in_seo_title',
            'label'  => 'Añade la palabra clave objetivo en el título SEO.',
            'pass'   => $kw !== '' && stripos( $seo_title, $kw ) !== false,
            'weight' => 'high',
        ],
        [
            'check'  => 'keyword_in_meta_description',
            'label'  => 'Añade la palabra clave objetivo a tu descripción SEO.',
            'pass'   => $kw !== '' && stripos( $seo_desc, $kw ) !== false,
            'weight' => 'high',
        ],
        [
            'check'  => 'keyword_at_beginning',
            'label'  => 'Utiliza la palabra clave objetivo al principio de tu contenido.',
            'pass'   => $kw !== '' && rmai_seo_kw_at_beginning( $kw, $plain_text ),
            'weight' => 'medium',
        ],
        [
            'check'  => 'keyword_in_content',
            'label'  => 'Utiliza la palabra clave objetivo en el contenido.',
            'pass'   => $kw !== '' && stripos( $plain_text, $kw ) !== false,
            'weight' => 'high',
        ],
        [
            'check'   => 'content_length',
            'label'   => "El contenido tiene {$char_count} caracteres." . ( $char_count >= 250 ? ' ¡Buen trabajo!' : ' El contenido es demasiado corto.' ),
            'pass'    => $char_count >= 250,
            'weight'  => 'medium',
            'details' => [ 'chars' => $char_count, 'words' => $word_count ],
        ],
    ];

    // ── ADICIONAL ────────────────────────────────────────────────────────────
    $density    = $kw !== '' && $word_count > 0 ? rmai_seo_kw_density( $kw, $plain_text ) : 0.0;
    $density_ok = $density >= 0.5 && $density <= 2.5;

    $additional = [
        [
            'check'  => 'keyword_in_subheadings',
            'label'  => 'Usa la palabra clave objetivo en el/los subencabezado/s como H2, H3, H4.',
            'pass'   => $kw !== '' && rmai_seo_kw_in_headings( $kw, $html ),
            'weight' => 'medium',
        ],
        [
            'check'  => 'keyword_in_image_alt',
            'label'  => 'Añade una imagen con tu palabra clave objetivo como texto alternativo.',
            'pass'   => $kw !== '' && rmai_seo_kw_in_alt( $kw, $html ),
            'weight' => 'medium',
        ],
        [
            'check'   => 'keyword_density',
            'label'   => "La densidad de palabra clave es {$density}%. " . ( $density_ok ? 'Dentro del rango óptimo (0.5–2.5%).' : 'Trata de moverte en torno a una densidad de un 1%.' ),
            'pass'    => $density_ok,
            'weight'  => 'medium',
            'details' => [ 'density' => $density ],
        ],
        [
            'check'  => 'has_links',
            'label'  => rmai_seo_has_links( $html ) ? 'Estás enlazando a otros recursos en tu web, y eso es fantástico.' : 'Añade al menos un enlace interno o externo al contenido.',
            'pass'   => rmai_seo_has_links( $html ),
            'weight' => 'low',
        ],
        [
            'check'  => 'focus_keyword_set',
            'label'  => $kw !== '' ? "Palabra clave objetivo configurada: \"{$kw}\"." : 'Configura una palabra clave objetivo para este contenido.',
            'pass'   => $kw !== '',
            'weight' => 'high',
        ],
    ];

    // ── LEGIBILIDAD DEL TÍTULO ───────────────────────────────────────────────
    $title_readability = [
        [
            'check'  => 'keyword_near_title_start',
            'label'  => 'Usa la palabra clave objetivo cerca del comienzo del título SEO.',
            'pass'   => $kw !== '' && rmai_seo_kw_near_title_start( $kw, $seo_title ),
            'weight' => 'medium',
        ],
    ];

    // ── LEGIBILIDAD DEL CONTENIDO ────────────────────────────────────────────
    $content_readability = [
        [
            'check'  => 'short_paragraphs',
            'label'  => rmai_seo_has_short_paragraphs( $html ) ? 'Estás usando párrafos cortos.' : 'Usa párrafos más cortos (máx. 120 palabras por párrafo).',
            'pass'   => rmai_seo_has_short_paragraphs( $html ),
            'weight' => 'low',
        ],
        [
            'check'  => 'has_media',
            'label'  => rmai_seo_has_media( $html ) ? 'Tu contenido contiene imágenes y/o vídeo(s).' : 'Añade al menos una imagen o vídeo al contenido.',
            'pass'   => rmai_seo_has_media( $html ),
            'weight' => 'low',
        ],
    ];

    $all_checks     = array_merge( $basic, $additional, $title_readability, $content_readability );
    $errors_count   = count( array_filter( $all_checks, fn( $c ) => ! $c['pass'] ) );
    $passed_count   = count( array_filter( $all_checks, fn( $c ) => $c['pass'] ) );
    $est_score      = rmai_seo_estimate_score( $all_checks );

    // ── SUGERENCIAS PARA SCORE MÁXIMO ────────────────────────────────────────
    $suggestions = rmai_seo_build_suggestions( $post, $seo_title, $seo_desc, $kw, $all_checks );

    return [
        'post_id'          => $post->ID,
        'title'            => $post->post_title,
        'url'              => get_permalink( $post->ID ),
        'slug'             => $post->post_name,
        'type'             => $post->post_type,
        'status'           => $post->post_status,
        'noindex'          => $noindex,
        'elementor_page'   => $is_elementor,
        'content_editable' => ! $is_elementor,
        'seo' => [
            'seo_title'        => $seo_title,
            'meta_description' => $seo_desc,
            'focus_keyword'    => $kw ?: null,
            'rank_math_score'  => $rm_score ?: null,
            'estimated_score'  => $est_score,
            'rating'           => rmai_score_rating( $est_score ),
        ],
        'content' => [
            'word_count' => $word_count,
            'char_count' => $char_count,
        ],
        'checks' => [
            'seo_basic'           => $basic,
            'additional'          => $additional,
            'title_readability'   => $title_readability,
            'content_readability' => $content_readability,
        ],
        'summary' => [
            'total'  => count( $all_checks ),
            'passed' => $passed_count,
            'errors' => $errors_count,
        ],
        'suggestions' => $suggestions,
    ];
}

function rmai_seo_build_suggestions( WP_Post $post, string $seo_title, string $seo_desc, string $kw, array $checks ): array {
    $suggestions = [];

    $failed = array_column(
        array_filter( $checks, fn( $c ) => ! $c['pass'] ),
        'check'
    );

    if ( in_array( 'focus_keyword_set', $failed, true ) ) {
        $suggestions[] = [
            'field'   => 'focus_keyword',
            'action'  => 'set',
            'message' => 'Define una palabra clave objetivo en Rank Math.',
            'meta_key'=> 'rank_math_focus_keyword',
            'value'   => null,
        ];
    }

    if ( $kw !== '' ) {
        if ( in_array( 'keyword_in_seo_title', $failed, true ) ) {
            $suggested_title = $kw . ' - ' . $post->post_title;
            $suggestions[] = [
                'field'    => 'seo_title',
                'action'   => 'update',
                'message'  => 'Incluye la palabra clave al inicio del título SEO.',
                'meta_key' => 'rank_math_title',
                'value'    => $suggested_title,
            ];
        }

        if ( in_array( 'keyword_in_meta_description', $failed, true ) && $seo_desc !== '' ) {
            $suggestions[] = [
                'field'    => 'seo_description',
                'action'   => 'update',
                'message'  => 'Incluye la palabra clave en la meta descripción.',
                'meta_key' => 'rank_math_description',
                'value'    => $kw . '. ' . $seo_desc,
            ];
        }

        if ( in_array( 'keyword_near_title_start', $failed, true ) && ! in_array( 'keyword_in_seo_title', $failed, true ) ) {
            $suggestions[] = [
                'field'    => 'seo_title',
                'action'   => 'update',
                'message'  => 'Mueve la palabra clave al principio del título SEO.',
                'meta_key' => 'rank_math_title',
                'value'    => $kw . ' - ' . $seo_title,
            ];
        }
    }

    foreach ( $failed as $check ) {
        $map = [
            'keyword_at_beginning'  => 'Añade la palabra clave en el primer párrafo del contenido.',
            'keyword_in_content'    => 'Menciona la palabra clave al menos una vez en el cuerpo del contenido.',
            'keyword_in_subheadings'=> 'Añade la palabra clave en al menos un H2 o H3.',
            'keyword_in_image_alt'  => 'Añade la palabra clave como texto alternativo (alt) de una imagen.',
            'keyword_density'       => 'Ajusta la densidad de la palabra clave entre 0.5% y 2.5%.',
            'has_links'             => 'Añade al menos un enlace interno o externo al contenido.',
            'content_length'        => 'El contenido debe tener al menos 250 caracteres (recomendado: +300 palabras).',
            'short_paragraphs'      => 'Divide los párrafos largos (más de 120 palabras) en bloques más cortos.',
            'has_media'             => 'Inserta al menos una imagen o vídeo en el contenido.',
        ];
        if ( isset( $map[ $check ] ) ) {
            $suggestions[] = [
                'field'   => $check,
                'action'  => 'edit_content',
                'message' => $map[ $check ],
                'value'   => null,
            ];
        }
    }

    return $suggestions;
}

// ───────────────────────────────────────────────────────
// 9b. HELPERS SEO
// ───────────────────────────────────────────────────────

function rmai_seo_strip( string $html ): string {
    $html = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', '', $html );
    return wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
}

function rmai_seo_word_count( string $text ): int {
    $text = trim( preg_replace( '/\s+/', ' ', $text ) );
    if ( $text === '' ) return 0;
    return str_word_count( $text );
}

function rmai_seo_kw_at_beginning( string $kw, string $text ): bool {
    $words     = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
    $threshold = max( 100, (int) ( count( $words ) * 0.1 ) );
    $segment   = implode( ' ', array_slice( $words, 0, $threshold ) );
    return stripos( $segment, $kw ) !== false;
}

function rmai_seo_kw_near_title_start( string $kw, string $title ): bool {
    $words     = preg_split( '/\s+/', trim( $title ), -1, PREG_SPLIT_NO_EMPTY );
    $threshold = max( 3, (int) ceil( count( $words ) / 2 ) );
    $segment   = implode( ' ', array_slice( $words, 0, $threshold ) );
    return stripos( $segment, $kw ) !== false;
}

function rmai_seo_kw_density( string $kw, string $text ): float {
    $kw_lc     = strtolower( $kw );
    $text_lc   = strtolower( $text );
    $kw_count  = substr_count( $text_lc, $kw_lc );
    $word_count= rmai_seo_word_count( $text );
    $kw_words  = rmai_seo_word_count( $kw );
    if ( $word_count === 0 || $kw_words === 0 ) return 0.0;
    return round( ( $kw_count * $kw_words / $word_count ) * 100, 2 );
}

function rmai_seo_kw_in_headings( string $kw, string $html ): bool {
    foreach ( [ 'h2', 'h3', 'h4' ] as $tag ) {
        if ( preg_match_all( '/<' . $tag . '[^>]*>(.*?)<\/' . $tag . '>/is', $html, $m ) ) {
            foreach ( $m[1] as $heading ) {
                if ( stripos( wp_strip_all_tags( $heading ), $kw ) !== false ) {
                    return true;
                }
            }
        }
    }
    return false;
}

function rmai_seo_kw_in_alt( string $kw, string $html ): bool {
    if ( preg_match_all( '/alt=["\']([^"\']*)["\']/i', $html, $m ) ) {
        foreach ( $m[1] as $alt ) {
            if ( stripos( $alt, $kw ) !== false ) return true;
        }
    }
    return false;
}

function rmai_seo_has_links( string $html ): bool {
    return (bool) preg_match( '/<a\s[^>]*href=["\'][^"\'#][^"\']*["\'][^>]*>/i', $html );
}

function rmai_seo_has_short_paragraphs( string $html ): bool {
    if ( ! preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $html, $m ) ) return true;
    foreach ( $m[1] as $p ) {
        if ( rmai_seo_word_count( wp_strip_all_tags( $p ) ) > 120 ) return false;
    }
    return true;
}

function rmai_seo_has_media( string $html ): bool {
    return (bool) preg_match( '/<(img|video|iframe|figure)\b[^>]*>/i', $html );
}

function rmai_seo_estimate_score( array $checks ): int {
    $weights = [ 'high' => 3, 'medium' => 2, 'low' => 1 ];
    $total_w = 0;
    $pass_w  = 0;
    foreach ( $checks as $c ) {
        $w        = $weights[ $c['weight'] ?? 'medium' ] ?? 2;
        $total_w += $w;
        if ( $c['pass'] ) $pass_w += $w;
    }
    return $total_w > 0 ? (int) round( ( $pass_w / $total_w ) * 100 ) : 0;
}

function rmai_seo_extract_headings( string $html, string $tag ): array {
    $out = [];
    if ( preg_match_all( '/<' . $tag . '[^>]*>(.*?)<\/' . $tag . '>/is', $html, $m ) ) {
        foreach ( $m[1] as $h ) {
            $out[] = trim( wp_strip_all_tags( $h ) );
        }
    }
    return $out;
}

// ───────────────────────────────────────────────────────
// 9c. CALLBACKS /seo
// ───────────────────────────────────────────────────────

/** GET /seo */
function rmai_seo_audit( WP_REST_Request $request ) {
    $type_param   = $request->get_param( 'type' );
    $per_page     = min( $request->get_param( 'per_page' ), 50 );
    $paged        = $request->get_param( 'page' );
    $status_param = $request->get_param( 'status' );

    $public_types = array_keys( get_post_types( [ 'public' => true ] ) );

    if ( $type_param === 'any' ) {
        $post_types = $public_types;
    } elseif ( in_array( $type_param, $public_types, true ) ) {
        $post_types = [ $type_param ];
    } else {
        return new WP_Error( 'rmai_invalid_type', "Tipo '{$type_param}' no válido. Disponibles: " . implode( ', ', $public_types ), [ 'status' => 400 ] );
    }

    $allowed_statuses = [ 'publish', 'draft', 'private', 'any' ];
    $status = in_array( $status_param, $allowed_statuses, true ) ? $status_param : 'publish';

    $query = new WP_Query( [
        'post_type'      => $post_types,
        'post_status'    => $status,
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ] );

    $items = [];
    foreach ( $query->posts as $post ) {
        $items[] = rmai_seo_analyze_post( $post );
    }

    return new WP_REST_Response( [
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'page'        => $paged,
        'per_page'    => $per_page,
        'items'       => $items,
    ], 200 );
}

/** GET /seo/post/{id} */
function rmai_seo_post_audit( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }
    return new WP_REST_Response( rmai_seo_analyze_post( $post ), 200 );
}

/** POST /seo/apply/{id} — escribe en Rank Math los campos sugeridos */
function rmai_seo_apply_optimizations( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    $body    = $request->get_json_params();
    $applied = [];
    $skipped = [];

    // Accepted writable meta keys via this endpoint (subset of field_map + focus_keyword)
    $writable = [
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_og_title',
        'rank_math_og_description',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
    ];

    // If body is empty, auto-apply safe suggestions from the audit
    if ( empty( $body ) ) {
        $audit = rmai_seo_analyze_post( $post );
        foreach ( $audit['suggestions'] as $s ) {
            if ( isset( $s['meta_key'] ) && $s['value'] !== null && in_array( $s['meta_key'], $writable, true ) ) {
                update_post_meta( $post->ID, $s['meta_key'], sanitize_text_field( $s['value'] ) );
                $applied[] = [ 'meta_key' => $s['meta_key'], 'value' => $s['value'] ];
            }
        }
    } else {
        // Manual overrides: { "rank_math_title": "...", "rank_math_description": "...", ... }
        foreach ( $body as $meta_key => $value ) {
            $meta_key = sanitize_key( $meta_key );
            if ( ! in_array( $meta_key, $writable, true ) ) {
                $skipped[] = $meta_key;
                continue;
            }
            update_post_meta( $post->ID, $meta_key, sanitize_text_field( (string) $value ) );
            $applied[] = [ 'meta_key' => $meta_key, 'value' => $value ];
        }
    }

    rmai_trigger_score_recalculation( $post->ID );

    return new WP_REST_Response( [
        'success'   => true,
        'post_id'   => $post->ID,
        'applied'   => $applied,
        'skipped'   => $skipped,
        'seo'       => rmai_read_seo_data( $post->ID ),
    ], 200 );
}

// ───────────────────────────────────────────────────────
// 9d. AUDITORÍA DEL SITEMAP
// ───────────────────────────────────────────────────────

/** GET /seo/sitemap */
function rmai_seo_sitemap_audit( WP_REST_Request $request ) {
    $candidates = [
        get_site_url() . '/sitemap_index.xml',
        get_site_url() . '/sitemap.xml',
        get_site_url() . '/?sitemap=1',
    ];

    $sitemap_url  = '';
    $sitemap_body = '';

    foreach ( $candidates as $url ) {
        $r = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => false ] );
        if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
            $sitemap_url  = $url;
            $sitemap_body = wp_remote_retrieve_body( $r );
            break;
        }
    }

    if ( $sitemap_body === '' ) {
        return new WP_Error( 'rmai_sitemap_not_found', 'No se encontró ningún sitemap accesible.', [ 'status' => 404 ] );
    }

    // Resolve sub-sitemaps (index) or use directly
    $sub_sitemaps = rmai_seo_parse_sitemap_index( $sitemap_body, $sitemap_url );
    $all_urls     = [];

    foreach ( $sub_sitemaps as $sm ) {
        if ( $sm === $sitemap_url && strpos( $sitemap_body, '<urlset' ) !== false ) {
            $all_urls = array_merge( $all_urls, rmai_seo_parse_sitemap_urls( $sitemap_body ) );
        } else {
            $r = wp_remote_get( $sm, [ 'timeout' => 10, 'sslverify' => false ] );
            if ( ! is_wp_error( $r ) ) {
                $all_urls = array_merge( $all_urls, rmai_seo_parse_sitemap_urls( wp_remote_retrieve_body( $r ) ) );
            }
        }
    }

    $bad_slugs = [
        'privacidad', 'privacy', 'privacy-policy', 'politica-privacidad', 'politica-de-privacidad',
        'aviso-legal', 'legal', 'cookies', 'politica-cookies', 'politica-de-cookies',
        'rgpd', 'gdpr', 'terminos', 'terms', 'terms-conditions', 'terms-and-conditions',
        'mi-cuenta', 'my-account', 'login', 'logout', 'register', 'registro',
        'carrito', 'cart', 'checkout', 'pedidos', 'orders', 'perfil', 'profile',
        'hello-world', 'hola-mundo', 'sample-page', 'pagina-de-muestra',
    ];

    $issues  = [];
    $ok_urls = [];

    foreach ( array_unique( $all_urls ) as $url ) {
        $path  = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
        $parts = explode( '/', $path );
        $slug  = end( $parts );
        $found = false;

        foreach ( $bad_slugs as $bad ) {
            if ( $slug === $bad || $path === $bad ) {
                $issues[] = [
                    'url'    => $url,
                    'slug'   => $slug,
                    'reason' => "Página que no debería estar en el sitemap: '{$slug}'",
                    'action' => 'Añade noindex con Rank Math o excluye del sitemap en Rank Math → Sitemap.',
                ];
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            $ok_urls[] = $url;
        }
    }

    return new WP_REST_Response( [
        'sitemap_url'  => $sitemap_url,
        'total_urls'   => count( $all_urls ),
        'ok_count'     => count( $ok_urls ),
        'issues_count' => count( $issues ),
        'issues'       => $issues,
        'ok_urls'      => $ok_urls,
    ], 200 );
}

function rmai_seo_parse_sitemap_index( string $xml, string $base_url ): array {
    $prev = libxml_use_internal_errors( true );
    $doc  = simplexml_load_string( $xml );
    libxml_use_internal_errors( $prev );

    if ( ! $doc ) return [ $base_url ];

    $doc->registerXPathNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
    $locs = $doc->xpath( '//sm:sitemap/sm:loc' );
    if ( empty( $locs ) ) return [ $base_url ];

    $urls = [];
    foreach ( $locs as $loc ) {
        $urls[] = (string) $loc;
    }
    return $urls;
}

function rmai_seo_parse_sitemap_urls( string $xml ): array {
    $prev = libxml_use_internal_errors( true );
    $doc  = simplexml_load_string( $xml );
    libxml_use_internal_errors( $prev );

    if ( ! $doc ) return [];

    $doc->registerXPathNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
    $locs = $doc->xpath( '//sm:url/sm:loc' );

    $urls = [];
    foreach ( (array) $locs as $loc ) {
        $url = (string) $loc;
        if ( $url !== '' ) $urls[] = $url;
    }
    return $urls;
}

// ───────────────────────────────────────────────────────
// 9e. AUDITORÍA H1
// ───────────────────────────────────────────────────────

/** GET /seo/h1 */
function rmai_seo_h1_audit( WP_REST_Request $request ) {
    $type_param = $request->get_param( 'type' );
    $per_page   = min( $request->get_param( 'per_page' ), 100 );
    $paged      = $request->get_param( 'page' );

    $public_types = array_keys( get_post_types( [ 'public' => true ] ) );
    $post_types   = ( $type_param === 'any' || ! in_array( $type_param, $public_types, true ) )
        ? $public_types
        : [ $type_param ];

    $query = new WP_Query( [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'title',
        'order'          => 'ASC',
        // Exclude noindex pages
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => 'rank_math_robots', 'compare' => 'NOT EXISTS' ],
            [ 'key' => 'rank_math_robots', 'value' => 'noindex', 'compare' => 'NOT LIKE' ],
        ],
    ] );

    $items    = [];
    $warnings = 0;

    foreach ( $query->posts as $post ) {
        $html   = do_shortcode( $post->post_content );
        $h1s    = rmai_seo_extract_headings( $html, 'h1' );
        $issues = [];

        if ( empty( $h1s ) ) {
            $issues[] = 'Sin H1 en el contenido (el título del post actúa como H1 en la plantilla).';
        }
        if ( count( $h1s ) > 1 ) {
            $issues[] = 'Múltiples H1 detectados (' . count( $h1s ) . '). Solo debe existir uno por página.';
        }

        if ( ! empty( $issues ) ) $warnings++;

        $items[] = [
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'url'    => get_permalink( $post->ID ),
            'type'   => $post->post_type,
            'h1s'    => $h1s,
            'count'  => count( $h1s ),
            'status' => empty( $issues ) ? 'ok' : 'warning',
            'issues' => $issues,
        ];
    }

    return new WP_REST_Response( [
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'page'        => $paged,
        'per_page'    => $per_page,
        'warnings'    => $warnings,
        'items'       => $items,
    ], 200 );
}

// ───────────────────────────────────────────────────────
// 9f. AUDITORÍA NOINDEX
// ───────────────────────────────────────────────────────

/** GET /seo/noindex */
function rmai_seo_noindex_audit( WP_REST_Request $request ) {
    $sensitive_slugs = [
        'privacidad', 'privacy', 'privacy-policy', 'politica-privacidad', 'politica-de-privacidad',
        'aviso-legal', 'legal', 'cookies', 'politica-cookies', 'politica-de-cookies',
        'rgpd', 'gdpr', 'terminos', 'terms', 'terms-conditions', 'terms-and-conditions',
        'mi-cuenta', 'my-account', 'login', 'logout', 'register', 'registro',
        'carrito', 'cart', 'checkout', 'pedidos', 'orders', 'perfil', 'profile',
        'hello-world', 'hola-mundo', 'sample-page', 'pagina-de-muestra',
    ];

    $public_types          = array_keys( get_post_types( [ 'public' => true ] ) );
    $should_be_noindexed   = [];
    $correctly_noindexed   = [];

    // ── Generic pages by slug ─────────────────────────
    $query = new WP_Query( [
        'post_type'      => $public_types,
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'post_name__in'  => $sensitive_slugs,
        'no_found_rows'  => true,
    ] );

    foreach ( $query->posts as $post ) {
        if ( ! in_array( $post->post_name, $sensitive_slugs, true ) ) continue;

        $robots  = get_post_meta( $post->ID, 'rank_math_robots', true );
        $noindex = is_array( $robots )
            ? in_array( 'noindex', $robots, true )
            : ( is_string( $robots ) && strpos( $robots, 'noindex' ) !== false );

        $entry = [
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'slug'   => $post->post_name,
            'url'    => get_permalink( $post->ID ),
            'type'   => $post->post_type,
            'robots' => $robots,
        ];

        if ( $noindex ) {
            $correctly_noindexed[] = $entry;
        } else {
            $entry['action'] = 'En Rank Math → Configuración avanzada de esta página → Robots meta → Marcar noindex.';
            $should_be_noindexed[] = $entry;
        }
    }

    // ── WooCommerce functional pages ──────────────────
    if ( function_exists( 'wc_get_page_id' ) ) {
        $wc_noindex_pages = [ 'cart' => 'Carrito', 'checkout' => 'Checkout', 'myaccount' => 'Mi cuenta' ];

        foreach ( $wc_noindex_pages as $wc_key => $label ) {
            $page_id = wc_get_page_id( $wc_key );
            if ( $page_id <= 0 ) continue;

            $p = get_post( $page_id );
            if ( ! $p ) continue;

            $robots  = get_post_meta( $page_id, 'rank_math_robots', true );
            $noindex = is_array( $robots )
                ? in_array( 'noindex', $robots, true )
                : ( is_string( $robots ) && strpos( $robots, 'noindex' ) !== false );

            $entry = [
                'id'      => $page_id,
                'title'   => $p->post_title,
                'slug'    => $p->post_name,
                'url'     => get_permalink( $page_id ),
                'type'    => 'woocommerce_' . $wc_key,
                'robots'  => $robots,
            ];

            if ( $noindex ) {
                $correctly_noindexed[] = $entry;
            } else {
                $entry['action'] = "Página funcional de WooCommerce ({$label}): debe ser noindex.";
                $should_be_noindexed[] = $entry;
            }
        }
    }

    return new WP_REST_Response( [
        'issues_count'         => count( $should_be_noindexed ),
        'correctly_noindexed'  => $correctly_noindexed,
        'should_be_noindexed'  => $should_be_noindexed,
    ], 200 );
}

// ───────────────────────────────────────────────────────
// 9g. AUDITORÍA 404
// ───────────────────────────────────────────────────────

/** GET /seo/404 */
function rmai_seo_404_audit( WP_REST_Request $request ) {
    $source = $request->get_param( 'source' );
    $limit  = min( $request->get_param( 'limit' ), 500 );
    $items  = [];
    $used   = '';

    // ── Rank Math 404 monitor ─────────────────────────
    if ( in_array( $source, [ 'rankmath', 'auto' ], true ) ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'rank_math_404_logs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        if ( $exists ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT uri, accessed, times FROM `{$table}` ORDER BY times DESC LIMIT %d",
                    $limit
                )
            );

            foreach ( $rows as $row ) {
                $items[] = [
                    'url'       => rtrim( get_site_url(), '/' ) . $row->uri,
                    'path'      => $row->uri,
                    'hits'      => (int) $row->times,
                    'last_seen' => $row->accessed,
                ];
            }
            $used = 'rank_math_404_monitor';
        }
    }

    // ── Fallback: crawl sitemap ───────────────────────
    if ( empty( $items ) && in_array( $source, [ 'sitemap', 'auto' ], true ) ) {
        $sm_url = get_site_url() . '/sitemap_index.xml';
        $r      = wp_remote_get( $sm_url, [ 'timeout' => 10, 'sslverify' => false ] );

        if ( ! is_wp_error( $r ) ) {
            $sub_sitemaps = rmai_seo_parse_sitemap_index( wp_remote_retrieve_body( $r ), $sm_url );
            $all_urls     = [];

            foreach ( array_slice( $sub_sitemaps, 0, 5 ) as $sm ) {
                $sub = wp_remote_get( $sm, [ 'timeout' => 10, 'sslverify' => false ] );
                if ( ! is_wp_error( $sub ) ) {
                    $all_urls = array_merge( $all_urls, array_slice( rmai_seo_parse_sitemap_urls( wp_remote_retrieve_body( $sub ) ), 0, 100 ) );
                }
            }

            foreach ( array_slice( array_unique( $all_urls ), 0, $limit ) as $url ) {
                $head = wp_remote_head( $url, [ 'timeout' => 5, 'redirection' => 0, 'sslverify' => false ] );
                if ( is_wp_error( $head ) ) continue;
                $code = (int) wp_remote_retrieve_response_code( $head );
                if ( $code === 404 ) {
                    $items[] = [ 'url' => $url, 'http_code' => $code ];
                }
            }
            $used = 'sitemap_crawl';
        }
    }

    if ( $used === '' ) {
        return new WP_Error(
            'rmai_404_unavailable',
            'Monitor 404 de Rank Math no activo. Actívalo en Rank Math → General → Monitor 404, o usa ?source=sitemap.',
            [ 'status' => 404 ]
        );
    }

    return new WP_REST_Response( [
        'source'     => $used,
        'total_404s' => count( $items ),
        'items'      => $items,
    ], 200 );
}

// ═══════════════════════════════════════════════════════════════════════════
// 10. AUTO-REGENERACIÓN DIARIA DE API KEY (WP Cron)
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'rmai_daily_key_rotation', 'rmai_rotate_api_key' );
function rmai_rotate_api_key(): void {
    update_option( RMAI_OPTION_API_KEY, rmai_generate_key() );
}

add_action( 'wp_loaded', 'rmai_schedule_key_rotation' );
function rmai_schedule_key_rotation(): void {
    if ( ! wp_next_scheduled( 'rmai_daily_key_rotation' ) ) {
        wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', 'rmai_daily_key_rotation' );
    }
}

register_deactivation_hook( __FILE__, 'rmai_unschedule_key_rotation' );
function rmai_unschedule_key_rotation(): void {
    wp_clear_scheduled_hook( 'rmai_daily_key_rotation' );
}

// ═══════════════════════════════════════════════════════════════════════════
// 11. IMÁGENES / MEDIA — alt text, imagen destacada
// ═══════════════════════════════════════════════════════════════════════════

/** Devuelve array con los datos de un adjunto de imagen */
function rmai_media_item( int $id ): array {
    $att = get_post( $id );
    if ( ! $att ) {
        return [ 'id' => $id, 'error' => 'No encontrado' ];
    }
    return [
        'id'       => $id,
        'url'      => wp_get_attachment_image_url( $id, 'full' ) ?: null,
        'filename' => basename( get_attached_file( $id ) ?: '' ),
        'alt'      => get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: null,
        'title'    => $att->post_title ?: null,
        'caption'  => $att->post_excerpt ?: null,
        'mime'     => $att->post_mime_type,
    ];
}

/** GET /post/{id}/images */
function rmai_get_post_images( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    // Imagen destacada
    $featured  = null;
    $thumb_id  = (int) get_post_thumbnail_id( $post->ID );
    if ( $thumb_id ) {
        $featured = rmai_media_item( $thumb_id );
    }

    // Imágenes adjuntas al post
    $attached_posts = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_parent'    => $post->ID,
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ] );
    $attached = array_map( 'rmai_media_item', array_map( 'intval', $attached_posts ) );

    // Imágenes embebidas en post_content (busca <img src="...">)
    $embedded = [];
    if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $m ) ) {
        foreach ( array_unique( $m[1] ) as $src ) {
            $att_id = attachment_url_to_postid( $src );
            if ( $att_id ) {
                $embedded[] = rmai_media_item( (int) $att_id );
            } else {
                $embedded[] = [ 'id' => null, 'url' => $src, 'alt' => null, 'title' => null, 'external' => true ];
            }
        }
    }

    // ¿Alguna imagen tiene la keyword en el alt?
    $focus_kw      = (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
    $kw_in_alt     = false;
    if ( $focus_kw !== '' ) {
        $all_imgs = array_merge( $attached, $embedded, $featured ? [ $featured ] : [] );
        foreach ( $all_imgs as $img ) {
            if ( ! empty( $img['alt'] ) && stripos( $img['alt'], $focus_kw ) !== false ) {
                $kw_in_alt = true;
                break;
            }
        }
    }

    return new WP_REST_Response( [
        'post_id'        => $post->ID,
        'title'          => $post->post_title,
        'focus_keyword'  => $focus_kw ?: null,
        'kw_in_alt'      => $kw_in_alt,
        'featured_image' => $featured,
        'attached'       => $attached,
        'embedded'       => $embedded,
    ], 200 );
}

/** PUT /post/{id}/images — asignar/quitar imagen destacada */
function rmai_update_post_images( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }

    $body    = $request->get_json_params();
    $updated = [];

    if ( isset( $body['featured_image_id'] ) ) {
        $img_id = (int) $body['featured_image_id'];
        if ( $img_id > 0 ) {
            $att = get_post( $img_id );
            if ( ! $att || $att->post_type !== 'attachment' ) {
                return new WP_Error( 'rmai_invalid_image', 'ID no válido o no es un adjunto.', [ 'status' => 400 ] );
            }
            set_post_thumbnail( $post->ID, $img_id );
            $updated[] = 'featured_image';
        }
    }

    if ( ! empty( $body['remove_featured'] ) ) {
        delete_post_thumbnail( $post->ID );
        $updated[] = 'remove_featured_image';
    }

    if ( empty( $updated ) ) {
        return new WP_Error( 'rmai_no_fields', 'Incluye featured_image_id (int) o remove_featured: true.', [ 'status' => 400 ] );
    }

    $thumb_id = (int) get_post_thumbnail_id( $post->ID );

    return new WP_REST_Response( [
        'success'        => true,
        'post_id'        => $post->ID,
        'updated'        => $updated,
        'featured_image' => $thumb_id ? rmai_media_item( $thumb_id ) : null,
    ], 200 );
}

/** GET /media — lista imágenes de la biblioteca */
function rmai_get_media( WP_REST_Request $request ) {
    $per_page    = min( (int) $request->get_param( 'per_page' ), 100 );
    $page        = (int) $request->get_param( 'page' );
    $search      = $request->get_param( 'search' );
    $missing_alt = filter_var( $request->get_param( 'missing_alt' ), FILTER_VALIDATE_BOOLEAN );

    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    if ( $search !== '' ) {
        $args['s'] = $search;
    }

    if ( $missing_alt ) {
        $args['meta_query'] = [
            'relation' => 'OR',
            [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_wp_attachment_image_alt', 'value'   => '',           'compare' => '=' ],
        ];
    }

    $query = new WP_Query( $args );
    $items = array_map( fn( $a ) => rmai_media_item( (int) $a->ID ), $query->posts );

    return new WP_REST_Response( [
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'page'        => $page,
        'per_page'    => $per_page,
        'items'       => $items,
    ], 200 );
}

/** PUT /media/{id} — actualiza alt, title y/o caption de un adjunto */
function rmai_update_media( WP_REST_Request $request ) {
    $id  = (int) $request->get_param( 'id' );
    $att = get_post( $id );

    if ( ! $att || $att->post_type !== 'attachment' ) {
        return new WP_Error( 'rmai_not_found', 'Adjunto no encontrado.', [ 'status' => 404 ] );
    }

    $body    = $request->get_json_params();
    $updated = [];
    $post_up = [ 'ID' => $id ];

    if ( isset( $body['alt'] ) ) {
        update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $body['alt'] ) );
        $updated[] = 'alt';
    }

    if ( isset( $body['title'] ) ) {
        $post_up['post_title'] = sanitize_text_field( (string) $body['title'] );
        $updated[] = 'title';
    }

    if ( isset( $body['caption'] ) ) {
        $post_up['post_excerpt'] = sanitize_text_field( (string) $body['caption'] );
        $updated[] = 'caption';
    }

    if ( empty( $updated ) ) {
        return new WP_Error( 'rmai_no_fields', 'Incluye al menos uno: alt, title, caption.', [ 'status' => 400 ] );
    }

    if ( count( $post_up ) > 1 ) {
        wp_update_post( $post_up );
    }

    return new WP_REST_Response( [
        'success' => true,
        'updated' => $updated,
        'image'   => rmai_media_item( $id ),
    ], 200 );
}

/** POST /bulk-media-alt — actualiza alt (y opcionalmente title) de múltiples imágenes */
function rmai_bulk_media_alt( WP_REST_Request $request ) {
    $body = $request->get_json_params();

    if ( empty( $body ) || ! is_array( $body ) ) {
        return new WP_Error( 'rmai_empty_body', 'Envía [{id, alt, title?}].', [ 'status' => 400 ] );
    }

    if ( count( $body ) > 100 ) {
        return new WP_Error( 'rmai_too_many', 'Máximo 100 imágenes por petición.', [ 'status' => 400 ] );
    }

    $results = [];

    foreach ( $body as $item ) {
        $id  = isset( $item['id'] ) ? (int) $item['id'] : 0;
        $att = $id ? get_post( $id ) : null;

        if ( ! $att || $att->post_type !== 'attachment' ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'Adjunto no encontrado' ];
            continue;
        }

        if ( ! isset( $item['alt'] ) ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'Falta campo alt' ];
            continue;
        }

        update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $item['alt'] ) );

        $post_up = [ 'ID' => $id ];
        if ( isset( $item['title'] ) ) {
            $post_up['post_title'] = sanitize_text_field( (string) $item['title'] );
        }
        if ( isset( $item['caption'] ) ) {
            $post_up['post_excerpt'] = sanitize_text_field( (string) $item['caption'] );
        }
        if ( count( $post_up ) > 1 ) {
            wp_update_post( $post_up );
        }

        $results[] = [
            'id'      => $id,
            'success' => true,
            'alt'     => get_post_meta( $id, '_wp_attachment_image_alt', true ),
        ];
    }

    return new WP_REST_Response( [
        'processed' => count( $results ),
        'results'   => $results,
    ], 200 );
}

// ═══════════════════════════════════════════════════════════════════════════
// 12. GEO — Generative Engine Optimization (IA: ChatGPT, Perplexity, Gemini…)
// ═══════════════════════════════════════════════════════════════════════════

function rmai_geo_ai_bots(): array {
    return [
        'GPTBot'            => 'OpenAI / ChatGPT',
        'ChatGPT-User'      => 'OpenAI (browsing)',
        'anthropic-ai'      => 'Anthropic / Claude',
        'ClaudeBot'         => 'Anthropic / Claude (crawl)',
        'PerplexityBot'     => 'Perplexity AI',
        'Applebot-Extended' => 'Apple AI',
        'Google-Extended'   => 'Google AI (Gemini entrenamiento)',
        'CCBot'             => 'Common Crawl (datos entrenamiento)',
        'FacebookBot'       => 'Meta AI',
        'Bytespider'        => 'TikTok / ByteDance AI',
        'OAI-SearchBot'     => 'OpenAI SearchGPT',
        'cohere-ai'         => 'Cohere AI',
    ];
}

/** GET /seo/geo — auditoría GEO completa */
function rmai_geo_audit( WP_REST_Request $request ) {
    $site_url      = get_site_url();
    $robots_url    = $site_url . '/robots.txt';
    $llms_url      = $site_url . '/llms.txt';
    $llms_full_url = $site_url . '/llms-full.txt';

    // 1. robots.txt
    $robots_raw  = '';
    $robots_resp = wp_remote_get( $robots_url, [ 'timeout' => 8, 'sslverify' => false ] );
    if ( ! is_wp_error( $robots_resp ) && wp_remote_retrieve_response_code( $robots_resp ) === 200 ) {
        $robots_raw = wp_remote_retrieve_body( $robots_resp );
    }

    $blocked = [];
    $allowed = [];
    foreach ( rmai_geo_ai_bots() as $bot => $label ) {
        $blocked_by = false;
        $pattern    = '/User-agent:\s*' . preg_quote( $bot, '/' ) . '.*?(?=User-agent:|$)/is';

        if ( preg_match( $pattern, $robots_raw, $m ) ) {
            // Solo bloqueo real si Disallow es exactamente "/" (raíz), no subpaths como /wp-admin/
            if ( preg_match( '/Disallow:\s*\/\s*$/im', $m[0] ) ) {
                $blocked_by = true;
            }
        }
        // Wildcard block
        if ( ! $blocked_by && preg_match( '/User-agent:\s*\*.*?(?=User-agent:|$)/is', $robots_raw, $wm ) ) {
            if ( preg_match( '/Disallow:\s*\/\s*$/im', $wm[0] ) ) {
                $blocked_by = true;
            }
        }

        if ( $blocked_by ) {
            $blocked[] = [ 'bot' => $bot, 'label' => $label ];
        } else {
            $allowed[] = [ 'bot' => $bot, 'label' => $label ];
        }
    }

    // 2. llms.txt
    $llms_exists  = false;
    $llms_preview = null;
    $r = wp_remote_get( $llms_url, [ 'timeout' => 8, 'sslverify' => false ] );
    if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
        $llms_exists  = true;
        $llms_preview = substr( wp_remote_retrieve_body( $r ), 0, 500 );
    }

    $llms_full_exists = false;
    $rf = wp_remote_get( $llms_full_url, [ 'timeout' => 8, 'sslverify' => false ] );
    if ( ! is_wp_error( $rf ) && wp_remote_retrieve_response_code( $rf ) === 200 ) {
        $llms_full_exists = true;
    }

    // 3. Schema markup en homepage
    $schema_found = [];
    $hp = wp_remote_get( $site_url, [ 'timeout' => 10, 'sslverify' => false ] );
    if ( ! is_wp_error( $hp ) ) {
        if ( preg_match_all( '/"@type"\s*:\s*"([A-Za-z]+)"/i', wp_remote_retrieve_body( $hp ), $sm ) ) {
            $schema_found = array_values( array_unique( $sm[1] ) );
        }
    }

    $key_schemas    = [ 'Organization', 'WebSite', 'LocalBusiness', 'Person', 'Article', 'FAQPage', 'BreadcrumbList' ];
    $schema_ok      = array_values( array_intersect( $key_schemas, $schema_found ) );
    $schema_missing = array_values( array_diff( $key_schemas, $schema_found ) );

    // 4. Señales E-E-A-T
    $eat_signals = [];
    $authors     = get_users( [ 'capability' => [ 'edit_posts' ], 'fields' => [ 'ID', 'display_name', 'description' ] ] );
    foreach ( $authors as $a ) {
        $eat_signals['authors'][] = [
            'name'       => $a->display_name,
            'has_bio'    => ! empty( $a->description ),
            'has_avatar' => (bool) get_avatar_url( $a->ID ),
        ];
    }

    $key_pages = [];
    // Búsqueda por slug exacto + slug parcial (LIKE) para URLs tipo "contacto-jnc-..."
    $about_slugs   = [ 'about', 'sobre-nosotros', 'nosotros', 'quienes-somos', 'acerca-de', 'sobre-mi', 'quien-somos' ];
    $contact_slugs = [ 'contact', 'contacto', 'contactanos', 'contact-us' ];
    foreach ( array_merge( $about_slugs, $contact_slugs ) as $slug ) {
        $p = get_page_by_path( $slug );
        if ( $p && $p->post_status === 'publish' ) {
            $key_pages[] = [ 'slug' => $slug, 'url' => get_permalink( $p->ID ) ];
        }
    }
    // LIKE fallback: busca páginas cuyo slug contiene "contact" o "nosotros"
    if ( empty( $key_pages ) ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT ID, post_name FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type='page'
               AND (post_name LIKE 'contact%' OR post_name LIKE '%nosotros%' OR post_name LIKE '%about%' OR post_name LIKE '%contacto%')
             LIMIT 5"
        );
        foreach ( $rows as $r ) {
            $key_pages[] = [ 'slug' => $r->post_name, 'url' => get_permalink( $r->ID ) ];
        }
    } else {
        // También buscar por LIKE para completar (pueden existir ambos)
        global $wpdb;
        $found_slugs = array_column( $key_pages, 'slug' );
        $rows = $wpdb->get_results(
            "SELECT ID, post_name FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type='page'
               AND (post_name LIKE 'contact%' OR post_name LIKE '%nosotros%' OR post_name LIKE '%about%' OR post_name LIKE '%contacto%')
             LIMIT 10"
        );
        foreach ( $rows as $r ) {
            if ( ! in_array( $r->post_name, $found_slugs, true ) ) {
                $key_pages[] = [ 'slug' => $r->post_name, 'url' => get_permalink( $r->ID ) ];
                $found_slugs[] = $r->post_name;
            }
        }
    }
    $eat_signals['key_pages'] = $key_pages;

    $legal_pages = [];
    foreach ( [ 'privacidad', 'privacy-policy', 'aviso-legal', 'terminos', 'terms-conditions' ] as $slug ) {
        $p = get_page_by_path( $slug );
        if ( $p ) $legal_pages[] = [ 'slug' => $slug, 'url' => get_permalink( $p->ID ) ];
    }
    $eat_signals['legal_pages'] = $legal_pages;

    // 5. Score GEO
    $score   = 0;
    $max     = 0;
    $checks  = [];
    $add = function( string $id, string $label, bool $pass, int $w ) use ( &$checks, &$score, &$max ) {
        $checks[] = [ 'id' => $id, 'label' => $label, 'pass' => $pass, 'weight' => $w ];
        $max      += $w;
        if ( $pass ) $score += $w;
    };

    $add( 'no_ai_bots_blocked', 'Ningún bot de IA bloqueado en robots.txt',          empty( $blocked ), 25 );
    $add( 'llms_txt_exists',    'llms.txt existe en la raíz',                         $llms_exists,      20 );
    $add( 'llms_full_exists',   'llms-full.txt existe (contenido completo para IAs)', $llms_full_exists, 10 );
    $add( 'schema_site',        'Schema Organization/WebSite/LocalBusiness presente', ! empty( array_intersect( [ 'Organization', 'WebSite', 'LocalBusiness' ], $schema_found ) ), 15 );
    // Comprueba también schemas inyectados vía _ahm_jsonld en cualquier post/página
    $content_schema_types = [ 'Article', 'FAQPage', 'NewsArticle', 'BlogPosting' ];
    $has_content_schema   = ! empty( array_intersect( $content_schema_types, $schema_found ) );
    if ( ! $has_content_schema ) {
        global $wpdb;
        $ahm_schemas = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             INNER JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
             WHERE meta_key = '_ahm_jsonld'
               AND post_status = 'publish'
             LIMIT 50"
        );
        foreach ( $ahm_schemas as $raw ) {
            $arr = maybe_unserialize( $raw );
            if ( ! is_array( $arr ) ) continue;
            foreach ( $arr as $schema_item ) {
                if ( in_array( $schema_item['@type'] ?? '', $content_schema_types, true ) ) {
                    $has_content_schema = true;
                    break 2;
                }
            }
        }
    }
    $add( 'schema_content', 'Schema Article o FAQPage en contenido', $has_content_schema, 10 );
    $add( 'author_bios',        'Autores con bio completa (E-E-A-T)',                 ! empty( array_filter( $eat_signals['authors'] ?? [], fn($a) => $a['has_bio'] ) ), 10 );
    $add( 'about_page',         'Página Sobre nosotros / Contacto existe',            ! empty( $key_pages ), 5 );
    $add( 'legal_pages',        'Páginas legales (privacidad/aviso) existen',         ! empty( $legal_pages ), 5 );

    $geo_score = $max > 0 ? (int) round( ( $score / $max ) * 100 ) : 0;

    // 6. Recomendaciones
    $recs = [];
    if ( ! empty( $blocked ) ) {
        $recs[] = [ 'priority' => 'critical', 'action' => 'Desbloquea estos bots en robots.txt: ' . implode( ', ', array_column( $blocked, 'bot' ) ) . '. Sin acceso los LLMs no pueden indexar tu web.', 'file' => 'robots.txt' ];
    }
    if ( ! $llms_exists ) {
        $recs[] = [ 'priority' => 'high', 'action' => 'Crea llms.txt: GET /seo/geo/generate → copia el campo llms_txt → POST /seo/geo/write-llms-txt con {"file":"llms.txt","content":"..."}.', 'file' => 'llms.txt' ];
    }
    if ( ! $llms_full_exists ) {
        $recs[] = [ 'priority' => 'medium', 'action' => 'Crea llms-full.txt: GET /seo/geo/generate?full=true → POST /seo/geo/write-llms-txt con {"file":"llms-full.txt","content":"..."}.', 'file' => 'llms-full.txt' ];
    }
    if ( empty( array_intersect( [ 'Organization', 'WebSite', 'LocalBusiness' ], $schema_found ) ) ) {
        $recs[] = [ 'priority' => 'high', 'action' => 'Añade schema Organization/WebSite en Rank Math → Ajustes → Conocimiento → Tipo de sitio.', 'file' => null ];
    }
    if ( empty( array_filter( $eat_signals['authors'] ?? [], fn($a) => $a['has_bio'] ) ) ) {
        $recs[] = [ 'priority' => 'medium', 'action' => 'Rellena la bio de los autores (WordPress → Usuarios). Las IAs citan más fuentes con autoría visible.', 'file' => null ];
    }

    return new WP_REST_Response( [
        'site_url'        => $site_url,
        'geo_score'       => $geo_score,
        'geo_rating'      => $geo_score >= 75 ? 'good' : ( $geo_score >= 40 ? 'needs_work' : 'poor' ),
        'checks'          => $checks,
        'recommendations' => $recs,
        'robots_txt'      => [ 'url' => $robots_url, 'found' => $robots_raw !== '', 'ai_blocked' => $blocked, 'ai_allowed' => $allowed ],
        'llms_txt'        => [ 'url' => $llms_url, 'exists' => $llms_exists, 'preview' => $llms_preview, 'full_url' => $llms_full_url, 'full_exists' => $llms_full_exists ],
        'schema'          => [ 'found' => $schema_found, 'ok' => $schema_ok, 'missing' => $schema_missing ],
        'eat_signals'     => $eat_signals,
    ], 200 );
}

/** GET /seo/geo/generate — genera contenido de llms.txt y (opcional) llms-full.txt */
function rmai_geo_generate( WP_REST_Request $request ) {
    $max_posts = min( (int) $request->get_param( 'max_posts' ), 200 );
    $max_pages = min( (int) $request->get_param( 'max_pages' ), 100 );
    $full      = filter_var( $request->get_param( 'full' ), FILTER_VALIDATE_BOOLEAN );

    $site_name   = get_bloginfo( 'name' );
    $tagline     = get_bloginfo( 'description' );
    $site_url    = get_site_url();
    $language    = get_locale();
    $admin_email = get_option( 'admin_email' );

    $noindex_meta = [
        'relation' => 'OR',
        [ 'key' => 'rank_math_robots', 'compare' => 'NOT EXISTS' ],
        [ 'key' => 'rank_math_robots', 'value' => 'noindex', 'compare' => 'NOT LIKE' ],
    ];

    $pages = get_posts( [
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => $max_pages,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'meta_query'     => $noindex_meta,
    ] );

    $posts = get_posts( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $max_posts,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => $noindex_meta,
    ] );

    $categories = get_categories( [ 'hide_empty' => true, 'number' => 30 ] );

    // Construir llms.txt
    $l   = [];
    $l[] = "# {$site_name}";
    $l[] = '';
    if ( $tagline ) { $l[] = "> {$tagline}"; $l[] = ''; }
    $l[] = "Sitio web en {$language}. URL base: {$site_url}";
    $l[] = '';

    if ( ! empty( $pages ) ) {
        $l[] = '## Páginas principales';
        $l[] = '';
        foreach ( $pages as $p ) {
            $ex  = $p->post_excerpt ? wp_strip_all_tags( $p->post_excerpt ) : wp_trim_words( wp_strip_all_tags( $p->post_content ), 20, '' );
            $l[] = '- [' . $p->post_title . '](' . get_permalink( $p->ID ) . ')' . ( $ex ? ': ' . $ex : '' );
        }
        $l[] = '';
    }

    if ( ! empty( $posts ) ) {
        $l[] = '## Artículos y contenido';
        $l[] = '';
        foreach ( $posts as $p ) {
            $kw  = get_post_meta( $p->ID, 'rank_math_focus_keyword', true );
            $ex  = $p->post_excerpt ? wp_strip_all_tags( $p->post_excerpt ) : wp_trim_words( wp_strip_all_tags( $p->post_content ), 20, '' );
            $l[] = '- [' . $p->post_title . '](' . get_permalink( $p->ID ) . ')' . ( $kw ? " [tema: {$kw}]" : '' ) . ( $ex ? ': ' . $ex : '' );
        }
        $l[] = '';
    }

    if ( ! empty( $categories ) ) {
        $l[] = '## Categorías';
        $l[] = '';
        foreach ( $categories as $cat ) {
            $l[] = '- [' . $cat->name . '](' . get_category_link( $cat->term_id ) . '): ' . $cat->count . ' artículos';
        }
        $l[] = '';
    }

    $l[] = '## Información del sitio';
    $l[] = '';
    $l[] = "- Sitio: {$site_name}";
    $l[] = "- URL: {$site_url}";
    $l[] = "- Idioma: {$language}";
    $l[] = "- Contacto: {$admin_email}";
    $l[] = '- Plataforma: WordPress ' . get_bloginfo( 'version' );
    $l[] = '';
    $l[] = '## Permisos para agentes IA';
    $l[] = '';
    $l[] = '- Permitido: rastreo, indexación, citas, resúmenes';
    $l[] = '- No permitido: reproducción literal masiva de contenido';
    $l[] = "- Sitemap: {$site_url}/sitemap_index.xml";
    $l[] = "- robots.txt: {$site_url}/robots.txt";

    $llms_txt = implode( "\n", $l );

    // llms-full.txt
    $llms_full_txt = null;
    if ( $full ) {
        $fl   = $l;
        $fl[] = '';
        $fl[] = '---';
        $fl[] = '';
        $fl[] = '# Contenido completo';
        $fl[] = '';
        foreach ( array_merge( $pages, $posts ) as $p ) {
            $content = trim( preg_replace( '/\s{3,}/', "\n\n", wp_strip_all_tags( do_shortcode( $p->post_content ) ) ) );
            if ( $content === '' ) continue;
            $fl[] = '## [' . $p->post_title . '](' . get_permalink( $p->ID ) . ')';
            $fl[] = '';
            $kw   = get_post_meta( $p->ID, 'rank_math_focus_keyword', true );
            if ( $kw ) { $fl[] = "_Palabra clave: {$kw}_"; $fl[] = ''; }
            $fl[] = $content;
            $fl[] = '';
            $fl[] = '---';
            $fl[] = '';
        }
        $llms_full_txt = implode( "\n", $fl );
    }

    return new WP_REST_Response( [
        'site'           => $site_name,
        'generated_at'   => current_time( 'Y-m-d H:i:s' ),
        'pages_included' => count( $pages ),
        'posts_included' => count( $posts ),
        'llms_txt'       => $llms_txt,
        'llms_txt_url'   => $site_url . '/llms.txt',
        'llms_full_txt'  => $llms_full_txt,
        'llms_full_url'  => $site_url . '/llms-full.txt',
        'how_to_write'   => 'POST /seo/geo/write-llms-txt con {"file":"llms.txt","content":"<el valor de llms_txt>"}',
    ], 200 );
}

/** POST /seo/geo/write-llms-txt — escribe llms.txt o llms-full.txt en la raíz de WordPress */
function rmai_geo_write_llms_txt( WP_REST_Request $request ) {
    $body    = $request->get_json_params();
    $content = $body['content'] ?? null;
    $file    = sanitize_file_name( $body['file'] ?? 'llms.txt' );

    if ( ! in_array( $file, [ 'llms.txt', 'llms-full.txt' ], true ) ) {
        return new WP_Error( 'rmai_invalid_file', 'Solo se permite llms.txt o llms-full.txt.', [ 'status' => 400 ] );
    }

    if ( empty( $content ) || ! is_string( $content ) ) {
        return new WP_Error( 'rmai_missing_content', 'Incluye {file:"llms.txt", content:"..."}.', [ 'status' => 400 ] );
    }

    if ( ! is_dir( ABSPATH ) ) {
        return new WP_Error( 'rmai_abspath_invalid', 'ABSPATH no válido.', [ 'status' => 500 ] );
    }

    $path    = rtrim( ABSPATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $file;
    $written = file_put_contents( $path, $content );

    if ( $written === false ) {
        return new WP_Error( 'rmai_write_failed', "No se pudo escribir {$file} en {$path}. El servidor web necesita permisos de escritura en la raíz de WordPress.", [ 'status' => 500 ] );
    }

    $public_url = get_site_url() . '/' . $file;
    $verify     = wp_remote_get( $public_url, [ 'timeout' => 5, 'sslverify' => false ] );
    $public_ok  = ! is_wp_error( $verify ) && wp_remote_retrieve_response_code( $verify ) === 200;

    return new WP_REST_Response( [
        'success'    => true,
        'file'       => $file,
        'path'       => $path,
        'bytes'      => $written,
        'public_url' => $public_url,
        'public_ok'  => $public_ok,
        'status'     => $public_ok ? "✅ Accesible en {$public_url}" : "⚠️ Escrito pero no accesible. Revisa .htaccess o config del servidor.",
    ], 200 );
}

// ═══════════════════════════════════════════════════════════════════════════
// 13. REPORT, IMAGES, DUPLICATES, ORPHANS, REDIRECTIONS
// ═══════════════════════════════════════════════════════════════════════════

/** GET /seo/report — resumen ejecutivo SEO del sitio */
function rmai_seo_report( WP_REST_Request $request ) {
    global $wpdb;

    $public_types = array_keys( get_post_types( [ 'public' => true ] ) );
    $types_in     = "'" . implode( "','", array_map( 'esc_sql', $public_types ) ) . "'";

    // Total posts publicados
    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type IN ({$types_in})"
    );

    // Sin focus keyword
    $no_keyword = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'rank_math_focus_keyword'
         WHERE p.post_status = 'publish' AND p.post_type IN ({$types_in})
         AND (pm.meta_value IS NULL OR pm.meta_value = '')"
    );

    // Sin meta description
    $no_desc = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'rank_math_description'
         WHERE p.post_status = 'publish' AND p.post_type IN ({$types_in})
         AND (pm.meta_value IS NULL OR pm.meta_value = '')"
    );

    // Sin seo title
    $no_title = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'rank_math_title'
         WHERE p.post_status = 'publish' AND p.post_type IN ({$types_in})
         AND (pm.meta_value IS NULL OR pm.meta_value = '')"
    );

    // Score < 50
    $bad_score = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'rank_math_seo_score'
         WHERE p.post_status = 'publish' AND p.post_type IN ({$types_in})
         AND CAST(pm.meta_value AS UNSIGNED) < 50 AND pm.meta_value != ''"
    );

    // Sin score (nunca analizado)
    $no_score = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'rank_math_seo_score'
         WHERE p.post_status = 'publish' AND p.post_type IN ({$types_in})
         AND (pm.meta_value IS NULL OR pm.meta_value = '0' OR pm.meta_value = '')"
    );

    // Imágenes sin alt
    $no_alt = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
         WHERE p.post_status = 'inherit' AND p.post_type = 'attachment'
         AND p.post_mime_type LIKE 'image/%'
         AND (pm.meta_value IS NULL OR pm.meta_value = '')"
    );

    // Total imágenes
    $total_images = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_status = 'inherit' AND post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
    );

    // Errores 404 (Rank Math)
    $total_404 = 0;
    $table_404 = $wpdb->prefix . 'rank_math_404_logs';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_404 ) ) ) {
        $total_404 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_404}`" );
    }

    // Redirections activas
    $total_redirections = 0;
    $table_redir = $wpdb->prefix . 'rank_math_redirections';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_redir ) ) ) {
        $total_redirections = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_redir}` WHERE status = 'active'" );
    }

    // llms.txt existe?
    $llms_exists = file_exists( rtrim( ABSPATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'llms.txt' );

    $seo_health = $total > 0 ? (int) round( ( ( $total - $no_keyword ) / $total ) * 100 ) : 0;

    return new WP_REST_Response( [
        'generated_at'  => current_time( 'Y-m-d H:i:s' ),
        'site_url'      => get_site_url(),
        'seo_health'    => $seo_health,
        'content' => [
            'total_published'      => $total,
            'no_focus_keyword'     => $no_keyword,
            'no_seo_description'   => $no_desc,
            'no_seo_title'         => $no_title,
            'score_below_50'       => $bad_score,
            'never_analyzed'       => $no_score,
        ],
        'images' => [
            'total'       => $total_images,
            'missing_alt' => $no_alt,
            'alt_coverage' => $total_images > 0 ? round( ( ( $total_images - $no_alt ) / $total_images ) * 100, 1 ) . '%' : 'N/A',
        ],
        'errors' => [
            '404_count'          => $total_404,
            'redirections_active'=> $total_redirections,
        ],
        'geo' => [
            'llms_txt_exists' => $llms_exists,
        ],
        'quick_wins' => array_filter( [
            $no_keyword  > 0 ? "GET /posts?missing=focus_keyword — {$no_keyword} posts sin keyword" : null,
            $no_desc     > 0 ? "GET /posts?missing=seo_description — {$no_desc} posts sin meta description" : null,
            $bad_score   > 0 ? "GET /posts?seo_score_lt=50 — {$bad_score} posts con score bajo" : null,
            $no_alt      > 0 ? "GET /seo/images?missing_alt=true — {$no_alt} imágenes sin alt" : null,
            $total_404   > 0 ? "GET /seo/404 — {$total_404} errores 404 registrados" : null,
            ! $llms_exists  ? 'GET /seo/geo/generate → POST /seo/geo/write-llms-txt — llms.txt no existe' : null,
        ] ),
    ], 200 );
}

/** GET /seo/images — imágenes sin alt en todo el sitio */
function rmai_seo_images_audit( WP_REST_Request $request ) {
    $per_page    = min( (int) $request->get_param( 'per_page' ), 200 );
    $page        = (int) $request->get_param( 'page' );
    $missing_alt = filter_var( $request->get_param( 'missing_alt' ), FILTER_VALIDATE_BOOLEAN );

    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    if ( $missing_alt ) {
        $args['meta_query'] = [
            'relation' => 'OR',
            [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_wp_attachment_image_alt', 'value'   => '',           'compare' => '=' ],
        ];
    }

    $query = new WP_Query( $args );
    $items = [];

    foreach ( $query->posts as $att ) {
        $item = rmai_media_item( (int) $att->ID );

        // Post padre (si está adjunta a un post)
        if ( $att->post_parent ) {
            $parent = get_post( $att->post_parent );
            $item['used_in'] = $parent ? [
                'id'    => $parent->ID,
                'title' => $parent->post_title,
                'url'   => get_permalink( $parent->ID ),
                'type'  => $parent->post_type,
            ] : null;
        } else {
            $item['used_in'] = null;
        }

        $items[] = $item;
    }

    return new WP_REST_Response( [
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'page'        => $page,
        'per_page'    => $per_page,
        'filter'      => $missing_alt ? 'missing_alt' : 'all',
        'items'       => $items,
    ], 200 );
}

/** GET /seo/duplicates — meta titles o descriptions duplicados */
function rmai_seo_duplicates( WP_REST_Request $request ) {
    global $wpdb;

    $field     = $request->get_param( 'field' );
    $public_types = array_keys( get_post_types( [ 'public' => true ] ) );
    $types_in  = "'" . implode( "','", array_map( 'esc_sql', $public_types ) ) . "'";

    $result = [ 'titles' => [], 'descriptions' => [] ];

    $find_dupes = function( string $meta_key ) use ( $wpdb, $types_in ): array {
        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS value, GROUP_CONCAT(pm.post_id ORDER BY pm.post_id ASC) AS ids, COUNT(*) AS total
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '{$meta_key}'
               AND pm.meta_value != ''
               AND p.post_status = 'publish'
               AND p.post_type IN ({$types_in})
             GROUP BY pm.meta_value
             HAVING total > 1
             ORDER BY total DESC
             LIMIT 50"
        );

        $dupes = [];
        foreach ( $rows as $row ) {
            $ids   = array_map( 'intval', explode( ',', $row->ids ) );
            $posts = [];
            foreach ( $ids as $id ) {
                $p = get_post( $id );
                if ( $p ) {
                    $posts[] = [ 'id' => $id, 'title' => $p->post_title, 'url' => get_permalink( $id ), 'type' => $p->post_type ];
                }
            }
            $dupes[] = [ 'value' => $row->value, 'count' => (int) $row->total, 'posts' => $posts ];
        }
        return $dupes;
    };

    if ( in_array( $field, [ 'title', 'both' ], true ) ) {
        $result['titles'] = $find_dupes( 'rank_math_title' );
    }
    if ( in_array( $field, [ 'description', 'both' ], true ) ) {
        $result['descriptions'] = $find_dupes( 'rank_math_description' );
    }

    return new WP_REST_Response( [
        'duplicate_titles'       => count( $result['titles'] ),
        'duplicate_descriptions' => count( $result['descriptions'] ),
        'titles'                 => $result['titles'],
        'descriptions'           => $result['descriptions'],
    ], 200 );
}

/** GET /seo/orphans — páginas sin enlaces internos entrantes */
function rmai_seo_orphans( WP_REST_Request $request ) {
    global $wpdb;

    $type         = sanitize_text_field( $request->get_param( 'type' ) );
    $public_types = array_keys( get_post_types( [ 'public' => true ] ) );
    $post_types   = ( $type === 'any' || ! in_array( $type, $public_types, true ) ) ? $public_types : [ $type ];
    $types_in     = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

    // Obtener todos los posts publicados del tipo solicitado
    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_name, post_type, post_status
         FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type IN ({$types_in})"
    );

    if ( empty( $posts ) ) {
        return new WP_REST_Response( [ 'orphans_count' => 0, 'orphans' => [] ], 200 );
    }

    // Obtener todo el post_content publicado en una sola query
    $all_content = (string) $wpdb->get_var(
        "SELECT GROUP_CONCAT(post_content SEPARATOR ' ')
         FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type IN ({$types_in})"
    );

    $orphans    = [];
    $site_url   = get_site_url();
    $site_host  = (string) parse_url( $site_url, PHP_URL_HOST );

    foreach ( $posts as $p ) {
        $permalink = get_permalink( (int) $p->ID );
        $slug      = $p->post_name;

        // Busca la URL o el slug en el contenido agregado
        $found = stripos( $all_content, $permalink ) !== false
              || stripos( $all_content, '/' . $slug . '"' ) !== false
              || stripos( $all_content, '/' . $slug . "'" ) !== false
              || stripos( $all_content, '/' . $slug . '/' ) !== false;

        if ( ! $found ) {
            $noindex = false;
            $robots  = get_post_meta( (int) $p->ID, 'rank_math_robots', true );
            if ( is_array( $robots ) ) {
                $noindex = in_array( 'noindex', $robots, true );
            } elseif ( is_string( $robots ) ) {
                $noindex = strpos( $robots, 'noindex' ) !== false;
            }

            $orphans[] = [
                'id'      => (int) $p->ID,
                'title'   => $p->post_title,
                'slug'    => $p->post_name,
                'url'     => $permalink,
                'type'    => $p->post_type,
                'noindex' => $noindex,
            ];
        }
    }

    return new WP_REST_Response( [
        'total_analyzed' => count( $posts ),
        'orphans_count'  => count( $orphans ),
        'note'           => 'Páginas sin ningún enlace interno desde el contenido de otros posts del mismo tipo.',
        'orphans'        => $orphans,
    ], 200 );
}

/** GET /redirections — lista redirections de Rank Math */
function rmai_get_redirections( WP_REST_Request $request ) {
    global $wpdb;

    $table = $wpdb->prefix . 'rank_math_redirections';
    if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        return new WP_Error( 'rmai_no_redirections', 'Tabla de redirections de Rank Math no encontrada. Activa el módulo Redirections en Rank Math.', [ 'status' => 404 ] );
    }

    $per_page  = min( (int) $request->get_param( 'per_page' ), 200 );
    $page      = max( 1, (int) $request->get_param( 'page' ) );
    $status    = $request->get_param( 'status' );
    $offset    = ( $page - 1 ) * $per_page;
    $allowed   = [ 'active', 'inactive', 'trashed' ];
    $status    = in_array( $status, $allowed, true ) ? $status : 'active';

    $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", $status ) );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, sources, url_to, header_code, status, created, updated
             FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
            $status, $per_page, $offset
        )
    );

    $items = [];
    foreach ( $rows as $row ) {
        $sources = @unserialize( $row->sources );
        $items[] = [
            'id'             => (int) $row->id,
            'sources'        => is_array( $sources ) ? $sources : [],
            'url_to'         => $row->url_to,
            'header_code'    => (int) $row->header_code,
            'status'         => $row->status,
            'created'        => $row->created,
            'updated'        => $row->updated,
        ];
    }

    return new WP_REST_Response( [
        'total'       => $total,
        'total_pages' => (int) ceil( $total / $per_page ),
        'page'        => $page,
        'per_page'    => $per_page,
        'status'      => $status,
        'items'       => $items,
    ], 200 );
}

/** POST /redirections — crea una redirection en Rank Math */
function rmai_create_redirection( WP_REST_Request $request ) {
    global $wpdb;

    $table = $wpdb->prefix . 'rank_math_redirections';
    if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        return new WP_Error( 'rmai_no_redirections', 'Módulo Redirections de Rank Math no activo.', [ 'status' => 404 ] );
    }

    $body = $request->get_json_params();

    if ( empty( $body['from'] ) || empty( $body['to'] ) ) {
        return new WP_Error( 'rmai_missing_fields', 'Incluye {from: "/url-origen", to: "/url-destino", code: 301}.', [ 'status' => 400 ] );
    }

    // Rank Math guarda los patrones SIN barra inicial (ej. "mi-pagina/").
    $from = ltrim( sanitize_text_field( $body['from'] ), '/' );
    $to   = sanitize_text_field( $body['to'] );
    $code = in_array( (int) ( $body['code'] ?? 301 ), [ 301, 302, 307, 308, 410, 451 ], true )
        ? (int) $body['code']
        : 301;

    // Formato sources de Rank Math
    $sources = serialize( [
        [ 'pattern' => $from, 'comparison' => 'exact' ],
    ] );

    $now = current_time( 'mysql' );

    $inserted = $wpdb->insert(
        $table,
        [
            'sources'        => $sources,
            'url_to'         => $to,
            'header_code'    => $code,
            'status'         => 'active',
            'created'        => $now,
            'updated'        => $now,
        ],
        [ '%s', '%s', '%d', '%s', '%s', '%s' ]
    );

    if ( ! $inserted ) {
        return new WP_Error( 'rmai_insert_failed', 'No se pudo crear la redirection: ' . $wpdb->last_error, [ 'status' => 500 ] );
    }

    return new WP_REST_Response( [
        'success'  => true,
        'id'       => $wpdb->insert_id,
        'from'     => $from,
        'to'       => $to,
        'code'     => $code,
        'status'   => 'active',
    ], 201 );
}

/** DELETE /redirections/{id} */
function rmai_delete_redirection( WP_REST_Request $request ) {
    global $wpdb;

    $table = $wpdb->prefix . 'rank_math_redirections';
    if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        return new WP_Error( 'rmai_no_redirections', 'Módulo Redirections de Rank Math no activo.', [ 'status' => 404 ] );
    }

    $id  = (int) $request->get_param( 'id' );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE id = %d", $id ) );

    if ( ! $row ) {
        return new WP_Error( 'rmai_not_found', 'Redirection no encontrada.', [ 'status' => 404 ] );
    }

    $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

    return new WP_REST_Response( [ 'success' => true, 'deleted_id' => $id ], 200 );
}

// ═══════════════════════════════════════════════════════════════════════════
// 14. SCHEMA JSON-LD PERSONALIZADO + BREADCRUMBS
// ═══════════════════════════════════════════════════════════════════════════

/** GET /seo/schema/{id} — lee los schemas JSON-LD de un post */
function rmai_get_post_schemas( WP_REST_Request $request ) {
    $id   = (int) $request->get_param( 'id' );
    $post = get_post( $id );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Post no encontrado.', [ 'status' => 404 ] );
    }
    $schemas = get_post_meta( $id, '_ahm_jsonld', true ) ?: [];
    return new WP_REST_Response( [
        'post_id'  => $id,
        'title'    => $post->post_title,
        'url'      => get_permalink( $id ),
        'count'    => count( $schemas ),
        'schemas'  => $schemas,
    ], 200 );
}

/** POST /seo/schema/{id} — añade/reemplaza un schema JSON-LD en un post */
function rmai_add_post_schema( WP_REST_Request $request ) {
    $id   = (int) $request->get_param( 'id' );
    $post = get_post( $id );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Post no encontrado.', [ 'status' => 404 ] );
    }

    $body = $request->get_json_params();
    if ( empty( $body['schema'] ) || ! is_array( $body['schema'] ) ) {
        return new WP_Error( 'rmai_missing', 'Incluye {"schema": {...}} con el objeto JSON-LD.', [ 'status' => 400 ] );
    }

    $type    = sanitize_text_field( $body['schema']['@type'] ?? 'Custom' );
    $replace = (bool) ( $body['replace'] ?? false );

    $schemas = get_post_meta( $id, '_ahm_jsonld', true );
    if ( ! is_array( $schemas ) ) $schemas = [];

    if ( $replace ) {
        // Reemplaza schema del mismo @type si ya existe
        foreach ( $schemas as $k => $s ) {
            if ( ( $s['@type'] ?? '' ) === $type ) {
                unset( $schemas[ $k ] );
            }
        }
        $schemas = array_values( $schemas );
    }

    $schemas[] = $body['schema'];
    // Los schemas llevan URLs y texto con comillas; sin wp_slash() el
    // wp_unslash() de update_metadata() se come las barras invertidas.
    update_post_meta( $id, '_ahm_jsonld', wp_slash( $schemas ) );

    return new WP_REST_Response( [
        'success'  => true,
        'post_id'  => $id,
        'type'     => $type,
        'action'   => $replace ? 'replaced' : 'added',
        'total'    => count( $schemas ),
    ], 200 );
}

/** DELETE /seo/schema/{id} — elimina todos los schemas de un post */
function rmai_delete_post_schemas( WP_REST_Request $request ) {
    $id   = (int) $request->get_param( 'id' );
    $post = get_post( $id );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Post no encontrado.', [ 'status' => 404 ] );
    }
    delete_post_meta( $id, '_ahm_jsonld' );
    return new WP_REST_Response( [ 'success' => true, 'post_id' => $id, 'deleted' => true ], 200 );
}

/** GET /seo/breadcrumbs — estado actual en Rank Math */
function rmai_get_breadcrumbs_status( WP_REST_Request $request ) {
    $rm = get_option( 'rank-math-options-general', [] );
    return new WP_REST_Response( [
        'breadcrumbs_enabled' => ! empty( $rm['breadcrumbs'] ),
        'separator'           => $rm['breadcrumbs_separator'] ?? '›',
        'home_label'          => $rm['breadcrumbs_home_label'] ?? 'Inicio',
        'rank_math_option'    => 'rank-math-options-general',
    ], 200 );
}

/** POST /seo/breadcrumbs — activa breadcrumbs en Rank Math */
function rmai_set_breadcrumbs( WP_REST_Request $request ) {
    $body    = $request->get_json_params();
    $enable  = isset( $body['enable'] ) ? (bool) $body['enable'] : true;
    $sep     = sanitize_text_field( $body['separator']   ?? '›' );
    $home    = sanitize_text_field( $body['home_label']  ?? 'Inicio' );

    $rm = get_option( 'rank-math-options-general', [] );
    if ( ! is_array( $rm ) ) $rm = [];

    $rm['breadcrumbs']           = $enable ? 'on' : 'off';
    $rm['breadcrumbs_separator'] = $sep;
    $rm['breadcrumbs_home_label']= $home;

    update_option( 'rank-math-options-general', $rm );

    // Limpiar caché de Rank Math si existe
    if ( function_exists( 'rank_math' ) && method_exists( rank_math(), 'clear_cache' ) ) {
        rank_math()->clear_cache();
    }

    return new WP_REST_Response( [
        'success'             => true,
        'breadcrumbs_enabled' => $enable,
        'separator'           => $sep,
        'home_label'          => $home,
        'note'                => 'Rank Math generará BreadcrumbList automáticamente en el <head> de cada página.',
    ], 200 );
}

// ═══════════════════════════════════════════════════════════════════════════
// 16. HARDENING DE SEGURIDAD (v3.4.0)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Bloquea la enumeración de usuarios vía REST (/wp/v2/users) para quien no
 * tenga capacidad de listar usuarios. Evita filtrar el slug de login (p.ej. user 1).
 */
add_filter( 'rest_endpoints', function ( array $endpoints ): array {
    // Solo se bloquea a peticiones NO autenticadas. Cualquier usuario logueado
    // (Editor, Autor, etc.) conserva el endpoint para que el editor de bloques
    // y Elementor puedan cargar la lista de autores. Esto corta la enumeración
    // pública sin romper la edición.
    if ( is_user_logged_in() ) {
        return $endpoints;
    }
    foreach ( [ '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ] as $route ) {
        if ( isset( $endpoints[ $route ] ) ) {
            unset( $endpoints[ $route ] );
        }
    }
    return $endpoints;
}, 20 );

/**
 * Bloquea la enumeración clásica vía ?author=N (redirección a /?author=slug).
 */
add_action( 'template_redirect', function (): void {
    if ( is_admin() || is_user_logged_in() ) {
        return;
    }
    if ( isset( $_GET['author'] ) && ! empty( $_GET['author'] ) && preg_match( '/^\d+$/', (string) $_GET['author'] ) ) {
        wp_safe_redirect( home_url(), 301 );
        exit;
    }
}, 1 );

/**
 * Cabeceras de seguridad en todo el frontend y REST.
 */
add_action( 'send_headers', 'rmai_security_headers' );
add_filter( 'rest_pre_serve_request', function ( $served ) {
    rmai_security_headers();
    return $served;
} );
function rmai_security_headers(): void {
    if ( headers_sent() ) {
        return;
    }
    // Oculta versión de PHP.
    header_remove( 'X-Powered-By' );

    header( 'X-Content-Type-Options: nosniff' );

    /**
     * Orígenes externos autorizados a enmarcar el sitio.
     *
     * Vacío (por defecto) = solo el propio dominio. Los sitios que se integran
     * con un portal de compras externo (PunchOut cXML/OCI: SAP Ariba, Coupa,
     * Jaggaer…) necesitan que ese portal pueda enmarcarlos, p. ej.:
     *
     *     add_filter( 'ahm_frame_ancestors', fn( $o ) => array_merge( $o, [ 'https://*.ariba.com' ] ) );
     *
     * @param string[] $ancestors Orígenes con esquema, admiten comodín de subdominio.
     */
    $ancestors = array_filter( array_map( 'trim', (array) apply_filters( 'ahm_frame_ancestors', array() ) ) );

    if ( $ancestors ) {
        // X-Frame-Options no admite lista blanca (ALLOW-FROM está obsoleto) y
        // algunos navegadores lo priorizan sobre la CSP, así que no se envía.
        header( "Content-Security-Policy: frame-ancestors 'self' " . implode( ' ', $ancestors ) );
    } else {
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( "Content-Security-Policy: frame-ancestors 'self'" );
    }

    header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
    if ( is_ssl() ) {
        header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload' );
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 17. PERFORMANCE FRONTEND (v3.5.0)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Preconnect a orígenes externos usados en el frontend (fuentes + CDNs).
 * Reduce el "resource load delay" del LCP (Lighthouse: no preconnected origins).
 */
add_filter( 'wp_resource_hints', function ( array $hints, string $relation ): array {
    if ( is_admin() ) {
        return $hints;
    }
    if ( 'preconnect' === $relation ) {
        $hints[] = [ 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' ];
        $hints[] = 'https://fonts.googleapis.com';
        $hints[] = 'https://cdnjs.cloudflare.com';
        $hints[] = 'https://cdn.jsdelivr.net';
    }
    return $hints;
}, 10, 2 );

/**
 * Desactiva el sistema de emojis de WordPress en el frontend.
 * Elimina wp-emoji-release.min.js y la detección — menos JS y peticiones.
 * (No afecta a los emojis del footer, que son un widget aparte.)
 *
 * No se toca el admin: los hooks de admin (admin-filters.php) se registran
 * después de `init`, así que quitarlos aquí no surtía efecto. Tampoco se
 * filtra `emoji_svg_url` a false: con svgUrl vacío, wp-emoji usa la base por
 * defecto de twemoji (cdn.jsdelivr.net/gh/jdecked/twemoji@X/assets/) sin la
 * carpeta svg/, y cada emoji del admin salía como imagen rota (404).
 */
add_action( 'init', function (): void {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    add_filter( 'tiny_mce_plugins', function ( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : [];
    } );
} );

/**
 * En la pantalla del plugin (Ajustes → AHM Connect) no se carga wp-emoji:
 * el panel usa dashicons y no necesita convertir emojis en <img> de twemoji.
 * El resto del admin queda como lo deja WordPress.
 */
add_action( 'admin_enqueue_scripts', function ( $hook_suffix ): void {
    if ( 'settings_page_ahm-connect' !== $hook_suffix ) return;
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
}, 1 );

/**
 * Quita etiquetas innecesarias del <head> (RSD, wlwmanifest, generator).
 * Menos ruido y menos exposición de versión.
 */
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_generator' );

// ═══════════════════════════════════════════════════════════════════════════
// 18. HERRAMIENTAS DE MANTENIMIENTO (v3.7.0) — /tools/*
// ═══════════════════════════════════════════════════════════════════════════
//
// Acciones de mantenimiento del sitio que hasta ahora exigían acceso a
// wp-admin o SSH. Nacido de un incidente real en un cliente: tras actualizar
// AHM Connect / Rank Math, las reglas de rewrite quedaron cacheadas y
// /sitemap_index.xml devolvía 404 aunque ?sitemap=1 funcionaba. El único
// arreglo fue "Ajustes → Enlaces permanentes → Guardar" a mano.

add_action( 'rest_api_init', 'rmai_register_tools_routes' );
function rmai_register_tools_routes(): void {
    $perm = 'rmai_check_permission';

    // Regenera las reglas de reescritura (equivalente a "Guardar" en
    // Ajustes → Enlaces permanentes, o a `wp rewrite flush`).
    register_rest_route( RMAI_NAMESPACE, '/tools/flush-rewrite', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_tool_flush_rewrite',
        'permission_callback' => $perm,
    ] );
}

/**
 * POST /tools/flush-rewrite
 *
 * Fuerza un flush "duro" de las reglas de reescritura de WordPress.
 * Resuelve, entre otros, sitemaps de Rank Math que devuelven 404 tras
 * actualizar plugins pese a que la URL con query string (?sitemap=1)
 * funciona con normalidad.
 */
function rmai_tool_flush_rewrite() {
    flush_rewrite_rules( true );

    return new WP_REST_Response( [
        'success' => true,
        'message' => 'Rewrite rules flushed.',
    ], 200 );
}

/**
 * Auto-flush tras actualizaciones de plugins (causa raíz).
 *
 * El incidente no fue puntual: el sitemap de Rank Math volvía
 * a devolver 404 sin que nadie tocara nada, porque cada vez que Rank Math /
 * WooCommerce se actualizan (auto-actualización de WP o desde el panel de
 * hosting, no solo a mano) sus reglas de rewrite cambian pero WordPress no
 * las regenera solo. Aquí se enlaza un flush al hook nativo que dispara
 * WordPress al terminar CUALQUIER actualización de plugin (manual o
 * automática), para que el sitemap no vuelva a romperse por esta causa.
 *
 * Solo actúa en updates de tipo "plugin" (no temas, no traducciones): un
 * flush es barato y seguro para cualquier plugin, así que no hace falta
 * filtrar por Rank Math/WooCommerce en concreto.
 */
add_action( 'upgrader_process_complete', 'rmai_maybe_flush_rewrites', 10, 2 );
function rmai_maybe_flush_rewrites( $upgrader_object, $hook_extra ): void {
    if ( ! is_array( $hook_extra )
        || ( $hook_extra['action'] ?? '' ) !== 'update'
        || ( $hook_extra['type'] ?? '' ) !== 'plugin'
    ) {
        return;
    }

    flush_rewrite_rules( false );

    $plugins = $hook_extra['plugins'] ?? [];
    rmai_log_internal_event( 'auto-flush-rewrite (plugin update: ' . implode( ', ', (array) $plugins ) . ')' );
}

/**
 * Registra un evento interno (no ligado a una petición REST) en el mismo
 * log que usa rmai_log(), para poder confirmar desde la pestaña "Log" que
 * el auto-flush tras actualizaciones se ha disparado.
 */
function rmai_log_internal_event( string $route ): void {
    $settings = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    if ( ! $settings['log_enabled'] ) return;

    $log   = get_option( RMAI_LOG_OPTION, [] );
    $log[] = [
        'date'   => current_time( 'Y-m-d H:i:s' ),
        'method' => 'CRON',
        'route'  => '/tools/' . $route,
        'status' => 200,
        'ip'     => 'internal',
    ];

    if ( count( $log ) > RMAI_LOG_MAX ) {
        $log = array_slice( $log, -RMAI_LOG_MAX );
    }

    update_option( RMAI_LOG_OPTION, $log );
}

// ═══════════════════════════════════════════════════════════════════════════
// 19. POWER TOOLS (v3.8.0) — Gutenberg, Skills, Design, Elementor clásico,
//     subida de medios y lectura de ficheros.
// ═══════════════════════════════════════════════════════════════════════════
//
// Bloque "no-RCE": amplía lo que la IA puede hacer sin introducir ejecución de
// código arbitrario. NO incluye (por decisión de producto, al ir todo con la
// misma key diaria única que se autoactualiza en toda la flota): ejecutar PHP,
// escribir/editar/borrar ficheros, WP-CLI, instalar plugins/temas ni enlaces de
// login admin. Con esa key cualquiera de esas capacidades sería RCE en todas las
// webs. Aquí solo hay: creación/edición de contenido (Gutenberg/Elementor),
// metadatos de diseño, subida de MEDIOS y LECTURA de ficheros.
//
// Todas las rutas usan rmai_check_permission (misma auth, rate limit y log) y
// las acciones de escritura dejan una entrada de auditoría en el log existente.

/**
 * Auditoría de una acción power-tools en el mismo log que ve el admin.
 * Añade el detalle (fichero, post, recurso) al final de la ruta para que sea
 * visible en la pestaña "Log" sin tocar la tabla de la UI.
 */
function rmai_power_audit( string $route, string $detail, int $status ): void {
    $settings = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    if ( empty( $settings['log_enabled'] ) ) {
        return;
    }
    $log   = get_option( RMAI_LOG_OPTION, [] );
    $log[] = [
        'date'   => current_time( 'Y-m-d H:i:s' ),
        'method' => 'POWER',
        'route'  => $route . ( '' !== $detail ? '  ·  ' . $detail : '' ),
        'status' => $status,
        'ip'     => rmai_get_ip(),
    ];
    if ( count( $log ) > RMAI_LOG_MAX ) {
        $log = array_slice( $log, -RMAI_LOG_MAX );
    }
    update_option( RMAI_LOG_OPTION, $log );
}

// ── Registro de rutas power-tools ────────────────────────────────────────────
add_action( 'rest_api_init', 'rmai_register_power_routes' );
function rmai_register_power_routes(): void {
    $perm   = 'rmai_check_permission';
    $groups = rmai_route_groups();

    if ( $groups['files'] ) {
        rmai_register_files_routes( $perm );
    }
    if ( ! $groups['power'] ) {
        return;
    }

    // Gutenberg
    register_rest_route( RMAI_NAMESPACE, '/gutenberg/blocks', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_gutenberg_blocks',
        'permission_callback' => $perm,
    ] );
    register_rest_route( RMAI_NAMESPACE, '/gutenberg/validate', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_gutenberg_validate',
        'permission_callback' => $perm,
    ] );
    register_rest_route( RMAI_NAMESPACE, '/gutenberg/post', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_gutenberg_create',
        'permission_callback' => $perm,
    ] );
    register_rest_route( RMAI_NAMESPACE, '/gutenberg/post/(?P<id>\d+)', [
        'methods'             => 'PUT, PATCH',
        'callback'            => 'rmai_gutenberg_update',
        'permission_callback' => $perm,
    ] );

    // Skills
    register_rest_route( RMAI_NAMESPACE, '/skills', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_skills_list',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'rmai_skills_create',
            'permission_callback' => $perm,
        ],
    ] );
    register_rest_route( RMAI_NAMESPACE, '/skills/(?P<id>\d+)', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_skills_get',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'rmai_skills_update',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'rmai_skills_delete',
            'permission_callback' => $perm,
        ],
    ] );

    // Design
    register_rest_route( RMAI_NAMESPACE, '/design', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_design_get',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'rmai_design_set',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'rmai_design_reset',
            'permission_callback' => $perm,
        ],
    ] );
    register_rest_route( RMAI_NAMESPACE, '/design/elementor-kit', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'rmai_elementor_kit_get',
            'permission_callback' => $perm,
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'rmai_elementor_kit_set',
            'permission_callback' => $perm,
        ],
    ] );
    register_rest_route( RMAI_NAMESPACE, '/design/elementor-regenerate-css', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_elementor_regenerate_css',
        'permission_callback' => $perm,
    ] );

    // Elementor clásico — insertar sección
    register_rest_route( RMAI_NAMESPACE, '/post/(?P<id>\d+)/elementor-section', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_elementor_section',
        'permission_callback' => $perm,
    ] );

    // Subida de medios
    register_rest_route( RMAI_NAMESPACE, '/media/upload', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_media_upload',
        'permission_callback' => $perm,
    ] );

}

/** Rutas del grupo "files" (solo lectura). Solo se registran si el grupo está activo. */
function rmai_register_files_routes( string $perm ): void {
    register_rest_route( RMAI_NAMESPACE, '/files/list', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_files_list',
        'permission_callback' => $perm,
    ] );
    register_rest_route( RMAI_NAMESPACE, '/files/tree', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_files_tree',
        'permission_callback' => $perm,
    ] );
    register_rest_route( RMAI_NAMESPACE, '/files/read', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_files_read',
        'permission_callback' => $perm,
    ] );
    register_rest_route( RMAI_NAMESPACE, '/files/search', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'rmai_files_search',
        'permission_callback' => $perm,
    ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 19.1 GUTENBERG
// ─────────────────────────────────────────────────────────────────────────────

/** GET /gutenberg/blocks — lista los tipos de bloque registrados. */
function rmai_gutenberg_blocks() {
    $out = [];
    if ( class_exists( 'WP_Block_Type_Registry' ) ) {
        foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
            $out[] = [
                'name'     => $name,
                'title'    => $type->title ?? '',
                'category' => $type->category ?? null,
                'keywords' => $type->keywords ?? [],
            ];
        }
    }
    sort( $out );
    return new WP_REST_Response( [ 'total' => count( $out ), 'blocks' => $out ], 200 );
}

/**
 * POST /gutenberg/validate — dry-run puro: parsea el markup de bloques y
 * devuelve el árbol + si reserializa de forma estable. No escribe nada.
 */
function rmai_gutenberg_validate( WP_REST_Request $request ) {
    $body    = $request->get_json_params();
    $content = isset( $body['content'] ) ? (string) $body['content'] : '';
    if ( '' === $content ) {
        return new WP_Error( 'rmai_missing', 'Incluye {content} con el markup de bloques.', [ 'status' => 400 ] );
    }
    return new WP_REST_Response( rmai_gutenberg_inspect( $content ), 200 );
}

/** Parsea y analiza un markup de bloques (helper compartido). */
function rmai_gutenberg_inspect( string $content ): array {
    $blocks       = parse_blocks( $content );
    $reserialized = serialize_blocks( $blocks );
    $named        = array_values( array_filter( array_map(
        static function ( $b ) { return $b['blockName'] ?? null; },
        $blocks
    ) ) );
    return [
        'blocks_count' => count( $named ),
        'block_names'  => $named,
        'tree'         => $blocks,
        'stable'       => ( trim( $reserialized ) === trim( $content ) ),
        'reserialized' => $reserialized,
    ];
}

/**
 * Ejecuta una escritura de post saltándose kses (para no romper atributos de
 * bloques de terceros) y restaurando siempre los filtros kses después.
 */
function rmai_gutenberg_write_post( array $postarr ) {
    kses_remove_filters();
    try {
        return wp_insert_post( wp_slash( $postarr ), true );
    } finally {
        kses_init_filters();
    }
}

/** POST /gutenberg/post — crea un post con contenido de bloques. */
function rmai_gutenberg_create( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    if ( empty( $body['title'] ) && empty( $body['content'] ) ) {
        return new WP_Error( 'rmai_missing', 'Incluye al menos {title} o {content}.', [ 'status' => 400 ] );
    }

    $content = isset( $body['content'] ) ? (string) $body['content'] : '';
    $inspect = rmai_gutenberg_inspect( $content );

    if ( ! empty( $body['dry_run'] ) ) {
        return new WP_REST_Response( [ 'dry_run' => true, 'would_create' => true ] + $inspect, 200 );
    }

    $allowed_types = array_keys( get_post_types( [ 'public' => true ] ) );
    $post_type     = isset( $body['post_type'] ) && in_array( $body['post_type'], $allowed_types, true )
        ? $body['post_type'] : 'post';

    $postarr = [
        'post_title'   => sanitize_text_field( $body['title'] ?? '' ),
        'post_content' => $content,
        'post_excerpt' => isset( $body['post_excerpt'] ) ? wp_kses_post( $body['post_excerpt'] ) : '',
        'post_status'  => isset( $body['status'] ) && in_array( $body['status'], [ 'publish', 'draft', 'private', 'pending' ], true )
            ? $body['status'] : 'draft',
        'post_type'    => $post_type,
    ];
    if ( ! empty( $body['slug'] ) ) {
        $postarr['post_name'] = sanitize_title( $body['slug'] );
    }

    $post_id = rmai_gutenberg_write_post( $postarr );
    if ( is_wp_error( $post_id ) ) {
        rmai_power_audit( 'POST /gutenberg/post', 'error: ' . $post_id->get_error_message(), 500 );
        return new WP_Error( 'rmai_create_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    rmai_power_audit( 'POST /gutenberg/post', 'post #' . $post_id . ' (' . $inspect['blocks_count'] . ' bloques)', 201 );
    return new WP_REST_Response( [
        'success'      => true,
        'post_id'      => $post_id,
        'url'          => get_permalink( $post_id ),
        'status'       => $postarr['post_status'],
        'blocks_count' => $inspect['blocks_count'],
    ], 201 );
}

/** PUT /gutenberg/post/{id} — reescribe el contenido de bloques de un post. */
function rmai_gutenberg_update( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }
    if ( rmai_content_write_blocked( $post ) ) {
        return new WP_Error( 'rmai_content_write_blocked', 'Esta página se edita con Elementor: usa /post/{id}/meta o /elementor-section.', [ 'status' => 409 ] );
    }

    $body = $request->get_json_params();
    if ( ! isset( $body['content'] ) && ! isset( $body['title'] ) ) {
        return new WP_Error( 'rmai_missing', 'Incluye {content} y/o {title}.', [ 'status' => 400 ] );
    }

    $inspect = isset( $body['content'] ) ? rmai_gutenberg_inspect( (string) $body['content'] ) : null;

    if ( ! empty( $body['dry_run'] ) ) {
        return new WP_REST_Response( [ 'dry_run' => true, 'post_id' => $post->ID ] + ( $inspect ?: [] ), 200 );
    }

    $postarr = [ 'ID' => $post->ID ];
    if ( isset( $body['content'] ) ) {
        $postarr['post_content'] = (string) $body['content'];
    }
    if ( isset( $body['title'] ) ) {
        $postarr['post_title'] = sanitize_text_field( $body['title'] );
    }
    if ( isset( $body['status'] ) && in_array( $body['status'], [ 'publish', 'draft', 'private', 'pending' ], true ) ) {
        $postarr['post_status'] = $body['status'];
    }

    $res = rmai_gutenberg_write_post( $postarr );
    if ( is_wp_error( $res ) ) {
        rmai_power_audit( 'PUT /gutenberg/post/' . $post->ID, 'error: ' . $res->get_error_message(), 500 );
        return new WP_Error( 'rmai_update_failed', $res->get_error_message(), [ 'status' => 500 ] );
    }

    rmai_power_audit( 'PUT /gutenberg/post/' . $post->ID, 'actualizado' . ( $inspect ? ' (' . $inspect['blocks_count'] . ' bloques)' : '' ), 200 );
    return new WP_REST_Response( [
        'success'      => true,
        'post_id'      => $post->ID,
        'url'          => get_permalink( $post->ID ),
        'blocks_count' => $inspect['blocks_count'] ?? null,
    ], 200 );
}

// ─────────────────────────────────────────────────────────────────────────────
// 19.2 SKILLS (playbooks Markdown)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'init', 'rmai_register_skill_cpt' );
function rmai_register_skill_cpt(): void {
    register_post_type( RMAI_SKILL_CPT, [
        'labels'          => [ 'name' => 'AHM Skills', 'singular_name' => 'AHM Skill' ],
        'public'          => false,
        'show_ui'         => false,
        'show_in_rest'    => false,
        'hierarchical'    => false,
        'supports'        => [ 'title', 'editor', 'excerpt' ],
        'capability_type' => 'post',
    ] );
    rmai_skills_seed_builtin();
}

/** Siembra una vez la skill integrada que explica cómo escribir skills. */
function rmai_skills_seed_builtin(): void {
    if ( get_option( RMAI_OPTION_SKILL_SEED ) ) {
        return;
    }
    $content = "# Cómo escribir una skill de AHM Connect\n\n"
        . "Una *skill* es un playbook en Markdown que la IA lee y sigue cuando su **descripción** encaja con lo que pide el usuario.\n\n"
        . "## Estructura recomendada\n"
        . "1. **Nombre** corto y accionable (p. ej. \"Publicar nota de prensa\").\n"
        . "2. **Descripción** de una frase: *cuándo* usarla. La IA elige la skill por esta frase, así que sé específico.\n"
        . "3. **Contenido**: pasos numerados, con los endpoints AHM Connect exactos a llamar y en qué orden.\n\n"
        . "## Buenas prácticas\n"
        . "- Un objetivo por skill.\n"
        . "- Indica los endpoints reales (`POST /gutenberg/post`, `PUT /post/{id}`, etc.) y los campos clave.\n"
        . "- Usa `dry_run` antes de escribir cuando exista.\n"
        . "- Para páginas Elementor, nunca toques `post_content`: usa `/post/{id}/meta` o `/post/{id}/elementor-section`.\n"
        . "- Lee antes `GET /design` para respetar la dirección de diseño del sitio.\n\n"
        . "## Cómo guardar una skill nueva\n"
        . "`POST /skills` con `{name, description, content}`. Lístalas con `GET /skills` (devuelve nombre + descripción para elegir) y lee una con `GET /skills/{id}`.\n";

    $id = wp_insert_post( [
        'post_type'    => RMAI_SKILL_CPT,
        'post_status'  => 'publish',
        'post_title'   => 'Cómo escribir skills',
        'post_excerpt' => 'Meta-skill: explica el formato y las buenas prácticas para crear nuevas skills en este sitio.',
        'post_content' => $content,
    ], true );

    if ( ! is_wp_error( $id ) ) {
        update_option( RMAI_OPTION_SKILL_SEED, 1 );
    }
}

/** Serializa una skill (CPT) a array de respuesta. */
function rmai_skill_to_array( WP_Post $p, bool $with_content = false ): array {
    $out = [
        'id'          => $p->ID,
        'name'        => $p->post_title,
        'slug'        => $p->post_name,
        'description' => $p->post_excerpt,
        'modified'    => $p->post_modified,
    ];
    if ( $with_content ) {
        $out['content'] = $p->post_content;
    }
    return $out;
}

/** GET /skills — lista con nombre + descripción (para que la IA elija). */
function rmai_skills_list() {
    $posts = get_posts( [
        'post_type'      => RMAI_SKILL_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    $items = array_map( static function ( $p ) { return rmai_skill_to_array( $p, false ); }, $posts );
    return new WP_REST_Response( [ 'total' => count( $items ), 'skills' => $items ], 200 );
}

/** GET /skills/{id} — skill completa, con contenido Markdown. */
function rmai_skills_get( WP_REST_Request $request ) {
    $p = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $p || RMAI_SKILL_CPT !== $p->post_type ) {
        return new WP_Error( 'rmai_not_found', 'Skill no encontrada.', [ 'status' => 404 ] );
    }
    return new WP_REST_Response( rmai_skill_to_array( $p, true ), 200 );
}

/** POST /skills — crea una skill {name, description, content}. */
function rmai_skills_create( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    if ( empty( $body['name'] ) || ! isset( $body['content'] ) ) {
        return new WP_Error( 'rmai_missing', 'Incluye {name, content} (description recomendada).', [ 'status' => 400 ] );
    }
    $id = wp_insert_post( [
        'post_type'    => RMAI_SKILL_CPT,
        'post_status'  => 'publish',
        'post_title'   => sanitize_text_field( $body['name'] ),
        'post_excerpt' => isset( $body['description'] ) ? sanitize_text_field( $body['description'] ) : '',
        'post_content' => (string) $body['content'],
    ], true );
    if ( is_wp_error( $id ) ) {
        return new WP_Error( 'rmai_create_failed', $id->get_error_message(), [ 'status' => 500 ] );
    }
    rmai_power_audit( 'POST /skills', 'skill #' . $id . ' "' . sanitize_text_field( $body['name'] ) . '"', 201 );
    return new WP_REST_Response( rmai_skill_to_array( get_post( $id ), true ), 201 );
}

/** PUT /skills/{id} — actualiza una skill. */
function rmai_skills_update( WP_REST_Request $request ) {
    $p = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $p || RMAI_SKILL_CPT !== $p->post_type ) {
        return new WP_Error( 'rmai_not_found', 'Skill no encontrada.', [ 'status' => 404 ] );
    }
    $body    = $request->get_json_params();
    $postarr = [ 'ID' => $p->ID ];
    if ( isset( $body['name'] ) ) {
        $postarr['post_title'] = sanitize_text_field( $body['name'] );
    }
    if ( isset( $body['description'] ) ) {
        $postarr['post_excerpt'] = sanitize_text_field( $body['description'] );
    }
    if ( isset( $body['content'] ) ) {
        $postarr['post_content'] = (string) $body['content'];
    }
    $res = wp_update_post( wp_slash( $postarr ), true );
    if ( is_wp_error( $res ) ) {
        return new WP_Error( 'rmai_update_failed', $res->get_error_message(), [ 'status' => 500 ] );
    }
    rmai_power_audit( 'PUT /skills/' . $p->ID, 'actualizada', 200 );
    return new WP_REST_Response( rmai_skill_to_array( get_post( $p->ID ), true ), 200 );
}

/** DELETE /skills/{id} — borra una skill. */
function rmai_skills_delete( WP_REST_Request $request ) {
    $p = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $p || RMAI_SKILL_CPT !== $p->post_type ) {
        return new WP_Error( 'rmai_not_found', 'Skill no encontrada.', [ 'status' => 404 ] );
    }
    wp_delete_post( $p->ID, true );
    rmai_power_audit( 'DELETE /skills/' . $p->ID, 'borrada', 200 );
    return new WP_REST_Response( [ 'success' => true, 'deleted' => $p->ID ], 200 );
}

// ─────────────────────────────────────────────────────────────────────────────
// 19.3 DESIGN (dirección de diseño global + Kit clásico de Elementor)
// ─────────────────────────────────────────────────────────────────────────────

/** Estructura por defecto de la dirección de diseño. */
function rmai_design_defaults(): array {
    return [
        'palette'    => [], // [{name, color}]
        'typography' => [], // {headings, body, ...}
        'spacing'    => [], // {base, scale, ...}
        'tone'       => '', // voz/tono de marca
        'rules'      => '', // reglas libres en texto que la IA debe respetar
    ];
}

/** GET /design — lee la dirección de diseño global. */
function rmai_design_get() {
    $design = wp_parse_args( (array) get_option( RMAI_OPTION_DESIGN, [] ), rmai_design_defaults() );
    return new WP_REST_Response( $design, 200 );
}

/** PUT /design — fusiona y guarda la dirección de diseño. */
function rmai_design_set( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    if ( ! is_array( $body ) || empty( $body ) ) {
        return new WP_Error( 'rmai_missing', 'Envía al menos una clave: palette, typography, spacing, tone o rules.', [ 'status' => 400 ] );
    }
    $current = wp_parse_args( (array) get_option( RMAI_OPTION_DESIGN, [] ), rmai_design_defaults() );
    foreach ( [ 'palette', 'typography', 'spacing' ] as $k ) {
        if ( isset( $body[ $k ] ) && is_array( $body[ $k ] ) ) {
            $current[ $k ] = $body[ $k ];
        }
    }
    foreach ( [ 'tone', 'rules' ] as $k ) {
        if ( isset( $body[ $k ] ) ) {
            $current[ $k ] = wp_kses_post( (string) $body[ $k ] );
        }
    }
    update_option( RMAI_OPTION_DESIGN, $current );
    rmai_power_audit( 'PUT /design', 'claves: ' . implode( ', ', array_keys( $body ) ), 200 );
    return new WP_REST_Response( [ 'success' => true ] + $current, 200 );
}

/** DELETE /design — restablece la dirección de diseño. */
function rmai_design_reset() {
    delete_option( RMAI_OPTION_DESIGN );
    rmai_power_audit( 'DELETE /design', 'restablecida', 200 );
    return new WP_REST_Response( [ 'success' => true ] + rmai_design_defaults(), 200 );
}

/** Devuelve el ID del Kit activo de Elementor o 0. */
function rmai_elementor_kit_id(): int {
    return (int) get_option( 'elementor_active_kit', 0 );
}

/**
 * GET /design/elementor-kit — lee colores y tipografías globales y el CSS
 * personalizado del Kit CLÁSICO de Elementor (v3, nada atómico/V4).
 */
function rmai_elementor_kit_get() {
    if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
        return new WP_Error( 'rmai_no_elementor', 'Elementor no está activo.', [ 'status' => 400 ] );
    }
    $kit_id = rmai_elementor_kit_id();
    if ( ! $kit_id ) {
        return new WP_Error( 'rmai_no_kit', 'No hay Kit de Elementor activo.', [ 'status' => 400 ] );
    }
    $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
    $settings = is_array( $settings ) ? $settings : [];
    return new WP_REST_Response( [
        'kit_id'             => $kit_id,
        'system_colors'      => $settings['system_colors'] ?? [],
        'custom_colors'      => $settings['custom_colors'] ?? [],
        'system_typography'  => $settings['system_typography'] ?? [],
        'custom_typography'  => $settings['custom_typography'] ?? [],
        'custom_css'         => $settings['custom_css'] ?? '',
    ], 200 );
}

/**
 * POST /design/elementor-kit — escribe colores/tipografías/CSS del Kit clásico
 * y regenera el CSS global. Solo toca las claves enviadas.
 */
function rmai_elementor_kit_set( WP_REST_Request $request ) {
    if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
        return new WP_Error( 'rmai_no_elementor', 'Elementor no está activo.', [ 'status' => 400 ] );
    }
    $kit_id = rmai_elementor_kit_id();
    if ( ! $kit_id ) {
        return new WP_Error( 'rmai_no_kit', 'No hay Kit de Elementor activo.', [ 'status' => 400 ] );
    }
    $body = $request->get_json_params();
    if ( ! is_array( $body ) || empty( $body ) ) {
        return new WP_Error( 'rmai_missing', 'Envía system_colors, custom_colors, system_typography, custom_typography o custom_css.', [ 'status' => 400 ] );
    }

    $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
    $settings = is_array( $settings ) ? $settings : [];
    $changed  = [];
    foreach ( [ 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' ] as $k ) {
        if ( isset( $body[ $k ] ) && is_array( $body[ $k ] ) ) {
            $settings[ $k ] = $body[ $k ];
            $changed[]      = $k;
        }
    }
    if ( isset( $body['custom_css'] ) ) {
        $settings['custom_css'] = (string) $body['custom_css'];
        $changed[]              = 'custom_css';
    }
    if ( empty( $changed ) ) {
        return new WP_Error( 'rmai_missing', 'Ninguna clave válida en el cuerpo.', [ 'status' => 400 ] );
    }

    update_post_meta( $kit_id, '_elementor_page_settings', wp_slash( $settings ) );
    rmai_elementor_regenerate_css_internal();
    rmai_power_audit( 'POST /design/elementor-kit', 'kit #' . $kit_id . ' · ' . implode( ', ', $changed ), 200 );

    return new WP_REST_Response( [ 'success' => true, 'kit_id' => $kit_id, 'updated' => $changed ], 200 );
}

/** Regenera el CSS global de Elementor (si está disponible). */
function rmai_elementor_regenerate_css_internal(): bool {
    if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
        return false;
    }
    try {
        $instance = \Elementor\Plugin::$instance;
        if ( isset( $instance->files_manager ) ) {
            $instance->files_manager->clear_cache();
            return true;
        }
    } catch ( \Throwable $e ) {
        return false;
    }
    return false;
}

/** POST /design/elementor-regenerate-css — fuerza la regeneración del CSS. */
function rmai_elementor_regenerate_css() {
    if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
        return new WP_Error( 'rmai_no_elementor', 'Elementor no está activo.', [ 'status' => 400 ] );
    }
    $ok = rmai_elementor_regenerate_css_internal();
    rmai_power_audit( 'POST /design/elementor-regenerate-css', $ok ? 'ok' : 'no disponible', $ok ? 200 : 500 );
    return new WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 500 );
}

// ─────────────────────────────────────────────────────────────────────────────
// 19.4 ELEMENTOR CLÁSICO — insertar sección
// ─────────────────────────────────────────────────────────────────────────────

/**
 * POST /post/{id}/elementor-section
 *
 * Inserta uno o varios elementos de nivel superior (sección/contenedor clásico
 * con sus columnas y widgets) dentro de _elementor_data en una posición dada.
 * Reutiliza la escritura segura (rmai_write_elementor_data) y, tras escribir,
 * verifica que el árbol conserva exactamente los nodos previos + los nuevos; si
 * no, revierte. Limpia el CSS del post. Admite dry_run.
 *
 * Body: { section: <elemento|array de elementos>, position?: int|'start'|'end', dry_run?: bool }
 */
function rmai_elementor_section( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
    }
    if ( ! rmai_is_elementor_post( $post->ID ) ) {
        return new WP_Error( 'rmai_not_elementor', 'El post no está en modo Elementor (builder).', [ 'status' => 400 ] );
    }

    $body    = $request->get_json_params();
    $section = $body['section'] ?? null;
    if ( empty( $section ) || ! is_array( $section ) ) {
        return new WP_Error( 'rmai_missing', 'Incluye {section} con el/los elemento(s) de Elementor a insertar.', [ 'status' => 400 ] );
    }
    // Permitir un único elemento o un array de elementos.
    if ( isset( $section['elType'] ) ) {
        $section = [ $section ];
    }
    foreach ( $section as $el ) {
        if ( ! is_array( $el ) || ! isset( $el['elType'] ) ) {
            return new WP_Error( 'rmai_bad_section', 'Cada elemento debe ser un objeto Elementor con "elType".', [ 'status' => 400 ] );
        }
    }

    $raw  = (string) get_post_meta( $post->ID, '_elementor_data', true );
    $data = '' !== $raw ? json_decode( $raw, true ) : [];
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'rmai_bad_data', '_elementor_data existente no es JSON válido; abortado.', [ 'status' => 500 ] );
    }

    $nodes_before   = rmai_count_eltype_nodes( $data );
    $nodes_section  = rmai_count_eltype_nodes( $section );
    $position       = $body['position'] ?? 'end';
    $total          = count( $data );

    if ( 'start' === $position ) {
        array_splice( $data, 0, 0, $section );
        $pos_label = 'start';
    } elseif ( 'end' === $position || null === $position ) {
        $data      = array_merge( $data, $section );
        $pos_label = 'end';
    } else {
        $idx = max( 0, min( (int) $position, $total ) );
        array_splice( $data, $idx, 0, $section );
        $pos_label = (string) $idx;
    }

    // Mismo codificador que usa Elementor al guardar, para no alterar el escapado.
    $new_json = wp_json_encode( $data );
    if ( false === $new_json ) {
        return new WP_Error( 'rmai_encode_failed', 'No se pudo codificar el nuevo _elementor_data.', [ 'status' => 500 ] );
    }

    if ( ! empty( $body['dry_run'] ) ) {
        return new WP_REST_Response( [
            'dry_run'        => true,
            'post_id'        => $post->ID,
            'position'       => $pos_label,
            'nodes_before'   => $nodes_before,
            'nodes_added'    => $nodes_section,
            'nodes_expected' => $nodes_before + $nodes_section,
            'bytes'          => strlen( $new_json ),
        ], 200 );
    }

    rmai_write_elementor_data( $post->ID, $new_json );

    // Verificación de integridad: nodos finales = previos + nuevos.
    $stored     = (string) get_post_meta( $post->ID, '_elementor_data', true );
    $decoded    = json_decode( $stored, true );
    $nodes_now  = is_array( $decoded ) ? rmai_count_eltype_nodes( $decoded ) : -1;
    if ( $nodes_now !== $nodes_before + $nodes_section ) {
        rmai_write_elementor_data( $post->ID, $raw ); // revertir
        rmai_power_audit( 'POST /post/' . $post->ID . '/elementor-section', 'revertido (nodos ' . $nodes_now . ' != ' . ( $nodes_before + $nodes_section ) . ')', 500 );
        return new WP_Error( 'rmai_section_roundtrip_failed', 'El árbol resultante no cuadra; se revirtió al estado anterior.', [ 'status' => 500 ] );
    }

    rmai_clear_elementor_post_css( $post->ID );
    rmai_power_audit( 'POST /post/' . $post->ID . '/elementor-section', 'insertada en ' . $pos_label . ' (+' . $nodes_section . ' nodos)', 200 );

    return new WP_REST_Response( [
        'success'     => true,
        'post_id'     => $post->ID,
        'position'    => $pos_label,
        'nodes_total' => $nodes_now,
        'note'        => 'CSS del post invalidado. Si añadiste estilos globales regenera el CSS de Elementor.',
    ], 200 );
}

// ─────────────────────────────────────────────────────────────────────────────
// 19.5 SUBIDA DE MEDIOS (solo medios; nada de plugins/temas/ZIP ejecutable)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * POST /media/upload
 *
 * Sube un medio a la biblioteca vía media_handle_sideload. Acepta:
 *   { url: "https://..." }                       → descarga remota
 *   { base64: "...", filename: "foto.jpg" }       → datos en línea
 * Opcionales: { alt, title, post_id } (post_id adjunta el medio a ese post).
 * Devuelve el ID y los datos del adjunto.
 */
function rmai_media_upload( WP_REST_Request $request ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $body      = $request->get_json_params();
    $tmp       = null;
    $filename  = '';
    $cleanup   = null;
    $max_bytes = (int) RMAI_MEDIA_MAX_MB * 1048576;

    if ( ! empty( $body['url'] ) ) {
        $url      = esc_url_raw( $body['url'] );
        $filename = sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ) );
        // download_url() no acepta argumentos HTTP: el tope se inyecta solo
        // durante esta descarga. Se pide 1 byte más para distinguir "justo en
        // el tope" de "truncado por pasarse".
        $limit_cb = static function ( $args ) use ( $max_bytes ) {
            $args['limit_response_size'] = $max_bytes + 1;
            return $args;
        };
        add_filter( 'http_request_args', $limit_cb, 99 );
        $tmp = download_url( $url, 60 );
        remove_filter( 'http_request_args', $limit_cb, 99 );
        if ( is_wp_error( $tmp ) ) {
            return new WP_Error( 'rmai_download_failed', $tmp->get_error_message(), [ 'status' => 400 ] );
        }
        $cleanup = $tmp;
        if ( (int) @filesize( $tmp ) > $max_bytes ) {
            @unlink( $tmp );
            return new WP_Error( 'rmai_too_large', 'El fichero supera el tope de ' . RMAI_MEDIA_MAX_MB . ' MB.', [ 'status' => 413 ] );
        }
    } elseif ( ! empty( $body['base64'] ) ) {
        $filename = sanitize_file_name( $body['filename'] ?? '' );
        if ( '' === $filename ) {
            return new WP_Error( 'rmai_missing', 'Con base64 hay que incluir {filename}.', [ 'status' => 400 ] );
        }
        $b64 = (string) $body['base64'];
        // Se comprueba el tamaño ANTES de decodificar: 4 caracteres base64 = 3 bytes.
        if ( strlen( $b64 ) > (int) ceil( $max_bytes / 3 ) * 4 + 4 ) {
            return new WP_Error( 'rmai_too_large', 'El fichero supera el tope de ' . RMAI_MEDIA_MAX_MB . ' MB.', [ 'status' => 413 ] );
        }
        $raw = base64_decode( $b64, true );
        unset( $b64 );
        if ( false === $raw ) {
            return new WP_Error( 'rmai_bad_base64', 'base64 no válido.', [ 'status' => 400 ] );
        }
        if ( strlen( $raw ) > $max_bytes ) {
            return new WP_Error( 'rmai_too_large', 'El fichero supera el tope de ' . RMAI_MEDIA_MAX_MB . ' MB.', [ 'status' => 413 ] );
        }
        $tmp = wp_tempnam( $filename );
        if ( ! $tmp ) {
            return new WP_Error( 'rmai_tmp_failed', 'No se pudo crear el fichero temporal.', [ 'status' => 500 ] );
        }
        $written = file_put_contents( $tmp, $raw );
        $cleanup = $tmp;
        if ( strlen( $raw ) !== $written ) {
            @unlink( $tmp );
            return new WP_Error( 'rmai_tmp_failed', 'No se pudo escribir el fichero temporal (¿disco lleno?).', [ 'status' => 500 ] );
        }
        unset( $raw );
    } else {
        return new WP_Error( 'rmai_missing', 'Incluye {url} o {base64, filename}.', [ 'status' => 400 ] );
    }

    if ( '' === $filename ) {
        $filename = 'upload-' . time();
    }

    // Validación de tipo: solo MIME permitidos por WordPress para subidas.
    $check = wp_check_filetype( $filename );
    if ( empty( $check['type'] ) ) {
        if ( $cleanup && file_exists( $cleanup ) ) {
            @unlink( $cleanup );
        }
        return new WP_Error( 'rmai_bad_type', 'Tipo de fichero no permitido: ' . $filename, [ 'status' => 400 ] );
    }

    $file_array = [ 'name' => $filename, 'tmp_name' => $tmp ];
    $parent_id  = isset( $body['post_id'] ) ? (int) $body['post_id'] : 0;
    $att_id     = media_handle_sideload( $file_array, $parent_id );

    if ( is_wp_error( $att_id ) ) {
        if ( $cleanup && file_exists( $cleanup ) ) {
            @unlink( $cleanup );
        }
        rmai_power_audit( 'POST /media/upload', 'error: ' . $att_id->get_error_message(), 500 );
        return new WP_Error( 'rmai_sideload_failed', $att_id->get_error_message(), [ 'status' => 500 ] );
    }

    if ( ! empty( $body['alt'] ) ) {
        update_post_meta( $att_id, '_wp_attachment_image_alt', sanitize_text_field( $body['alt'] ) );
    }
    if ( ! empty( $body['title'] ) ) {
        wp_update_post( [ 'ID' => $att_id, 'post_title' => sanitize_text_field( $body['title'] ) ] );
    }

    rmai_power_audit( 'POST /media/upload', 'attachment #' . $att_id . ' (' . $filename . ')', 201 );
    return new WP_REST_Response( [ 'success' => true ] + rmai_media_item( (int) $att_id ), 201 );
}

// ─────────────────────────────────────────────────────────────────────────────
// 19.6 FICHEROS — SOLO LECTURA (listar, árbol, buscar, leer)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resuelve una ruta de entrada a una ruta absoluta real DENTRO de ABSPATH.
 * Devuelve null si está fuera o no existe. La entrada puede ser relativa a
 * ABSPATH o absoluta (pero siempre tiene que resolver dentro de ABSPATH).
 */
function rmai_files_resolve( string $input ) {
    $base = realpath( ABSPATH );
    if ( false === $base ) {
        return null;
    }
    $input = trim( $input );
    if ( '' === $input || '.' === $input || '/' === $input ) {
        return $base;
    }
    $candidate = ( '/' === $input[0] ) ? $input : $base . DIRECTORY_SEPARATOR . ltrim( $input, '/' );
    $real      = realpath( $candidate );
    if ( false === $real ) {
        return null;
    }
    if ( $real !== $base && 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
        return null;
    }
    return $real;
}

/**
 * Ficheros y carpetas que /files/* nunca enseña ni lee: credenciales
 * (wp-config, .env, .htpasswd, claves), configuración del servidor
 * (.htaccess, .user.ini, php.ini), volcados SQL, logs, copias y
 * comprimidos, carpetas de copias de seguridad y repositorios git.
 * Se evalúa SIEMPRE sobre la ruta ya resuelta con realpath() y relativa a
 * ABSPATH, para que ni enlaces simbólicos ni "../" la esquiven.
 *
 * Se puede AMPLIAR con el filtro 'rmai_files_blocked' (devolver true para
 * bloquear algo más); el filtro no puede desbloquear lo de la lista base.
 *     add_filter( 'rmai_files_blocked', fn( $b, $rel ) => $b || 0 === strpos( $rel, 'privado/' ), 10, 2 );
 */
function rmai_files_blocked( string $rel ): bool {
    $rel     = ltrim( str_replace( '\\', '/', $rel ), '/' );
    $blocked = (bool) preg_match(
        '#(^|/)(wp-config[^/]*|\.env[^/]*|\.htpasswd|\.htaccess|\.user\.ini|php\.ini'
        . '|[^/]*\.(sql|sql\.gz|log|bak|old|orig|save|swp|key|pem|p12|pfx|zip|tar|gz|tgz|bz2|7z|rar|wpress))$'
        . '|(^|/)(updraft|ai1wm-backups|backwpup[^/]*|wpvivid[^/]*|backups?|\.git|\.ssh)(/|$)#i',
        $rel
    );
    return $blocked || (bool) apply_filters( 'rmai_files_blocked', false, $rel );
}

/** Error estándar para una ruta protegida. */
function rmai_files_blocked_error(): WP_Error {
    return new WP_Error( 'rmai_path_blocked', 'Ruta protegida: no se puede consultar por la API.', [ 'status' => 403 ] );
}

/** Convierte una ruta absoluta a relativa respecto a ABSPATH (para respuestas). */
function rmai_files_relpath( string $abs ): string {
    $base = realpath( ABSPATH );
    if ( false !== $base && 0 === strpos( $abs, $base ) ) {
        return ltrim( substr( $abs, strlen( $base ) ), DIRECTORY_SEPARATOR );
    }
    return $abs;
}

/** Describe una entrada del sistema de ficheros. */
function rmai_files_entry( string $abs ): array {
    $is_dir = is_dir( $abs );
    return [
        'name'     => basename( $abs ),
        'path'     => rmai_files_relpath( $abs ),
        'type'     => $is_dir ? 'dir' : 'file',
        'size'     => $is_dir ? null : ( @filesize( $abs ) ?: 0 ),
        'modified' => @gmdate( 'Y-m-d H:i:s', (int) @filemtime( $abs ) ),
    ];
}

/** GET /files/list?path= — lista el contenido de un directorio. */
function rmai_files_list( WP_REST_Request $request ) {
    $dir = rmai_files_resolve( (string) $request->get_param( 'path' ) );
    if ( null === $dir ) {
        return new WP_Error( 'rmai_path_denied', 'Ruta fuera de ABSPATH o inexistente.', [ 'status' => 400 ] );
    }
    if ( ! is_dir( $dir ) ) {
        return new WP_Error( 'rmai_not_dir', 'La ruta no es un directorio.', [ 'status' => 400 ] );
    }
    if ( rmai_files_blocked( rmai_files_relpath( $dir ) ) ) {
        return rmai_files_blocked_error();
    }
    $entries = [];
    $hidden  = 0;
    foreach ( (array) @scandir( $dir ) as $name ) {
        if ( '.' === $name || '..' === $name ) {
            continue;
        }
        $abs = $dir . DIRECTORY_SEPARATOR . $name;
        if ( rmai_files_blocked( rmai_files_relpath( $abs ) ) ) {
            $hidden++;
            continue;
        }
        $entries[] = rmai_files_entry( $abs );
    }
    rmai_power_audit( 'GET /files/list', rmai_files_relpath( $dir ), 200 );
    return new WP_REST_Response( [
        'path'    => rmai_files_relpath( $dir ),
        'total'   => count( $entries ),
        'hidden'  => $hidden,
        'entries' => $entries,
    ], 200 );
}

/** Construcción recursiva del árbol con tope de profundidad y de nodos. */
function rmai_files_build_tree( string $dir, int $depth, int &$budget ): array {
    $out = [];
    if ( $depth < 0 || $budget <= 0 ) {
        return $out;
    }
    $names = @scandir( $dir );
    if ( false === $names ) {
        return $out;
    }
    foreach ( $names as $name ) {
        if ( '.' === $name || '..' === $name ) {
            continue;
        }
        $abs = $dir . DIRECTORY_SEPARATOR . $name;
        if ( rmai_files_blocked( rmai_files_relpath( $abs ) ) ) {
            continue; // ni se muestra ni se recorre
        }
        if ( $budget-- <= 0 ) {
            break;
        }
        $entry = rmai_files_entry( $abs );
        if ( 'dir' === $entry['type'] && $depth > 0 ) {
            $entry['children'] = rmai_files_build_tree( $abs, $depth - 1, $budget );
        }
        $out[] = $entry;
    }
    return $out;
}

/** GET /files/tree?path=&depth= — árbol recursivo con profundidad limitada. */
function rmai_files_tree( WP_REST_Request $request ) {
    $dir = rmai_files_resolve( (string) $request->get_param( 'path' ) );
    if ( null === $dir ) {
        return new WP_Error( 'rmai_path_denied', 'Ruta fuera de ABSPATH o inexistente.', [ 'status' => 400 ] );
    }
    if ( ! is_dir( $dir ) ) {
        return new WP_Error( 'rmai_not_dir', 'La ruta no es un directorio.', [ 'status' => 400 ] );
    }
    if ( rmai_files_blocked( rmai_files_relpath( $dir ) ) ) {
        return rmai_files_blocked_error();
    }
    $depth  = max( 0, min( 6, (int) $request->get_param( 'depth' ) ?: 2 ) );
    $budget = RMAI_FILES_NODE_MAX;
    $tree   = rmai_files_build_tree( $dir, $depth, $budget );
    rmai_power_audit( 'GET /files/tree', rmai_files_relpath( $dir ) . ' depth=' . $depth, 200 );
    return new WP_REST_Response( [
        'path'      => rmai_files_relpath( $dir ),
        'depth'     => $depth,
        'truncated' => ( $budget <= 0 ),
        'tree'      => $tree,
    ], 200 );
}

/** GET /files/read?path= — lee un fichero de texto (con tope de tamaño). */
function rmai_files_read( WP_REST_Request $request ) {
    $file = rmai_files_resolve( (string) $request->get_param( 'path' ) );
    if ( null === $file ) {
        return new WP_Error( 'rmai_path_denied', 'Ruta fuera de ABSPATH o inexistente.', [ 'status' => 400 ] );
    }
    if ( ! is_file( $file ) ) {
        return new WP_Error( 'rmai_not_file', 'La ruta no es un fichero.', [ 'status' => 400 ] );
    }
    if ( rmai_files_blocked( rmai_files_relpath( $file ) ) ) {
        rmai_power_audit( 'GET /files/read', 'BLOQUEADO ' . rmai_files_relpath( $file ), 403 );
        return rmai_files_blocked_error();
    }
    $size = (int) filesize( $file );
    if ( $size > RMAI_FILES_READ_MAX ) {
        return new WP_Error( 'rmai_too_large', 'El fichero supera el tope de lectura (' . RMAI_FILES_READ_MAX . ' bytes).', [ 'status' => 413 ] );
    }
    $content = file_get_contents( $file );
    if ( false === $content ) {
        return new WP_Error( 'rmai_read_failed', 'No se pudo leer el fichero.', [ 'status' => 500 ] );
    }
    $is_binary = ( '' !== $content && false !== strpos( $content, "\0" ) );
    rmai_power_audit( 'GET /files/read', rmai_files_relpath( $file ), 200 );
    return new WP_REST_Response( [
        'path'    => rmai_files_relpath( $file ),
        'size'    => $size,
        'binary'  => $is_binary,
        'content' => $is_binary ? null : $content,
    ], 200 );
}

/**
 * GET /files/search?path=&name=&contains= — busca ficheros por nombre (subcadena
 * sobre el nombre) y/o por contenido (subcadena). Recorre recursivamente bajo
 * path con tope de nodos; para contenido solo abre ficheros < RMAI_FILES_SCAN_MAX.
 */
function rmai_files_search( WP_REST_Request $request ) {
    $root = rmai_files_resolve( (string) $request->get_param( 'path' ) );
    if ( null === $root ) {
        return new WP_Error( 'rmai_path_denied', 'Ruta fuera de ABSPATH o inexistente.', [ 'status' => 400 ] );
    }
    if ( ! is_dir( $root ) ) {
        return new WP_Error( 'rmai_not_dir', 'La ruta base no es un directorio.', [ 'status' => 400 ] );
    }
    if ( rmai_files_blocked( rmai_files_relpath( $root ) ) ) {
        return rmai_files_blocked_error();
    }
    $name     = (string) $request->get_param( 'name' );
    $contains = (string) $request->get_param( 'contains' );
    if ( '' === $name && '' === $contains ) {
        return new WP_Error( 'rmai_missing', 'Incluye al menos "name" o "contains".', [ 'status' => 400 ] );
    }

    $results = [];
    $budget  = RMAI_FILES_NODE_MAX;
    try {
        // Las carpetas y ficheros protegidos se podan antes de entrar: ni se
        // listan ni se abren para buscar dentro (no sirven de oráculo).
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                static function ( $current ) {
                    return ! rmai_files_blocked( rmai_files_relpath( $current->getPathname() ) );
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $it as $fileinfo ) {
            if ( $budget-- <= 0 ) {
                break;
            }
            if ( ! $fileinfo->isFile() ) {
                continue;
            }
            $abs   = $fileinfo->getPathname();
            $fname = $fileinfo->getFilename();
            $match_name    = ( '' === $name ) || ( false !== stripos( $fname, $name ) );
            $match_content = ( '' === $contains );
            $line          = null;
            if ( '' !== $contains && $fileinfo->getSize() <= RMAI_FILES_SCAN_MAX ) {
                $data = @file_get_contents( $abs );
                if ( false !== $data && false === strpos( $data, "\0" ) && false !== stripos( $data, $contains ) ) {
                    $match_content = true;
                    $pos           = stripos( $data, $contains );
                    $start         = max( 0, $pos - 40 );
                    $line          = trim( substr( $data, $start, 120 ) );
                }
            } elseif ( '' !== $contains ) {
                $match_content = false; // demasiado grande para escanear
            }
            if ( $match_name && $match_content ) {
                $entry = rmai_files_entry( $abs );
                if ( null !== $line ) {
                    $entry['snippet'] = $line;
                }
                $results[] = $entry;
            }
        }
    } catch ( \Throwable $e ) {
        return new WP_Error( 'rmai_search_failed', $e->getMessage(), [ 'status' => 500 ] );
    }

    rmai_power_audit( 'GET /files/search', rmai_files_relpath( $root ) . ' name="' . $name . '" contains="' . $contains . '"', 200 );
    return new WP_REST_Response( [
        'path'      => rmai_files_relpath( $root ),
        'total'     => count( $results ),
        'truncated' => ( $budget <= 0 ),
        'results'   => $results,
    ], 200 );
}

// ═══════════════════════════════════════════════════════════════════════════
// 20. PURGA DE CACHÉS TRAS ESCRIBIR (v3.8.0) — automática + /tools/flush-cache
// ═══════════════════════════════════════════════════════════════════════════
//
// La API escribe con update_post_meta() y similares, que no disparan
// save_post: ni Elementor (CSS por post, element cache) ni LiteSpeed y
// compañía se enteraban del cambio, y la web pública seguía sirviendo la
// versión vieja hasta vaciar a mano "Elementor → Herramientas → Limpiar
// archivos y datos" y "LiteSpeed → Purge All".
//
// Funcionamiento: durante cada petición de escritura (no GET) a este
// namespace se registran los IDs de post que reciben cambios (meta, guardado,
// términos). Al terminar el callback, si la respuesta es de éxito (< 400), se
// purga cada post una vez con rmai_flush_post_cache() y se añade el campo
// "cache_flushed" a la respuesta JSON. Así cualquier endpoint, presente o
// futuro, purga sin tener que acordarse de llamarlo.
//
// Nada de esto puede tumbar la petición: cada purga va en su propio try/catch
// y solo se llama a funciones/acciones que existen.
//
// Decisión Elementor (global vs post): para una página normal basta con
// invalidar lo del propio post (_elementor_css + fichero post-{id}.css,
// _elementor_element_cache y _elementor_page_assets); el element cache de
// Elementor 4 se guarda por post, en meta del documento que lo contiene.
// El clear_cache() global borra el CSS y la caché de TODOS los posts y obliga
// a regenerarlos en las siguientes visitas, así que solo se usa cuando el
// post tocado se pinta dentro de otros: plantillas de elementor_library
// (cabecera, pie, single, popups, secciones/widgets globales) y el kit activo
// (colores y tipografías globales). En esos casos también se purga entera la
// caché de página (LiteSpeed, etc.), porque el cambio afecta a todas las URLs.

/** Metas que no cuentan como "cambio de contenido" para la purga automática. */
function rmai_cache_ignored_meta_keys(): array {
    return [
        '_edit_lock',
        '_edit_last',
        '_elementor_css',
        '_elementor_element_cache',
        '_elementor_page_assets',
    ];
}

/** Estado del registro de posts tocados durante la petición actual. */
function &rmai_cache_tracker(): array {
    static $state = [ 'active' => false, 'ids' => [] ];
    return $state;
}

/** Marca un post como modificado si el registro está activo. */
function rmai_cache_mark_post( $post_id ): void {
    $state =& rmai_cache_tracker();
    if ( ! $state['active'] ) {
        return;
    }
    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) {
        return;
    }
    // Las revisiones y autoguardados no se sirven: cuenta el post padre, que
    // wp_update_post() ya guarda por su lado.
    if ( function_exists( 'wp_is_post_revision' ) && ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) ) {
        return;
    }
    $state['ids'][ $post_id ] = true;

    // Una variación no tiene URL propia: lo que hay que purgar es el producto.
    if ( 'product_variation' === get_post_type( $post_id ) ) {
        $parent = (int) wp_get_post_parent_id( $post_id );
        if ( $parent > 0 ) {
            $state['ids'][ $parent ] = true;
        }
    }
}

function rmai_cache_on_meta_change( $meta_id, $object_id, $meta_key ): void {
    if ( in_array( (string) $meta_key, rmai_cache_ignored_meta_keys(), true ) ) {
        return;
    }
    rmai_cache_mark_post( $object_id );
}
add_action( 'added_post_meta', 'rmai_cache_on_meta_change', 10, 3 );
add_action( 'updated_post_meta', 'rmai_cache_on_meta_change', 10, 3 );
add_action( 'deleted_post_meta', 'rmai_cache_on_meta_change', 10, 3 );
add_action( 'save_post', 'rmai_cache_mark_post', 10, 1 );
add_action( 'edit_attachment', 'rmai_cache_mark_post', 10, 1 );
add_action( 'set_object_terms', 'rmai_cache_mark_post', 10, 1 );

/** Rutas de este namespace que no deben activar la purga automática. */
function rmai_cache_route_excluded( string $route ): bool {
    $prefix = '/' . RMAI_NAMESPACE;
    if ( 0 !== strpos( $route, $prefix . '/' ) ) {
        return true; // no es de este plugin
    }
    $rel = substr( $route, strlen( $prefix ) );
    // /skills/* escribe en un CPT privado que no se pinta en la web: purgar
    // LiteSpeed/Elementor por eso solo gastaría tiempo.
    return 0 === strpos( $rel, '/tools/' ) || 0 === strpos( $rel, '/ahm-sites/' ) || 0 === strpos( $rel, '/skills' );
}

add_filter( 'rest_request_before_callbacks', 'rmai_cache_begin_tracking', 10, 3 );
function rmai_cache_begin_tracking( $response, $handler, $request ) {
    $state =& rmai_cache_tracker();
    $state = [ 'active' => false, 'ids' => [] ];

    if ( ! $request instanceof WP_REST_Request ) {
        return $response;
    }
    $method = strtoupper( $request->get_method() );
    if ( 'GET' === $method || 'HEAD' === $method || 'OPTIONS' === $method ) {
        return $response;
    }
    if ( rmai_cache_route_excluded( (string) $request->get_route() ) ) {
        return $response;
    }
    $state['active'] = true;
    return $response;
}

add_filter( 'rest_request_after_callbacks', 'rmai_cache_finish_tracking', 10, 3 );
function rmai_cache_finish_tracking( $response, $handler, $request ) {
    $state =& rmai_cache_tracker();
    if ( ! $state['active'] ) {
        return $response;
    }
    $ids   = array_keys( $state['ids'] );
    $state = [ 'active' => false, 'ids' => [] ]; // la purga no debe registrarse a sí misma

    if ( empty( $ids ) || is_wp_error( $response ) ) {
        return $response;
    }
    // Un dry_run nunca purga (no escribe nada, pero se blinda igualmente).
    if ( $request instanceof WP_REST_Request && ! empty( $request->get_param( 'dry_run' ) ) ) {
        return $response;
    }
    if ( $response instanceof WP_REST_Response && $response->get_status() >= 400 ) {
        return $response;
    }

    $summary = rmai_flush_posts_cache( $ids );

    if ( $response instanceof WP_REST_Response ) {
        $data = $response->get_data();
        if ( is_array( $data ) && ! isset( $data['cache_flushed'] ) ) {
            $data['cache_flushed'] = $summary;
            $response->set_data( $data );
        }
    } elseif ( is_array( $response ) && ! isset( $response['cache_flushed'] ) ) {
        $response['cache_flushed'] = $summary;
    }

    return $response;
}

/**
 * Ejecuta un paso de purga sin dejar que un fallo se propague. Si el paso
 * devuelve false se considera "no aplicable" y no se anota.
 */
function rmai_cache_step( string $label, callable $fn, array &$purged, array &$errors ): void {
    try {
        if ( false !== $fn() ) {
            $purged[] = $label;
        }
    } catch ( \Throwable $e ) {
        $errors[] = $label . ': ' . $e->getMessage();
    }
}

/** True si el post se pinta dentro de otras páginas (plantilla o kit de Elementor). */
function rmai_is_elementor_global_post( int $post_id ): bool {
    if ( 'elementor_library' === get_post_type( $post_id ) ) {
        return true;
    }
    return $post_id === (int) get_option( 'elementor_active_kit' );
}

/**
 * Purga todas las cachés conocidas de un post tras escribirlo.
 *
 * @return array{post_id:int, purged:string[], errors:string[], global:bool}
 */
function rmai_flush_post_cache( int $post_id ): array {
    $purged = [];
    $errors = [];
    $global = false;

    if ( $post_id <= 0 || ! get_post( $post_id ) ) {
        return [ 'post_id' => $post_id, 'purged' => [], 'errors' => [ 'post no encontrado' ], 'global' => false ];
    }

    // Que la purga no se registre como cambio si se llama dentro de una petición.
    $state      =& rmai_cache_tracker();
    $was_active = $state['active'];
    $state['active'] = false;

    rmai_cache_step( 'wp:clean_post_cache', static function () use ( $post_id ) {
        clean_post_cache( $post_id );
    }, $purged, $errors );

    // ── Elementor ──
    $is_elementor = metadata_exists( 'post', $post_id, '_elementor_data' ) || rmai_is_elementor_post( $post_id );
    if ( $is_elementor && class_exists( '\\Elementor\\Plugin' ) ) {
        rmai_cache_step( 'elementor:post_meta_cache', static function () use ( $post_id ) {
            delete_post_meta( $post_id, '_elementor_element_cache' );
            delete_post_meta( $post_id, '_elementor_page_assets' );
            delete_post_meta( $post_id, '_elementor_css' );
        }, $purged, $errors );

        rmai_cache_step( 'elementor:post_css', static function () use ( $post_id ) {
            rmai_clear_elementor_post_css( $post_id );
        }, $purged, $errors );

        if ( rmai_is_elementor_global_post( $post_id ) ) {
            $global = true;
            rmai_cache_step( 'elementor:clear_cache', static function () {
                if ( ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
                    return false;
                }
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }, $purged, $errors );
        }
    }

    if ( $global ) {
        // Plantilla/kit: cambia todas las URLs, se purga toda la caché de página.
        $page = rmai_flush_page_caches_global();
        $purged = array_merge( $purged, $page['purged'] );
        $errors = array_merge( $errors, $page['errors'] );
    } else {
        $page = rmai_flush_page_caches_post( $post_id );
        $purged = array_merge( $purged, $page['purged'] );
        $errors = array_merge( $errors, $page['errors'] );
    }

    $state['active'] = $was_active;

    return [
        'post_id' => $post_id,
        'purged'  => array_values( array_unique( $purged ) ),
        'errors'  => $errors,
        'global'  => $global,
    ];
}

/** Purga varios posts y devuelve un resumen compacto para la respuesta JSON. */
function rmai_flush_posts_cache( array $post_ids ): array {
    $post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
    $purged   = [];
    $errors   = [];
    $global   = false;

    foreach ( $post_ids as $id ) {
        if ( ! get_post( $id ) ) {
            continue; // borrado en esta misma petición (p. ej. variaciones)
        }
        $r      = rmai_flush_post_cache( $id );
        $purged = array_merge( $purged, $r['purged'] );
        foreach ( $r['errors'] as $err ) {
            $errors[] = "#{$id} {$err}";
        }
        $global = $global || $r['global'];
    }

    return [
        'post_ids' => array_values( array_filter( $post_ids, 'get_post' ) ),
        'purged'   => array_values( array_unique( $purged ) ),
        'global'   => $global,
        'errors'   => $errors,
    ];
}

/** Caché de página de un post (y de la portada si el post es la portada). */
function rmai_flush_page_caches_post( int $post_id ): array {
    $purged = [];
    $errors = [];

    $home_id   = (int) get_option( 'page_on_front' );
    $blog_id   = (int) get_option( 'page_for_posts' );
    $is_home   = ( $post_id > 0 && ( $post_id === $home_id || $post_id === $blog_id ) );
    $home_url  = home_url( '/' );
    $permalink = get_permalink( $post_id );

    // LiteSpeed Cache
    if ( has_action( 'litespeed_purge_post' ) ) {
        rmai_cache_step( 'litespeed:post', static function () use ( $post_id ) {
            do_action( 'litespeed_purge_post', $post_id );
        }, $purged, $errors );
        if ( $is_home && has_action( 'litespeed_purge_url' ) ) {
            rmai_cache_step( 'litespeed:home', static function () use ( $home_url ) {
                do_action( 'litespeed_purge_url', $home_url );
            }, $purged, $errors );
        }
    }

    // WP Rocket
    if ( function_exists( 'rocket_clean_post' ) ) {
        rmai_cache_step( 'wp-rocket:post', static function () use ( $post_id ) {
            rocket_clean_post( $post_id );
        }, $purged, $errors );
        if ( $is_home && function_exists( 'rocket_clean_home' ) ) {
            rmai_cache_step( 'wp-rocket:home', static function () {
                rocket_clean_home();
            }, $purged, $errors );
        }
    }

    // W3 Total Cache
    if ( function_exists( 'w3tc_flush_post' ) ) {
        rmai_cache_step( 'w3tc:post', static function () use ( $post_id ) {
            w3tc_flush_post( $post_id );
        }, $purged, $errors );
    }

    // WP Super Cache
    if ( function_exists( 'wp_cache_post_change' ) ) {
        rmai_cache_step( 'wp-super-cache:post', static function () use ( $post_id ) {
            wp_cache_post_change( $post_id );
        }, $purged, $errors );
    }

    // SiteGround Optimizer
    if ( function_exists( 'sg_cachepress_purge_cache' ) && $permalink ) {
        rmai_cache_step( 'sg-optimizer:post', static function () use ( $permalink, $is_home, $home_url ) {
            sg_cachepress_purge_cache( $permalink );
            if ( $is_home ) {
                sg_cachepress_purge_cache( $home_url );
            }
        }, $purged, $errors );
    }

    // WP Fastest Cache
    if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
        rmai_cache_step( 'wp-fastest-cache:post', static function () use ( $post_id ) {
            wpfc_clear_post_cache_by_id( $post_id );
        }, $purged, $errors );
    }

    // Cache Enabler
    if ( has_action( 'cache_enabler_clear_page_cache_by_post' ) ) {
        rmai_cache_step( 'cache-enabler:post', static function () use ( $post_id ) {
            do_action( 'cache_enabler_clear_page_cache_by_post', $post_id );
        }, $purged, $errors );
    }

    // Hummingbird
    if ( has_action( 'wphb_clear_page_cache' ) ) {
        rmai_cache_step( 'hummingbird:post', static function () use ( $post_id ) {
            do_action( 'wphb_clear_page_cache', $post_id );
        }, $purged, $errors );
    }

    // Breeze no expone purga por post: en la portada se purga entero.
    if ( $is_home && has_action( 'breeze_clear_all_cache' ) ) {
        rmai_cache_step( 'breeze:all', static function () {
            do_action( 'breeze_clear_all_cache' );
        }, $purged, $errors );
    }

    return [ 'purged' => $purged, 'errors' => $errors ];
}

/** Caché de página completa de todos los plugins conocidos. */
function rmai_flush_page_caches_global(): array {
    $purged = [];
    $errors = [];

    if ( has_action( 'litespeed_purge_all' ) ) {
        rmai_cache_step( 'litespeed:all', static function () {
            do_action( 'litespeed_purge_all' );
        }, $purged, $errors );
    }
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rmai_cache_step( 'wp-rocket:all', static function () {
            rocket_clean_domain();
        }, $purged, $errors );
    }
    if ( function_exists( 'w3tc_flush_all' ) ) {
        rmai_cache_step( 'w3tc:all', static function () {
            w3tc_flush_all();
        }, $purged, $errors );
    }
    if ( function_exists( 'wp_cache_clear_cache' ) ) {
        rmai_cache_step( 'wp-super-cache:all', static function () {
            wp_cache_clear_cache();
        }, $purged, $errors );
    }
    if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
        rmai_cache_step( 'sg-optimizer:all', static function () {
            sg_cachepress_purge_everything();
        }, $purged, $errors );
    }
    if ( function_exists( 'wpfc_clear_all_cache' ) ) {
        rmai_cache_step( 'wp-fastest-cache:all', static function () {
            wpfc_clear_all_cache( true );
        }, $purged, $errors );
    }
    if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
        rmai_cache_step( 'cache-enabler:all', static function () {
            do_action( 'cache_enabler_clear_complete_cache' );
        }, $purged, $errors );
    }
    if ( has_action( 'wphb_clear_page_cache' ) ) {
        rmai_cache_step( 'hummingbird:all', static function () {
            do_action( 'wphb_clear_page_cache' );
        }, $purged, $errors );
    }
    if ( has_action( 'breeze_clear_all_cache' ) ) {
        rmai_cache_step( 'breeze:all', static function () {
            do_action( 'breeze_clear_all_cache' );
        }, $purged, $errors );
    }
    if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
        rmai_cache_step( 'nginx-helper:all', static function () {
            do_action( 'rt_nginx_helper_purge_all' );
        }, $purged, $errors );
    }

    return [ 'purged' => $purged, 'errors' => $errors ];
}

/** Purga global: Elementor completo + caché de página + caché de objetos. */
function rmai_flush_global_cache(): array {
    $purged = [];
    $errors = [];

    if ( class_exists( '\\Elementor\\Plugin' ) ) {
        rmai_cache_step( 'elementor:clear_cache', static function () {
            if ( ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
                return false;
            }
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }, $purged, $errors );
    }

    $page   = rmai_flush_page_caches_global();
    $purged = array_merge( $purged, $page['purged'] );
    $errors = array_merge( $errors, $page['errors'] );

    if ( function_exists( 'wp_cache_flush' ) ) {
        rmai_cache_step( 'wp:object_cache', static function () {
            wp_cache_flush();
        }, $purged, $errors );
    }

    return [
        'post_ids' => [],
        'purged'   => array_values( array_unique( $purged ) ),
        'global'   => true,
        'errors'   => $errors,
    ];
}

add_action( 'rest_api_init', 'rmai_register_cache_tools_route' );
function rmai_register_cache_tools_route(): void {
    register_rest_route( RMAI_NAMESPACE, '/tools/flush-cache', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_tool_flush_cache',
        'permission_callback' => 'rmai_check_permission',
        'args'                => [
            'post_id' => [
                'type'     => 'integer',
                'required' => false,
            ],
        ],
    ] );
}

/**
 * POST /tools/flush-cache  {post_id?}
 *
 * Con post_id purga solo ese post (Elementor del post + caché de página de su
 * URL; si es plantilla o kit de Elementor, purga global). Sin post_id hace la
 * purga global: equivale a "Elementor → Herramientas → Limpiar archivos y
 * datos" + "LiteSpeed → Purge All" (y equivalentes de otros plugins).
 */
function rmai_tool_flush_cache( WP_REST_Request $request ) {
    $post_id = (int) $request->get_param( 'post_id' );

    if ( $post_id > 0 ) {
        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'rmai_not_found', 'Entrada no encontrada.', [ 'status' => 404 ] );
        }
        return new WP_REST_Response( [
            'success'       => true,
            'scope'         => 'post',
            'cache_flushed' => rmai_flush_posts_cache( [ $post_id ] ),
        ], 200 );
    }

    return new WP_REST_Response( [
        'success'       => true,
        'scope'         => 'global',
        'cache_flushed' => rmai_flush_global_cache(),
    ], 200 );
}

// ═══════════════════════════════════════════════════════
// 12. ACTUALIZACIONES AUTOMÁTICAS
// ═══════════════════════════════════════════════════════
//
// El plugin no vive en wordpress.org, así que WordPress no sabe por sí solo
// que hay versiones nuevas. Aquí se le enseña a mirar un manifiesto JSON
// publicado en el repositorio y a ofrecer "Actualizar" en Escritorio →
// Plugins como con cualquier otro plugin.
//
// Para publicar una versión nueva:
//   1. subir RMAI_VERSION y la cabecera "Version:" de este archivo
//   2. actualizar ahm-connect.json (version, download_url, changelog)
//   3. crear la release en GitHub adjuntando ahm-connect.zip
//
// La URL del manifiesto se puede sobreescribir por sitio definiendo
// RMAI_UPDATE_MANIFEST en wp-config.php (útil para probar en staging).

// Las webs que no deben actualizarse desde este repositorio (p. ej. el
// paquete AHM Connect Heleo, que se actualiza a mano) definen en
// wp-config.php o en su cargador, ANTES de cargar este archivo:
//     define( 'RMAI_DISABLE_UPDATES', true );
// y toda esta sección queda sin registrar: ni consulta el manifiesto ni
// ofrece actualizaciones.

if ( ! defined( 'RMAI_DISABLE_UPDATES' ) || ! RMAI_DISABLE_UPDATES ) {

    if ( ! defined( 'RMAI_UPDATE_MANIFEST' ) ) {
        define( 'RMAI_UPDATE_MANIFEST', 'https://raw.githubusercontent.com/aquihaydesarrollo/AHM-connect/main/ahm-connect.json' );
    }

    define( 'RMAI_UPDATE_TRANSIENT', 'rmai_update_manifest' );
    define( 'RMAI_UPDATE_TTL',       12 * HOUR_IN_SECONDS );

    /**
     * Descarga el manifiesto remoto, cacheado para no consultar en cada carga.
     *
     * @param bool $force Ignora la caché (lo usa el enlace "Buscar actualizaciones").
     * @return array|null Manifiesto decodificado, o null si no se pudo obtener.
     */
    function rmai_fetch_manifest( bool $force = false ): ?array {
        if ( ! $force ) {
            $cached = get_site_transient( RMAI_UPDATE_TRANSIENT );
            if ( is_array( $cached ) ) {
                return $cached;
            }
            // Un fallo se cachea en corto para no reintentar en cada pantalla.
            if ( 'error' === $cached ) {
                return null;
            }
        }

        $response = wp_remote_get( RMAI_UPDATE_MANIFEST, [
            'timeout' => 10,
            'headers' => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            set_site_transient( RMAI_UPDATE_TRANSIENT, 'error', HOUR_IN_SECONDS );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
            set_site_transient( RMAI_UPDATE_TRANSIENT, 'error', HOUR_IN_SECONDS );
            return null;
        }

        set_site_transient( RMAI_UPDATE_TRANSIENT, $data, RMAI_UPDATE_TTL );
        return $data;
    }

    /** Anuncia la actualización disponible en la pantalla de Plugins. */
    add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $manifest = rmai_fetch_manifest();
        if ( ! $manifest ) {
            return $transient;
        }

        $basename = plugin_basename( __FILE__ );
        $item     = (object) [
            'id'            => 'ahm-connect',
            'slug'          => 'ahm-connect',
            'plugin'        => $basename,
            'new_version'   => $manifest['version'],
            'url'           => $manifest['homepage'] ?? '',
            'package'       => $manifest['download_url'],
            'requires'      => $manifest['requires'] ?? '',
            'requires_php'  => $manifest['requires_php'] ?? '',
            'tested'        => $manifest['tested'] ?? '',
            'icons'         => $manifest['icons'] ?? [],
        ];

        if ( version_compare( $manifest['version'], RMAI_VERSION, '>' ) ) {
            $transient->response[ $basename ] = $item;
            unset( $transient->no_update[ $basename ] );
        } else {
            // Sin esto WordPress marca el plugin como "sin información de actualización".
            unset( $transient->response[ $basename ] );
            $transient->no_update[ $basename ] = $item;
        }

        return $transient;
    } );

    /** Rellena la ficha "Ver detalles de la versión". */
    add_filter( 'plugins_api', function ( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'ahm-connect' !== $args->slug ) {
            return $result;
        }

        $manifest = rmai_fetch_manifest();
        if ( ! $manifest ) {
            return $result;
        }

        return (object) [
            'name'          => $manifest['name'] ?? 'AHM Connect',
            'slug'          => 'ahm-connect',
            'version'       => $manifest['version'],
            'author'        => $manifest['author'] ?? '',
            'homepage'      => $manifest['homepage'] ?? '',
            'requires'      => $manifest['requires'] ?? '',
            'requires_php'  => $manifest['requires_php'] ?? '',
            'tested'        => $manifest['tested'] ?? '',
            'last_updated'  => $manifest['last_updated'] ?? '',
            'download_link' => $manifest['download_url'],
            'sections'      => $manifest['sections'] ?? [],
            'banners'       => $manifest['banners'] ?? [],
        ];
    }, 10, 3 );

    /**
     * El zip de una release de GitHub se descomprime en una carpeta con el nombre
     * del tag (ahm-connect-3.6.0). WordPress necesita que se llame igual que la
     * carpeta instalada o crearía un plugin duplicado en vez de actualizar.
     */
    add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $args = [] ) {
        if ( empty( $args['plugin'] ) || plugin_basename( __FILE__ ) !== $args['plugin'] ) {
            return $source;
        }

        $expected = trailingslashit( $remote_source ) . 'ahm-connect';
        if ( untrailingslashit( $source ) === $expected ) {
            return $source;
        }

        global $wp_filesystem;
        if ( $wp_filesystem && $wp_filesystem->move( $source, $expected ) ) {
            return trailingslashit( $expected );
        }

        return $source;
    }, 10, 4 );

    /** Tras actualizar, tira la caché del manifiesto para no ofrecer la misma versión. */
    add_action( 'upgrader_process_complete', function ( $upgrader, $options ) {
        if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
            delete_site_transient( RMAI_UPDATE_TRANSIENT );
        }
    }, 10, 2 );

    /** Enlace "Buscar actualizaciones" en la fila del plugin. */
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
        $url = wp_nonce_url(
            add_query_arg( 'rmai_check_update', '1', admin_url( 'plugins.php' ) ),
            'rmai_check_update'
        );
        $links[] = '<a href="' . esc_url( $url ) . '">Buscar actualizaciones</a>';
        return $links;
    } );

    add_action( 'admin_init', function () {
        if ( empty( $_GET['rmai_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        check_admin_referer( 'rmai_check_update' );

        delete_site_transient( RMAI_UPDATE_TRANSIENT );
        rmai_fetch_manifest( true );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        wp_safe_redirect( admin_url( 'plugins.php' ) );
        exit;
    } );

} // fin RMAI_DISABLE_UPDATES

// ═══════════════════════════════════════════════════════════════════════════
// 19. AHM SITES — CONEXIÓN CON EL PANEL (v3.7.0)
// ═══════════════════════════════════════════════════════════════════════════
//
// El panel AHM Sites es quien genera y posee el secret real de conexión.
// Este plugin solo INICIA el emparejamiento con un código corto de un solo
// uso (16 hex, vive 10 min, se guarda hasheado con SHA-256 — nunca en claro).
// El panel llama a /ahm-sites/pair y /ahm-sites/activate con ese código para
// entregar el secret definitivo (64 hex). A partir de ahí, el plugin hace un
// checkin periódico saliente firmado con HMAC (igual esquema que ahm-agent).
//
// Rutas públicas por diseño (permission_callback true): el código de
// emparejamiento y la firma HMAC SON la credencial. Llevan su propio rate
// limit (5 req/min por IP) para no compartir cupo con la API key general.

/**
 * Arranca (o reinicia) el emparejamiento: genera un código de un solo uso,
 * guarda solo su hash + expiración, y devuelve el código EN CLARO para que
 * el llamante lo muestre una única vez. No queda persistido en claro en
 * ningún sitio tras esta llamada.
 */
function rmai_ahm_sites_start_pairing(): string {
    $code = bin2hex( random_bytes( 8 ) ); // 16 caracteres hex

    update_option( RMAI_OPTION_AHM_SITES_PAIRING, [
        'hash'    => hash( 'sha256', $code ),
        'expires' => time() + 600, // 10 minutos
        'status'  => 'pending',
    ], false );

    return $code;
}

/**
 * Rate limit propio para las rutas públicas de emparejamiento/activación:
 * 5 peticiones/min por IP, en un bucket de transients separado del de la
 * API key general (RMAI_RATE_LIMIT) para no interferir con integraciones
 * ya autenticadas.
 */
function rmai_ahm_sites_pairing_permission( WP_REST_Request $request ) {
    $ip        = rmai_get_ip();
    $transient = 'rmai_ahm_sites_rl_' . md5( $ip );
    $count     = (int) get_transient( $transient );

    if ( $count >= 5 ) {
        return new WP_Error( 'rmai_ahm_sites_rate_limit', 'Demasiadas peticiones. Espera un minuto.', [ 'status' => 429 ] );
    }

    set_transient( $transient, $count + 1, 60 );
    return true;
}

add_action( 'rest_api_init', 'rmai_ahm_sites_register_routes' );
function rmai_ahm_sites_register_routes(): void {

    // POST /ahm-sites/pair — { code } -> confirma que el código es válido y sigue vivo.
    register_rest_route( RMAI_NAMESPACE, '/ahm-sites/pair', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_rest_ahm_sites_pair',
        'permission_callback' => 'rmai_ahm_sites_pairing_permission',
        'args'                => [
            'code' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    // POST /ahm-sites/activate — { code, site_id, secret, panel_url } -> entrega el secret definitivo.
    register_rest_route( RMAI_NAMESPACE, '/ahm-sites/activate', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_rest_ahm_sites_activate',
        'permission_callback' => 'rmai_ahm_sites_pairing_permission',
        'args'                => [
            'code'      => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'site_id'   => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            'secret'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'panel_url' => [ 'required' => true, 'sanitize_callback' => 'esc_url_raw' ],
        ],
    ] );

    // POST /ahm-sites/rotate — job firmado por el panel (id.type.payload.expires + HMAC),
    // igual esquema que los agent-jobs de ahm-agent, pero con rmai_ahm_sites_secret como clave.
    register_rest_route( RMAI_NAMESPACE, '/ahm-sites/rotate', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rmai_rest_ahm_sites_rotate',
        'permission_callback' => '__return_true', // la firma HMAC es la única credencial
    ] );
}

function rmai_rest_ahm_sites_pair( WP_REST_Request $request ) {
    $code    = (string) $request->get_param( 'code' );
    $pairing = get_option( RMAI_OPTION_AHM_SITES_PAIRING, [] );

    $valid = ! empty( $code )
        && ! empty( $pairing['hash'] )
        && ( $pairing['status'] ?? '' ) === 'pending'
        && ( $pairing['expires'] ?? 0 ) >= time()
        && hash_equals( $pairing['hash'], hash( 'sha256', $code ) );

    if ( ! $valid ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_or_expired_code' ], 403 );
    }

    $pairing['status']          = 'confirmed';
    $pairing['confirmed_until'] = time() + 60;
    update_option( RMAI_OPTION_AHM_SITES_PAIRING, $pairing, false );

    return new WP_REST_Response( [
        'ok'        => true,
        'site_url'  => home_url(),
        'rest_base' => rest_url( RMAI_NAMESPACE ),
    ], 200 );
}

function rmai_rest_ahm_sites_activate( WP_REST_Request $request ) {
    $code      = (string) $request->get_param( 'code' );
    $site_id   = absint( $request->get_param( 'site_id' ) );
    $secret    = (string) $request->get_param( 'secret' );
    $panel_url = esc_url_raw( (string) $request->get_param( 'panel_url' ) );

    $pairing = get_option( RMAI_OPTION_AHM_SITES_PAIRING, [] );

    $valid = ! empty( $code )
        && ! empty( $pairing['hash'] )
        && ( $pairing['status'] ?? '' ) === 'confirmed'
        && ( $pairing['confirmed_until'] ?? 0 ) >= time()
        && hash_equals( $pairing['hash'], hash( 'sha256', $code ) )
        && $site_id > 0
        && $panel_url
        && preg_match( '/^[a-f0-9]{64}$/i', $secret );

    if ( ! $valid ) {
        // Nunca se registra $code ni $secret en claro, ni siquiera en el error.
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_or_expired_code' ], 403 );
    }

    update_option( RMAI_OPTION_AHM_SITES_SITE_ID, $site_id, false );
    update_option( RMAI_OPTION_AHM_SITES_SECRET, $secret, false );
    update_option( RMAI_OPTION_AHM_SITES_PANEL_URL, $panel_url, false );

    // El código de emparejamiento es de un solo uso: se destruye entero (hash incluido).
    delete_option( RMAI_OPTION_AHM_SITES_PAIRING );

    rmai_ahm_sites_maybe_schedule_checkin();

    return new WP_REST_Response( [ 'ok' => true ], 200 );
}

/**
 * Rotación de secret iniciada por el panel, sin desconectar el sitio.
 * Verifica un "signed job" con el MISMO algoritmo que ahm-agent usa para sus
 * agent-jobs (ver ahm-agent.php, ahm_run_jobs): HMAC-SHA256 sobre
 * "id.type.payload_json.expires", firmado con el secret VIGENTE. payload_json
 * se serializa con JSON_UNESCAPED_SLASHES para que coincida byte a byte con
 * lo que firma el panel.
 */
function rmai_rest_ahm_sites_rotate( WP_REST_Request $request ) {
    $secret = (string) get_option( RMAI_OPTION_AHM_SITES_SECRET, '' );
    if ( empty( $secret ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_connected' ], 403 );
    }

    $id        = absint( $request->get_param( 'id' ) );
    $type      = (string) $request->get_param( 'type' );
    $payload   = $request->get_param( 'payload' );
    $payload   = is_array( $payload ) ? $payload : [];
    $expires   = absint( $request->get_param( 'expires' ) );
    $signature = (string) $request->get_param( 'signature' );

    $payload_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
    $expected     = hash_hmac( 'sha256', $id . '.' . $type . '.' . $payload_json . '.' . $expires, $secret );

    if ( empty( $signature ) || ! hash_equals( $expected, $signature ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_signature' ], 403 );
    }
    if ( $expires < time() ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'expired' ], 403 );
    }
    if ( 'rotate_secret' !== $type ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_type' ], 400 );
    }

    $new_secret = (string) ( $payload['new_secret'] ?? '' );
    if ( ! preg_match( '/^[a-f0-9]{64}$/i', $new_secret ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_new_secret' ], 400 );
    }

    // $new_secret nunca se pasa a rmai_log() ni a error_log().
    update_option( RMAI_OPTION_AHM_SITES_SECRET, $new_secret, false );

    return new WP_REST_Response( [ 'ok' => true ], 200 );
}

// ── Checkin periódico saliente (plugin -> panel) ────────────────────────────

add_filter( 'cron_schedules', 'rmai_ahm_sites_cron_schedules' );
function rmai_ahm_sites_cron_schedules( array $schedules ): array {
    $schedules['rmai_15min'] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => 'Cada 15 minutos (AHM Sites)',
    ];
    return $schedules;
}

/** Programa o desprograma el checkin según el toggle. Idempotente. */
function rmai_ahm_sites_maybe_schedule_checkin(): void {
    $enabled   = (bool) get_option( RMAI_OPTION_AHM_SITES_ENABLED, false );
    $scheduled = wp_next_scheduled( RMAI_AHM_SITES_CRON_HOOK );

    if ( $enabled && ! $scheduled ) {
        wp_schedule_event( time() + 60, 'rmai_15min', RMAI_AHM_SITES_CRON_HOOK );
    } elseif ( ! $enabled && $scheduled ) {
        wp_clear_scheduled_hook( RMAI_AHM_SITES_CRON_HOOK );
    }
}
add_action( 'wp_loaded', 'rmai_ahm_sites_maybe_schedule_checkin' );

register_deactivation_hook( __FILE__, 'rmai_ahm_sites_unschedule_checkin' );
function rmai_ahm_sites_unschedule_checkin(): void {
    wp_clear_scheduled_hook( RMAI_AHM_SITES_CRON_HOOK );
}

add_action( RMAI_AHM_SITES_CRON_HOOK, 'rmai_ahm_sites_checkin' );
/**
 * Checkin saliente firmado, mismo formato que ahm-agent (ver ahm-agent.php,
 * ahm_agent_checkin): headers X-AHM-Site / X-AHM-Timestamp / X-AHM-Nonce /
 * X-AHM-Signature, con Signature = HMAC-SHA256("timestamp.nonce.rawBody", secret).
 */
function rmai_ahm_sites_checkin(): void {
    if ( ! get_option( RMAI_OPTION_AHM_SITES_ENABLED, false ) ) {
        return;
    }

    $secret    = (string) get_option( RMAI_OPTION_AHM_SITES_SECRET, '' );
    $site_id   = (int) get_option( RMAI_OPTION_AHM_SITES_SITE_ID, 0 );
    $panel_url = rtrim( (string) get_option( RMAI_OPTION_AHM_SITES_PANEL_URL, '' ), '/' );

    if ( empty( $secret ) || empty( $site_id ) || empty( $panel_url ) ) {
        return;
    }

    $state = [
        'agent_version' => RMAI_VERSION,
        'agent_type'    => 'ahm-connect',
        'site_url'      => home_url(),
        'wp_version'    => get_bloginfo( 'version' ),
    ];
    $body = wp_json_encode( $state );

    $timestamp = (string) time();
    $nonce     = wp_generate_uuid4();
    $signature = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $secret );

    wp_remote_post( $panel_url . '/api/agent/checkin', [
        'timeout'   => 20,
        'headers'   => [
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
            'X-AHM-Site'      => (string) $site_id,
            'X-AHM-Timestamp' => $timestamp,
            'X-AHM-Nonce'     => $nonce,
            'X-AHM-Signature' => $signature,
        ],
        'body'      => $body,
        'sslverify' => true,
    ] );
    // $secret nunca se registra en logs.
}
