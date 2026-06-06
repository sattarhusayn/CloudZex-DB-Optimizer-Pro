<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bdopt_defaults() {
    return array(
        'auto_enabled'      => 0,
        'auto_frequency'    => 'daily',
        'clean_sessions'    => 1,
        'clean_transients'  => 1,
        'clean_actions'     => 1,
        'clean_logs'        => 1,
        'clean_revisions'   => 1,
        'clean_autodraft'   => 1,
        'clean_spam'        => 1,
        'clean_trashed'     => 1,
        'clean_orphan_meta' => 1,
        'optimize_tables'        => 0,
        'clean_orders'      => 0,
        'order_days'        => 30,
        'order_statuses'    => 'completed,cancelled,refunded,failed',
        'action_days'       => 7,
        'log_days'          => 7,
        'revision_keep'     => 3,
        'perf_heartbeat'    => 0,
        'perf_xmlrpc'       => 0,
        'perf_pingbacks'    => 0,
        'perf_qs'           => 0,
        'perf_oembed'       => 0,
        'backup_before_optimize' => 0,
        'backup_mode'            => 'background',
    );
}

function bdopt_s( $key, $default = 0 ) {
    $settings = wp_parse_args( (array) get_option( 'bdopt_settings', array() ), bdopt_defaults() );
    return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}

function bdopt_table_exists( $table ) {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

register_activation_hook( BDOPT_FILE, 'bdopt_activate' );
register_deactivation_hook( BDOPT_FILE, 'bdopt_deactivate' );

function bdopt_activate() {
    $existing = get_option( 'bdopt_settings' );
    if ( ! $existing ) {
        update_option( 'bdopt_settings', bdopt_defaults() );
    }
    bdopt_reschedule();
}

function bdopt_deactivate() {
    wp_clear_scheduled_hook( 'bdopt_auto_clean' );
}

function bdopt_reschedule() {
    wp_clear_scheduled_hook( 'bdopt_auto_clean' );
    $s    = get_option( 'bdopt_settings', array() );
    if ( empty( $s['auto_enabled'] ) ) return;
    $ok   = array( 'daily', 'twicedaily', 'weekly' );
    $freq = ( isset( $s['auto_frequency'] ) && in_array( $s['auto_frequency'], $ok, true ) )
            ? $s['auto_frequency'] : 'daily';
    wp_schedule_event( time(), $freq, 'bdopt_auto_clean' );
}
add_action( 'bdopt_auto_clean', 'bdopt_run_all_clean' );

function bdopt_card( $type, $icon, $theme, $title, $count, $desc, $extra = '' ) {
    $cid = 'cnt-' . esc_attr( str_replace( '_', '-', $type ) );
    $num = is_numeric( $count ) ? number_format( (int) $count ) : esc_html( $count );
    echo '<div class="card ' . $theme . '">';
    echo '<div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span></div><div class="card-ttl">' . esc_html( $title ) . '</div></div>';
    echo '<div class="card-body"><div class="card-num" id="' . esc_attr( $cid ) . '">' . $num . '</div><div class="card-desc">' . esc_html( $desc ) . '</div>';
    echo '<button class="card-btn" type="button" data-type="' . esc_attr( $type ) . '"><span class="dashicons dashicons-trash"></span> Clean</button>';
    if ( $extra ) echo $extra;
    echo '</div></div>';
}

function bdopt_card_opt() {
    $bb = bdopt_s( 'backup_before_optimize', 0 );
    echo '<div class="card c-opt"><div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-performance"></span></div><div class="card-ttl">Table Optimize</div></div>';
    echo '<div class="card-body"><div class="card-num" style="font-size:20px;margin-top:4px">OPTIMIZE</div><div class="card-desc">Rebuilds tables &amp; reclaims disk space<br>MyISAM + InnoDB</div>';
    echo '<button class="card-btn" type="button" data-type="optimize"><span class="dashicons dashicons-performance"></span> Run Optimize</button>';
    echo '<label style="display:flex;align-items:center;gap:7px;font-size:12px;margin-top:12px;cursor:pointer;padding-top:11px;border-top:1px solid #f0f0f1"><span class="tog"><input type="checkbox" id="s-backup-before" ' . checked( $bb, 1, false ) . '><span class="tog-sl"></span></span> Backup before optimize</label>';
    echo '</div></div>';
}

function bdopt_setting_toggle( $id, $label, $desc, $checked ) {
    echo '<div class="s-row"><div class="s-lbl">' . wp_kses_post( $label ) . ' <small>' . wp_kses_post( $desc ) . '</small></div>';
    echo '<div class="s-ctrl"><label class="tog"><input type="checkbox" id="' . esc_attr( $id ) . '" ' . $checked . '><span class="tog-sl"></span></label></div></div>';
}

/* ─── Activity Log ─── */
function bdopt_add_log( $type, $message ) {
    $log = (array) get_option( 'bdopt_activity_log', array() );
    $log[] = array( 'time' => current_time( 'mysql' ), 'type' => $type, 'msg' => $message );
    if ( count( $log ) > 200 ) $log = array_slice( $log, -200 );
    update_option( 'bdopt_activity_log', $log, false );
}

function bdopt_get_log( $limit = 50 ) {
    $log = (array) get_option( 'bdopt_activity_log', array() );
    return array_reverse( array_slice( $log, -$limit ) );
}

function bdopt_clear_log() {
    delete_option( 'bdopt_activity_log' );
}

/* ─── System Info ─── */
function bdopt_system_info() {
    global $wpdb;
    $mysql_ver = $wpdb->get_var( "SELECT VERSION()" );
    return array(
        'server_os'       => PHP_OS,
        'php_ver'         => PHP_VERSION,
        'mysql_ver'       => $mysql_ver,
        'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
        'memory_limit'    => ini_get( 'memory_limit' ),
        'max_exec'        => ini_get( 'max_execution_time' ),
        'max_upload'      => size_format( wp_max_upload_size() ),
        'wp_ver'          => get_bloginfo( 'version' ),
        'wp_mem_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '40M',
        'db_name'         => defined('DB_NAME') ? DB_NAME : '?',
        'db_host'         => defined('DB_HOST') ? DB_HOST : '?',
        'table_prefix'    => $wpdb->prefix,
        'site_url'        => get_site_url(),
        'abs_path'        => ABSPATH,
        'wp_content_dir'  => WP_CONTENT_DIR,
    );
}

/* ─── Health Check ─── */
function bdopt_health_check() {
    $checks = array();
    $checks[] = array( 'name' => 'PHP Version',       'ok' => version_compare( PHP_VERSION, '7.2', '>=' ), 'detail' => PHP_VERSION );
    $checks[] = array( 'name' => 'zlib Extension',     'ok' => extension_loaded( 'zlib' ),       'detail' => extension_loaded( 'zlib' ) ? 'Loaded' : 'Missing (backup .gz)' );
    $checks[] = array( 'name' => 'ZipArchive',         'ok' => class_exists( 'ZipArchive' ),      'detail' => class_exists( 'ZipArchive' ) ? 'Available' : 'Missing (ZIP backup)' );
    $checks[] = array( 'name' => 'MySQLi/mysql',       'ok' => extension_loaded( 'mysqli' ),      'detail' => 'mysqli: ' . ( extension_loaded( 'mysqli' ) ? 'Yes' : 'No' ) );
    $checks[] = array( 'name' => 'cURL',               'ok' => extension_loaded( 'curl' ),        'detail' => extension_loaded( 'curl' ) ? 'Loaded' : 'Not loaded' );
    $checks[] = array( 'name' => 'GD / Imagick',       'ok' => extension_loaded( 'gd' ) || extension_loaded( 'imagick' ), 'detail' => ( extension_loaded( 'gd' ) ? 'GD' : '' ) . ( extension_loaded( 'gd' ) && extension_loaded( 'imagick' ) ? ' + ' : '' ) . ( extension_loaded( 'imagick' ) ? 'Imagick' : 'Neither' ) );
    $checks[] = array( 'name' => 'Memory Limit',       'ok' => (int)ini_get( 'memory_limit' ) >= 128 || ini_get( 'memory_limit' ) === '-1', 'detail' => ini_get( 'memory_limit' ) );
    $checks[] = array( 'name' => 'Max Execution Time', 'ok' => (int)ini_get( 'max_execution_time' ) >= 60 || ini_get( 'max_execution_time' ) === '0', 'detail' => ini_get( 'max_execution_time' ) . 's' );
    $checks[] = array( 'name' => 'Backup Dir Writable','ok' => wp_is_writable( WP_CONTENT_DIR . '/cz-backups' ), 'detail' => wp_is_writable( WP_CONTENT_DIR . '/cz-backups' ) ? 'Writable' : 'Not writable' );
    $dir = WP_CONTENT_DIR . '/cz-backups';
    if ( ! is_dir( $dir ) ) @mkdir( $dir, 0755, true );
    $checks[] = array( 'name' => 'Backup Dir Exists',  'ok' => is_dir( $dir ),                   'detail' => is_dir( $dir ) ? 'Exists' : 'Failed to create' );
    return $checks;
}

/* ─── Multisite Check ─── */
function bdopt_multisite_check() {
    if ( ! is_multisite() ) return array( 'is_multisite' => false, 'message' => 'Single site installation — no issues.' );
    global $wpdb;
    $issues = array();
    if ( $wpdb->prefix !== 'wp_' ) $issues[] = 'Custom table prefix detected: ' . $wpdb->prefix . ' (may need per-site handling)';
    if ( get_site_option( 'active_sitewide_plugins' ) ) $issues[] = 'Network-activated plugins may require per-site settings';
    return array( 'is_multisite' => true, 'issues' => $issues, 'message' => empty( $issues ) ? 'Multisite detected — no known conflicts.' : 'Potential issues found.' );
}