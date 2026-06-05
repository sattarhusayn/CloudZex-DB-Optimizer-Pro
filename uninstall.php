<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'bdopt_settings' );
delete_option( 'bdopt_last_run' );
wp_clear_scheduled_hook( 'bdopt_auto_clean' );

$dropin = WP_CONTENT_DIR . '/object-cache.php';
if ( file_exists( $dropin ) ) {
    $content = file_get_contents( $dropin );
    if ( strpos( $content, 'CloudZex DB Optimizer' ) !== false ) {
        unlink( $dropin );
    }
}
