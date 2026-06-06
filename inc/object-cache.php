<?php
/**
 * CloudZex DB Optimizer Pro — Object Cache Drop-in Bootstrap.
 *
 * This file is copied to wp-content/object-cache.php when object cache is enabled.
 * It does NOT declare WP_Object_Cache directly; instead it bootstraps the
 * real cache class from the plugin directory, avoiding conflicts with
 * WordPress 6.x class-wp-object-cache.php.
 */

defined( 'WPINC' ) || exit;

! defined( 'BDOPT_OBJECT_CACHE' ) && define( 'BDOPT_OBJECT_CACHE', true );

// Locate the plugin directory
$bdopt_dir = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ) . '/cz-db-optimizer';

// Use plugin as higher priority than MU plugin
if ( ! file_exists( $bdopt_dir . '/cz-db-optimizer.php' ) ) {
    $bdopt_dir = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/cz-db-optimizer';
}

$bdopt_class_file = $bdopt_dir . '/inc/class-bdopt-object-cache.php';

if ( ! $bdopt_dir || ! file_exists( $bdopt_dir . '/cz-db-optimizer.php' ) || ! file_exists( $bdopt_class_file ) ) {
    // Cannot locate the plugin — fall back to WordPress default cache
    if ( ! is_admin() ) {
        require_once ABSPATH . WPINC . '/cache.php';
    }
} elseif ( ! BDOPT_OBJECT_CACHE ) {
    // Explicitly disabled
    wp_using_ext_object_cache( false );
} elseif ( file_exists( $bdopt_class_file ) ) {
    require_once $bdopt_class_file;
}
