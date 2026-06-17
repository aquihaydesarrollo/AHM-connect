<?php
/**
 * Plugin Name: AHM Connect v3.2
 * Plugin URI:  https://aquihaymarketing.es
 * Description: API REST segura para gestionar contenido, SEO con Rank Math, atributos y productos WooCommerce, y metadatos de páginas desde herramientas externas de automatización.
 * Version:     3.2.0
 * Author:      Aquí Hay Marketing
 * Author URI:  https://aquihaymarketing.es
 * License:     GPL-2.0+
 * Text Domain: ahm-connect
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'RMAI_VERSION',         '3.2.0' );
define( 'RMAI_OPTION_API_KEY',  'rmai_api_key' );
define( 'RMAI_OPTION_SETTINGS', 'rmai_settings' );
define( 'RMAI_NAMESPACE',       'rankmath-ai/v1' );
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
}

register_uninstall_hook( __FILE__, 'rmai_uninstall' );
function rmai_uninstall(): void {
    delete_option( RMAI_OPTION_API_KEY );
    delete_option( RMAI_OPTION_SETTINGS );
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

// ═══════════════════════════════════════════════════════
// 2. ADMIN: MENÚ Y AJUSTES
// ═══════════════════════════════════════════════════════

add_action( 'admin_menu', 'rmai_admin_menu' );
function rmai_admin_menu(): void {
    add_options_page(
        'AHM Connect',
        'AHM Connect',
        'manage_options',
        'rankmath-ai-manager',
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
        wp_safe_redirect( admin_url( 'options-general.php?page=rankmath-ai-manager&rmai_msg=key_regenerated' ) );
        exit;
    }

    if (
        isset( $_POST['rmai_clear_log'] ) &&
        check_admin_referer( 'rmai_clear_log_action' ) &&
        current_user_can( 'manage_options' )
    ) {
        delete_option( RMAI_LOG_OPTION );
        wp_safe_redirect( admin_url( 'options-general.php?page=rankmath-ai-manager&rmai_msg=log_cleared' ) );
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
        wp_safe_redirect( admin_url( 'options-general.php?page=rankmath-ai-manager&rmai_msg=settings_saved' ) );
        exit;
    }
}

function rmai_settings_page(): void {
    $api_key  = get_option( RMAI_OPTION_API_KEY, '' );
    $settings = wp_parse_args( get_option( RMAI_OPTION_SETTINGS, [] ), rmai_default_settings() );
    $base_url = rest_url( RMAI_NAMESPACE );
    $msg      = $_GET['rmai_msg'] ?? '';

    $notices = [
        'key_regenerated' => [ 'success', 'API Key regenerada correctamente.' ],
        'log_cleared'     => [ 'success', 'Log de peticiones eliminado.' ],
        'settings_saved'  => [ 'success', 'Ajustes guardados.' ],
    ];

    if ( isset( $notices[ $msg ] ) ) {
        [ $type, $text ] = $notices[ $msg ];
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
    }

    $log     = get_option( RMAI_LOG_OPTION, [] );
    $rm_ver  = defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '<em>no detectada</em>';
    ?>
    <div class="wrap">
        <h1>AHM Connect <span style="font-size:13px;color:#888">v<?php echo esc_html( RMAI_VERSION ); ?></span></h1>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1000px">

            <div class="postbox" style="padding:16px">
                <h2 class="hndle">🔑 API Key</h2>
                <p>Incluye esta clave en la cabecera <code>X-RMAI-Key</code> de cada petición.</p>
                <input type="text" value="<?php echo esc_attr( $api_key ); ?>" class="widefat" readonly onclick="this.select()" style="font-family:monospace;margin-bottom:10px" />
                <form method="post">
                    <?php wp_nonce_field( 'rmai_regenerate_key_action' ); ?>
                    <button type="submit" name="rmai_regenerate_key" class="button" onclick="return confirm('¿Regenerar la clave?')">Regenerar clave</button>
                </form>
            </div>

            <div class="postbox" style="padding:16px">
                <h2 class="hndle">ℹ️ Info del sitio</h2>
                <table class="form-table" style="margin:0">
                    <tr><th>Sitio</th><td><?php echo esc_html( get_bloginfo( 'name' ) ); ?></td></tr>
                    <tr><th>URL</th><td><?php echo esc_html( get_site_url() ); ?></td></tr>
                    <tr><th>WordPress</th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
                    <tr><th>Rank Math</th><td><?php echo wp_kses_post( $rm_ver ); ?></td></tr>
                    <tr><th>WooCommerce</th><td><?php echo defined( 'WC_VERSION' ) ? esc_html( WC_VERSION ) : '—'; ?></td></tr>
                    <tr><th>PHP</th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
                    <tr><th>API base</th><td><code style="font-size:11px"><?php echo esc_html( $base_url ); ?></code></td></tr>
                </table>
            </div>

            <div class="postbox" style="padding:16px">
                <h2 class="hndle">⚙️ Ajustes</h2>
                <form method="post">
                    <?php wp_nonce_field( 'rmai_save_settings_action' ); ?>
                    <table class="form-table" style="margin:0">
                        <tr>
                            <th>Límite de peticiones</th>
                            <td><label><input type="checkbox" name="rate_limit_enabled" <?php checked( $settings['rate_limit_enabled'] ); ?>> Máx. <?php echo esc_html( RMAI_RATE_LIMIT ); ?> peticiones/minuto por IP</label></td>
                        </tr>
                        <tr>
                            <th>Log de accesos</th>
                            <td><label><input type="checkbox" name="log_enabled" <?php checked( $settings['log_enabled'] ); ?>> Registrar últimas <?php echo esc_html( RMAI_LOG_MAX ); ?> peticiones</label></td>
                        </tr>
                        <tr>
                            <th><label for="ip_whitelist">IP permitidas</label></th>
                            <td><input type="text" id="ip_whitelist" name="ip_whitelist" value="<?php echo esc_attr( $settings['ip_whitelist'] ); ?>" class="widefat" placeholder="Vacío = todas. Separar por coma: 1.2.3.4, 5.6.7.8" /></td>
                        </tr>
                    </table>
                    <?php submit_button( 'Guardar ajustes', 'primary', 'rmai_save_settings' ); ?>
                </form>
            </div>

            <div class="postbox" style="padding:16px">
                <h2 class="hndle">📡 Endpoints</h2>
                <table class="widefat striped" style="font-size:12px">
                    <tr><th colspan="3" style="background:#f0f0f0">Contenido y SEO</th></tr>
                    <tr><td><code>GET</code></td><td><code>/posts</code></td><td>Listar entradas con SEO</td></tr>
                    <tr><td><code>GET</code></td><td><code>/post/{id}</code></td><td>SEO + contenido de una entrada</td></tr>
                    <tr><td><code>PUT</code></td><td><code>/post/{id}</code></td><td>Actualizar campos SEO</td></tr>
                    <tr><td><code>PUT</code></td><td><code>/post/{id}/content</code></td><td>Actualizar post_content / post_excerpt</td></tr>
                    <tr><td><code>POST</code></td><td><code>/bulk-update</code></td><td>Actualizar SEO de varios [{id, fields:{}}]</td></tr>
                    <tr><td><code>POST</code></td><td><code>/bulk-content</code></td><td>Actualizar contenido [{id, post_content, post_excerpt}]</td></tr>
                    <tr><td><code>POST</code></td><td><code>/bulk-attributes</code></td><td>Merge atributos WooCommerce</td></tr>
                    <tr><td><code>GET/POST</code></td><td><code>/post/{id}/meta</code></td><td>Leer / escribir post meta</td></tr>
                    <tr><td><code>GET</code></td><td><code>/post/{id}/score</code></td><td>Puntuación SEO</td></tr>
                    <tr><td><code>POST</code></td><td><code>/create-post</code></td><td>Crear entrada/página/producto</td></tr>
                    <tr><td><code>POST</code></td><td><code>/recalculate-scores</code></td><td>Recalcular scores Rank Math</td></tr>
                    <tr><td><code>GET</code></td><td><code>/post-types</code></td><td>Tipos de contenido</td></tr>
                    <tr><td><code>GET</code></td><td><code>/info</code></td><td>Info del sitio + plugin</td></tr>
                    <tr><th colspan="3" style="background:#e8f4e8">Auditoría SEO — /seo/*</th></tr>
                    <tr><td><code>GET</code></td><td><code>/seo</code></td><td>Audit SEO completo: título, meta, kw, checks Rank Math</td></tr>
                    <tr><td><code>GET</code></td><td><code>/seo/post/{id}</code></td><td>Audit + sugerencias para máximo score Rank Math</td></tr>
                    <tr><td><code>POST</code></td><td><code>/seo/apply/{id}</code></td><td>Aplica optimizaciones SEO automáticamente</td></tr>
                    <tr><td><code>GET</code></td><td><code>/seo/sitemap</code></td><td>Audita el sitemap (páginas incorrectas, Hello World…)</td></tr>
                    <tr><td><code>GET</code></td><td><code>/seo/h1</code></td><td>Revisión de H1 en todas las páginas indexadas</td></tr>
                    <tr><td><code>GET</code></td><td><code>/seo/noindex</code></td><td>Detecta páginas RGPD/cuenta que deberían ser noindex</td></tr>
                    <tr><td><code>GET</code></td><td><code>/seo/404</code></td><td>Errores 404 (monitor Rank Math o crawl sitemap)</td></tr>
                </table>
            </div>

        </div>

        <?php if ( $settings['log_enabled'] && ! empty( $log ) ) : ?>
        <div class="postbox" style="padding:16px;margin-top:20px;max-width:1000px">
            <h2 class="hndle">📋 Últimas peticiones</h2>
            <form method="post" style="margin-bottom:10px">
                <?php wp_nonce_field( 'rmai_clear_log_action' ); ?>
                <button type="submit" name="rmai_clear_log" class="button button-small">Borrar log</button>
            </form>
            <table class="widefat striped" style="font-size:12px">
                <thead><tr><th>Fecha</th><th>Método</th><th>Ruta</th><th>Estado</th><th>IP</th></tr></thead>
                <tbody>
                    <?php foreach ( array_reverse( $log ) as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( $entry['date'] ); ?></td>
                        <td><code><?php echo esc_html( $entry['method'] ); ?></code></td>
                        <td><code><?php echo esc_html( $entry['route'] ); ?></code></td>
                        <td><?php echo esc_html( $entry['status'] ); ?></td>
                        <td><?php echo esc_html( $entry['ip'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
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
            'type'     => [ 'default' => 'post', 'sanitize_callback' => 'sanitize_text_field' ],
            'per_page' => [ 'default' => 20,     'sanitize_callback' => 'absint' ],
            'page'     => [ 'default' => 1,      'sanitize_callback' => 'absint' ],
            'search'   => [ 'default' => '',     'sanitize_callback' => 'sanitize_text_field' ],
            'orderby'  => [ 'default' => 'modified', 'sanitize_callback' => 'sanitize_key' ],
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
}

// ═══════════════════════════════════════════════════════
// 4. AUTENTICACIÓN, RATE LIMIT Y LOG
// ═══════════════════════════════════════════════════════

function rmai_check_permission( WP_REST_Request $request ) {
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

    rmai_log( $request, 200, $ip );
    return true;
}

function rmai_get_ip(): string {
    foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
        }
    }
    return 'unknown';
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
    $allowed_ob   = [ 'modified', 'date', 'title', 'ID', 'menu_order' ];
    $orderby      = in_array( $orderby_raw, $allowed_ob, true ) ? $orderby_raw : 'modified';

    $allowed_types = array_keys( get_post_types( [ 'public' => true ] ) );
    if ( ! in_array( $type, $allowed_types, true ) ) {
        return new WP_Error(
            'rmai_invalid_type',
            "Tipo '{$type}' no válido. Disponibles: " . implode( ', ', $allowed_types ),
            [ 'status' => 400 ]
        );
    }

    $args = [
        'post_type'      => $type,
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => $orderby,
        'order'          => 'DESC',
    ];

    if ( ! empty( $search ) ) {
        $args['s'] = $search;
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

    update_post_meta( $post->ID, $key, $value );

    return new WP_REST_Response( [
        'success' => true,
        'post_id' => $post->ID,
        'key'     => $key,
        'stored'  => get_post_meta( $post->ID, $key, true ),
    ], 200 );
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

        update_post_meta( $id, '_product_attributes', $existing );
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
