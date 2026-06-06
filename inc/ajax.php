<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_ajax_bdopt_run_clean', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $allowed = array('sessions','transients','actions','logs','revisions','autodraft','spam','trashed','orphan_meta','oembed','personal_data','orders','optimize','all');
    $type    = sanitize_text_field( isset($_POST['type']) ? $_POST['type'] : 'all' );
    if ( ! in_array($type, $allowed, true) ) wp_die('Invalid type', 400);

    $count = 0;
    $label = '';
    switch ( $type ) {
        case 'sessions':    $count = bdopt_clean_sessions(); $label = 'Expired Sessions'; break;
        case 'transients':  $count = bdopt_clean_transients(); $label = 'Expired Transients'; break;
        case 'actions':     $count = bdopt_clean_actions( (int) bdopt_s('action_days',7) ); $label = 'Action Scheduler'; break;
        case 'logs':        $count = bdopt_clean_logs( (int) bdopt_s('log_days',7) ); $label = 'Log Files'; break;
        case 'revisions':   $count = bdopt_clean_revisions( (int) bdopt_s('revision_keep',3) ); $label = 'Post Revisions'; break;
        case 'autodraft':   $count = bdopt_clean_autodraft(); $label = 'Auto Drafts'; break;
        case 'spam':        $count = bdopt_clean_spam_comments(); $label = 'Spam & Trash Comments'; break;
        case 'trashed':     $count = bdopt_clean_trashed(); $label = 'Trashed Posts'; break;
        case 'orphan_meta': $count = bdopt_clean_orphan_meta(); $label = 'Orphan Meta'; break;
        case 'oembed':      $count = bdopt_clean_oembed(); $label = 'oEmbed Cache'; break;
        case 'personal_data': $count = bdopt_clean_personal_data(); $label = 'Personal Data'; break;
        case 'orders':
            $mDays = isset( $_POST['order_days'] ) ? (int) $_POST['order_days'] : (int) bdopt_s( 'order_days', 30 );
            $mStatuses = isset( $_POST['order_statuses'] ) ? array_map( 'trim', explode( ',', sanitize_text_field( $_POST['order_statuses'] ) ) ) : array();
            $mFrom    = isset( $_POST['order_from'] ) ? sanitize_text_field( $_POST['order_from'] ) : '';
            $mTo      = isset( $_POST['order_to'] ) ? sanitize_text_field( $_POST['order_to'] ) : '';
            $mIdFrom  = isset( $_POST['order_id_from'] ) ? (int) $_POST['order_id_from'] : 0;
            $mIdTo    = isset( $_POST['order_id_to'] ) ? (int) $_POST['order_id_to'] : 0;
            $count = bdopt_clean_orders( $mDays, $mStatuses, $mFrom, $mTo, $mIdFrom, $mIdTo );
            $label = 'WooCommerce Orders';
            break;
        case 'optimize':
            if ( (int) bdopt_s( 'backup_before_optimize', 0 ) ) {
                bdopt_create_backup();
            }
            $count = bdopt_optimize_tables();
            $label = 'Table Optimization';
            break;
        case 'all':
            $r = bdopt_run_all_clean();
            $count = array_sum($r);
            $label = 'Clean All';
            break;
    }

    if ( $label ) bdopt_add_log( 'clean', "{$label}: {$count} item(s) cleaned" );

    wp_send_json_success( array(
        'count'   => $count,
        'db_size' => bdopt_get_db_size(),
        'counts'  => bdopt_get_counts(),
    ));
});

add_action('wp_ajax_bdopt_download_orders', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $mDays     = isset( $_POST['order_days'] ) ? (int) $_POST['order_days'] : 30;
    $mStatuses = isset( $_POST['order_statuses'] ) ? array_map( 'trim', explode( ',', sanitize_text_field( $_POST['order_statuses'] ) ) ) : array();
    $mFrom     = isset( $_POST['order_from'] ) ? sanitize_text_field( $_POST['order_from'] ) : '';
    $mTo       = isset( $_POST['order_to'] ) ? sanitize_text_field( $_POST['order_to'] ) : '';
    $mIdFrom   = isset( $_POST['order_id_from'] ) ? (int) $_POST['order_id_from'] : 0;
    $mIdTo     = isset( $_POST['order_id_to'] ) ? (int) $_POST['order_id_to'] : 0;

    $csv = bdopt_export_orders_csv( $mDays, $mStatuses, $mFrom, $mTo, $mIdFrom, $mIdTo );
    if ( empty( $csv ) ) {
        wp_send_json_error( array( 'message' => 'No orders found matching the filters.' ) );
        return;
    }
    wp_send_json_success( array( 'csv' => $csv ) );
});

add_action('wp_ajax_bdopt_cache_action', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $action = isset( $_POST['cache_action'] ) ? sanitize_text_field( $_POST['cache_action'] ) : '';
    $source = BDOPT_DIR . '/inc/object-cache.php';
    $target = WP_CONTENT_DIR . '/object-cache.php';

    if ( $action === 'enable' ) {
        if ( ! extension_loaded('redis') || ! class_exists('Redis') ) {
            wp_send_json_error( array( 'message' => 'Redis PHP extension is not installed.' ) );
        }
        if ( file_exists( $target ) ) {
            wp_send_json_error( array( 'message' => 'object-cache.php already exists.' ) );
        }
        if ( ! file_exists( $source ) ) {
            wp_send_json_error( array( 'message' => 'Plugin drop-in file missing.' ) );
        }
        if ( copy( $source, $target ) ) {
            $config_file = ABSPATH . 'wp-config.php';
            if ( file_exists( $config_file ) && is_writable( $config_file ) ) {
                $content = file_get_contents( $config_file );
                if ( preg_match( "/define\s*\(\s*'WP_CACHE'\s*,/i", $content ) === 0 ) {
                    $insert = "define('WP_CACHE', true); // Added by CloudZex DB Optimizer\n";
                    $content = preg_replace( '/^<\?php\s*/', "<?php\n" . $insert, $content, 1 );
                    if ( $content !== null ) {
                        file_put_contents( $config_file, $content );
                    }
                }
            }
            bdopt_add_log( 'cache', 'Object cache enabled' );
            wp_send_json_success( array( 'message' => 'Object cache enabled! Reloading...' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Failed to copy drop-in. Check permissions on wp-content/' ) );
        }
    } elseif ( $action === 'disable' ) {
        if ( file_exists( $target ) ) {
            if ( unlink( $target ) ) {
                $config_file = ABSPATH . 'wp-config.php';
                if ( file_exists( $config_file ) && is_writable( $config_file ) ) {
                    $content = file_get_contents( $config_file );
                    $new_content = preg_replace(
                        "/define\s*\(\s*'WP_CACHE'\s*,\s*true\s*\)\s*;\s*\/\/.*\n?/i",
                        '',
                        $content
                    );
                    $new_content = preg_replace(
                        "/define\s*\(\s*'WP_CACHE'\s*,\s*true\s*\)\s*;\s*\n?/i",
                        '',
                        $new_content
                    );
                    if ( $new_content !== null && $new_content !== $content ) {
                        file_put_contents( $config_file, $new_content );
                    }
                }
                bdopt_add_log( 'cache', 'Object cache disabled' );
                wp_send_json_success( array( 'message' => 'Object cache disabled! Reloading...' ) );
            } else {
                wp_send_json_error( array( 'message' => 'Failed to remove object-cache.php.' ) );
            }
        } else {
            wp_send_json_error( array( 'message' => 'object-cache.php not found.' ) );
        }
    } else {
        wp_send_json_error( array( 'message' => 'Invalid action.' ) );
    }
});

add_action('wp_ajax_bdopt_purge_cache', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $cache = isset( $_POST['cache'] ) ? sanitize_text_field( $_POST['cache'] ) : '';
    $label = '';
    $ok    = false;

    switch ( $cache ) {
        case 'litespeed':
            $label = 'LiteSpeed Cache';
            if ( defined('LITESPEED_PURGE_ALL') ) { do_action( 'litespeed_purge_all' ); $ok = true; }
            break;
        case 'w3tc':
            $label = 'W3 Total Cache';
            if ( function_exists('w3tc_flush_all') ) { w3tc_flush_all(); $ok = true; }
            elseif ( function_exists('w3tc_pgcache_flush') ) { w3tc_pgcache_flush(); $ok = true; }
            break;
        case 'wp-super':
            $label = 'WP Super Cache';
            if ( function_exists('wp_cache_clear_cache') ) { wp_cache_clear_cache(); $ok = true; }
            break;
        case 'wp-rocket':
            $label = 'WP Rocket';
            if ( function_exists('rocket_clean_domain') ) { rocket_clean_domain(); $ok = true; }
            elseif ( function_exists('rocket_clean_cache_domain') ) { rocket_clean_cache_domain(); $ok = true; }
            break;
        case 'autoptimize':
            $label = 'Autoptimize';
            if ( class_exists('autoptimizeCache') ) { autoptimizeCache::clearall(); $ok = true; }
            break;
        case 'swift':
            $label = 'Swift Performance';
            if ( class_exists('Swift_Performance_Cache') && method_exists('Swift_Performance_Cache', 'clear_all_cache') ) { Swift_Performance_Cache::clear_all_cache(); $ok = true; }
            break;
        case 'breeze':
            $label = 'Breeze';
            if ( function_exists('breeze_cache_flush') ) { breeze_cache_flush(); $ok = true; }
            break;
        case 'hummingbird':
            $label = 'Hummingbird';
            if ( class_exists('WP_Hummingbird') && method_exists('WP_Hummingbird', 'flush_cache') ) { WP_Hummingbird::flush_cache(); $ok = true; }
            break;
        default:
            wp_send_json_error( array( 'message' => 'Unknown cache plugin.' ) );
            return;
    }

    if ( $ok ) {
        bdopt_add_log( 'cache', "{$label} cache purged" );
        wp_send_json_success( array( 'message' => $label . ' cache purged!' ) );
    } else {
        wp_send_json_error( array( 'message' => $label . ' purge function not found.' ) );
    }
});

add_action('wp_ajax_bdopt_purge_wp_cache', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $dir = WP_CONTENT_DIR . '/cache';
    if ( ! is_dir( $dir ) ) {
        wp_send_json_error( array( 'message' => 'wp-content/cache/ folder not found.' ) );
        return;
    }

    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $it as $f ) {
        if ( $f->isFile() || $f->isLink() ) {
            @unlink( $f->getPathname() );
            $count++;
        } elseif ( $f->isDir() ) {
            @rmdir( $f->getPathname() );
        }
    }

    bdopt_add_log( 'cache', "wp-content/cache/ purged ({$count} items)" );
    wp_send_json_success( array( 'message' => "wp-content/cache/ purged! ($count items removed)" ) );
});

add_action('wp_ajax_bdopt_save_settings', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $freq = sanitize_text_field( isset($_POST['auto_frequency']) ? $_POST['auto_frequency'] : 'daily' );
    if ( ! in_array($freq, array('daily','twicedaily','weekly'), true) ) $freq = 'daily';

    $bools = array('auto_enabled','clean_sessions','clean_transients','clean_actions','clean_logs',
                   'clean_revisions','clean_autodraft','clean_spam','clean_trashed','clean_orphan_meta',
                   'clean_orders','optimize_tables',
                   'perf_heartbeat','perf_xmlrpc','perf_pingbacks','perf_qs','perf_oembed',
                   'backup_before_optimize');
    $s = array(
        'auto_frequency' => $freq,
        'order_days'     => max(1, (int)(isset($_POST['order_days'])    ? $_POST['order_days']    : 30)),
        'order_statuses' => sanitize_text_field( isset($_POST['order_statuses']) ? $_POST['order_statuses'] : 'completed,cancelled,refunded,failed' ),
        'action_days'    => max(1, (int)(isset($_POST['action_days'])   ? $_POST['action_days']   : 7)),
        'log_days'       => max(1, (int)(isset($_POST['log_days'])      ? $_POST['log_days']      : 7)),
        'revision_keep'  => max(0, (int)(isset($_POST['revision_keep']) ? $_POST['revision_keep'] : 3)),
        'backup_mode'    => isset( $_POST['backup_mode'] ) && in_array( $_POST['backup_mode'], array( 'background', 'browser' ), true ) ? $_POST['backup_mode'] : 'background',
    );
    foreach ( $bools as $f ) {
        $s[$f] = (int)( isset($_POST[$f]) ? $_POST[$f] : 0 );
    }

    update_option('bdopt_settings', $s);
    bdopt_reschedule();
    wp_send_json_success(array('message' => 'Settings saved!'));
});

add_action('wp_ajax_bdopt_get_breakdown', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    wp_send_json_success(array(
        'tables' => bdopt_get_db_breakdown(),
    ));
});

add_action('wp_ajax_bdopt_create_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    set_transient( 'bdopt_backup_progress', array(
        'status' => 'running', 'pct' => 0, 'file' => '', 'error' => '',
    ), HOUR_IN_SECONDS );

    $mode = bdopt_s( 'backup_mode', 'background' );

    /* auto-fallback to browser if set_time_limit is disabled */
    if ( $mode === 'background' && ! bdopt_can_set_time_limit() ) {
        $mode = 'browser';
    }

    $background = $mode === 'background';
    if ( $background ) {
        ignore_user_abort( true );
        while ( ob_get_level() ) ob_end_clean();
        $resp = wp_json_encode( array( 'success' => true, 'data' => array( 'started' => true ) ) );
        header( 'Content-Type: application/json' );
        header( 'Content-Length: ' . strlen( $resp ) );
        header( 'Connection: close' );
        echo $resp;
        ob_flush();
        flush();
        if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();
    }

    $result = bdopt_create_backup( function( $i, $total, $name ) {
        set_transient( 'bdopt_backup_progress', array(
            'status' => 'running', 'pct' => min( 99, round( $i / $total * 100 ) ), 'file' => $name, 'error' => '',
        ), HOUR_IN_SECONDS );
    } );

    if ( false === $result ) {
        set_transient( 'bdopt_backup_progress', array(
            'status' => 'error', 'pct' => 0, 'file' => '', 'error' => 'Backup failed — check disk space or permissions.',
        ), HOUR_IN_SECONDS );
        if ( ! $background ) {
            wp_send_json_error( array( 'message' => 'Backup failed.' ) );
        }
    } else {
        set_transient( 'bdopt_backup_progress', array(
            'status' => 'done', 'pct' => 100, 'file' => $result['name'], 'error' => '',
        ), HOUR_IN_SECONDS );
        bdopt_add_log( 'backup', "DB Backup created: {$result['name']}" );
        if ( ! $background ) {
            wp_send_json_success( array( 'done' => true, 'name' => $result['name'] ) );
        }
    }
});

add_action('wp_ajax_bdopt_backup_status', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $p = get_transient( 'bdopt_backup_progress' );
    if ( ! $p ) $p = array( 'status' => 'idle', 'pct' => 0, 'file' => '', 'error' => '' );
    if ( $p['status'] === 'done' || $p['status'] === 'error' ) {
        delete_transient( 'bdopt_backup_progress' );
    }
    wp_send_json_success( $p );
});

add_action('wp_ajax_bdopt_delete_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $name = isset( $_POST['name'] ) ? basename( $_POST['name'] ) : '';
    if ( empty( $name ) ) {
        wp_send_json_error( array( 'message' => 'Invalid backup name.' ) );
        return;
    }

    $ok = bdopt_delete_backup( $name );
    if ( ! $ok ) {
        $err = error_get_last();
        $msg = 'Could not delete backup.';
        if ( $err && ! empty( $err['message'] ) ) {
            $msg .= ' (' . $err['message'] . ')';
        }
        wp_send_json_error( array( 'message' => $msg ) );
        return;
    }

    bdopt_add_log( 'backup', "DB Backup deleted: {$name}" );
    wp_send_json_success( array( 'backups' => bdopt_get_backups() ) );
});

add_action('wp_ajax_bdopt_download_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $name = isset( $_GET['file'] ) ? basename( $_GET['file'] ) : '';
    if ( empty( $name ) ) {
        wp_die( 'Missing file parameter', 400 );
    }

    $dir  = realpath( bdopt_backup_dir() );
    $path = realpath( $dir . DIRECTORY_SEPARATOR . $name );
    if ( false === $path || strpos( $path, $dir . DIRECTORY_SEPARATOR ) !== 0 || ! file_exists( $path ) ) {
        wp_die( 'File not found', 404 );
    }

    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: application/gzip' );
    header( 'Content-Disposition: attachment; filename="' . $name . '"' );
    header( 'Content-Length: ' . filesize( $path ) );
    header( 'Pragma: public' );
    while ( ob_get_level() ) ob_end_clean();
    $fh = fopen( $path, 'rb' );
    if ( $fh ) {
        while ( ! feof( $fh ) ) { echo fread( $fh, 1048576 ); flush(); }
        fclose( $fh );
    }
    exit;
});

add_action('wp_ajax_bdopt_create_wp_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    set_transient( 'bdopt_wp_backup_progress', array(
        'status' => 'running', 'pct' => 0, 'file' => '', 'error' => '',
    ), HOUR_IN_SECONDS );

    $mode = bdopt_s( 'backup_mode', 'background' );

    /* auto-fallback to browser if set_time_limit is disabled */
    if ( $mode === 'background' && ! bdopt_can_set_time_limit() ) {
        $mode = 'browser';
    }

    $background = $mode === 'background';
    if ( $background ) {
        ignore_user_abort( true );
        while ( ob_get_level() ) ob_end_clean();
        $resp = wp_json_encode( array( 'success' => true, 'data' => array( 'started' => true ) ) );
        header( 'Content-Type: application/json' );
        header( 'Content-Length: ' . strlen( $resp ) );
        header( 'Connection: close' );
        echo $resp;
        ob_flush();
        flush();
        if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();
    }

    $result = bdopt_create_wp_backup( function( $i, $total, $file ) {
        set_transient( 'bdopt_wp_backup_progress', array(
            'status' => 'running', 'pct' => min( 99, round( $i / $total * 100 ) ), 'file' => $file, 'error' => '',
        ), HOUR_IN_SECONDS );
    } );

    if ( false === $result ) {
        set_transient( 'bdopt_wp_backup_progress', array(
            'status' => 'error', 'pct' => 0, 'file' => '', 'error' => 'Full backup failed — check disk space or ZipArchive.',
        ), HOUR_IN_SECONDS );
        if ( ! $background ) {
            wp_send_json_error( array( 'message' => 'Full backup failed.' ) );
        }
    } else {
        set_transient( 'bdopt_wp_backup_progress', array(
            'status' => 'done', 'pct' => 100, 'file' => $result['name'], 'error' => '',
        ), HOUR_IN_SECONDS );
        bdopt_add_log( 'backup', "Full Site Backup created: {$result['name']}" );
        if ( ! $background ) {
            wp_send_json_success( array( 'done' => true, 'name' => $result['name'] ) );
        }
    }
});

add_action('wp_ajax_bdopt_wp_backup_status', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $p = get_transient( 'bdopt_wp_backup_progress' );
    if ( ! $p ) $p = array( 'status' => 'idle', 'pct' => 0, 'file' => '', 'error' => '' );
    if ( $p['status'] === 'done' || $p['status'] === 'error' ) {
        delete_transient( 'bdopt_wp_backup_progress' );
    }
    wp_send_json_success( $p );
});

add_action('wp_ajax_bdopt_cancel_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    delete_transient( 'bdopt_backup_progress' );
    delete_transient( 'bdopt_wp_backup_progress' );
    wp_send_json_success( array( 'message' => 'Backup cancelled.' ) );
});

add_action('wp_ajax_bdopt_delete_wp_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $name = isset( $_POST['name'] ) ? basename( $_POST['name'] ) : '';
    if ( empty( $name ) ) {
        wp_send_json_error( array( 'message' => 'Invalid backup name.' ) );
        return;
    }

    $ok = bdopt_delete_wp_backup( $name );
    if ( ! $ok ) {
        $err = error_get_last();
        $msg = 'Could not delete backup.';
        if ( $err && ! empty( $err['message'] ) ) {
            $msg .= ' (' . $err['message'] . ')';
        }
        wp_send_json_error( array( 'message' => $msg ) );
        return;
    }

    bdopt_add_log( 'backup', "Full Site Backup deleted: {$name}" );
    wp_send_json_success( array( 'backups' => bdopt_get_wp_backups() ) );
});

add_action('wp_ajax_bdopt_list_wp_backups', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    wp_send_json_success( array( 'backups' => bdopt_get_wp_backups() ) );
});

add_action('wp_ajax_bdopt_download_wp_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $name = isset( $_GET['file'] ) ? basename( $_GET['file'] ) : '';
    if ( empty( $name ) ) {
        wp_die( 'Missing file parameter', 400 );
    }

    $dir  = realpath( bdopt_wp_backup_dir() );
    $path = realpath( $dir . DIRECTORY_SEPARATOR . $name );
    if ( false === $path || strpos( $path, $dir . DIRECTORY_SEPARATOR ) !== 0 || ! file_exists( $path ) ) {
        wp_die( 'File not found', 404 );
    }

    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $name . '"' );
    header( 'Content-Length: ' . filesize( $path ) );
    header( 'Pragma: public' );
    while ( ob_get_level() ) ob_end_clean();
    $fh = fopen( $path, 'rb' );
    if ( $fh ) {
        while ( ! feof( $fh ) ) { echo fread( $fh, 1048576 ); flush(); }
        fclose( $fh );
    }
    exit;
});

add_action('wp_ajax_bdopt_list_backups', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    wp_send_json_success( array( 'backups' => bdopt_get_backups() ) );
});

add_action('wp_ajax_bdopt_restore_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $name = isset( $_POST['name'] ) ? basename( $_POST['name'] ) : '';
    if ( empty( $name ) ) {
        wp_send_json_error( array( 'message' => 'Invalid backup name.' ) );
        return;
    }

    set_transient( 'bdopt_import_progress', array(
        'status' => 'running', 'pct' => 0, 'msg' => 'Starting restore...', 'error' => '',
    ), HOUR_IN_SECONDS );

    ignore_user_abort( true );
    while ( ob_get_level() ) ob_end_clean();
    $resp = wp_json_encode( array( 'success' => true, 'data' => array( 'started' => true, 'name' => $name ) ) );
    header( 'Content-Type: application/json' );
    header( 'Content-Length: ' . strlen( $resp ) );
    header( 'Connection: close' );
    echo $resp;
    ob_flush();
    flush();
    if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();

    $result = bdopt_restore_backup( $name, function( $pct, $total, $msg ) {
        set_transient( 'bdopt_import_progress', array(
            'status' => 'running', 'pct' => $pct, 'msg' => $msg, 'error' => '',
        ), HOUR_IN_SECONDS );
    } );

    if ( $result['success'] ) {
        set_transient( 'bdopt_import_progress', array(
            'status' => 'done', 'pct' => 100, 'msg' => "Restore complete! {$result['queries']} queries executed.", 'error' => '',
        ), HOUR_IN_SECONDS );
        bdopt_add_log( 'restore', "DB restore: {$name} ({$result['queries']} queries)" );
    } else {
        set_transient( 'bdopt_import_progress', array(
            'status' => 'error', 'pct' => 0, 'msg' => '', 'error' => $result['error'],
        ), HOUR_IN_SECONDS );
        bdopt_add_log( 'error', "DB restore failed: {$name} - {$result['error']}" );
    }
});

add_action('wp_ajax_bdopt_restore_wp_backup', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $name = isset( $_POST['name'] ) ? basename( $_POST['name'] ) : '';
    if ( empty( $name ) ) {
        wp_send_json_error( array( 'message' => 'Invalid backup name.' ) );
        return;
    }

    set_transient( 'bdopt_import_progress', array(
        'status' => 'running', 'pct' => 0, 'msg' => 'Starting ZIP restore...', 'error' => '',
    ), HOUR_IN_SECONDS );

    ignore_user_abort( true );
    while ( ob_get_level() ) ob_end_clean();
    $resp = wp_json_encode( array( 'success' => true, 'data' => array( 'started' => true, 'name' => $name ) ) );
    header( 'Content-Type: application/json' );
    header( 'Content-Length: ' . strlen( $resp ) );
    header( 'Connection: close' );
    echo $resp;
    ob_flush();
    flush();
    if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();

    $result = bdopt_restore_wp_backup( $name, function( $pct, $total, $msg ) {
        set_transient( 'bdopt_import_progress', array(
            'status' => 'running', 'pct' => $pct, 'msg' => $msg, 'error' => '',
        ), HOUR_IN_SECONDS );
    } );

    if ( $result['success'] ) {
        set_transient( 'bdopt_import_progress', array(
            'status' => 'done', 'pct' => 100, 'msg' => "ZIP restore complete! {$result['queries']} queries executed.", 'error' => '',
        ), HOUR_IN_SECONDS );
        bdopt_add_log( 'restore', "Full Site restore: {$name} ({$result['queries']} queries)" );
    } else {
        set_transient( 'bdopt_import_progress', array(
            'status' => 'error', 'pct' => 0, 'msg' => '', 'error' => $result['error'],
        ), HOUR_IN_SECONDS );
        bdopt_add_log( 'error', "Full Site restore failed: {$name} - {$result['error']}" );
    }
});

add_action('wp_ajax_bdopt_import_status', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $p = get_transient( 'bdopt_import_progress' );
    if ( ! $p ) $p = array( 'status' => 'idle', 'pct' => 0, 'msg' => '', 'error' => '' );
    if ( $p['status'] === 'done' || $p['status'] === 'error' ) {
        delete_transient( 'bdopt_import_progress' );
    }
    wp_send_json_success( $p );
});

add_action('wp_ajax_bdopt_upload_import_chunk', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $upload_id   = isset( $_POST['upload_id'] ) ? sanitize_key( $_POST['upload_id'] ) : '';
    $chunk_index = isset( $_POST['chunk_index'] ) ? (int) $_POST['chunk_index'] : 0;
    $total_chunks = isset( $_POST['total_chunks'] ) ? (int) $_POST['total_chunks'] : 0;
    $data        = isset( $_POST['data'] ) ? $_POST['data'] : '';
    $ext         = isset( $_POST['ext'] ) && in_array( $_POST['ext'], array( 'sql', 'gz', 'zip' ), true ) ? $_POST['ext'] : 'sql';

    if ( empty( $upload_id ) || $total_chunks === 0 || empty( $data ) ) {
        wp_send_json_error( array( 'message' => 'Invalid upload parameters.' ) );
        return;
    }

    $result = bdopt_handle_upload_chunk( $upload_id, $chunk_index, $total_chunks, $data, $ext );

    if ( ! $result['success'] ) {
        wp_send_json_error( array( 'message' => $result['error'] ) );
        return;
    }

    if ( ! empty( $result['done'] ) ) {
        $final_path = $result['path'];
        set_transient( 'bdopt_import_progress', array(
            'status' => 'running', 'pct' => 0, 'msg' => 'Starting import of uploaded file...', 'error' => '',
        ), HOUR_IN_SECONDS );

        ignore_user_abort( true );
        while ( ob_get_level() ) ob_end_clean();
        $resp2 = wp_json_encode( array( 'success' => true, 'data' => array( 'started' => true, 'upload_done' => true ) ) );
        header( 'Content-Type: application/json' );
        header( 'Content-Length: ' . strlen( $resp2 ) );
        header( 'Connection: close' );
        echo $resp2;
        ob_flush();
        flush();
        if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();

        $progress_cb = function( $pct, $total, $msg ) {
            set_transient( 'bdopt_import_progress', array(
                'status' => 'running', 'pct' => $pct, 'msg' => $msg, 'error' => '',
            ), HOUR_IN_SECONDS );
        };

        if ( $result['ext'] === 'zip' ) {
            $import_result = bdopt_restore_from_zip( $final_path, $progress_cb );
        } else {
            bdopt_detect_and_replace_prefix( $final_path, function( $pct, $total, $msg ) {
                set_transient( 'bdopt_import_progress', array(
                    'status' => 'running', 'pct' => $pct, 'msg' => $msg, 'error' => '',
                ), HOUR_IN_SECONDS );
            } );
            $import_result = bdopt_import_sql( $final_path, $progress_cb );
        }

        @unlink( $final_path );
        $done_file = WP_CONTENT_DIR . '/cz-backups/.import-tmp/' . $upload_id . '.done';
        @unlink( $done_file );

        if ( $import_result['success'] ) {
            /* auto-detect old site URL from imported DB and migrate if needed */
            global $wpdb;
            $current_url = get_site_url();
            $imported_siteurl = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'siteurl' ) );

            if ( ! empty( $imported_siteurl ) && $imported_siteurl !== $current_url ) {
                set_transient( 'bdopt_import_progress', array(
                    'status' => 'running', 'pct' => 95, 'msg' => "Auto-migrating from {$imported_siteurl} to {$current_url}...", 'error' => '',
                ), HOUR_IN_SECONDS );
                $migrate_result = bdopt_migrate_domain( $imported_siteurl, $current_url, function( $pct, $total, $msg ) {
                    set_transient( 'bdopt_import_progress', array(
                        'status' => 'running', 'pct' => round( 95 + $pct * 0.05 ), 'msg' => $msg, 'error' => '',
                    ), HOUR_IN_SECONDS );
                } );
                if ( $migrate_result['success'] ) {
                    set_transient( 'bdopt_import_progress', array(
                        'status' => 'done', 'pct' => 100, 'msg' => "Import complete! {$import_result['queries']} queries executed. {$migrate_result['total']} URLs auto-migrated.", 'error' => '',
                    ), HOUR_IN_SECONDS );
                } else {
                    set_transient( 'bdopt_import_progress', array(
                        'status' => 'done', 'pct' => 100, 'msg' => "Import done, but auto-migration failed: {$migrate_result['error']}", 'error' => '',
                    ), HOUR_IN_SECONDS );
                }
            } else {
                set_transient( 'bdopt_import_progress', array(
                    'status' => 'done', 'pct' => 100, 'msg' => "Import complete! {$import_result['queries']} queries executed.", 'error' => '',
                ), HOUR_IN_SECONDS );
            }
        } else {
            set_transient( 'bdopt_import_progress', array(
                'status' => 'error', 'pct' => 0, 'msg' => '', 'error' => $import_result['error'],
            ), HOUR_IN_SECONDS );
        }
        exit;
    }

    wp_send_json_success( array( 'received' => $result['received'], 'total' => $result['total'] ) );
});

add_action('wp_ajax_bdopt_cancel_import', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    delete_transient( 'bdopt_import_progress' );

    foreach ( glob( WP_CONTENT_DIR . '/cz-backups/.import-tmp/*' ) as $f ) {
        @unlink( $f );
    }

    wp_send_json_success( array( 'message' => 'Import cancelled.' ) );
});

/* ── Orphan Media Scan ── */
add_action( 'wp_ajax_bdopt_orphan_scan', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    set_transient( 'bdopt_orphan_scan', 'started', 600 );
    wp_send_json_success( array( 'message' => 'Scan started.' ) );
});

add_action( 'wp_ajax_bdopt_orphan_status', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    $result = get_transient( 'bdopt_orphan_progress' );
    if ( ! $result ) {
        $stats = bdopt_get_orphan_media_stats();
        wp_send_json_success( $stats );
    }
    wp_send_json_success( $result );
});

add_action( 'wp_ajax_bdopt_orphan_process', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    ignore_user_abort( true );
    $progress = function( $pct, $total, $msg ) {
        set_transient( 'bdopt_orphan_progress', array( 'status' => 'running', 'pct' => $pct, 'msg' => $msg ), 600 );
    };
    $result = bdopt_scan_orphan_media( $progress );
    set_transient( 'bdopt_orphan_progress', array( 'status' => 'done', 'pct' => 100, 'msg' => "Found {$result['total']} files (" . size_format( $result['size'], 1 ) . ")", 'data' => $result ), 600 );
    wp_send_json_success( array( 'message' => 'Scan complete.', 'total' => $result['total'], 'size' => $result['size'] ) );
});

add_action( 'wp_ajax_bdopt_orphan_delete', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    $files_json = isset( $_POST['files_json'] ) ? wp_unslash( $_POST['files_json'] ) : '';
    $files = json_decode( $files_json, true );
    if ( ! is_array( $files ) || empty( $files ) ) {
        wp_send_json_error( array( 'message' => 'No files selected.' ) );
    }
    $result = bdopt_delete_orphan_media( $files );
    delete_transient( 'bdopt_orphan_progress' );
    delete_transient( 'bdopt_orphan_scan' );
    wp_send_json_success( $result );
});

/* ─── Table Check & Repair ─── */
add_action( 'wp_ajax_bdopt_check_tables', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    $repair = isset( $_POST['repair'] ) && $_POST['repair'] === '1';
    $result = bdopt_check_repair_tables( $repair );
    wp_send_json_success( $result );
});

/* ─── MySQL Process List ─── */
add_action( 'wp_ajax_bdopt_mysql_processes', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    $processes = bdopt_get_mysql_processes();
    wp_send_json_success( array( 'processes' => $processes, 'count' => count( $processes ) ) );
});

add_action( 'wp_ajax_bdopt_mysql_kill', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    $pid = isset( $_POST['pid'] ) ? (int) $_POST['pid'] : 0;
    $result = bdopt_kill_mysql_process( $pid );
    if ( $result['success'] ) wp_send_json_success( $result );
    else wp_send_json_error( $result );
});

/* ─── Activity Log ─── */
add_action( 'wp_ajax_bdopt_get_log', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    $log = bdopt_get_log( 100 );
    wp_send_json_success( array( 'log' => $log, 'count' => count( $log ) ) );
});

add_action( 'wp_ajax_bdopt_clear_log', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    bdopt_clear_log();
    wp_send_json_success( array( 'message' => 'Log cleared.' ) );
});

/* ─── Health Check ─── */
add_action( 'wp_ajax_bdopt_health_check', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    $checks = bdopt_health_check();
    $info = bdopt_system_info();
    $ms = bdopt_multisite_check();
    wp_send_json_success( array( 'checks' => $checks, 'info' => $info, 'multisite' => $ms ) );
});