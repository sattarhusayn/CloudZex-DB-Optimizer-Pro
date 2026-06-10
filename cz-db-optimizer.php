<?php
/**
 * Plugin Name: CloudZex DB Optimizer Pro
 * Plugin URI:  https://cloudzex.com
 * Description: All-in-one DB & site optimizer — Clean & Optimize DB, WooCommerce Orders, Table Check/Repair, MySQL Process Killer, DB & Full Site Backup/Restore, Orphan Media Cleaner, Object Cache (Redis), System Info & Health Check, Activity Log, Multisite check, Performance tweaks.
 * Version:     8.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * Author:      Abdus Sattar
 * Text Domain: cz-db-optimizer
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BDOPT_VERSION', '8.1.0' );
define( 'BDOPT_FILE', __FILE__ );
define( 'BDOPT_DIR', __DIR__ );

require_once BDOPT_DIR . '/inc/helpers.php';
require_once BDOPT_DIR . '/inc/clean.php';
require_once BDOPT_DIR . '/inc/backup.php';
require_once BDOPT_DIR . '/inc/ajax.php';
require_once BDOPT_DIR . '/inc/settings.php';
require_once BDOPT_DIR . '/inc/ui.php';

add_action('admin_menu', function() {
    add_menu_page(
        'CloudZex DB Optimizer Pro', 'DB Optimizer', 'manage_options',
        'cz-db-optimizer', 'bdopt_render_page',
        'dashicons-database-view', 80
    );
});

add_action('admin_enqueue_scripts', function( $hook ) {
    if ( 'toplevel_page_cz-db-optimizer' !== $hook ) return;

    wp_enqueue_style( 'bdopt-admin', plugin_dir_url( BDOPT_FILE ) . 'inc/admin.css', array(), BDOPT_VERSION );
    wp_enqueue_script( 'bdopt-admin', plugin_dir_url( BDOPT_FILE ) . 'inc/admin.js', array(), BDOPT_VERSION, true );
    wp_add_inline_script( 'bdopt-admin', 'var BDOPT_AJAX=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',BDOPT_NONCE=' . wp_json_encode( wp_create_nonce( 'bdopt_nonce' ) ) . ',BDOPT_BACKUP_MODE=' . wp_json_encode( bdopt_s( 'backup_mode', 'background' ) ) . ';', 'before' );
});

/* ─── GitHub Auto-Updater ─── */
add_filter( 'site_transient_update_plugins', function( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;
    $plugin_slug = plugin_basename( BDOPT_FILE );

    $gh_url = 'https://api.github.com/repos/sattarhusayn/CloudZex-DB-Optimizer-Pro/releases/latest';
    $cache  = get_transient( 'bdopt_github_latest' );

    if ( false === $cache ) {
        $resp = wp_remote_get( $gh_url, array( 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'CZ-DB-Optimizer' ), 'timeout' => 10 ) );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return $transient;
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body['tag_name'] ) ) return $transient;
        $cache = array( 'version' => ltrim( $body['tag_name'], 'v' ), 'zipball' => $body['zipball_url'] );
        set_transient( 'bdopt_github_latest', $cache, HOUR_IN_SECONDS * 6 );
    }

    if ( empty( $cache['version'] ) ) return $transient;
    $current_ver = isset( $transient->checked[ $plugin_slug ] ) ? $transient->checked[ $plugin_slug ] : BDOPT_VERSION;

    if ( version_compare( $cache['version'], $current_ver, '>' ) ) {
        $obj = new stdClass();
        $obj->slug        = plugin_basename( BDOPT_FILE );
        $obj->plugin      = $plugin_slug;
        $obj->new_version = $cache['version'];
        $obj->package     = $cache['zipball'];
        $obj->url         = 'https://github.com/sattarhusayn/CloudZex-DB-Optimizer-Pro';
        $obj->tested      = get_bloginfo( 'version' );
        $obj->requires_php = '7.2';
        $transient->response[ $plugin_slug ] = $obj;
    }
    return $transient;
} );

add_filter( 'plugins_api', function( $res, $action, $args ) {
    if ( $action !== 'plugin_information' || $args->slug !== plugin_basename( BDOPT_FILE ) ) return $res;

    $gh_url = 'https://api.github.com/repos/sattarhusayn/CloudZex-DB-Optimizer-Pro/releases/latest';
    $resp = wp_remote_get( $gh_url, array( 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'CZ-DB-Optimizer' ), 'timeout' => 10 ) );
    if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return $res;
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( empty( $body['tag_name'] ) ) return $res;

    $res = new stdClass();
    $res->name          = 'CloudZex DB Optimizer Pro';
    $res->slug          = plugin_basename( BDOPT_FILE );
    $res->version       = ltrim( $body['tag_name'], 'v' );
    $res->author        = '<a href="https://cloudzex.com">Abdus Sattar</a>';
    $res->homepage      = 'https://github.com/sattarhusayn/CloudZex-DB-Optimizer-Pro';
    $res->download_link = $body['zipball_url'];
    $res->requires      = '5.8';
    $res->tested        = get_bloginfo( 'version' );
    $res->requires_php  = '7.2';
    $res->last_updated  = isset( $body['published_at'] ) ? $body['published_at'] : '';
    $res->sections      = array(
        'description' => 'All-in-one DB & site optimizer for WordPress.',
        'changelog'   => isset( $body['body'] ) ? nl2br( esc_html( $body['body'] ) ) : '',
    );
    return $res;
}, 10, 3 );