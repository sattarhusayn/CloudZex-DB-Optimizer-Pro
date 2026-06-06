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

// %%BDOPT_DIR%% is replaced with the actual plugin path during enable.
$bdopt_dir = '%%BDOPT_DIR%%';

$bdopt_class_file = $bdopt_dir . '/inc/class-bdopt-object-cache.php';

if ( ! file_exists( $bdopt_class_file ) ) {
    if ( ! is_admin() ) {
        require_once ABSPATH . WPINC . '/cache.php';
    }
} elseif ( ! BDOPT_OBJECT_CACHE ) {
    wp_using_ext_object_cache( false );
} elseif ( file_exists( $bdopt_class_file ) ) {
    require_once $bdopt_class_file;
}
