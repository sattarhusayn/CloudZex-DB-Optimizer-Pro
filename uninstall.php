<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'bdopt_settings' );
delete_option( 'bdopt_last_run' );
delete_option( 'bdopt_activity_log' );

/* remove temp import chunks */
foreach ( array( 'bdopt_backup_progress', 'bdopt_wp_backup_progress', 'bdopt_import_progress', 'bdopt_orphan_progress', 'bdopt_orphan_scan' ) as $t ) {
    delete_transient( $t );
}
$tmp = WP_CONTENT_DIR . '/cz-backups/.import-tmp';
if ( is_dir( $tmp ) ) {
    foreach ( glob( $tmp . '/*' ) as $f ) { @unlink( $f ); }
    @rmdir( $tmp );
}

wp_clear_scheduled_hook( 'bdopt_auto_clean' );

/* remove object-cache drop-in if it's ours */
$dropin = WP_CONTENT_DIR . '/object-cache.php';
if ( file_exists( $dropin ) ) {
    $content = file_get_contents( $dropin );
    if ( strpos( $content, 'CloudZex DB Optimizer' ) !== false ) {
        @unlink( $dropin );
    }
}

/* clean WP_CACHE from wp-config.php */
$config_file = ABSPATH . 'wp-config.php';
if ( file_exists( $config_file ) && is_writable( $config_file ) ) {
    $content = file_get_contents( $config_file );
    $new = preg_replace(
        "/define\s*\(\s*'WP_CACHE'\s*,\s*true\s*\)\s*;\s*\/\/.*\n?/i",
        '',
        $content
    );
    $new = preg_replace(
        "/define\s*\(\s*'WP_CACHE'\s*,\s*true\s*\)\s*;\s*\n?/i",
        '',
        $new
    );
    $new = preg_replace(
        "/define\s*\(\s*'BDOPT_CACHE_TTL'\s*,\s*\d+\s*\)\s*;\s*\/\/.*\n?/i",
        '',
        $new
    );
    $new = preg_replace(
        "/define\s*\(\s*'BDOPT_CACHE_TTL'\s*,\s*\d+\s*\)\s*;\s*\n?/i",
        '',
        $new
    );
    if ( $new !== null && $new !== $content ) {
        file_put_contents( $config_file, $new );
    }
}
