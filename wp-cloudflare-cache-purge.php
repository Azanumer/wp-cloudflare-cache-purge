<?php
/**
 * Plugin Name:       Cloudflare Cache Purge
 * Plugin URI:        https://github.com/Azanumer/wp-cloudflare-cache-purge
 * Description:       Purges Cloudflare's edge cache automatically when content changes — by URL on post/comment updates, or everything on theme switch — using a scoped API token.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wccp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCCP_VERSION', '1.0.0' );

/** Default settings. */
function wccp_defaults() {
    return array(
        'zone_id'        => '',
        'api_token'      => '',
        'purge_mode'     => 'urls', // urls | everything
        'purge_homepage' => 1,
    );
}

function wccp_settings() {
    $saved = get_option( 'wccp_settings', array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }
    return wp_parse_args( $saved, wccp_defaults() );
}

/** True when the plugin is configured with both credentials. */
function wccp_configured() {
    $s = wccp_settings();
    return '' !== $s['zone_id'] && '' !== $s['api_token'];
}

/**
 * Send one purge request to Cloudflare and record the outcome in an option
 * (shown on the settings page). Returns true on API success.
 *
 * @param array $body Request body: ['files' => [...]] or ['purge_everything' => true].
 */
function wccp_purge( array $body ) {
    if ( ! wccp_configured() ) {
        return false;
    }
    $s   = wccp_settings();
    $url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $s['zone_id'] ) . '/purge_cache';
    $res = wp_remote_post(
        $url,
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $s['api_token'],
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        )
    );
    $ok = false;
    if ( ! is_wp_error( $res ) ) {
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        $ok   = is_array( $data ) && ! empty( $data['success'] );
    }
    update_option(
        'wccp_last_result',
        array(
            'time'    => current_time( 'mysql' ),
            'success' => $ok,
            'body'    => $body,
            'error'   => is_wp_error( $res ) ? $res->get_error_message() : '',
        )
    );
    return $ok;
}

/**
 * Purge the given URLs (plus the homepage when enabled), or everything
 * when purge_mode is 'everything'. Filter wccp_urls_to_purge lets themes
 * add extra URLs (e.g. archive pages) to a purge.
 */
function wccp_purge_urls( array $urls ) {
    $s = wccp_settings();
    if ( 'everything' === $s['purge_mode'] ) {
        return wccp_purge( array( 'purge_everything' => true ) );
    }
    $urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
    if ( ! empty( $s['purge_homepage'] ) ) {
        $urls[] = home_url( '/' );
        $urls   = array_values( array_unique( $urls ) );
    }
    $urls = (array) apply_filters( 'wccp_urls_to_purge', $urls );
    if ( empty( $urls ) ) {
        return false;
    }
    // Cloudflare caps a single files purge at 30 URLs.
    foreach ( array_chunk( $urls, 30 ) as $chunk ) {
        wccp_purge( array( 'files' => $chunk ) );
    }
    return true;
}

/** Purge a post's permalink when it is published or updated. */
function wccp_on_save_post( $post_id, $post, $update ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    if ( 'publish' !== $post->post_status ) {
        return;
    }
    // Draft-to-publish and plain updates both need the fresh page purged.
    wccp_purge_urls( array( get_permalink( $post_id ) ) );
}
add_action( 'save_post', 'wccp_on_save_post', 10, 3 );

/** Purge a deleted post's URL before it disappears. */
function wccp_on_delete_post( $post_id, $post ) {
    if ( $post && 'publish' === $post->post_status ) {
        wccp_purge_urls( array( get_permalink( $post_id ) ) );
    }
}
add_action( 'before_delete_post', 'wccp_on_delete_post', 10, 2 );

/** Purge everything when the theme changes (templates/assets all shift). */
function wccp_on_theme_switch() {
    wccp_purge( array( 'purge_everything' => true ) );
}
add_action( 'switch_theme', 'wccp_on_theme_switch' );

/** Purge the post URL when a comment on it is approved (comment count changes). */
function wccp_on_comment_approved( $new_status, $old_status, $comment ) {
    if ( 'approved' === $new_status && 'approved' !== $old_status ) {
        wccp_purge_urls( array( get_permalink( $comment->comment_post_ID ) ) );
    }
}
add_action( 'transition_comment_status', 'wccp_on_comment_approved', 10, 3 );

/* ------------------------------------------------------------------ */
/* Settings page                                                       */
/* ------------------------------------------------------------------ */

function wccp_admin_menu() {
    add_options_page(
        'Cloudflare Cache Purge',
        'Cloudflare Purge',
        'manage_options',
        'wccp-settings',
        'wccp_render_page'
    );
}
add_action( 'admin_menu', 'wccp_admin_menu' );

function wccp_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'wccp' ) );
    }
    if ( isset( $_POST['wccp_save'] ) && check_admin_referer( 'wccp_save_settings' ) ) {
        $settings = array(
            'zone_id'        => isset( $_POST['wccp_zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wccp_zone_id'] ) ) : '',
            'api_token'      => isset( $_POST['wccp_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wccp_api_token'] ) ) : '',
            'purge_mode'     => ( isset( $_POST['wccp_purge_mode'] ) && 'everything' === $_POST['wccp_purge_mode'] ) ? 'everything' : 'urls',
            'purge_homepage' => isset( $_POST['wccp_purge_homepage'] ) ? 1 : 0,
        );
        update_option( 'wccp_settings', $settings );
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'wccp' ) . '</p></div>';
    }
    if ( isset( $_POST['wccp_purge_all'] ) && check_admin_referer( 'wccp_purge_all_now' ) ) {
        $ok = wccp_purge( array( 'purge_everything' => true ) );
        echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>'
            . ( $ok ? esc_html__( 'Full purge sent.', 'wccp' ) : esc_html__( 'Purge failed — check the last result below.', 'wccp' ) )
            . '</p></div>';
    }
    $s    = wccp_settings();
    $last = get_option( 'wccp_last_result', array() );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Cloudflare Cache Purge', 'wccp' ); ?></h1>
        <p><?php esc_html_e( 'Create a scoped API token at Cloudflare → My Profile → API Tokens with Zone:Cache Purge permission for this zone only. The token is stored in wp_options and never sent anywhere except api.cloudflare.com.', 'wccp' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'wccp_save_settings' ); ?>
            <table class="form-table">
                <tr><th><label for="wccp_zone_id"><?php esc_html_e( 'Zone ID', 'wccp' ); ?></label></th>
                    <td><input type="text" id="wccp_zone_id" name="wccp_zone_id" class="regular-text" value="<?php echo esc_attr( $s['zone_id'] ); ?>" /></td></tr>
                <tr><th><label for="wccp_api_token"><?php esc_html_e( 'API Token', 'wccp' ); ?></label></th>
                    <td><input type="password" id="wccp_api_token" name="wccp_api_token" class="regular-text" value="<?php echo esc_attr( $s['api_token'] ); ?>" autocomplete="off" /></td></tr>
                <tr><th><?php esc_html_e( 'Purge mode', 'wccp' ); ?></th>
                    <td><label><input type="radio" name="wccp_purge_mode" value="urls" <?php checked( $s['purge_mode'], 'urls' ); ?> /> <?php esc_html_e( 'Changed URLs only', 'wccp' ); ?></label><br />
                        <label><input type="radio" name="wccp_purge_mode" value="everything" <?php checked( $s['purge_mode'], 'everything' ); ?> /> <?php esc_html_e( 'Everything on every change', 'wccp' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'Also purge homepage', 'wccp' ); ?></th>
                    <td><label><input type="checkbox" name="wccp_purge_homepage" value="1" <?php checked( $s['purge_homepage'], 1 ); ?> /> <?php esc_html_e( 'Include the homepage in URL purges', 'wccp' ); ?></label></td></tr>
            </table>
            <?php submit_button( __( 'Save settings', 'wccp' ), 'primary', 'wccp_save' ); ?>
        </form>
        <hr />
        <form method="post">
            <?php wp_nonce_field( 'wccp_purge_all_now' ); ?>
            <?php submit_button( __( 'Purge everything now', 'wccp' ), 'secondary', 'wccp_purge_all' ); ?>
        </form>
        <?php if ( ! empty( $last ) ) : ?>
            <h2><?php esc_html_e( 'Last purge result', 'wccp' ); ?></h2>
            <p><?php echo esc_html( $last['time'] . ' — ' . ( $last['success'] ? 'OK' : 'FAILED' ) . ( ! empty( $last['error'] ) ? ' (' . $last['error'] . ')' : '' ) ); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
