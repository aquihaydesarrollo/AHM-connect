<?php
/**
 * Plugin Name: AHM Connect
 * Plugin URI:  https://aquihaymarketing.es
 * Description: API REST segura para gestionar contenido, SEO con Rank Math, atributos y productos WooCommerce, y metadatos de páginas desde herramientas externas de automatización.
 * Version:     3.6.0
 * Update URI:  https://github.com/aquihaydesarrollo/ahm-connect
 * Author:      Aquí Hay Marketing
 * Author URI:  https://aquihaymarketing.es
 * License:     GPL-2.0+
 * Text Domain: ahm-connect
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'RMAI_VERSION',         '3.6.0' );
define( 'RMAI_OPTION_API_KEY',  'rmai_api_key' );
define( 'RMAI_OPTION_SETTINGS', 'rmai_settings' );
define( 'RMAI_OPTION_ENABLED',  'rmai_api_enabled' );
define( 'RMAI_NAMESPACE',       'ahm-connect/v1' );
define( 'RMAI_RATE_LIMIT',      60 );
define( 'RMAI_LOG_OPTION',      'rmai_request_log' );
define( 'RMAI_LOG_MAX',         100 );

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
}

function rmai_generate_key(): string {
    return bin2hex( random_bytes( 24 ) );
}

function rmai_default_settings(): array {
    return [
        'rate_limit_enabled' => true,
        'log_enabled'        => true,
        'ip_whitelist'       => '',
    ];
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
        wp_safe_redirect( admin_url( 'options-general.php?page=ahm-connect&rmai_msg=log_cleared' ) );
        exit;
    }

    if (
        isset( $_POST['rmai_save_settings'] ) &&
        check_admin_referer( 'rmai_save_settings_action' ) &&
        current_user_can( 'manage_options' )
    ) {
        $settings = [
            'rate_limit_enabled' => isset( $_POST['rate_limit_enabled'] ),
            'log_enabled'        => isset( $_POST['log_enabled'] ),
            'ip_whitelist'       => sanitize_text_field( $_POST['ip_whitelist'] ?? '' ),
        ];
        update_option( RMAI_OPTION_SETTINGS, $settings );
        wp_safe_redirect( admin_url( 'options-general.php?page=ahm-connect&rmai_msg=settings_saved' ) );
        exit;
    }
}

function rmai_settings_page(): void {
    $api_key     = get_option( RMAI_OPTION_API_KEY, '' );
    $settings    = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    $base_url    = rest_url( RMAI_NAMESPACE );
    $msg         = $_GET['rmai_msg'] ?? '';
    $api_enabled = (bool) get_option( RMAI_OPTION_ENABLED, 1 );
    $log         = get_option( RMAI_LOG_OPTION, [] );
    $rm_ver      = defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null;
    $wc_ver      = defined( 'WC_VERSION' ) ? WC_VERSION : null;
    $toggle_url  = wp_nonce_url( admin_url( 'admin-post.php?action=rmai_toggle_api' ), 'rmai_toggle_api' );
    $log_count   = count( $log );
    $notices     = [
        'key_regenerated' => [ 'success', '✓ API Key regenerada. Actualiza la clave en tus herramientas.' ],
        'log_cleared'     => [ 'success', '✓ Log de peticiones eliminado.' ],
        'settings_saved'  => [ 'success', '✓ Ajustes guardados correctamente.' ],
    ];
    ?>
    <style>
    #ahm-wrap *{box-sizing:border-box}
    #ahm-wrap{max-width:1100px;margin:20px 20px 40px 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}

    /* Header */
    .ahm-header{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);border-radius:12px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:16px}
    .ahm-header-left{display:flex;align-items:center;gap:16px}
    .ahm-logo{width:44px;height:44px;background:#3b82f6;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
    .ahm-header h1{color:#fff;font-size:22px;font-weight:700;margin:0;padding:0}
    .ahm-header h1 span{color:#93c5fd;font-size:13px;font-weight:400;margin-left:8px}
    .ahm-header p{color:#94a3b8;font-size:13px;margin:3px 0 0}
    .ahm-badge{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#e2e8f0;font-size:11px;padding:3px 10px;border-radius:20px}

    /* Status bar */
    .ahm-status{border-radius:10px;padding:14px 20px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .ahm-status.on{background:#f0fdf4;border:1.5px solid #86efac}
    .ahm-status.off{background:#fef2f2;border:1.5px solid #fca5a5}
    .ahm-status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
    .ahm-status.on .ahm-status-dot{background:#22c55e;box-shadow:0 0 0 3px #bbf7d0}
    .ahm-status.off .ahm-status-dot{background:#ef4444;box-shadow:0 0 0 3px #fecaca}
    .ahm-status-text{font-size:14px;font-weight:600}
    .ahm-status.on .ahm-status-text{color:#15803d}
    .ahm-status.off .ahm-status-text{color:#b91c1c}
    .ahm-status-sub{font-size:12px;color:#64748b;margin-top:1px}

    /* Notice */
    .ahm-notice{border-radius:8px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500}
    .ahm-notice.success{background:#f0fdf4;border:1px solid #86efac;color:#166534}

    /* Grid */
    .ahm-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
    .ahm-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px}

    /* Card */
    .ahm-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
    .ahm-card-header{padding:16px 20px 12px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px}
    .ahm-card-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
    .ahm-card-icon.blue{background:#eff6ff}
    .ahm-card-icon.green{background:#f0fdf4}
    .ahm-card-icon.purple{background:#faf5ff}
    .ahm-card-icon.orange{background:#fff7ed}
    .ahm-card-icon.slate{background:#f8fafc}
    .ahm-card-title{font-size:13px;font-weight:600;color:#0f172a;margin:0}
    .ahm-card-body{padding:18px 20px}

    /* API Key */
    .ahm-key-wrap{position:relative;margin-bottom:12px}
    .ahm-key-input{width:100%;padding:10px 44px 10px 12px;font-family:"SFMono-Regular",Consolas,monospace;font-size:12px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;color:#334155;cursor:pointer;transition:.15s}
    .ahm-key-input:focus{outline:none;border-color:#3b82f6;background:#fff}
    .ahm-copy-btn{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#64748b;padding:4px;font-size:14px;transition:.15s}
    .ahm-copy-btn:hover{color:#3b82f6}

    /* Buttons */
    .ahm-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;border:none;text-decoration:none;transition:.15s;line-height:1}
    .ahm-btn:hover{text-decoration:none}
    .ahm-btn-primary{background:#3b82f6;color:#fff}
    .ahm-btn-primary:hover{background:#2563eb;color:#fff}
    .ahm-btn-danger{background:#ef4444;color:#fff}
    .ahm-btn-danger:hover{background:#dc2626;color:#fff}
    .ahm-btn-success{background:#22c55e;color:#fff}
    .ahm-btn-success:hover{background:#16a34a;color:#fff}
    .ahm-btn-ghost{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}
    .ahm-btn-ghost:hover{background:#e2e8f0;color:#334155}
    .ahm-btn-sm{padding:5px 12px;font-size:12px}

    /* Stats */
    .ahm-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;text-align:center}
    .ahm-stat-val{font-size:26px;font-weight:700;color:#0f172a;line-height:1}
    .ahm-stat-label{font-size:11px;color:#64748b;margin-top:4px;text-transform:uppercase;letter-spacing:.05em}

    /* Info table */
    .ahm-info-table{width:100%;border-collapse:collapse;font-size:13px}
    .ahm-info-table tr:not(:last-child) td{border-bottom:1px solid #f1f5f9}
    .ahm-info-table td{padding:8px 0}
    .ahm-info-table td:first-child{color:#64748b;width:110px;font-weight:500}
    .ahm-info-table td:last-child{color:#0f172a;font-weight:500}
    .ahm-pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
    .ahm-pill-green{background:#dcfce7;color:#166534}
    .ahm-pill-blue{background:#dbeafe;color:#1d4ed8}
    .ahm-pill-gray{background:#f1f5f9;color:#475569}

    /* Settings toggles */
    .ahm-toggle-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0}
    .ahm-toggle-row:not(:last-of-type){border-bottom:1px solid #f1f5f9}
    .ahm-toggle-label{font-size:13px;font-weight:500;color:#334155}
    .ahm-toggle-desc{font-size:11px;color:#94a3b8;margin-top:2px}
    .ahm-switch{position:relative;display:inline-block;width:36px;height:20px;flex-shrink:0}
    .ahm-switch input{opacity:0;width:0;height:0}
    .ahm-slider{position:absolute;inset:0;background:#e2e8f0;border-radius:20px;cursor:pointer;transition:.2s}
    .ahm-slider:before{content:"";position:absolute;width:14px;height:14px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
    .ahm-switch input:checked + .ahm-slider{background:#3b82f6}
    .ahm-switch input:checked + .ahm-slider:before{transform:translateX(16px)}
    .ahm-input{width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;color:#334155;background:#f8fafc;transition:.15s;margin-top:8px}
    .ahm-input:focus{outline:none;border-color:#3b82f6;background:#fff}

    /* Endpoints */
    .ahm-ep-section{margin-bottom:4px}
    .ahm-ep-group{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:6px 0 4px;color:#94a3b8}
    .ahm-ep-row{display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f8fafc;font-size:12px}
    .ahm-ep-row:last-child{border-bottom:none}
    .ahm-method{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;font-family:monospace;flex-shrink:0;min-width:40px;text-align:center}
    .ahm-method.get{background:#dcfce7;color:#166534}
    .ahm-method.post{background:#dbeafe;color:#1d4ed8}
    .ahm-method.put{background:#fef9c3;color:#854d0e}
    .ahm-method.delete{background:#fee2e2;color:#b91c1c}
    .ahm-method.mixed{background:#f3e8ff;color:#7e22ce}
    .ahm-ep-path{font-family:monospace;color:#334155;flex-shrink:0;min-width:220px}
    .ahm-ep-desc{color:#64748b}

    /* Log */
    .ahm-log-table{width:100%;border-collapse:collapse;font-size:12px}
    .ahm-log-table th{text-align:left;padding:8px 12px;background:#f8fafc;color:#64748b;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e2e8f0}
    .ahm-log-table td{padding:8px 12px;border-bottom:1px solid #f8fafc;color:#334155}
    .ahm-log-table tr:hover td{background:#fafafa}
    .ahm-status-code{padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600}
    .ahm-status-code.ok{background:#dcfce7;color:#166534}
    .ahm-status-code.err{background:#fee2e2;color:#b91c1c}
    .ahm-status-code.warn{background:#fef9c3;color:#854d0e}

    /* Tabs */
    .ahm-tabs{display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:20px}
    .ahm-tab{padding:8px 16px;font-size:13px;font-weight:500;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;background:none;border-top:none;border-left:none;border-right:none;transition:.15s}
    .ahm-tab:hover{color:#334155}
    .ahm-tab.active{color:#3b82f6;border-bottom-color:#3b82f6}
    .ahm-tab-content{display:none}
    .ahm-tab-content.active{display:block}
    </style>

    <div id="ahm-wrap">

    <?php if ( isset( $notices[ $msg ] ) ) : [ $ntype, $ntext ] = $notices[ $msg ]; ?>
    <div class="ahm-notice success">✓ <?php echo esc_html( $ntext ); ?></div>
    <?php endif; ?>

    <!-- HEADER -->
    <div class="ahm-header">
        <div class="ahm-header-left">
            <div class="ahm-logo">⚡</div>
            <div>
                <h1>AHM Connect <span>v<?php echo esc_html( RMAI_VERSION ); ?></span></h1>
                <p>API REST para gestión de contenido, SEO y automatización</p>
            </div>
        </div>
        <span class="ahm-badge">aquihaymarketing.es</span>
    </div>

    <!-- STATUS BAR -->
    <div class="ahm-status <?php echo $api_enabled ? 'on' : 'off'; ?>">
        <div style="display:flex;align-items:center;gap:12px">
            <div class="ahm-status-dot"></div>
            <div>
                <div class="ahm-status-text"><?php echo $api_enabled ? 'API Activa' : 'API Desactivada'; ?></div>
                <div class="ahm-status-sub"><?php echo $api_enabled ? 'Las herramientas externas tienen acceso. Al desactivar se rota la clave.' : 'Ninguna herramienta externa puede conectarse. Al activar se rota la clave.'; ?></div>
            </div>
        </div>
        <?php if ( $api_enabled ) : ?>
            <a href="<?php echo esc_url( $toggle_url ); ?>" class="ahm-btn ahm-btn-danger" onclick="return confirm('¿Desactivar la API? La clave se rotará automáticamente.')">
                <span>⏸</span> Desactivar API
            </a>
        <?php else : ?>
            <a href="<?php echo esc_url( $toggle_url ); ?>" class="ahm-btn ahm-btn-success" onclick="return confirm('¿Activar la API? La clave se rotará automáticamente.')">
                <span>▶</span> Activar API
            </a>
        <?php endif; ?>
    </div>

    <!-- STATS ROW -->
    <div class="ahm-grid-3" style="margin-bottom:24px">
        <div class="ahm-stat">
            <div class="ahm-stat-val"><?php echo esc_html( $log_count ); ?></div>
            <div class="ahm-stat-label">Peticiones en log</div>
        </div>
        <div class="ahm-stat">
            <div class="ahm-stat-val"><?php echo esc_html( count( get_post_types( [ 'public' => true ] ) ) ); ?></div>
            <div class="ahm-stat-label">Tipos de contenido</div>
        </div>
        <div class="ahm-stat">
            <div class="ahm-stat-val"><?php echo esc_html( wp_count_posts()->publish + wp_count_posts( 'page' )->publish ); ?></div>
            <div class="ahm-stat-label">Posts + Páginas</div>
        </div>
    </div>

    <!-- TABS -->
    <div class="ahm-tabs">
        <button class="ahm-tab active" onclick="ahmTab(this,'tab-main')">⚙️ Configuración</button>
        <button class="ahm-tab" onclick="ahmTab(this,'tab-endpoints')">📡 Endpoints</button>
        <button class="ahm-tab" onclick="ahmTab(this,'tab-geo')">📈 SEO / GEO</button>
        <?php if ( $settings['log_enabled'] && ! empty( $log ) ) : ?>
        <button class="ahm-tab" onclick="ahmTab(this,'tab-log')">📋 Log <span style="background:#ef4444;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px"><?php echo $log_count; ?></span></button>
        <?php endif; ?>
    </div>

    <!-- TAB: CONFIGURACIÓN -->
    <div id="tab-main" class="ahm-tab-content active">
        <div class="ahm-grid">

            <!-- API Key -->
            <div class="ahm-card">
                <div class="ahm-card-header">
                    <div class="ahm-card-icon blue">🔑</div>
                    <div class="ahm-card-title">API Key</div>
                </div>
                <div class="ahm-card-body">
                    <p style="font-size:12px;color:#64748b;margin:0 0 12px">Incluye en la cabecera <code style="background:#f1f5f9;padding:1px 5px;border-radius:4px">X-RMAI-Key</code> de cada petición.</p>
                    <div class="ahm-key-wrap">
                        <input type="text" class="ahm-key-input" id="ahm-api-key" value="<?php echo esc_attr( $api_key ); ?>" readonly onclick="this.select()">
                        <button class="ahm-copy-btn" title="Copiar clave" onclick="ahmCopy()">📋</button>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <form method="post" style="margin:0">
                            <?php wp_nonce_field( 'rmai_regenerate_key_action' ); ?>
                            <button type="submit" name="rmai_regenerate_key" class="ahm-btn ahm-btn-ghost ahm-btn-sm" onclick="return confirm('¿Regenerar la clave? Los clientes actuales perderán acceso.')">
                                🔄 Regenerar clave
                            </button>
                        </form>
                        <span id="ahm-copied" style="color:#22c55e;font-size:12px;display:none">✓ Copiada</span>
                    </div>
                    <div style="margin-top:14px;padding:10px 12px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                        <div style="font-size:11px;color:#64748b;margin-bottom:4px;font-weight:600">BASE URL</div>
                        <code style="font-size:11px;color:#334155;word-break:break-all"><?php echo esc_html( $base_url ); ?></code>
                    </div>
                </div>
            </div>

            <!-- Info del sitio -->
            <div class="ahm-card">
                <div class="ahm-card-header">
                    <div class="ahm-card-icon green">🌐</div>
                    <div class="ahm-card-title">Información del sitio</div>
                </div>
                <div class="ahm-card-body">
                    <table class="ahm-info-table">
                        <tr><td>Sitio</td><td><?php echo esc_html( get_bloginfo( 'name' ) ); ?></td></tr>
                        <tr><td>URL</td><td><a href="<?php echo esc_url( get_site_url() ); ?>" target="_blank" style="color:#3b82f6"><?php echo esc_html( get_site_url() ); ?></a></td></tr>
                        <tr><td>WordPress</td><td><span class="ahm-pill ahm-pill-blue">v<?php echo esc_html( get_bloginfo( 'version' ) ); ?></span></td></tr>
                        <tr><td>PHP</td><td><span class="ahm-pill ahm-pill-gray">v<?php echo esc_html( PHP_VERSION ); ?></span></td></tr>
                        <tr><td>Rank Math</td><td><?php echo $rm_ver ? '<span class="ahm-pill ahm-pill-green">v' . esc_html( $rm_ver ) . '</span>' : '<span class="ahm-pill ahm-pill-gray">No instalado</span>'; ?></td></tr>
                        <tr><td>WooCommerce</td><td><?php echo $wc_ver ? '<span class="ahm-pill ahm-pill-blue">v' . esc_html( $wc_ver ) . '</span>' : '<span class="ahm-pill ahm-pill-gray">No instalado</span>'; ?></td></tr>
                    </table>
                </div>
            </div>

            <!-- Ajustes de seguridad -->
            <div class="ahm-card">
                <div class="ahm-card-header">
                    <div class="ahm-card-icon purple">🛡️</div>
                    <div class="ahm-card-title">Seguridad y límites</div>
                </div>
                <div class="ahm-card-body">
                    <form method="post">
                        <?php wp_nonce_field( 'rmai_save_settings_action' ); ?>

                        <div class="ahm-toggle-row">
                            <div>
                                <div class="ahm-toggle-label">Límite de peticiones</div>
                                <div class="ahm-toggle-desc">Máx. <?php echo esc_html( RMAI_RATE_LIMIT ); ?> peticiones/minuto por IP</div>
                            </div>
                            <label class="ahm-switch">
                                <input type="checkbox" name="rate_limit_enabled" <?php checked( $settings['rate_limit_enabled'] ); ?>>
                                <span class="ahm-slider"></span>
                            </label>
                        </div>

                        <div class="ahm-toggle-row">
                            <div>
                                <div class="ahm-toggle-label">Log de accesos</div>
                                <div class="ahm-toggle-desc">Registrar últimas <?php echo esc_html( RMAI_LOG_MAX ); ?> peticiones</div>
                            </div>
                            <label class="ahm-switch">
                                <input type="checkbox" name="log_enabled" <?php checked( $settings['log_enabled'] ); ?>>
                                <span class="ahm-slider"></span>
                            </label>
                        </div>

                        <div style="margin-top:12px">
                            <div class="ahm-toggle-label" style="margin-bottom:4px">IPs permitidas</div>
                            <div class="ahm-toggle-desc" style="margin-bottom:6px">Vacío = todas. Separar por coma.</div>
                            <input type="text" id="ip_whitelist" name="ip_whitelist" class="ahm-input" value="<?php echo esc_attr( $settings['ip_whitelist'] ); ?>" placeholder="1.2.3.4, 5.6.7.8">
                        </div>

                        <div style="margin-top:16px">
                            <button type="submit" name="rmai_save_settings" class="ahm-btn ahm-btn-primary">Guardar ajustes</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- TAB: ENDPOINTS -->
    <div id="tab-endpoints" class="ahm-tab-content">
        <div class="ahm-card">
            <div class="ahm-card-body" style="column-count:2;column-gap:32px">

                <div class="ahm-ep-section" style="break-inside:avoid;margin-bottom:16px">
                    <div class="ahm-ep-group">📄 Contenido y SEO</div>
                    <?php
                    $endpoints = [
                        ['GET',    '/posts',                  'Listar entradas (?missing= ?seo_score_lt=)'],
                        ['GET',    '/post/{id}',              'SEO + contenido de una entrada'],
                        ['PUT',    '/post/{id}',              'Actualizar campos SEO'],
                        ['PUT',    '/post/{id}/content',      'Actualizar post_content / excerpt'],
                        ['POST',   '/bulk-update',            'Actualizar SEO en lote'],
                        ['POST',   '/bulk-content',           'Actualizar contenido en lote'],
                        ['GET',    '/post/{id}/score',        'Puntuación SEO Rank Math'],
                        ['POST',   '/create-post',            'Crear entrada/página/producto'],
                        ['POST',   '/recalculate-scores',     'Recalcular scores Rank Math'],
                        ['GET',    '/post-types',             'Tipos de contenido'],
                        ['GET',    '/info',                   'Info del sitio + plugin'],
                    ];
                    foreach ( $endpoints as [$m,$p,$d] ) {
                        $mc = strtolower( $m );
                        echo "<div class='ahm-ep-row'><span class='ahm-method {$mc}'>{$m}</span><span class='ahm-ep-path'>" . esc_html($p) . "</span><span class='ahm-ep-desc'>" . esc_html($d) . "</span></div>";
                    }
                    ?>
                </div>

                <div class="ahm-ep-section" style="break-inside:avoid;margin-bottom:16px">
                    <div class="ahm-ep-group">📊 Auditoría SEO</div>
                    <?php
                    $endpoints2 = [
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
                    ];
                    foreach ( $endpoints2 as [$m,$p,$d] ) {
                        $mc = strtolower( $m );
                        echo "<div class='ahm-ep-row'><span class='ahm-method {$mc}'>{$m}</span><span class='ahm-ep-path'>" . esc_html($p) . "</span><span class='ahm-ep-desc'>" . esc_html($d) . "</span></div>";
                    }
                    ?>
                </div>

                <div class="ahm-ep-section" style="break-inside:avoid;margin-bottom:16px">
                    <div class="ahm-ep-group">🤖 GEO — Buscadores IA</div>
                    <?php
                    $endpoints3 = [
                        ['GET',    '/seo/geo',                'Audit: robots.txt, llms.txt, schema, E-E-A-T'],
                        ['GET',    '/seo/geo/generate',       'Genera llms.txt / llms-full.txt'],
                        ['POST',   '/seo/geo/write-llms-txt', 'Escribe llms.txt en la raíz del servidor'],
                    ];
                    foreach ( $endpoints3 as [$m,$p,$d] ) {
                        $mc = strtolower( $m );
                        echo "<div class='ahm-ep-row'><span class='ahm-method {$mc}'>{$m}</span><span class='ahm-ep-path'>" . esc_html($p) . "</span><span class='ahm-ep-desc'>" . esc_html($d) . "</span></div>";
                    }
                    ?>
                </div>

                <div class="ahm-ep-section" style="break-inside:avoid;margin-bottom:16px">
                    <div class="ahm-ep-group">🖼️ Imágenes y Media</div>
                    <?php
                    $endpoints4 = [
                        ['GET',    '/post/{id}/images',       'Imágenes del post + check alt/keyword'],
                        ['PUT',    '/post/{id}/images',       'Asignar imagen destacada'],
                        ['GET',    '/media',                  'Biblioteca de imágenes (?missing_alt=true)'],
                        ['PUT',    '/media/{id}',             'Actualizar alt, title, caption'],
                        ['POST',   '/bulk-media-alt',         'Actualizar alt en lote [{id, alt}]'],
                    ];
                    foreach ( $endpoints4 as [$m,$p,$d] ) {
                        $mc = strtolower( $m );
                        echo "<div class='ahm-ep-row'><span class='ahm-method {$mc}'>{$m}</span><span class='ahm-ep-path'>" . esc_html($p) . "</span><span class='ahm-ep-desc'>" . esc_html($d) . "</span></div>";
                    }
                    ?>
                </div>

                <div class="ahm-ep-section" style="break-inside:avoid;margin-bottom:16px">
                    <div class="ahm-ep-group">🔀 Redirections</div>
                    <?php
                    $endpoints5 = [
                        ['GET',    '/redirections',           'Listar redirections Rank Math'],
                        ['POST',   '/redirections',           'Crear 301/302/307 {from, to, code}'],
                        ['DELETE', '/redirections/{id}',      'Eliminar una redirection'],
                    ];
                    foreach ( $endpoints5 as [$m,$p,$d] ) {
                        $mc = strtolower( $m );
                        echo "<div class='ahm-ep-row'><span class='ahm-method {$mc}'>{$m}</span><span class='ahm-ep-path'>" . esc_html($p) . "</span><span class='ahm-ep-desc'>" . esc_html($d) . "</span></div>";
                    }
                    ?>
                </div>

                <div class="ahm-ep-section" style="break-inside:avoid;margin-bottom:16px">
                    <div class="ahm-ep-group">📐 Schema JSON-LD</div>
                    <?php
                    $endpoints6 = [
                        ['GET',    '/seo/schema/{id}',        'Leer schemas JSON-LD de un post'],
                        ['POST',   '/seo/schema/{id}',        'Añadir FAQPage, BreadcrumbList, etc.'],
                        ['DELETE', '/seo/schema/{id}',        'Eliminar schemas de un post'],
                        ['GET',    '/seo/breadcrumbs',        'Estado breadcrumbs Rank Math'],
                        ['POST',   '/seo/breadcrumbs',        'Activar breadcrumbs Rank Math'],
                    ];
                    foreach ( $endpoints6 as [$m,$p,$d] ) {
                        $mc = strtolower( $m );
                        echo "<div class='ahm-ep-row'><span class='ahm-method {$mc}'>{$m}</span><span class='ahm-ep-path'>" . esc_html($p) . "</span><span class='ahm-ep-desc'>" . esc_html($d) . "</span></div>";
                    }
                    ?>
                </div>

                <div class="ahm-ep-section" style="break-inside:avoid">
                    <div class="ahm-ep-group">🛒 WooCommerce</div>
                    <?php
                    $endpoints7 = [
                        ['POST',  '/bulk-attributes',          'Merge atributos _product_attributes'],
                        ['GET',   '/post/{id}/meta',           'Leer post meta (atributos, precio…)'],
                        ['POST',  '/post/{id}/meta',           'Escribir post meta'],
                    ];
                    foreach ( $endpoints7 as [$m,$p,$d] ) {
                        $mc = strtolower( $m );
                        echo "<div class='ahm-ep-row'><span class='ahm-method {$mc}'>{$m}</span><span class='ahm-ep-path'>" . esc_html($p) . "</span><span class='ahm-ep-desc'>" . esc_html($d) . "</span></div>";
                    }
                    ?>
                </div>

            </div>
        </div>
    </div>

    <!-- TAB: LOG -->
    <?php if ( $settings['log_enabled'] && ! empty( $log ) ) : ?>
    <div id="tab-log" class="ahm-tab-content">
        <div class="ahm-card">
            <div class="ahm-card-header" style="justify-content:space-between">
                <div style="display:flex;align-items:center;gap:10px">
                    <div class="ahm-card-icon slate">📋</div>
                    <div class="ahm-card-title">Últimas <?php echo $log_count; ?> peticiones</div>
                </div>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field( 'rmai_clear_log_action' ); ?>
                    <button type="submit" name="rmai_clear_log" class="ahm-btn ahm-btn-ghost ahm-btn-sm">🗑 Borrar log</button>
                </form>
            </div>
            <div style="overflow-x:auto">
                <table class="ahm-log-table">
                    <thead>
                        <tr><th>Fecha</th><th>Método</th><th>Ruta</th><th>Estado</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ( array_reverse( $log ) as $entry ) :
                            $s = (int) $entry['status'];
                            $sc = $s >= 400 ? 'err' : ( $s >= 300 ? 'warn' : 'ok' );
                        ?>
                        <tr>
                            <td style="white-space:nowrap;color:#64748b"><?php echo esc_html( $entry['date'] ); ?></td>
                            <td><code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px"><?php echo esc_html( $entry['method'] ); ?></code></td>
                            <td><code style="font-size:11px;color:#334155"><?php echo esc_html( $entry['route'] ); ?></code></td>
                            <td><span class="ahm-status-code <?php echo $sc; ?>"><?php echo esc_html( $entry['status'] ); ?></span></td>
                            <td style="color:#94a3b8;font-size:11px"><?php echo esc_html( $entry['ip'] ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB: GEO / IA -->
    <div id="tab-geo" class="ahm-tab-content">
        <?php
        // Load GEO data server-side by calling internal functions directly
        $geo_data     = null;
        $geo_error    = null;
        $geo_req      = new WP_REST_Request( 'GET', '/' . RMAI_NAMESPACE . '/seo/geo' );
        $geo_response = rmai_geo_audit( $geo_req );
        if ( is_wp_error( $geo_response ) ) {
            $geo_error = $geo_response->get_error_message();
        } elseif ( $geo_response instanceof WP_REST_Response ) {
            $geo_data = $geo_response->get_data();
        }

        // Page SEO scores summary via wpdb
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
        foreach ( $scores_raw as $row ) {
            $s = (int) $row['score'];
            $scored_total += (int) $row['total'];
            if ( $s < 50 )       $score_buckets['poor'] += (int) $row['total'];
            elseif ( $s < 80 )   $score_buckets['ok']   += (int) $row['total'];
            else                 $score_buckets['good']  += (int) $row['total'];
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
        ?>

        <?php if ( $geo_error ) : ?>
        <div class="ahm-notice" style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c">⚠️ <?php echo esc_html( $geo_error ); ?></div>
        <?php endif; ?>

        <?php if ( $geo_data ) :
            $geo_score  = (int) ( $geo_data['geo_score'] ?? 0 );
            $geo_rating = $geo_data['geo_rating'] ?? 'poor';
            $score_color = $geo_score >= 70 ? '#22c55e' : ( $geo_score >= 40 ? '#f59e0b' : '#ef4444' );
            $score_bg    = $geo_score >= 70 ? '#f0fdf4' : ( $geo_score >= 40 ? '#fffbeb' : '#fef2f2' );
            $checks      = $geo_data['checks'] ?? [];
            $recs        = $geo_data['recommendations'] ?? [];
            $robots      = $geo_data['robots_txt'] ?? [];
            $llms        = $geo_data['llms_txt'] ?? [];
            $schema      = $geo_data['schema'] ?? [];
            $eat         = $geo_data['eat_signals'] ?? [];
        ?>

        <!-- GEO Score + Page Scores -->
        <div style="display:grid;grid-template-columns:220px 1fr;gap:20px;margin-bottom:20px">

            <!-- GEO Score big -->
            <div class="ahm-card" style="text-align:center">
                <div class="ahm-card-body" style="padding:28px 20px">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:12px">GEO Score</div>
                    <div style="width:100px;height:100px;border-radius:50%;background:conic-gradient(<?php echo $score_color; ?> <?php echo $geo_score * 3.6; ?>deg, #e2e8f0 0);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;position:relative">
                        <div style="width:76px;height:76px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-direction:column">
                            <span style="font-size:26px;font-weight:800;color:<?php echo $score_color; ?>;line-height:1"><?php echo $geo_score; ?></span>
                            <span style="font-size:10px;color:#94a3b8">/100</span>
                        </div>
                    </div>
                    <div style="display:inline-block;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;background:<?php echo $score_bg; ?>;color:<?php echo $score_color; ?>;text-transform:uppercase;letter-spacing:.05em">
                        <?php echo esc_html( $geo_rating ); ?>
                    </div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:8px"><?php echo esc_html( $geo_data['site_url'] ?? '' ); ?></div>
                </div>
            </div>

            <!-- Checks grid -->
            <div class="ahm-card">
                <div class="ahm-card-header">
                    <div class="ahm-card-icon blue">✅</div>
                    <div class="ahm-card-title">Checks GEO</div>
                </div>
                <div class="ahm-card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <?php foreach ( $checks as $check ) :
                        $pass = (bool) $check['pass'];
                        $ic   = $pass ? '✓' : '✗';
                        $cc   = $pass ? '#22c55e' : '#ef4444';
                        $cb   = $pass ? '#f0fdf4' : '#fef2f2';
                        $w    = (int) ( $check['weight'] ?? 0 );
                    ?>
                    <div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:<?php echo $cb; ?>;border-radius:8px;border:1px solid <?php echo $pass ? '#bbf7d0' : '#fecaca'; ?>">
                        <span style="font-size:13px;font-weight:700;color:<?php echo $cc; ?>;flex-shrink:0;margin-top:1px"><?php echo $ic; ?></span>
                        <div>
                            <div style="font-size:12px;font-weight:500;color:#334155;line-height:1.3"><?php echo esc_html( $check['label'] ); ?></div>
                            <div style="font-size:10px;color:#94a3b8;margin-top:2px">peso: <?php echo $w; ?>pts</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- llms.txt + robots.txt -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

            <div class="ahm-card">
                <div class="ahm-card-header">
                    <div class="ahm-card-icon orange">📄</div>
                    <div class="ahm-card-title">llms.txt</div>
                </div>
                <div class="ahm-card-body">
                    <?php
                    $llms_ok   = ! empty( $llms['exists'] );
                    $llmsfull  = ! empty( $llms['full_exists'] );
                    ?>
                    <div style="display:flex;flex-direction:column;gap:10px">
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:<?php echo $llms_ok ? '#f0fdf4' : '#fef2f2'; ?>;border-radius:8px;border:1px solid <?php echo $llms_ok ? '#bbf7d0' : '#fecaca'; ?>">
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#334155">llms.txt</div>
                                <?php if ( $llms_ok && ! empty( $llms['url'] ) ) : ?>
                                <a href="<?php echo esc_url( $llms['url'] ); ?>" target="_blank" style="font-size:11px;color:#3b82f6"><?php echo esc_html( $llms['url'] ); ?></a>
                                <?php endif; ?>
                            </div>
                            <span style="font-weight:700;color:<?php echo $llms_ok ? '#22c55e' : '#ef4444'; ?>;font-size:15px"><?php echo $llms_ok ? '✓' : '✗'; ?></span>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:<?php echo $llmsfull ? '#f0fdf4' : '#fef2f2'; ?>;border-radius:8px;border:1px solid <?php echo $llmsfull ? '#bbf7d0' : '#fecaca'; ?>">
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#334155">llms-full.txt</div>
                                <?php if ( $llmsfull && ! empty( $llms['full_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $llms['full_url'] ); ?>" target="_blank" style="font-size:11px;color:#3b82f6"><?php echo esc_html( $llms['full_url'] ); ?></a>
                                <?php endif; ?>
                            </div>
                            <span style="font-weight:700;color:<?php echo $llmsfull ? '#22c55e' : '#ef4444'; ?>;font-size:15px"><?php echo $llmsfull ? '✓' : '✗'; ?></span>
                        </div>
                        <?php if ( ! empty( $llms['preview'] ) ) : ?>
                        <details style="margin-top:4px">
                            <summary style="font-size:12px;color:#64748b;cursor:pointer">Vista previa llms.txt</summary>
                            <pre style="font-size:11px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;margin-top:6px;max-height:140px;overflow-y:auto;white-space:pre-wrap"><?php echo esc_html( $llms['preview'] ); ?></pre>
                        </details>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ahm-card">
                <div class="ahm-card-header">
                    <div class="ahm-card-icon slate">🤖</div>
                    <div class="ahm-card-title">Bots IA en robots.txt</div>
                </div>
                <div class="ahm-card-body">
                    <?php
                    $blocked = $robots['ai_blocked'] ?? [];
                    $allowed = $robots['ai_allowed'] ?? [];
                    ?>
                    <?php if ( ! empty( $blocked ) ) : ?>
                    <div style="margin-bottom:10px">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#ef4444;margin-bottom:6px;letter-spacing:.05em">Bloqueados (<?php echo count($blocked); ?>)</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            <?php foreach ( $blocked as $bot ) : ?>
                            <span style="background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:600;padding:3px 8px;border-radius:4px"><?php echo esc_html( $bot['bot'] ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $allowed ) ) : ?>
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#22c55e;margin-bottom:6px;letter-spacing:.05em">Permitidos (<?php echo count($allowed); ?>)</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            <?php foreach ( $allowed as $bot ) : ?>
                            <span style="background:#dcfce7;color:#166534;font-size:11px;font-weight:600;padding:3px 8px;border-radius:4px"><?php echo esc_html( $bot['bot'] ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( empty( $blocked ) && empty( $allowed ) ) : ?>
                    <p style="color:#22c55e;font-size:13px;font-weight:600">✓ Ningún bot IA bloqueado — acceso libre</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Schema + E-E-A-T -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

            <div class="ahm-card">
                <div class="ahm-card-header">
                    <div class="ahm-card-icon purple">📐</div>
                    <div class="ahm-card-title">Schema.org detectado</div>
                </div>
                <div class="ahm-card-body">
                    <?php
                    $schema_ok      = $schema['ok'] ?? [];
                    $schema_missing = $schema['missing'] ?? [];
                    ?>
                    <?php if ( ! empty( $schema_ok ) ) : ?>
                    <div style="margin-bottom:12px">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#22c55e;margin-bottom:6px;letter-spacing:.05em">Presentes</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            <?php foreach ( $schema_ok as $s ) : ?>
                            <span style="background:#dcfce7;color:#166534;font-size:11px;font-weight:600;padding:3px 8px;border-radius:4px">✓ <?php echo esc_html($s); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $schema_missing ) ) : ?>
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#f59e0b;margin-bottom:6px;letter-spacing:.05em">Faltantes</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            <?php foreach ( $schema_missing as $s ) : ?>
                            <span style="background:#fef9c3;color:#854d0e;font-size:11px;font-weight:600;padding:3px 8px;border-radius:4px">✗ <?php echo esc_html($s); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ahm-card">
                <div class="ahm-card-header">
                    <div class="ahm-card-icon green">👤</div>
                    <div class="ahm-card-title">E-E-A-T Signals</div>
                </div>
                <div class="ahm-card-body">
                    <?php
                    $authors    = $eat['authors'] ?? [];
                    $legal_pgs  = $eat['legal_pages'] ?? [];
                    $key_pgs    = $eat['key_pages'] ?? [];
                    ?>
                    <?php if ( ! empty( $authors ) ) : ?>
                    <div style="margin-bottom:12px">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:8px;letter-spacing:.05em">Autores</div>
                        <?php foreach ( $authors as $author ) :
                            $bio_ok = ! empty( $author['has_bio'] );
                            $av_ok  = ! empty( $author['has_avatar'] );
                        ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9">
                            <span style="font-size:13px;font-weight:500;color:#334155"><?php echo esc_html( $author['name'] ); ?></span>
                            <div style="display:flex;gap:6px">
                                <span style="font-size:11px;padding:2px 7px;border-radius:10px;font-weight:600;background:<?php echo $bio_ok ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $bio_ok ? '#166534' : '#b91c1c'; ?>">bio</span>
                                <span style="font-size:11px;padding:2px 7px;border-radius:10px;font-weight:600;background:<?php echo $av_ok ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $av_ok ? '#166534' : '#b91c1c'; ?>">avatar</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $legal_pgs ) ) : ?>
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:6px;letter-spacing:.05em">Páginas legales</div>
                        <?php foreach ( $legal_pgs as $pg ) : ?>
                        <a href="<?php echo esc_url($pg['url']); ?>" target="_blank" style="display:block;font-size:12px;color:#3b82f6;margin-bottom:2px"><?php echo esc_html($pg['slug']); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recomendaciones -->
        <?php if ( ! empty( $recs ) ) : ?>
        <div class="ahm-card" style="margin-bottom:20px">
            <div class="ahm-card-header">
                <div class="ahm-card-icon orange">💡</div>
                <div class="ahm-card-title">Recomendaciones GEO</div>
            </div>
            <div class="ahm-card-body" style="display:flex;flex-direction:column;gap:10px">
                <?php foreach ( $recs as $rec ) :
                    $prio   = $rec['priority'] ?? 'medium';
                    $pcolors = [
                        'critical' => ['#fef2f2','#fecaca','#b91c1c','🔴'],
                        'high'     => ['#fff7ed','#fed7aa','#c2410c','🟠'],
                        'medium'   => ['#fffbeb','#fde68a','#92400e','🟡'],
                        'low'      => ['#f0fdf4','#bbf7d0','#166534','🟢'],
                    ];
                    [ $bg, $border, $tc, $emoji ] = $pcolors[ $prio ] ?? $pcolors['medium'];
                ?>
                <div style="background:<?php echo $bg; ?>;border:1px solid <?php echo $border; ?>;border-radius:8px;padding:12px 14px">
                    <div style="display:flex;align-items:flex-start;gap:10px">
                        <span style="font-size:14px;flex-shrink:0;margin-top:1px"><?php echo $emoji; ?></span>
                        <div>
                            <div style="font-size:12px;font-weight:600;color:<?php echo $tc; ?>;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><?php echo esc_html( $prio ); ?><?php echo $rec['file'] ? ' — ' . esc_html( $rec['file'] ) : ''; ?></div>
                            <div style="font-size:13px;color:#334155"><?php echo esc_html( $rec['action'] ); ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // end $geo_data ?>

        <!-- SEO Scores de páginas -->
        <div class="ahm-card">
            <div class="ahm-card-header">
                <div class="ahm-card-icon blue">📊</div>
                <div class="ahm-card-title">Scores SEO del sitio (Rank Math)</div>
            </div>
            <div class="ahm-card-body">
                <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px">
                    <div class="ahm-stat">
                        <div class="ahm-stat-val" style="color:<?php echo $avg_score >= 70 ? '#22c55e' : ($avg_score >= 40 ? '#f59e0b' : '#ef4444'); ?>"><?php echo $avg_score; ?></div>
                        <div class="ahm-stat-label">Score medio</div>
                    </div>
                    <div class="ahm-stat">
                        <div class="ahm-stat-val"><?php echo $scored_total; ?></div>
                        <div class="ahm-stat-label">Con score</div>
                    </div>
                    <div class="ahm-stat">
                        <div class="ahm-stat-val" style="color:#22c55e"><?php echo $score_buckets['good']; ?></div>
                        <div class="ahm-stat-label">Buenos ≥80</div>
                    </div>
                    <div class="ahm-stat">
                        <div class="ahm-stat-val" style="color:#f59e0b"><?php echo $score_buckets['ok']; ?></div>
                        <div class="ahm-stat-label">Mejorables 50-79</div>
                    </div>
                    <div class="ahm-stat">
                        <div class="ahm-stat-val" style="color:#ef4444"><?php echo $score_buckets['poor']; ?></div>
                        <div class="ahm-stat-label">Pobres &lt;50</div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;display:flex;align-items:center;justify-content:space-between">
                        <div>
                            <div style="font-size:13px;font-weight:600;color:#334155">Sin keyword asignada</div>
                            <div style="font-size:11px;color:#64748b;margin-top:2px">GET /posts?missing=focus_keyword</div>
                        </div>
                        <span style="font-size:26px;font-weight:800;color:#ef4444"><?php echo $missing_kw; ?></span>
                    </div>
                    <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;display:flex;align-items:center;justify-content:space-between">
                        <div>
                            <div style="font-size:13px;font-weight:600;color:#334155">Sin meta description</div>
                            <div style="font-size:11px;color:#64748b;margin-top:2px">GET /posts?missing=seo_description</div>
                        </div>
                        <span style="font-size:26px;font-weight:800;color:#ef4444"><?php echo $missing_desc; ?></span>
                    </div>
                </div>
                <?php if ( $scored_total > 0 ) : ?>
                <div style="margin-top:16px">
                    <div style="font-size:11px;font-weight:600;color:#94a3b8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Distribución de scores</div>
                    <div style="height:10px;border-radius:6px;overflow:hidden;display:flex;background:#f1f5f9">
                        <?php if ( $score_buckets['poor'] ) : ?>
                        <div style="width:<?php echo round($score_buckets['poor']/$scored_total*100); ?>%;background:#ef4444;transition:.3s" title="Pobres: <?php echo $score_buckets['poor']; ?>"></div>
                        <?php endif; ?>
                        <?php if ( $score_buckets['ok'] ) : ?>
                        <div style="width:<?php echo round($score_buckets['ok']/$scored_total*100); ?>%;background:#f59e0b;transition:.3s" title="Mejorables: <?php echo $score_buckets['ok']; ?>"></div>
                        <?php endif; ?>
                        <?php if ( $score_buckets['good'] ) : ?>
                        <div style="width:<?php echo round($score_buckets['good']/$scored_total*100); ?>%;background:#22c55e;transition:.3s" title="Buenos: <?php echo $score_buckets['good']; ?>"></div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:16px;margin-top:6px">
                        <span style="font-size:11px;color:#ef4444">⬛ Pobres: <?php echo $score_buckets['poor']; ?></span>
                        <span style="font-size:11px;color:#f59e0b">⬛ Mejorables: <?php echo $score_buckets['ok']; ?></span>
                        <span style="font-size:11px;color:#22c55e">⬛ Buenos: <?php echo $score_buckets['good']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- tab-geo -->

    </div><!-- #ahm-wrap -->

    <script>
    function ahmTab(btn, id) {
        document.querySelectorAll('.ahm-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.ahm-tab-content').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        var el = document.getElementById(id);
        if (el) el.classList.add('active');
    }
    function ahmCopy() {
        var val = document.getElementById('ahm-api-key').value;
        navigator.clipboard.writeText(val).then(function() {
            var el = document.getElementById('ahm-copied');
            el.style.display = 'inline';
            setTimeout(function(){ el.style.display = 'none'; }, 2000);
        });
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
        $transient = 'rmai_rl_' . md5( $ip );
        $count     = (int) get_transient( $transient );
        if ( $count >= RMAI_RATE_LIMIT ) {
            rmai_log( $request, 429, $ip );
            return new WP_Error( 'rmai_rate_limit', 'Demasiadas peticiones. Espera un minuto.', [ 'status' => 429 ] );
        }
        set_transient( $transient, $count + 1, 60 );
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

    // update_metadata() aplica wp_unslash() al valor, así que hay que escaparlo antes.
    // Sin esto, cualquier JSON con barras invertidas (p. ej. _elementor_data) se corrompe.
    update_post_meta( $post->ID, $key, wp_slash( $value ) );

    // Verificación de ida y vuelta: si lo almacenado no coincide con lo enviado
    // (barras invertidas comidas, sanitización de terceros, etc.) se revierte en
    // lugar de dejar el contenido corrupto.
    $stored = get_post_meta( $post->ID, $key, true );
    if ( ! rmai_meta_values_match( $value, $stored ) ) {
        if ( $existed ) {
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

    return new WP_REST_Response( [
        'success'  => true,
        'post_id'  => $post->ID,
        'key'      => $key,
        'verified' => true,
        'stored'   => $stored,
    ], 200 );
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

    if ( rmai_is_elementor_post( $post->ID ) ) {
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

/** POST /bulk-content — formato: [{id, post_content?, post_excerpt?, slug?}] */
function rmai_bulk_content( WP_REST_Request $request ) {
    $body = $request->get_json_params();

    if ( empty( $body ) || ! is_array( $body ) ) {
        return new WP_Error( 'rmai_empty_body', 'Envía un array de objetos [{id, post_content?, post_excerpt?}].', [ 'status' => 400 ] );
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
        $is_elementor = rmai_is_elementor_post( $id );
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
    header( 'X-Frame-Options: SAMEORIGIN' );
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
 */
add_action( 'init', function (): void {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    add_filter( 'tiny_mce_plugins', function ( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : [];
    } );
    add_filter( 'emoji_svg_url', '__return_false' );
} );

/**
 * Quita etiquetas innecesarias del <head> (RSD, wlwmanifest, generator).
 * Menos ruido y menos exposición de versión.
 */
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_generator' );

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

if ( ! defined( 'RMAI_UPDATE_MANIFEST' ) ) {
    define( 'RMAI_UPDATE_MANIFEST', 'https://raw.githubusercontent.com/aquihaydesarrollo/ahm-connect/main/ahm-connect.json' );
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
