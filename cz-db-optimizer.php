<?php
/**
 * Plugin Name: CloudZex DB Optimizer Pro
 * Plugin URI:  https://cloudzex.com
 * Description: All-in-one DB & site optimizer — Clean & Optimize DB, WooCommerce Orders, Table Check/Repair, MySQL Process Killer, DB & Full Site Backup/Restore, Orphan Media Cleaner, Object Cache (Redis), System Info & Health Check, Activity Log, Multisite check, Performance tweaks.
 * Version:     8.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * Author:      Abdus Sattar
 * Text Domain: cz-db-optimizer
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BDOPT_VERSION', '8.0.0' );
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