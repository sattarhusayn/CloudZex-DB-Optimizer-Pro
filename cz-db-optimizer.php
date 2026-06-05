<?php
/**
 * Plugin Name: CloudZex DB Optimizer Pro
 * Plugin URI:  https://cloudzex.com
 * Description: Keep WordPress database optimized — Sessions, Transients, Action Scheduler, Revisions, Spam, Orphan Meta, Table Repair & more.
 * Version:     8.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * Author:      Abdus Sattar
 * Text Domain: cz-db-optimizer
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;
define( 'BDOPT_VERSION', '8.0.0' );

// ================================================================
// DEFAULTS
// ================================================================
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
    );
}

// ================================================================
// ACTIVATION / DEACTIVATION
// ================================================================
register_activation_hook( __FILE__, 'bdopt_activate' );
register_deactivation_hook( __FILE__, 'bdopt_deactivate' );

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

// ================================================================
// CRON
// ================================================================
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

// ================================================================
// SETTINGS HELPER
// ================================================================
function bdopt_s( $key, $default = 0 ) {
    $settings = wp_parse_args( (array) get_option( 'bdopt_settings', array() ), bdopt_defaults() );
    return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}
function bdopt_table_exists( $table ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

// ================================================================
// PERFORMANCE TWEAKS
//     Applied on every page load to reduce server load.
// ================================================================
function bdopt_perf_init() {
    $s = wp_parse_args( (array) get_option( 'bdopt_settings', array() ), bdopt_defaults() );

    // Heartbeat control — limit to post edit only
    if ( ! empty( $s['perf_heartbeat'] ) ) {
        add_action( 'init', function() {
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_deregister_script( 'heartbeat' );
            }
        }, 1 );
    }

    // Disable XML-RPC
    if ( ! empty( $s['perf_xmlrpc'] ) ) {
        add_filter( 'xmlrpc_enabled', '__return_false' );
    }

    // Disable self-pingbacks
    if ( ! empty( $s['perf_pingbacks'] ) ) {
        add_action( 'pre_ping', function( &$links ) {
            $home = get_option( 'home' );
            foreach ( $links as $i => $link ) {
                if ( strpos( $link, $home ) === 0 ) {
                    unset( $links[ $i ] );
                }
            }
        } );
    }

    // Remove query strings from static assets (better proxy/CDN cache)
    if ( ! empty( $s['perf_qs'] ) ) {
        add_filter( 'script_loader_src', 'bdopt_remove_qs', 15 );
        add_filter( 'style_loader_src',  'bdopt_remove_qs', 15 );
    }

    // Disable oEmbed discovery
    if ( ! empty( $s['perf_oembed'] ) ) {
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );
    }
}
add_action( 'plugins_loaded', 'bdopt_perf_init' );

function bdopt_remove_qs( $src ) {
    if ( strpos( $src, '?' ) !== false ) {
        $src = remove_query_arg( array( 'ver', 'version', 'v' ), $src );
    }
    return $src;
}

// ================================================================
// 1. EXPIRED WOOCOMMERCE SESSIONS
// ================================================================
function bdopt_clean_sessions() {
    global $wpdb;
    $t = $wpdb->prefix . 'woocommerce_sessions';
    if ( ! bdopt_table_exists( $t ) ) return 0;
    return (int) $wpdb->query( "DELETE FROM `{$t}` WHERE session_expiry < UNIX_TIMESTAMP()" );
}

// ================================================================
// 1b. WOOCOMMERCE ORDERS (by status + age)
// ================================================================
function bdopt_clean_orders( $days = 30, $statuses = array(), $from = '', $to = '', $id_from = 0, $id_to = 0 ) {
    if ( ! class_exists( 'WooCommerce' ) ) return 0;
    $days = max( 1, (int) $days );
    $id_from = max( 0, (int) $id_from );
    $id_to   = max( 0, (int) $id_to );
    if ( empty( $statuses ) ) {
        $saved = bdopt_s( 'order_statuses', 'completed,cancelled,refunded,failed' );
        $statuses = array_map( 'trim', explode( ',', $saved ) );
    }
    $raw_statuses = array_map( function($s) { return 'wc-' . trim( $s ); }, $statuses );

    // Build date query
    $date_q = array();
    if ( ! empty( $from ) || ! empty( $to ) ) {
        if ( ! empty( $from ) ) $date_q['after'] = $from;
        if ( ! empty( $to ) )   $date_q['before'] = $to;
        $date_q['inclusive'] = true;
    } else {
        $cut = time() - $days * DAY_IN_SECONDS;
        $date_q = '<' . $cut;
    }

    $page = 1;
    $ids  = array();
    do {
        $args = array(
            'status'       => $raw_statuses,
            'limit'        => 100,
            'page'         => $page,
            'return'       => 'ids',
            'date_created' => $date_q,
            'orderby'      => 'ID',
            'order'        => 'ASC',
        );
        $result = wc_get_orders( $args );
        if ( empty( $result ) ) break;
        // Filter by ID range if set
        foreach ( $result as $oid ) {
            if ( $id_from > 0 && $oid < $id_from ) continue;
            if ( $id_to > 0 && $oid > $id_to ) continue;
            $ids[] = $oid;
        }
        // Stop early if we've gone past id_to
        if ( $id_to > 0 && max( $result ) >= $id_to ) break;
        $page++;
    } while ( count( $result ) === 100 );

    $n = 0;
    foreach ( $ids as $id ) {
        $order = wc_get_order( (int) $id );
        if ( $order ) {
            $order->delete( true );
            $n++;
        }
    }
    return $n;
}

function bdopt_export_orders_csv( $days = 30, $statuses = array(), $from = '', $to = '', $id_from = 0, $id_to = 0 ) {
    if ( ! class_exists( 'WooCommerce' ) ) return '';
    $days = max( 1, (int) $days );
    $id_from = max( 0, (int) $id_from );
    $id_to   = max( 0, (int) $id_to );
    if ( empty( $statuses ) ) {
        $saved = bdopt_s( 'order_statuses', 'completed,cancelled,refunded,failed' );
        $statuses = array_map( 'trim', explode( ',', $saved ) );
    }
    $raw_statuses = array_map( function($s) { return 'wc-' . trim( $s ); }, $statuses );

    $date_q = array();
    if ( ! empty( $from ) || ! empty( $to ) ) {
        if ( ! empty( $from ) ) $date_q['after'] = $from;
        if ( ! empty( $to ) )   $date_q['before'] = $to;
        $date_q['inclusive'] = true;
    } else {
        $cut = time() - $days * DAY_IN_SECONDS;
        $date_q = '<' . $cut;
    }

    $page = 1;
    $ids  = array();
    do {
        $args = array(
            'status'       => $raw_statuses,
            'limit'        => 100,
            'page'         => $page,
            'return'       => 'ids',
            'date_created' => $date_q,
            'orderby'      => 'ID',
            'order'        => 'ASC',
        );
        $result = wc_get_orders( $args );
        if ( empty( $result ) ) break;
        foreach ( $result as $oid ) {
            if ( $id_from > 0 && $oid < $id_from ) continue;
            if ( $id_to > 0 && $oid > $id_to ) continue;
            $ids[] = $oid;
        }
        if ( $id_to > 0 && max( $result ) >= $id_to ) break;
        $page++;
    } while ( count( $result ) === 100 );

    if ( empty( $ids ) ) return '';

    $headers = array( 'Order ID', 'Status', 'Date', 'Total', 'Currency', 'Payment Method', 'Customer Name', 'Email', 'Phone', 'Items', 'Billing Address' );
    $rows    = array();
    foreach ( $ids as $oid ) {
        $order = wc_get_order( (int) $oid );
        if ( ! $order ) continue;
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        $addr = $order->get_billing_address_1();
        if ( $order->get_billing_address_2() ) $addr .= ', ' . $order->get_billing_address_2();
        if ( $order->get_billing_city() )      $addr .= ', ' . $order->get_billing_city();
        $rows[] = array(
            $order->get_id(),
            $order->get_status(),
            $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i:s' ) : '',
            $order->get_total(),
            $order->get_currency(),
            $order->get_payment_method_title(),
            $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            $order->get_billing_email(),
            $order->get_billing_phone(),
            implode( '; ', $items ),
            $addr,
        );
    }

    $csv = fopen( 'php://temp', 'r+' );
    fputcsv( $csv, $headers );
    foreach ( $rows as $row ) fputcsv( $csv, $row );
    rewind( $csv );
    $content = stream_get_contents( $csv );
    fclose( $csv );
    return $content;
}

// ================================================================
// 2. EXPIRED TRANSIENTS ONLY
//    Never deletes active (non-expired) transients.
// ================================================================
function bdopt_clean_transients() {
    global $wpdb;
    $t   = $wpdb->prefix . 'options';
    $now = time();

    $k1 = $wpdb->get_col( $wpdb->prepare(
        "SELECT SUBSTRING(option_name, 20) FROM `{$t}` WHERE option_name LIKE '_transient_timeout_%' AND CAST(option_value AS UNSIGNED) < %d",
        $now
    ) );
    $k2 = $wpdb->get_col( $wpdb->prepare(
        "SELECT SUBSTRING(option_name, 25) FROM `{$t}` WHERE option_name LIKE '_site_transient_timeout_%' AND CAST(option_value AS UNSIGNED) < %d",
        $now
    ) );

    $del = 0;
    foreach ( $k1 as $k ) {
        $k    = sanitize_key( $k );
        if ( ! $k ) continue;
        $del += (int) $wpdb->query(
            "DELETE FROM `{$t}` WHERE option_name IN ('_transient_timeout_{$k}','_transient_{$k}')"
        );
    }
    foreach ( $k2 as $k ) {
        $k    = sanitize_key( $k );
        if ( ! $k ) continue;
        $del += (int) $wpdb->query(
            "DELETE FROM `{$t}` WHERE option_name IN ('_site_transient_timeout_{$k}','_site_transient_{$k}')"
        );
    }
    return $del;
}

// ================================================================
// 3. ACTION SCHEDULER
//    - complete/failed/canceled older than X days
//    - past-due (pending whose scheduled_date_gmt is past by X days)
//    Uses UTC_TIMESTAMP() because scheduled_date_gmt is UTC.
// ================================================================
function bdopt_clean_actions( $days = 7 ) {
    global $wpdb;
    $t  = $wpdb->prefix . 'actionscheduler_actions';
    $lt = $wpdb->prefix . 'actionscheduler_logs';
    if ( ! bdopt_table_exists( $t ) ) return 0;
    $days = max( 1, (int) $days );

    $del = (int) $wpdb->query( $wpdb->prepare(
        "DELETE FROM `{$t}`
         WHERE ( status IN ('complete','failed','canceled')
                 AND scheduled_date_gmt < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY ) )
            OR ( status = 'pending'
                 AND scheduled_date_gmt < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY ) )",
        $days, $days
    ) );

    if ( $del > 0 && bdopt_table_exists( $lt ) ) {
        $wpdb->query(
            "DELETE l FROM `{$lt}` l
             LEFT JOIN `{$t}` a ON l.action_id = a.action_id
             WHERE a.action_id IS NULL
             LIMIT 10000"
        );
    }
    return $del;
}

// ================================================================
// 4. WOOCOMMERCE LOG FILES
// ================================================================
function bdopt_clean_logs( $days = 7 ) {
    if ( ! defined( 'WC_LOG_DIR' ) || ! is_dir( WC_LOG_DIR ) ) return 0;
    $files = glob( WC_LOG_DIR . '*.log' );
    if ( ! $files ) return 0;
    $cut = time() - max( 1, (int) $days ) * DAY_IN_SECONDS;
    $n   = 0;
    foreach ( $files as $f ) {
        if ( is_file( $f ) && filemtime( $f ) < $cut ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink( $f );
            $n++;
        }
    }
    return $n;
}

// ================================================================
// 5. POST REVISIONS  (keep last $keep per post)
//    Uses wp_delete_post_revision() so hooks + caches fire.
// ================================================================
function bdopt_clean_revisions( $keep = 3 ) {
    global $wpdb;
    $keep    = max( 0, (int) $keep );
    $parents = $wpdb->get_col(
        "SELECT post_parent FROM `{$wpdb->posts}` WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > $keep"
    );
    $del = 0;
    foreach ( $parents as $pid ) {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC",
            (int) $pid
        ) );
        foreach ( array_slice( $ids, $keep ) as $rid ) {
            if ( wp_delete_post_revision( (int) $rid ) ) {
                $del++;
            }
        }
    }
    return $del;
}

// ================================================================
// 6. AUTO-DRAFTS
//    wp_delete_post() cleans meta + caches properly.
// ================================================================
function bdopt_clean_autodraft() {
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT ID FROM `{$wpdb->posts}` WHERE post_status = 'auto-draft'"
    );
    $n = 0;
    foreach ( $ids as $id ) {
        if ( wp_delete_post( (int) $id, true ) ) $n++;
    }
    return $n;
}

// ================================================================
// 7. SPAM + TRASH COMMENTS
//    wp_delete_comment() updates comment counts.
// ================================================================
function bdopt_clean_spam_comments() {
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT comment_ID FROM `{$wpdb->comments}` WHERE comment_approved IN ('spam','trash')"
    );
    $n = 0;
    foreach ( $ids as $id ) {
        if ( wp_delete_comment( (int) $id, true ) ) $n++;
    }
    return $n;
}

// ================================================================
// 8. TRASHED POSTS
//    wp_delete_post() handles attachments + meta.
// ================================================================
function bdopt_clean_trashed() {
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT ID FROM `{$wpdb->posts}` WHERE post_status = 'trash'"
    );
    $n = 0;
    foreach ( $ids as $id ) {
        if ( wp_delete_post( (int) $id, true ) ) $n++;
    }
    return $n;
}

// ================================================================
// 9. ORPHAN META
//    Cleans postmeta, commentmeta AND usermeta orphans.
// ================================================================
function bdopt_clean_orphan_meta() {
    global $wpdb;
    $del  = (int) $wpdb->query(
        "DELETE pm FROM `{$wpdb->postmeta}` pm
         LEFT JOIN `{$wpdb->posts}` p ON pm.post_id = p.ID
         WHERE p.ID IS NULL"
    );
    $del += (int) $wpdb->query(
        "DELETE cm FROM `{$wpdb->commentmeta}` cm
         LEFT JOIN `{$wpdb->comments}` c ON cm.comment_id = c.comment_ID
         WHERE c.comment_ID IS NULL"
    );
    $del += (int) $wpdb->query(
        "DELETE um FROM `{$wpdb->usermeta}` um
         LEFT JOIN `{$wpdb->users}` u ON um.user_id = u.ID
         WHERE u.ID IS NULL"
    );
    return $del;
}

// ================================================================
// 9.5. OEMBED CACHE
//     Cleans oEmbed cached data from postmeta
// ================================================================
function bdopt_clean_oembed() {
    global $wpdb;
    return (int) $wpdb->query(
        "DELETE FROM `{$wpdb->postmeta}` WHERE meta_key LIKE '_oembed_%'"
    );
}

// ================================================================
// 9.6. PERSONAL DATA
//     Cleans old privacy & data removal requests + export files
// ================================================================
function bdopt_clean_personal_data() {
    global $wpdb;
    $n = 0;
    // Delete completed user_request posts older than 30 days
    $ids = $wpdb->get_col(
        "SELECT ID FROM `{$wpdb->posts}`
         WHERE post_type = 'user_request'
           AND post_status IN ('request-completed','request-confirmed')
           AND post_date < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    foreach ( $ids as $id ) {
        if ( wp_delete_post( (int) $id, true ) ) $n++;
    }
    // Delete old privacy export files
    $dir = WP_CONTENT_DIR . '/uploads/wp-privacy-export-files';
    if ( is_dir( $dir ) ) {
        $files = glob( $dir . '/*' );
        if ( $files ) {
            $cutoff = time() - 30 * DAY_IN_SECONDS;
            foreach ( $files as $f ) {
                if ( is_file( $f ) && filemtime( $f ) < $cutoff ) {
                    @unlink( $f );
                    $n++;
                }
            }
        }
    }
    return $n;
}

// ================================================================
// 10. OPTIMIZE TABLES
//     Both MyISAM & InnoDB → OPTIMIZE TABLE
//     Reclaims disk space after row deletion
// ================================================================
function bdopt_optimize_tables() {
    global $wpdb;
    $like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
    $tables = $wpdb->get_results( $wpdb->prepare(
        "SELECT table_name, engine
         FROM information_schema.TABLES
         WHERE table_schema = %s AND table_name LIKE %s",
        DB_NAME, $like
    ) );
    $n = 0;
    foreach ( $tables as $tbl ) {
        $name = $tbl->table_name;
        $res = $wpdb->query( "OPTIMIZE TABLE `{$name}`" );
        if ( $res !== false ) $n++;
    }
    return $n;
}


// 12. (removed - Unused Tables feature deleted)
// 1. Active plugin table scan (most accurate)
// 2. Known core/plugin whitelist (fallback)
// 3. Manual user-defined safe list
// ================================================================
// ================================================================
// RUN ALL CLEAN
// ================================================================
function bdopt_run_all_clean( $include_draft = false, $include_trashed = false ) {
    $r = array();
    if ( (int) bdopt_s('clean_sessions') )    $r['sessions']    = bdopt_clean_sessions();
    if ( (int) bdopt_s('clean_transients') )  $r['transients']  = bdopt_clean_transients();
    if ( (int) bdopt_s('clean_actions') )     $r['actions']     = bdopt_clean_actions( (int) bdopt_s('action_days',7) );
    if ( (int) bdopt_s('clean_logs') )        $r['logs']        = bdopt_clean_logs( (int) bdopt_s('log_days',7) );
    if ( (int) bdopt_s('clean_revisions') )   $r['revisions']   = bdopt_clean_revisions( (int) bdopt_s('revision_keep',3) );
    if ( $include_draft && (int) bdopt_s('clean_autodraft') )   $r['autodraft']   = bdopt_clean_autodraft();
    if ( (int) bdopt_s('clean_spam') )        $r['spam']        = bdopt_clean_spam_comments();
    if ( $include_trashed && (int) bdopt_s('clean_trashed') )   $r['trashed']     = bdopt_clean_trashed();
    if ( (int) bdopt_s('clean_orphan_meta') ) $r['orphan_meta'] = bdopt_clean_orphan_meta();
    $r['oembed'] = bdopt_clean_oembed();
    $r['personal_data'] = bdopt_clean_personal_data();
    if ( (int) bdopt_s('clean_orders') )      $r['orders']      = bdopt_clean_orders( (int) bdopt_s('order_days',30) );
    if ( (int) bdopt_s('optimize_tables') )   $r['optimized']   = bdopt_optimize_tables();
    update_option( 'bdopt_last_run', array( 'time' => current_time('mysql'), 'results' => $r ) );
    return $r;
}

// ================================================================
// DATA HELPERS
// ================================================================
function bdopt_get_db_size() {
    global $wpdb;
    $v = $wpdb->get_var( $wpdb->prepare(
        'SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) FROM information_schema.TABLES WHERE table_schema=%s',
        DB_NAME
    ) );
    return $v !== null ? (float) $v : 0;
}

function bdopt_get_db_breakdown() {
    global $wpdb;
    $like = $wpdb->esc_like( $wpdb->prefix ) . '%';
    return $wpdb->get_results( $wpdb->prepare(
        'SELECT table_name, ROUND((data_length+index_length)/1024/1024,3) AS size_mb,
                table_rows, engine
         FROM information_schema.TABLES
         WHERE table_schema=%s AND table_name LIKE %s
         ORDER BY (data_length+index_length) DESC LIMIT 30',
        DB_NAME, $like
    ), ARRAY_A ) ?: array();
}

function bdopt_get_counts() {
    global $wpdb;
    $d = array();

    // Sessions
    $t = $wpdb->prefix . 'woocommerce_sessions';
    $d['sessions'] = bdopt_table_exists($t)
        ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}` WHERE session_expiry < UNIX_TIMESTAMP()") : 0;

    // Orders (all statuses) — uses WC API for reliability
    $d['orders'] = 0;
    $d['order_statuses'] = array();
    if ( class_exists( 'WooCommerce' ) ) {
        $all_stat = wc_get_order_statuses();
        foreach ( $all_stat as $sk => $sl ) {
            $raw       = $sk;
            $sk        = str_replace( 'wc-', '', $sk );
            $count     = 0;
            // Prefer WC API (works with both HPOS & legacy)
            $result = wc_get_orders( array(
                'status'  => array( $raw ),
                'limit'   => 1,
                'paginate' => true,
                'return'  => 'ids',
            ) );
            if ( ! empty( $result ) && is_object( $result ) && isset( $result->total ) ) {
                $count = (int) $result->total;
            } else {
                // Fallback: direct SQL
                $count += (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = 'shop_order' AND post_status = %s",
                    'wc-' . $sk
                ) );
                $ht = $wpdb->prefix . 'wc_orders';
                if ( bdopt_table_exists( $ht ) ) {
                    $count += (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM `{$ht}` WHERE type = 'shop_order' AND status = %s",
                        $sk
                    ) );
                }
            }
            $d['order_statuses'][ $sk ] = $count;
            $d['orders'] += $count;
        }
    }

    // Expired transients
    $o = $wpdb->prefix . 'options'; $now = time();
    $d['transients'] =
        (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$o}` WHERE option_name LIKE '_transient_timeout_%' AND CAST(option_value AS UNSIGNED)<%d",$now)) +
        (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$o}` WHERE option_name LIKE '_site_transient_timeout_%' AND CAST(option_value AS UNSIGNED)<%d",$now));

    // Action Scheduler
    $t2 = $wpdb->prefix . 'actionscheduler_actions';
    if ( bdopt_table_exists($t2) ) {
        $d['as_complete'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$t2}` WHERE status='complete'");
        $d['as_failed']   = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$t2}` WHERE status='failed'");
        $d['as_canceled'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$t2}` WHERE status='canceled'");
        $d['as_past_due'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$t2}` WHERE status='pending' AND scheduled_date_gmt < UTC_TIMESTAMP()");
        $d['as_pending']  = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$t2}` WHERE status='pending' AND scheduled_date_gmt >= UTC_TIMESTAMP()");
    } else {
        $d['as_complete']=$d['as_failed']=$d['as_canceled']=$d['as_past_due']=$d['as_pending']=0;
    }

    // Log files
    $d['logs'] = 0;
    if ( defined('WC_LOG_DIR') && is_dir(WC_LOG_DIR) ) {
        $f = glob( WC_LOG_DIR . '*.log' );
        $d['logs'] = $f ? count($f) : 0;
    }

    // WordPress data
    $d['revisions']   = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type='revision'");
    $d['autodraft']   = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_status='auto-draft'");
    $d['spam']        = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->comments}` WHERE comment_approved IN('spam','trash')");
    $d['trashed']     = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_status='trash'");
    $d['orphan_meta'] = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM `{$wpdb->postmeta}` pm LEFT JOIN `{$wpdb->posts}` p ON pm.post_id=p.ID WHERE p.ID IS NULL"
    );
    $d['oembed'] = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE meta_key LIKE '_oembed_%'"
    );
    $d['personal_data'] = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM `{$wpdb->posts}`
         WHERE post_type = 'user_request'
           AND post_status IN ('request-completed','request-confirmed')
           AND post_date < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );

    return $d;
}

// ================================================================
// ADMIN MENU
// ================================================================
add_action('admin_menu', function() {
    add_menu_page(
        'CloudZex DB Optimizer Pro', 'DB Optimizer', 'manage_options',
        'cz-db-optimizer', 'bdopt_render_page',
        'dashicons-database-view', 80
    );
});

// ================================================================
// AJAX: CLEAN
// ================================================================
add_action('wp_ajax_bdopt_run_clean', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $allowed = array('sessions','transients','actions','logs','revisions','autodraft','spam','trashed','orphan_meta','oembed','personal_data','orders','optimize','all');
    $type    = sanitize_text_field( isset($_POST['type']) ? $_POST['type'] : 'all' );
    if ( ! in_array($type, $allowed, true) ) wp_die('Invalid type', 400);

    $count = 0;
    switch ( $type ) {
        case 'sessions':    $count = bdopt_clean_sessions(); break;
        case 'transients':  $count = bdopt_clean_transients(); break;
        case 'actions':     $count = bdopt_clean_actions( (int) bdopt_s('action_days',7) ); break;
        case 'logs':        $count = bdopt_clean_logs( (int) bdopt_s('log_days',7) ); break;
        case 'revisions':   $count = bdopt_clean_revisions( (int) bdopt_s('revision_keep',3) ); break;
        case 'autodraft':   $count = bdopt_clean_autodraft(); break;
        case 'spam':        $count = bdopt_clean_spam_comments(); break;
        case 'trashed':     $count = bdopt_clean_trashed(); break;
        case 'orphan_meta': $count = bdopt_clean_orphan_meta(); break;
        case 'oembed':      $count = bdopt_clean_oembed(); break;
        case 'personal_data': $count = bdopt_clean_personal_data(); break;
        case 'orders':
            $mDays = isset( $_POST['order_days'] ) ? (int) $_POST['order_days'] : (int) bdopt_s( 'order_days', 30 );
            $mStatuses = isset( $_POST['order_statuses'] ) ? array_map( 'trim', explode( ',', sanitize_text_field( $_POST['order_statuses'] ) ) ) : array();
            $mFrom    = isset( $_POST['order_from'] ) ? sanitize_text_field( $_POST['order_from'] ) : '';
            $mTo      = isset( $_POST['order_to'] ) ? sanitize_text_field( $_POST['order_to'] ) : '';
            $mIdFrom  = isset( $_POST['order_id_from'] ) ? (int) $_POST['order_id_from'] : 0;
            $mIdTo    = isset( $_POST['order_id_to'] ) ? (int) $_POST['order_id_to'] : 0;
            $count = bdopt_clean_orders( $mDays, $mStatuses, $mFrom, $mTo, $mIdFrom, $mIdTo );
            break;
        case 'optimize':    $count = bdopt_optimize_tables(); break;
        case 'all':
            $inc_draft   = ! empty( $_POST['include_draft'] );
            $inc_trashed = ! empty( $_POST['include_trashed'] );
            $r = bdopt_run_all_clean( $inc_draft, $inc_trashed );
            $count = array_sum($r);
            break;
    }

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

// ================================================================
// AJAX: OBJECT CACHE (ENABLE / DISABLE)
// ================================================================
add_action('wp_ajax_bdopt_cache_action', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $action = isset( $_POST['cache_action'] ) ? sanitize_text_field( $_POST['cache_action'] ) : '';
    $source = __DIR__ . '/inc/object-cache.php';
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
            // Try to add WP_CACHE to wp-config.php
            $config_file = ABSPATH . 'wp-config.php';
            if ( file_exists( $config_file ) && is_writable( $config_file ) ) {
                $content = file_get_contents( $config_file );
                if ( strpos( $content, 'WP_CACHE' ) === false ) {
                    $insert = "define('WP_CACHE', true);\n";
                    // Insert after opening <?php
                    $content = preg_replace( '/^<\?php\s*/', '<?php ' . "\n" . $insert, $content );
                    file_put_contents( $config_file, $content );
                }
            }
            wp_send_json_success( array( 'message' => 'Object cache enabled! Reloading...' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Failed to copy drop-in. Check permissions on wp-content/' ) );
        }
    } elseif ( $action === 'disable' ) {
        if ( file_exists( $target ) ) {
            if ( unlink( $target ) ) {
                // Remove WP_CACHE from wp-config.php
                $config_file = ABSPATH . 'wp-config.php';
                if ( file_exists( $config_file ) && is_writable( $config_file ) ) {
                    $content = file_get_contents( $config_file );
                    if ( strpos( $content, "define('WP_CACHE', true);" ) !== false ) {
                        $content = str_replace( "define('WP_CACHE', true);\n", '', $content );
                        $content = str_replace( "define('WP_CACHE', true);", '', $content );
                        file_put_contents( $config_file, $content );
                    }
                }
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

// ================================================================
// AJAX: PURGE CACHE
// ================================================================
add_action('wp_ajax_bdopt_purge_cache', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $cache = isset( $_POST['cache'] ) ? sanitize_text_field( $_POST['cache'] ) : '';
    $label = '';
    $ok    = false;

    switch ( $cache ) {
        case 'litespeed':
            $label = 'LiteSpeed Cache';
            if ( defined('LITESPEED_PURGE_ALL') ) {
                do_action( 'litespeed_purge_all' );
                $ok = true;
            }
            break;
        case 'w3tc':
            $label = 'W3 Total Cache';
            if ( function_exists('w3tc_flush_all') ) {
                w3tc_flush_all();
                $ok = true;
            } elseif ( function_exists('w3tc_pgcache_flush') ) {
                w3tc_pgcache_flush();
                $ok = true;
            }
            break;
        case 'wp-super':
            $label = 'WP Super Cache';
            if ( function_exists('wp_cache_clear_cache') ) {
                wp_cache_clear_cache();
                $ok = true;
            }
            break;
        case 'wp-rocket':
            $label = 'WP Rocket';
            if ( function_exists('rocket_clean_domain') ) {
                rocket_clean_domain();
                $ok = true;
            } elseif ( function_exists('rocket_clean_cache_domain') ) {
                rocket_clean_cache_domain();
                $ok = true;
            }
            break;
        case 'autoptimize':
            $label = 'Autoptimize';
            if ( class_exists('autoptimizeCache') ) {
                autoptimizeCache::clearall();
                $ok = true;
            }
            break;
        case 'swift':
            $label = 'Swift Performance';
            if ( class_exists('Swift_Performance_Cache') && method_exists('Swift_Performance_Cache', 'clear_all_cache') ) {
                Swift_Performance_Cache::clear_all_cache();
                $ok = true;
            }
            break;
        case 'breeze':
            $label = 'Breeze';
            if ( function_exists('breeze_cache_flush') ) {
                breeze_cache_flush();
                $ok = true;
            }
            break;
        case 'hummingbird':
            $label = 'Hummingbird';
            if ( class_exists('WP_Hummingbird') && method_exists('WP_Hummingbird', 'flush_cache') ) {
                WP_Hummingbird::flush_cache();
                $ok = true;
            }
            break;
        default:
            wp_send_json_error( array( 'message' => 'Unknown cache plugin.' ) );
            return;
    }

    if ( $ok ) {
        wp_send_json_success( array( 'message' => $label . ' cache purged!' ) );
    } else {
        wp_send_json_error( array( 'message' => $label . ' purge function not found.' ) );
    }
});

// ================================================================
// AJAX: SAVE SETTINGS
// ================================================================
add_action('wp_ajax_bdopt_save_settings', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);

    $freq = sanitize_text_field( isset($_POST['auto_frequency']) ? $_POST['auto_frequency'] : 'daily' );
    if ( ! in_array($freq, array('daily','twicedaily','weekly'), true) ) $freq = 'daily';

    $bools = array('auto_enabled','clean_sessions','clean_transients','clean_actions','clean_logs',
                   'clean_revisions','clean_autodraft','clean_spam','clean_trashed','clean_orphan_meta',
                   'clean_orders','optimize_tables',
                   'perf_heartbeat','perf_xmlrpc','perf_pingbacks','perf_qs','perf_oembed');
    $s = array(
        'auto_frequency' => $freq,
        'order_days'     => max(1, (int)(isset($_POST['order_days'])    ? $_POST['order_days']    : 30)),
        'order_statuses' => sanitize_text_field( isset($_POST['order_statuses']) ? $_POST['order_statuses'] : 'completed,cancelled,refunded,failed' ),
        'action_days'    => max(1, (int)(isset($_POST['action_days'])   ? $_POST['action_days']   : 7)),
        'log_days'       => max(1, (int)(isset($_POST['log_days'])      ? $_POST['log_days']      : 7)),
        'revision_keep'  => max(0, (int)(isset($_POST['revision_keep']) ? $_POST['revision_keep'] : 3)),
    );
    foreach ( $bools as $f ) {
        $s[$f] = (int)( isset($_POST[$f]) ? $_POST[$f] : 0 );
    }

    update_option('bdopt_settings', $s);
    bdopt_reschedule();
    wp_send_json_success(array('message' => 'Settings saved!'));
});

// ================================================================
// AJAX: DB BREAKDOWN + UNUSED TABLES
// ================================================================
add_action('wp_ajax_bdopt_get_breakdown', function() {
    check_ajax_referer('bdopt_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized', 403);
    wp_send_json_success(array(
        'tables' => bdopt_get_db_breakdown(),
    ));
});

// ================================================================
// RENDER PAGE
// ================================================================
function bdopt_render_page() {
    if ( ! current_user_can('manage_options') ) return;

    $s        = wp_parse_args( (array) get_option('bdopt_settings', array()), bdopt_defaults() );
    $last_run = get_option('bdopt_last_run', null);
    $db_size  = bdopt_get_db_size();
    $counts   = bdopt_get_counts();
    $nonce    = wp_create_nonce('bdopt_nonce');
    $next_run = wp_next_scheduled('bdopt_auto_clean');
    $af  = isset($s['auto_frequency'])   ? $s['auto_frequency']   : 'daily';
    $od  = (int)( isset($s['order_days'])     ? $s['order_days']     : 30 );
    $ad  = (int)( isset($s['action_days'])    ? $s['action_days']    : 7 );
    $ld  = (int)( isset($s['log_days'])       ? $s['log_days']       : 7 );
    $rk  = (int)( isset($s['revision_keep'])  ? $s['revision_keep']  : 3 );
    $os  = isset($s['order_statuses']) ? $s['order_statuses'] : 'completed,cancelled,refunded,failed';

    $freq_label = array('daily'=>'Daily','twicedaily'=>'Twice Daily','weekly'=>'Weekly');
    $chk = function($k) use ($s) { return !empty($s[$k]) ? 'checked' : ''; };

    ?>
<style>
#bdopt,#bdopt *{box-sizing:border-box;margin:0;padding:0}
#bdopt{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,sans-serif;font-size:13px;color:#1d2327;padding:22px 24px 60px 24px}

/* Page header */
#bdopt .pg-hd{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:22px}
#bdopt h1.pg-title{font-size:22px;font-weight:600;color:#1d2327;display:flex;align-items:center;gap:10px;line-height:1.3}
#bdopt h1.pg-title .dashicons{font-size:27px;color:#2271b1;flex-shrink:0}
#bdopt .ver{background:#f0f6fc;color:#2271b1;border:1px solid #c3d9f2;border-radius:3px;padding:2px 8px;font-size:11px;font-weight:700}

#bdopt .bdopt-ftr{text-align:center;padding:22px 0 0;font-size:12px;color:#646970;border-top:1px solid #e5e5e5;margin-top:8px}
#bdopt .bdopt-ftr .dashicons-cloud{color:#2271b1;font-size:16px;width:16px;height:16px;vertical-align:middle}
#bdopt .bdopt-ftr strong{color:#1d2327}
/* DB badge */
#bdopt .db-badge{background:linear-gradient(135deg,#2271b1,#135e96);color:#fff;border-radius:8px;padding:13px 22px;display:flex;align-items:center;gap:16px;box-shadow:0 2px 10px rgba(34,113,177,.28);cursor:pointer;transition:box-shadow .2s,transform .15s;user-select:none;border:none;font-family:inherit}
#bdopt .db-badge:hover{box-shadow:0 5px 18px rgba(34,113,177,.4);transform:translateY(-1px)}
#bdopt .db-num{font-size:34px;font-weight:700;line-height:1;transition:all .5s}
#bdopt .db-unit{font-size:12px;opacity:.8;margin-top:2px}
#bdopt .db-lbl{font-size:11px;opacity:.6;margin-top:4px;line-height:1.4}
/* Main tabs */
#bdopt .tabs{display:flex;flex-wrap:wrap;border-bottom:1px solid #c3c4c7;margin-bottom:22px}
#bdopt .tab{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;font-size:13px;font-weight:500;color:#646970;cursor:pointer;border:1px solid transparent;border-bottom:none;border-radius:4px 4px 0 0;background:none;margin-bottom:-1px;transition:all .15s;font-family:inherit;line-height:1.4}
#bdopt .tab:hover{color:#1d2327;background:#f6f7f7}
#bdopt .tab.active{background:#fff;border-color:#c3c4c7;color:#1d2327;font-weight:600}
#bdopt .tab .dashicons{font-size:16px;margin-top:1px}
#bdopt .tab .tbadge{min-width:18px;height:18px;padding:0 5px;background:#d63638;color:#fff;border-radius:9px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}
/* Panels */
#bdopt .panel{display:none}
#bdopt .panel.active{display:block}
/* Clean all btn */
#bdopt .btn-all{width:100%;display:flex;align-items:center;justify-content:center;gap:9px;padding:14px 20px;margin-bottom:22px;background:#2271b1;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s;font-family:inherit}
#bdopt .btn-all:hover:not(:disabled){background:#135e96}
#bdopt .btn-all:disabled{background:#8db8da;cursor:not-allowed}
#bdopt .btn-all .dashicons{font-size:20px;flex-shrink:0}
/* Boxes */
#bdopt .box{background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:18px;overflow:hidden}
#bdopt .box-hd{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid #f0f0f1;font-size:14px;font-weight:600;gap:10px}
#bdopt .box-hd-l{display:flex;align-items:center;gap:8px;color:#1d2327}
#bdopt .box-hd .dashicons{font-size:18px;color:#2271b1;flex-shrink:0}
/* Cards */
#bdopt .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;padding:16px}
@media(min-width:1440px){#bdopt .cards{grid-template-columns:repeat(4,1fr)}}
@media(max-width:960px){#bdopt .cards{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){#bdopt .cards{grid-template-columns:1fr}}
#bdopt .card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.05);display:flex;flex-direction:column;overflow:hidden;transition:box-shadow .2s}
#bdopt .card:hover{box-shadow:0 3px 10px rgba(0,0,0,.1)}
#bdopt .card-hd{display:flex;align-items:center;gap:10px;padding:12px 14px 10px;border-bottom:1px solid #f0f0f1}
#bdopt .card-ico{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--bg,#f0f6fc)}
#bdopt .card-ico .dashicons{font-size:17px;color:var(--cc,#2271b1)}
#bdopt .card-ttl{font-size:12px;font-weight:600;color:#1d2327;line-height:1.3}
#bdopt .card-body{padding:12px 14px 14px;flex:1;display:flex;flex-direction:column}
#bdopt .card-num{font-size:32px;font-weight:700;line-height:1;margin-bottom:5px;color:var(--cc,#2271b1);transition:all .3s}
#bdopt .card-desc{font-size:11px;color:#646970;margin-bottom:13px;line-height:1.5;flex:1}
#bdopt .card-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:7px 12px;border:1px solid var(--cc,#2271b1);border-radius:4px;color:var(--cc,#2271b1);font-size:11px;font-weight:600;cursor:pointer;background:transparent;transition:all .15s;width:100%;font-family:inherit}
#bdopt .card-btn:hover:not(:disabled){background:var(--bg,#f0f6fc)}
#bdopt .card-btn:disabled{opacity:.45;cursor:not-allowed}
#bdopt .card-btn .dashicons{font-size:14px;flex-shrink:0}
/* Card themes */
#bdopt .c-ses{--cc:#1a7d34;--bg:#edfaef} #bdopt .c-trn{--cc:#2271b1;--bg:#f0f6fc}
#bdopt .c-log{--cc:#b32d2e;--bg:#fce8e8} #bdopt .c-rev{--cc:#7c3aed;--bg:#f6f0ff}
#bdopt .c-dft{--cc:#b45309;--bg:#fff8ec} #bdopt .c-spm{--cc:#dc2626;--bg:#fff0f0}
#bdopt .c-tsh{--cc:#4b5563;--bg:#f3f4f6} #bdopt .c-orp{--cc:#a21caf;--bg:#fdf4ff}
#bdopt .c-opt{--cc:#059669;--bg:#ecfdf5}
#bdopt .c-oem{--cc:#a21caf;--bg:#fdf4ff}
#bdopt .c-pdr{--cc:#b45309;--bg:#fff8ec}
#bdopt .c-ok{--cc:#1a7d34;--bg:#edfaef}
#bdopt .c-warn{--cc:#b45309;--bg:#fff8ec}
/* AS block */
#bdopt .as-wrap{padding:16px 18px;display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap}
#bdopt .pills{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px}
#bdopt .pill{font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;display:inline-flex;align-items:center;gap:3px}
#bdopt .p-ok{background:#edfaef;color:#1a7d34} #bdopt .p-fail{background:#fce8e8;color:#b32d2e}
#bdopt .p-due{background:#fef3cd;color:#7a5c00} #bdopt .p-pend{background:#f0f6fc;color:#2271b1}
#bdopt .as-cnt{font-size:42px;font-weight:700;color:#7a5c00;line-height:1;flex-shrink:0}
#bdopt .as-cnt small{display:block;font-size:11px;color:#8c8f94;font-weight:400;margin-top:2px}
#bdopt .as-info{font-size:12px;color:#646970;line-height:1.6;flex:1}
#bdopt .as-info .safe{color:#1a7d34;font-weight:600}
#bdopt .as-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:1px solid #7a5c00;border-radius:5px;color:#7a5c00;font-size:12px;font-weight:600;cursor:pointer;background:transparent;transition:.15s;font-family:inherit;flex-shrink:0;align-self:flex-end}
#bdopt .as-btn:hover:not(:disabled){background:#fef3cd}
#bdopt .as-btn:disabled{opacity:.45;cursor:not-allowed}
/* Settings rows */
#bdopt .s-row{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid #f6f7f7;gap:16px;flex-wrap:wrap}
#bdopt .s-row:last-of-type{border-bottom:none}
#bdopt .s-lbl{font-size:13px;color:#1d2327;line-height:1.4;flex:1}
#bdopt .s-lbl small{display:block;color:#646970;font-size:11px;margin-top:2px}
#bdopt .s-ctrl{display:flex;align-items:center;gap:10px;flex-shrink:0}
#bdopt .day-lbl{font-size:12px;color:#646970;white-space:nowrap}
#bdopt select.sel,#bdopt input.num{border:1px solid #8c8f94;border-radius:4px;font-size:13px;background:#fff;color:#1d2327;font-family:inherit}
#bdopt select.sel{padding:5px 8px} #bdopt input.num{padding:5px 6px;width:60px;text-align:center}
#bdopt .save-row{padding:14px 18px;border-top:1px solid #f0f0f1;background:#f9f9f9;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
/* Toggle */
#bdopt .tog{position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0}
#bdopt .tog input{opacity:0;width:0;height:0;position:absolute}
#bdopt .tog-sl{position:absolute;inset:0;background:#8c8f94;border-radius:22px;cursor:pointer;transition:.2s}
#bdopt .tog-sl::before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.28)}
#bdopt .tog input:checked+.tog-sl{background:#2271b1}
#bdopt .tog input:checked+.tog-sl::before{transform:translateX(18px)}
/* Status */
#bdopt .stat-grid{display:grid;grid-template-columns:1fr 1fr 1fr}
@media(max-width:600px){#bdopt .stat-grid{grid-template-columns:1fr}}
#bdopt .stat-cell{padding:14px 18px;border-right:1px solid #f0f0f1}
#bdopt .stat-cell:last-child{border-right:none}
#bdopt .stat-lbl{font-size:10px;color:#8c8f94;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
#bdopt .stat-val{font-size:13px;color:#1d2327;line-height:1.6}
#bdopt .stat-val strong{color:#2271b1}
/* Breakdown */
#bdopt .brk-tbl{width:100%;border-collapse:collapse;font-size:12px}
#bdopt .brk-tbl th{background:#f6f7f7;padding:9px 14px;text-align:left;font-size:11px;color:#646970;font-weight:700;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e5e5e5;white-space:nowrap}
#bdopt .brk-tbl td{padding:9px 14px;border-bottom:1px solid #f6f7f7;vertical-align:middle}
#bdopt .brk-tbl tr:last-child td{border-bottom:none}
#bdopt .brk-tbl tbody tr:hover td{background:#f9fafb}
#bdopt .bar-wrap{width:120px;background:#e5e5e5;border-radius:10px;height:7px;overflow:hidden}
#bdopt .bar-fill{height:7px;border-radius:10px;background:linear-gradient(90deg,#2271b1,#00a0d2);transition:width .7s}
#bdopt .mono{font-family:Consolas,Monaco,"Courier New",monospace;font-size:11px;word-break:break-all}
#bdopt .num-cell{font-weight:600;font-size:12px;white-space:nowrap;text-align:right}
#bdopt .rank-cell{font-size:11px;color:#8c8f94;font-weight:700;text-align:center;width:30px}
#bdopt .engine-pill{font-size:10px;padding:2px 7px;border-radius:10px;font-weight:600}
#bdopt .ep-myisam{background:#fff8ec;color:#b45309}
#bdopt .ep-innodb{background:#f0f6fc;color:#2271b1}
#bdopt .ep-other{background:#f3f4f6;color:#6b7280}

/* Buttons */
#bdopt .btn-ref{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#f6f7f7;border:1px solid #c3c4c7;color:#1d2327;border-radius:5px;font-size:12px;font-weight:500;cursor:pointer;transition:.15s;font-family:inherit}
#bdopt .btn-ref:hover{background:#fff;border-color:#8c8f94}
#bdopt .btn-ref .dashicons{font-size:15px}
#btn-download-orders,#btn-manual-delete,#btn-orders-save,#btn-save,#btn-perf-save,#btn-cache-enable,#btn-cache-disable{padding-left:20px;padding-right:20px;height:36px}
#bdopt button .dashicons{font-size:16px;vertical-align:middle;width:16px;height:16px}
/* Loading */
#bdopt .loading-row{padding:36px 20px;text-align:center;color:#8c8f94;display:flex;align-items:center;justify-content:center;gap:10px;font-size:13px}
/* Order status grid */
#bdopt .s-ctrl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:6px;width:100%}
#bdopt .os-lbl{display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;border:1px solid #ddd;border-radius:4px;padding:4px 14px 4px 8px}
#bdopt .os-lbl:hover{border-color:#2271b1;background:#f6f7f7}
#bdopt .os-lbl span{color:#646970;font-size:10px;margin-left:auto;flex-shrink:0}
/* Toast */
#bdopt-notice{position:fixed;bottom:24px;right:24px;z-index:999999;background:#fff;border-left:4px solid #2271b1;border-radius:4px;box-shadow:0 4px 20px rgba(0,0,0,.15);padding:12px 20px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;max-width:340px;transform:translateY(90px);opacity:0;transition:all .35s cubic-bezier(.16,1,.3,1);pointer-events:none;font-family:inherit;color:#1d2327}
#bdopt-notice.show{transform:translateY(0);opacity:1}
#bdopt-notice.notice-err{border-color:#d63638}
#bdopt-notice .n-ico{font-size:18px;color:#2271b1;flex-shrink:0}
#bdopt-notice.notice-err .n-ico{color:#d63638}
#bdopt-notice .n-msg{line-height:1.4}
/* Spinner */
.bsp{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:bsp .65s linear infinite;vertical-align:middle;flex-shrink:0}
.bsp-d{border-color:rgba(0,0,0,.15);border-top-color:currentColor}
@keyframes bsp{to{transform:rotate(360deg)}}
</style>

<div id="bdopt">

<!-- PAGE HEADER -->
<div class="pg-hd">
    <h1 class="pg-title">
        <span class="dashicons dashicons-database-view"></span>
        CloudZex DB Optimizer Pro
        <span class="ver">v<?php echo esc_html(BDOPT_VERSION); ?></span>
    </h1>
    <button class="db-badge" id="badge-brk" type="button" title="View DB Breakdown">
        <div>
            <div class="db-num" id="bdopt-dbsize"><?php echo $db_size > 0 ? esc_html($db_size) : '0'; ?></div>
            <div class="db-unit">MB</div>
        </div>
        <div class="db-lbl">Database Size<br>Click &rarr; Breakdown</div>
    </button>
</div>

<!-- MAIN TABS -->
<div class="tabs" id="bdopt-tabs" role="tablist">
    <button class="tab active" type="button" data-panel="p-clean" role="tab" aria-selected="true">
        <span class="dashicons dashicons-superhero-alt"></span> Clean &amp; Optimize
    </button>
    <button class="tab" type="button" data-panel="p-orders" role="tab" aria-selected="false">
        <span class="dashicons dashicons-cart"></span> Orders
        <?php if ( ! empty($counts['orders']) && $counts['orders'] > 0 ): ?><span class="tbadge"><?php echo (int)$counts['orders']; ?></span><?php endif; ?>
    </button>
    <button class="tab" type="button" data-panel="p-breakdown" role="tab" aria-selected="false">
        <span class="dashicons dashicons-chart-bar"></span> DB Breakdown
    </button>
    <button class="tab" type="button" data-panel="p-performance" role="tab" aria-selected="false">
        <span class="dashicons dashicons-performance"></span> Performance
    </button>
    <button class="tab" type="button" data-panel="p-cache" role="tab" aria-selected="false">
        <span class="dashicons dashicons-database"></span> Object Cache
    </button>
    <button class="tab" type="button" data-panel="p-settings" role="tab" aria-selected="false">
        <span class="dashicons dashicons-admin-settings"></span> Settings
    </button>
</div>

<!-- ═══ PANEL: CLEAN ═══════════════════════════════════════════ -->
<div id="p-clean" class="panel active" role="tabpanel">
    <button class="btn-all" id="btn-all" type="button" data-type="all">
        <span class="dashicons dashicons-superhero-alt"></span>
        Clean &amp; Optimize All At Once
    </button>
    <div class="s-row" style="margin-bottom:18px;background:#f6f7f7;border:1px solid #e5e5e5;border-radius:6px;justify-content:center;gap:24px">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">Auto Drafts <span class="tog"><input type="checkbox" id="ca-draft"><span class="tog-sl"></span></span></label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">Trashed Posts <span class="tog"><input type="checkbox" id="ca-trashed"><span class="tog-sl"></span></span></label>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-cart"></span> WooCommerce</div></div>
        <div class="cards">
            <?php
            bdopt_card('sessions',   'groups',    'c-ses', 'Expired Sessions',   $counts['sessions'],   'Expired WooCommerce sessions');
            bdopt_card('transients', 'clock',     'c-trn', 'Expired Transients', $counts['transients'], 'Expired transient cache (active ones kept)');
            bdopt_card('logs',       'text',      'c-log', 'Log Files',          $counts['logs'],       "WooCommerce .log files (older than {$ld} days)");
            ?>
        </div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-calendar-alt"></span> Action Scheduler</div></div>
        <div class="as-wrap">
            <div style="flex:1;min-width:200px">
                <div class="pills">
                    <span class="pill p-ok">&#10003; Complete: <b><?php echo number_format($counts['as_complete']); ?></b></span>
                    <span class="pill p-fail">&#10007; Failed: <b><?php echo number_format($counts['as_failed']); ?></b></span>
                    <span class="pill p-due">&#9888; Past-due: <b id="cnt-pastdue"><?php echo number_format($counts['as_past_due']); ?></b></span>
                    <span class="pill p-pend">&#9654; Pending (future): <b id="cnt-aspend"><?php echo number_format($counts['as_pending']); ?></b></span>
                </div>
                <p class="as-info">
                    <b>Older than <?php echo $ad; ?> days</b> — complete / failed / past-due actions will be deleted.<br>
                    <span class="safe">&#10003; Future pending actions are safe — never deleted.</span>
                </p>
            </div>
            <div class="as-cnt">
                <span id="cnt-actions"><?php echo number_format($counts['as_complete']+$counts['as_failed']+$counts['as_past_due']); ?></span>
                <small>cleanable rows</small>
            </div>
            <button class="as-btn" type="button" data-type="actions">
                <span class="dashicons dashicons-trash"></span> Clean Actions
            </button>
        </div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-wordpress-alt"></span> WordPress Data</div></div>
        <div class="cards">
            <?php
            bdopt_card('revisions',   'backup',           'c-rev', 'Post Revisions',       $counts['revisions'],   "Keeps last {$rk}, deletes the rest");
            bdopt_card('autodraft',   'edit-page',        'c-dft', 'Auto Drafts',           $counts['autodraft'],   'WordPress auto-saved draft posts');
            bdopt_card('spam',        'shield-alt',       'c-spm', 'Spam & Trash Comments', $counts['spam'],        'All spam & trash comments');
            bdopt_card('trashed',     'trash',            'c-tsh', 'Trashed Posts',         $counts['trashed'],     'All posts & pages in trash');
            bdopt_card('orphan_meta', 'admin-site-alt3',  'c-orp', 'Orphan Meta',           $counts['orphan_meta'], 'Postmeta/commentmeta/usermeta with no parent');
            bdopt_card('oembed',      'video-alt3',       'c-oem', 'oEmbed Cache',          $counts['oembed'],      'Cached oEmbed data from postmeta');
            bdopt_card('personal_data', 'admin-users',    'c-pdr', 'Personal Data',          $counts['personal_data'], 'Old privacy/export requests (30+ days)');
            bdopt_card_opt();
            ?>
        </div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-performance"></span> Cache</div></div>
        <div class="cards">
            <?php
            $caches = array(
                'litespeed'  => array( 'LiteSpeed Cache',  defined('LITESPEED_PURGE_ALL') ),
                'w3tc'       => array( 'W3 Total Cache',   defined('W3TC') ),
                'wp-super'   => array( 'WP Super Cache',   defined('WPCACHEHOME') ),
                'wp-rocket'  => array( 'WP Rocket',        defined('WP_ROCKET_VERSION') ),
                'autoptimize'=> array( 'Autoptimize',      defined('AUTOPTIMIZE_PLUGIN_VERSION') ),
                'swift'      => array( 'Swift Performance', defined('SWIFT_PERFORMANCE_VERSION') ),
                'breeze'     => array( 'Breeze',           defined('BREEZE_VERSION') ),
                'hummingbird'=> array( 'Hummingbird',      defined('WPHB_VERSION') ),
            );
            $found = false;
            foreach ( $caches as $key => $item ) {
                if ( $item[1] ) {
                    $found = true;
                    echo '<div class="card c-ok"><div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-yes-alt" style="font-size:18px;color:#1a7d34"></span></div><div class="card-ttl">' . esc_html( $item[0] ) . '</div></div>';
                    echo '<div class="card-body"><div style="font-size:12px;color:#646970;margin-bottom:13px;line-height:1.5">Active &amp; running</div>';
                    echo '<button class="card-btn" type="button" data-cache="' . esc_attr( $key ) . '" style="--cc:#2271b1;--bg:#f0f6fc"><span class="dashicons dashicons-trash"></span> Purge Cache</button></div></div>';
                }
            }
            if ( ! $found ) {
                echo '<div class="card"><div class="card-body" style="padding:20px;text-align:center;color:#8c8f94;font-size:12px">No supported cache plugin detected.</div></div>';
            }
            ?>
        </div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-info-outline"></span> Status</div></div>
        <div class="stat-grid">
            <div class="stat-cell">
                <div class="stat-lbl">Last Clean</div>
                <div class="stat-val">
                    <?php
                    if ($last_run) {
                        echo esc_html($last_run['time']);
                        if (!empty($last_run['results'])) {
                            $t = array_sum(array_map('intval',$last_run['results']));
                            echo ' &mdash; <strong>'.esc_html($t).' item</strong> cleaned';
                        }
                    } else echo '<span style="color:#8c8f94">Not cleaned yet</span>';
                    ?>
                </div>
            </div>
            <div class="stat-cell">
                <div class="stat-lbl">Next Auto Clean</div>
                <div class="stat-val">
                    <?php
                    if ($next_run) {
                        echo esc_html(get_date_from_gmt(date('Y-m-d H:i:s',$next_run),'Y-m-d H:i:s'));
                        $lbl = isset($freq_label[$af]) ? $freq_label[$af] : $af;
                        echo ' <span style="color:#646970">('.esc_html($lbl).')</span>';
                    } else echo '<span style="color:#8c8f94">Auto clean is off</span>';
                    ?>
                </div>
            </div>
            <div class="stat-cell">
                <div class="stat-lbl">Object Cache</div>
                <div class="stat-val">
                    <?php if ( wp_using_ext_object_cache() ) : ?>
                        <strong style="color:#1a7d34">&#10003; Enabled</strong> &mdash; <span style="color:#646970">Redis / Memcached detected</span>
                    <?php else : ?>
                        <span style="color:#b32d2e">Not detected</span> &mdash; <span style="color:#646970">Use <a href="https://redis.io/" target="_blank">Redis</a> to reduce DB load</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div><!-- /p-clean -->

<!-- ═══ PANEL: ORDERS ══════════════════════════════════════════ -->
<div id="p-orders" class="panel" role="tabpanel">

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-cart"></span> Manual Delete — Filter &amp; Delete Now</div></div>
        <div class="s-row">
            <div class="s-lbl">Days <small>Delete orders older than how many days</small></div>
            <div class="s-ctrl">
                <input type="number" class="num" id="m-order-days" value="<?php echo esc_attr($od); ?>" min="1" max="365">
                <span class="day-lbl">days</span>
            </div>
        </div>
        <div class="s-row">
            <div class="s-lbl">Date Range <small>Leave days empty and set from/to for a specific date range</small></div>
            <div class="s-ctrl" style="gap:6px;flex-wrap:wrap">
                <label style="font-size:12px;display:flex;align-items:center;gap:4px">
                    From <input type="date" class="num" id="m-order-from" style="width:auto">
                </label>
                <label style="font-size:12px;display:flex;align-items:center;gap:4px">
                    To <input type="date" class="num" id="m-order-to" style="width:auto">
                </label>
            </div>
        </div>
        <div class="s-row">
            <div class="s-lbl">Order ID Range <small>Filter by a specific order ID range</small></div>
            <div class="s-ctrl" style="gap:6px;flex-wrap:wrap">
                <label style="font-size:12px;display:flex;align-items:center;gap:4px">
                    From ID <input type="number" class="num" id="m-order-id-from" style="width:100px" min="1" placeholder="e.g. 1000">
                </label>
                <label style="font-size:12px;display:flex;align-items:center;gap:4px">
                    To ID <input type="number" class="num" id="m-order-id-to" style="width:100px" min="1" placeholder="e.g. 5000">
                </label>
            </div>
        </div>
        <div class="s-row">
            <div class="s-lbl">Statuses <small>Select which order statuses to filter</small></div>
            <div class="s-ctrl s-ctrl-grid">
                <?php
                $os_counts  = isset( $counts['order_statuses'] ) ? $counts['order_statuses'] : array();
                $all_stat   = class_exists( 'WooCommerce' ) ? wc_get_order_statuses() : array();
                foreach ( $all_stat as $sk => $sl ) {
                    $sk      = str_replace( 'wc-', '', $sk );
                    $cnt     = isset( $os_counts[ $sk ] ) ? (int) $os_counts[ $sk ] : 0;
                    echo '<label class="os-lbl">';
                    echo '<input type="checkbox" class="m-os-cb" value="' . esc_attr( $sk ) . '" checked style="margin:0"> ';
                    echo esc_html( $sl ) . ' <span>(' . number_format( $cnt ) . ')</span></label>';
                }
                ?>
            </div>
        </div>
        <div class="save-row" style="justify-content:space-between">
            <span style="font-size:12px;color:#646970">Total <strong id="m-filter-total"><?php echo number_format( (int) $counts['orders'] ); ?></strong> orders match filter</span>
            <div style="display:flex;gap:8px">
                    <button class="button button-secondary" type="button" id="btn-download-orders" style="border-color:#2271b1">
                        <span class="dashicons dashicons-download"></span> Download CSV
                </button>
                    <button class="button button-primary" type="button" id="btn-manual-delete" style="background:#d63638;border-color:#d63638">
                        <span class="dashicons dashicons-trash"></span> Delete Selected Orders
                </button>
            </div>
        </div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-admin-settings"></span> Auto Delete Settings</div></div>
        <div class="s-row">
            <div class="s-lbl">Days <small>Auto delete orders older than how many days</small></div>
            <div class="s-ctrl">
                <input type="number" class="num" id="s-order-days" value="<?php echo esc_attr($od); ?>" min="1" max="365">
                <span class="day-lbl">days</span>
            </div>
        </div>
        <div class="s-row">
            <div class="s-lbl">Statuses <small>Which order statuses to auto delete</small></div>
            <div class="s-ctrl s-ctrl-grid">
                <?php
                $saved_statuses = array_map( 'trim', explode( ',', $os ) );
                foreach ( $all_stat as $sk => $sl ) {
                    $sk      = str_replace( 'wc-', '', $sk );
                    $checked = in_array( $sk, $saved_statuses, true ) ? 'checked' : '';
                    $cnt     = isset( $os_counts[ $sk ] ) ? (int) $os_counts[ $sk ] : 0;
                    echo '<label class="os-lbl">';
                    echo '<input type="checkbox" class="s-os-cb" value="' . esc_attr( $sk ) . '" ' . $checked . ' style="margin:0"> ';
                    echo esc_html( $sl ) . ' <span>(' . number_format( $cnt ) . ')</span></label>';
                }
                ?>
            </div>
        </div>
        <div class="s-row">
            <div class="s-lbl">Auto Delete On <small>Automatically delete old orders on schedule</small></div>
            <div class="s-ctrl">
                <label class="tog"><input type="checkbox" id="s-orders" <?php echo $chk('clean_orders'); ?>><span class="tog-sl"></span></label>
            </div>
        </div>
        <div class="save-row" style="justify-content:flex-end">
            <button class="button button-primary" type="button" id="btn-orders-save">Save Auto Settings</button>
        </div>
    </div>
</div>

<!-- ═══ PANEL: DB BREAKDOWN ════════════════════════════════════ -->
<div id="p-breakdown" class="panel" role="tabpanel">
    <div class="box">
        <div class="box-hd">
            <div class="box-hd-l"><span class="dashicons dashicons-chart-bar"></span> Database Table Breakdown (Top 30)</div>
            <button class="btn-ref" type="button" id="btn-brk-ref"><span class="dashicons dashicons-update"></span> Refresh</button>
        </div>
        <div id="brk-body"><div class="loading-row"><span class="bsp bsp-d"></span> Loading...</div></div>
    </div>
</div>


<!-- ═══ PANEL: PERFORMANCE ══════════════════════════════════ -->
<div id="p-performance" class="panel" role="tabpanel">
    <?php
    $server_load  = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : array( 0, 0, 0 );
    $memory_usage = function_exists( 'memory_get_peak_usage' ) ? memory_get_peak_usage( true ) : 0;
    $disk_free    = function_exists( 'disk_free_space' ) ? disk_free_space( WP_CONTENT_DIR ) : 0;
    $disk_total   = function_exists( 'disk_total_space' ) ? disk_total_space( WP_CONTENT_DIR ) : 1;
    $disk_pct     = $disk_total > 0 ? round( ( $disk_total - $disk_free ) / $disk_total * 100, 1 ) : 0;
    $opcache      = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
    ?>
    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-chart-area"></span> Server Resources</div></div>
        <div class="cards">
            <div class="card c-<?php echo $server_load[0] < 1 ? 'ok' : 'warn'; ?>">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-admin-generic"></span></div><div class="card-ttl">CPU Load (1 min)</div></div>
                <div class="card-body">
                    <div class="card-num" style="font-size:28px"><?php echo esc_html( number_format( $server_load[0], 2 ) ); ?></div>
                    <div class="card-desc">Load average (1 / 5 / 15 min)</div>
                    <div style="font-size:11px;color:#646970"><?php echo esc_html( number_format( $server_load[1], 2 ) ); ?> &middot; <?php echo esc_html( number_format( $server_load[2], 2 ) ); ?></div>
                </div>
            </div>
            <div class="card c-<?php echo $memory_usage < 128 * 1024 * 1024 ? 'ok' : 'warn'; ?>">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-memory"></span></div><div class="card-ttl">Memory</div></div>
                <div class="card-body">
                    <div class="card-num" style="font-size:28px"><?php echo esc_html( size_format( $memory_usage, 1 ) ); ?></div>
                    <div class="card-desc">Peak memory (this request)</div>
                    <div style="font-size:11px;color:#646970"><?php echo esc_html( ini_get( 'memory_limit' ) ? ini_get( 'memory_limit' ) : '?'); ?> limit</div>
                </div>
            </div>
            <div class="card c-<?php echo $disk_pct < 80 ? 'ok' : 'warn'; ?>">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-storage"></span></div><div class="card-ttl">Disk Usage</div></div>
                <div class="card-body">
                    <div class="card-num" style="font-size:28px"><?php echo esc_html( $disk_pct ); ?>%</div>
                    <div class="card-desc"><?php echo esc_html( size_format( $disk_free, 1 ) ); ?> free of <?php echo esc_html( size_format( $disk_total, 1 ) ); ?></div>
                </div>
            </div>
            <div class="card c-<?php echo $opcache !== false ? 'ok' : 'warn'; ?>">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-performance"></span></div><div class="card-ttl">Opcache</div></div>
                <div class="card-body">
                    <div class="card-num" style="font-size:28px"><?php echo $opcache !== false ? '&#10003;' : '&#10007;'; ?></div>
                    <div class="card-desc">PHP opcode cache<br><?php echo $opcache !== false ? 'Running' : 'Not enabled'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-admin-tools"></span> Performance Tweaks</div></div>
        <?php
        bdopt_setting_toggle('s-perf-heartbeat','Limit Heartbeat','Disable WP Heartbeat for non-admin visitors (saves CPU)',$chk('perf_heartbeat'));
        bdopt_setting_toggle('s-perf-xmlrpc','Disable XML-RPC','Block XML-RPC requests (reduces attack surface &amp; load)',$chk('perf_xmlrpc'));
        bdopt_setting_toggle('s-perf-pingbacks','Disable Self-Pingbacks','Prevent your own site from pinging itself',$chk('perf_pingbacks'));
        bdopt_setting_toggle('s-perf-qs','Remove Query Strings','Remove ?ver= from CSS/JS URLs (better proxy/CDN cache)',$chk('perf_qs'));
        bdopt_setting_toggle('s-perf-oembed','Disable oEmbed Discovery','Remove oEmbed discovery links from &lt;head&gt;',$chk('perf_oembed'));
        ?>
        <div class="save-row" style="justify-content:flex-end">
            <button class="button button-primary" type="button" id="btn-perf-save">Save Settings</button>
        </div>
    </div>
</div><!-- /p-performance -->

<!-- ═══ PANEL: OBJECT CACHE ═══════════════════════════════════ -->
<div id="p-cache" class="panel" role="tabpanel">
    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-database"></span> Object Cache &mdash; Redis</div></div>
        <?php
        $redis_ext    = extension_loaded('redis');
        $redis_class  = class_exists('Redis');
        $dropin       = file_exists(WP_CONTENT_DIR . '/object-cache.php');
        $wp_cache     = defined('WP_CACHE') && WP_CACHE;
        $redis_avail  = $redis_ext && $redis_class;
        $active       = $dropin && $wp_cache;
        $host         = defined('BDOPT_REDIS_HOST') ? BDOPT_REDIS_HOST : '127.0.0.1';
        $port         = defined('BDOPT_REDIS_PORT') ? BDOPT_REDIS_PORT : 6379;
        ?>
        <div class="cards">
            <div class="card c-ok">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-yes-alt"></span></div><div class="card-ttl">PHP Extension</div></div>
                <div class="card-body">
                    <div class="card-num" style="font-size:26px"><?php echo $redis_avail ? '&#10003;' : '&#10007;'; ?></div>
                    <div class="card-desc">Redis PHP extension<br><?php echo $redis_avail ? 'Detected' : 'Not installed or not enabled'; ?></div>
                </div>
            </div>
            <div class="card c-<?php echo $dropin ? 'ok' : 'warn'; ?>">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-admin-plugins"></span></div><div class="card-ttl">Drop-in</div></div>
                <div class="card-body">
                    <div class="card-num" style="font-size:26px"><?php echo $dropin ? '&#10003;' : '&#10007;'; ?></div>
                    <div class="card-desc">object-cache.php<br><?php echo $dropin ? 'Installed' : 'Not installed'; ?></div>
                </div>
            </div>
            <div class="card c-<?php echo $wp_cache ? 'ok' : 'warn'; ?>">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-admin-generic"></span></div><div class="card-ttl">WP_CACHE</div></div>
                <div class="card-body">
                    <div class="card-num" style="font-size:26px"><?php echo $wp_cache ? '&#10003;' : '&#10007;'; ?></div>
                    <div class="card-desc">wp-config.php constant<br><?php echo $wp_cache ? 'Enabled' : 'Not set in wp-config.php'; ?></div>
                </div>
            </div>
            <div class="card c-<?php echo $active ? 'ok' : 'warn'; ?>">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-performance"></span></div><div class="card-ttl">Status</div></div>
                <div class="card-body">
                    <div class="card-num" style="font-size:26px"><?php echo $active ? 'Active' : 'Inactive'; ?></div>
                    <div class="card-desc"><?php echo $active ? 'Object cache is running' : 'Not fully active'; ?></div>
                </div>
            </div>
        </div>
        <div id="cache-action-area" style="padding:0 18px 18px">
            <?php if ( ! $redis_avail ) : ?>
                <div class="s-row" style="border:1px solid #f0c;border-radius:6px;background:#fef3cd;padding:14px 18px;margin:0">
                    <span style="font-size:13px;font-weight:600;color:#7a5c00">&#9888; Redis PHP extension not detected.</span>
                    <span style="font-size:12px;color:#7a5c00">Contact your hosting provider to enable the <code>redis</code> PHP extension.</span>
                </div>
            <?php elseif ( $active ) : ?>
                <div class="s-row" style="border:1px solid #c3c4c7;border-radius:6px;background:#f6f7f7;padding:14px 18px;margin:0">
                    <span style="font-size:13px;font-weight:600;color:#1a7d34">&#10003; Object cache is active</span>
                    <span style="font-size:12px;color:#646970">Connected to Redis at <?php echo esc_html($host); ?>:<?php echo esc_html($port); ?></span>
                </div>
                <div style="margin-top:12px">
                    <button class="button button-secondary" type="button" id="btn-cache-disable" style="color:#d63638;border-color:#d63638">
                        <span class="dashicons dashicons-no"></span> Disable Object Cache
                    </button>
                </div>
            <?php elseif ( $redis_avail && ! $dropin ) : ?>
                <div class="s-row" style="border:1px solid #c3c4c7;border-radius:6px;background:#f6f7f7;padding:14px 18px;margin:0">
                    <span style="font-size:13px;color:#1d2327">Redis is available. Click below to enable object cache.</span>
                </div>
                <div style="margin-top:12px">
                    <button class="button button-primary" type="button" id="btn-cache-enable">
                        <span class="dashicons dashicons-yes"></span> Enable Object Cache
                    </button>
                </div>
            <?php elseif ( $redis_avail && $dropin && ! $wp_cache ) : ?>
                <div class="s-row" style="border:1px solid #f0c;border-radius:6px;background:#fef3cd;padding:14px 18px;margin:0">
                    <span style="font-size:13px;font-weight:600;color:#7a5c00">&#9888; Drop-in exists but WP_CACHE is not set.</span>
                    <span style="font-size:12px;color:#7a5c00">Add <code>define('WP_CACHE', true);</code> to your <code>wp-config.php</code></span>
                </div>
            <?php endif; ?>
            <div id="cache-msg" style="margin-top:10px;font-size:12px;color:#646970;display:none"></div>
        </div>
    </div>
</div><!-- /p-cache -->

<!-- ═══ PANEL: SETTINGS ════════════════════════════════════════ -->
<div id="p-settings" class="panel" role="tabpanel">
    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-admin-settings"></span> Auto Clean Settings</div></div>
        <?php
        bdopt_setting_toggle('s-auto','Enable Auto Clean','Automatically clean on schedule',$chk('auto_enabled'));
        ?>
        <div class="s-row">
            <div class="s-lbl">Clean every</div>
            <div class="s-ctrl">
                <select id="s-freq" class="sel">
                    <option value="daily"      <?php selected($af,'daily'); ?>>Daily</option>
                    <option value="twicedaily" <?php selected($af,'twicedaily'); ?>>Twice Daily</option>
                    <option value="weekly"     <?php selected($af,'weekly'); ?>>Weekly</option>
                </select>
            </div>
        </div>
        <?php bdopt_setting_toggle('s-sessions','Expired Sessions','Delete expired WooCommerce sessions',$chk('clean_sessions')); ?>
        <?php bdopt_setting_toggle('s-transients','Expired Transients','Delete expired transient cache (keep active)',$chk('clean_transients')); ?>
        <div class="s-row">
            <div class="s-lbl">Action Scheduler <small>Delete complete, failed &amp; past-due actions</small></div>
            <div class="s-ctrl">
                <input type="number" class="num" id="s-action-days" value="<?php echo esc_attr($ad); ?>" min="1" max="90">
                <span class="day-lbl">days</span>
                <label class="tog"><input type="checkbox" id="s-actions" <?php echo $chk('clean_actions'); ?>><span class="tog-sl"></span></label>
            </div>
        </div>
        <div class="s-row">
            <div class="s-lbl">Log Files <small>Delete old WooCommerce .log files</small></div>
            <div class="s-ctrl">
                <input type="number" class="num" id="s-log-days" value="<?php echo esc_attr($ld); ?>" min="1" max="365">
                <span class="day-lbl">days</span>
                <label class="tog"><input type="checkbox" id="s-logs" <?php echo $chk('clean_logs'); ?>><span class="tog-sl"></span></label>
            </div>
        </div>
        <div class="s-row">
            <div class="s-lbl">Post Revisions <small>How many revisions to keep per post</small></div>
            <div class="s-ctrl">
                <input type="number" class="num" id="s-rev-keep" value="<?php echo esc_attr($rk); ?>" min="0" max="50">
                <span class="day-lbl">keep</span>
                <label class="tog"><input type="checkbox" id="s-revisions" <?php echo $chk('clean_revisions'); ?>><span class="tog-sl"></span></label>
            </div>
        </div>
        <?php
        bdopt_setting_toggle('s-autodraft','Auto Drafts','Delete WordPress auto-saved draft posts',$chk('clean_autodraft'));
        bdopt_setting_toggle('s-spam','Spam &amp; Trash Comments','Delete all spam &amp; trash comments',$chk('clean_spam'));
        bdopt_setting_toggle('s-trashed','Trashed Posts','Delete all posts &amp; pages in trash',$chk('clean_trashed'));
        bdopt_setting_toggle('s-orphan','Orphan Meta','Delete postmeta/commentmeta/usermeta with no parent',$chk('clean_orphan_meta'));
        bdopt_setting_toggle('s-optimize','Table Optimization','Rebuilds tables &amp; reclaims disk space (MyISAM + InnoDB)',$chk('optimize_tables'));
        ?>
        <div class="save-row" style="justify-content:flex-end">
            <button class="button button-primary" type="button" id="btn-save">Save Settings</button>
        </div>
    </div>
</div><!-- /p-settings -->

</div><!-- /#bdopt -->

<div class="bdopt-ftr">
    <span class="dashicons dashicons-cloud"></span>
    <a href="https://cloudzex.com/" target="_blank" rel="noopener noreferrer"><strong>CloudZex</strong></a> &mdash; Server &amp; Database Optimization
</div>

<div id="bdopt-notice" role="alert" aria-live="polite">
    <span class="dashicons dashicons-yes-alt n-ico"></span>
    <span class="n-msg"></span>
</div>

<script>
(function(){
'use strict';
var NONCE=<?php echo wp_json_encode($nonce); ?>;
var AJAX=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
var brkLoaded=false;

/* ─── GENERIC POST ─────────────────────────────────────── */
function xpost(data, ok, err){
    fetch(AJAX,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)})
    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(ok)
    .catch(err||function(e){ toast('Network Error!',true); console.error(e); });
}

/* ─── MAIN TAB SWITCHING ───────────────────────────────── */
function switchTab(pid){
    var btn=document.querySelector('#bdopt-tabs .tab[data-panel="'+pid+'"]');
    if(!btn) return;
    document.querySelectorAll('#bdopt .tab').forEach(function(t){ t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
    document.querySelectorAll('#bdopt .panel').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active'); btn.setAttribute('aria-selected','true');
    var pel=document.getElementById(pid);
    if(pel) pel.classList.add('active');
    if(pid==='p-breakdown'&&!brkLoaded){ brkLoaded=true; loadBreakdown(); }
}
document.getElementById('bdopt-tabs').addEventListener('click',function(e){
    var btn=e.target.closest('.tab');
    if(!btn) return;
    var pid=btn.dataset.panel;
    if(history.replaceState) history.replaceState(null,'','#'+pid);
    else location.hash='#'+pid;
    switchTab(pid);
});
// Restore tab from hash on load (without scroll)
if(location.hash){
    var pid=location.hash.replace('#','');
    switchTab(pid);
    // Scroll to top of plugin container instead of the panel
    var ct=g('bdopt');
    if(ct&&ct.getBoundingClientRect().top<0) ct.scrollIntoView(true);
}

/* DB badge → breakdown tab */
document.getElementById('badge-brk').addEventListener('click',function(){
    var t=document.querySelector('#bdopt-tabs .tab[data-panel="p-breakdown"]');
    if(t) t.click();
});


/* ─── CLEAN delegation ──────────────────────────────────── */
document.getElementById('bdopt').addEventListener('click',function(e){
    var el=e.target.closest('[data-type]');
    if(!el||el.disabled) return;
    var type=el.dataset.type;
    if(!type) return;
    runClean(type,el);
});

function runClean(type,btn){
    var isAll=(btn.id==='btn-all');
    var orig=btn.innerHTML;
    btn.disabled=true;
    btn.innerHTML=isAll?'<span class="bsp"></span> Cleaning...':'<span class="bsp bsp-d"></span> ...';
    var data={action:'bdopt_run_clean',nonce:NONCE,type:type};
    if(isAll){
        data.include_draft=g('ca-draft').checked?1:0;
        data.include_trashed=g('ca-trashed').checked?1:0;
    }
    xpost(data,
    function(res){
        btn.disabled=false; btn.innerHTML=orig;
        if(res.success){
            var d=res.data;
            if(d.counts) syncCounts(d.counts);
            if(d.db_size!=null) g('bdopt-dbsize').textContent=d.db_size;
            toast('\u2713 '+Number(d.count).toLocaleString()+' item cleaned!',false);
        } else toast('Something went wrong, please try again.',true);
    },
    function(e){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); console.error(e); });
}

/* ─── SYNC COUNTS ───────────────────────────────────────── */
function syncCounts(c){
    setN('cnt-sessions',   c.sessions);
    setN('cnt-orders',     c.orders);
    var ot=document.querySelector('#bdopt-tabs .tab[data-panel="p-orders"] .tbadge');
    if(c.orders>0){ if(!ot){ var otp=document.querySelector('#bdopt-tabs .tab[data-panel="p-orders"]'); if(otp){ var b=document.createElement('span'); b.className='tbadge'; b.textContent=Number(c.orders).toLocaleString(); otp.appendChild(b); } } else { ot.textContent=Number(c.orders).toLocaleString(); } }
    else if(ot){ ot.remove(); }
    if(c.order_statuses){
        // Update counts in all status checkboxes (manual + auto)
        document.querySelectorAll('#p-orders .m-os-cb, #p-orders .s-os-cb').forEach(function(cb){
            var n=c.order_statuses[cb.value]||0;
            var sp=cb.parentElement.querySelector('span');
            if(sp) sp.textContent='('+Number(n).toLocaleString()+')';
        });
        updateFilterTotal();
    }
    setN('cnt-transients', c.transients);
    setN('cnt-actions',    (c.as_complete||0)+(c.as_failed||0)+(c.as_past_due||0));
    setN('cnt-pastdue',    c.as_past_due);
    setN('cnt-aspend',     c.as_pending);
    setN('cnt-logs',       c.logs);
    setN('cnt-revisions',  c.revisions);
    setN('cnt-autodraft',  c.autodraft);
    setN('cnt-spam',       c.spam);
    setN('cnt-trashed',    c.trashed);
    setN('cnt-orphan-meta',c.orphan_meta);
    setN('cnt-oembed',     c.oembed);
    setN('cnt-personal-data', c.personal_data);
}

/* ─── SAVE SETTINGS ─────────────────────────────────────── */
g('btn-save').addEventListener('click',function(){
    var btn=this, orig=btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="bsp bsp-d"></span> Saving...';
    xpost({
        action:'bdopt_save_settings',nonce:NONCE,
        auto_enabled:     g('s-auto').checked?1:0,
        auto_frequency:   g('s-freq').value,
        clean_sessions:   g('s-sessions').checked?1:0,
        clean_transients: g('s-transients').checked?1:0,
        clean_actions:    g('s-actions').checked?1:0,
        clean_logs:       g('s-logs').checked?1:0,
        clean_revisions:  g('s-revisions').checked?1:0,
        clean_autodraft:  g('s-autodraft').checked?1:0,
        clean_spam:       g('s-spam').checked?1:0,
        clean_trashed:    g('s-trashed').checked?1:0,
        clean_orphan_meta:g('s-orphan').checked?1:0,
        optimize_tables:  g('s-optimize').checked?1:0,
        action_days:      g('s-action-days').value,
        log_days:         g('s-log-days').value,
        revision_keep:    g('s-rev-keep').value,
    },
    function(d){ btn.disabled=false; btn.innerHTML=orig; toast(d.success?'\u2713 '+d.data.message:'Error!',!d.success); },
    function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
});

/* ─── ORDERS SAVE ───────────────────────────────────────── */
var osBtn=g('btn-orders-save');
if(osBtn){
    osBtn.addEventListener('click',function(){
        var btn=this, orig=btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="bsp bsp-d"></span> Saving...';
        var osCbs=document.querySelectorAll('.s-os-cb:checked'), osVals=[];
        osCbs.forEach(function(cb){ osVals.push(cb.value); });
        xpost({
            action:'bdopt_save_settings',nonce:NONCE,
            clean_orders:     g('s-orders').checked?1:0,
            order_days:       g('s-order-days').value,
            order_statuses:   osVals.join(','),
        },
        function(d){ btn.disabled=false; btn.innerHTML=orig; toast(d.success?'\u2713 '+d.data.message:'Error!',!d.success); },
        function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
    });
}

/* ─── PERFORMANCE SAVE ──────────────────────────────────── */
var perfBtn=g('btn-perf-save');
if(perfBtn){
    perfBtn.addEventListener('click',function(){
        var btn=this, orig=btn.innerHTML;
        btn.disabled=true; btn.innerHTML='<span class="bsp bsp-d"></span> Saving...';
        xpost({
            action:'bdopt_save_settings',nonce:NONCE,
            perf_heartbeat: g('s-perf-heartbeat').checked?1:0,
            perf_xmlrpc:    g('s-perf-xmlrpc').checked?1:0,
            perf_pingbacks: g('s-perf-pingbacks').checked?1:0,
            perf_qs:        g('s-perf-qs').checked?1:0,
            perf_oembed:    g('s-perf-oembed').checked?1:0,
        },
        function(d){ btn.disabled=false; btn.innerHTML=orig; toast(d.success?'\u2713 '+d.data.message:'Error!',!d.success); },
        function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
    });
}

/* ─── MANUAL DELETE ─────────────────────────────────────── */
var mdBtn=g('btn-manual-delete');
if(mdBtn){
    mdBtn.addEventListener('click',function(){
        var btn=this, orig=btn.innerHTML;
        var cbs=document.querySelectorAll('.m-os-cb:checked'), vals=[], total=0;
        cbs.forEach(function(cb){ vals.push(cb.value); });
        if(!vals.length){ toast('No status selected!',true); return; }
        var days=parseInt(g('m-order-days').value)||0;
        var fromVal=g('m-order-from')?g('m-order-from').value:'';
        var toVal=g('m-order-to')?g('m-order-to').value:'';
        var idFrom=g('m-order-id-from')?parseInt(g('m-order-id-from').value)||0:0;
        var idTo=g('m-order-id-to')?parseInt(g('m-order-id-to').value)||0:0;
        var parts=[];
        if(fromVal||toVal) parts.push('Date: '+(fromVal||'*')+'→'+(toVal||'*'));
        else { days=days||30; parts.push(days+'+ days'); }
        if(idFrom||idTo) parts.push('ID: '+(idFrom||'*')+'→'+(idTo||'*'));
        if(!parts.length) parts.push(days+'+ days');
        var ftext=parts.join(', ');
        if(!confirm('['+ftext+'] / '+vals.join(', ')+' orders — permanently DELETE?\n\nThis cannot be undone!')) return;
        btn.disabled=true; btn.innerHTML='<span class="bsp"></span> Deleting...';
        xpost({
            action:'bdopt_run_clean',nonce:NONCE,type:'orders',
            order_days:days, order_statuses:vals.join(','),
            order_from:fromVal, order_to:toVal,
            order_id_from:idFrom, order_id_to:idTo,
        },
        function(res){
            btn.disabled=false; btn.innerHTML=orig;
            if(res.success){
                var d=res.data;
                if(d.counts){
                    syncCounts(d.counts);
                    // Update manual filter counts
                    var lbs=document.querySelectorAll('#p-orders .m-os-cb, #p-orders .s-os-cb');
                    lbs.forEach(function(cb){
                        var sk=cb.value, n=d.counts.order_statuses?Number(d.counts.order_statuses[sk]||0):0;
                        var sp=cb.parentElement.querySelector('span');
                        if(sp) sp.textContent='('+n.toLocaleString()+')';
                    });
                    updateFilterTotal();
                }
                toast('\u2713 '+Number(d.count).toLocaleString()+' orders permanently deleted!',false);
            } else toast('Something went wrong, please try again.',true);
        },
        function(e){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); console.error(e); });
    });
}

/* ─── DOWNLOAD ORDERS CSV ───────────────────────────────── */
var dlBtn=g('btn-download-orders');
if(dlBtn){
    dlBtn.addEventListener('click',function(){
        var btn=this, orig=btn.innerHTML;
        var cbs=document.querySelectorAll('.m-os-cb:checked'), vals=[];
        cbs.forEach(function(cb){ vals.push(cb.value); });
        if(!vals.length){ toast('No status selected!',true); return; }
        var days=parseInt(g('m-order-days').value)||0;
        var fromVal=g('m-order-from')?g('m-order-from').value:'';
        var toVal=g('m-order-to')?g('m-order-to').value:'';
        var idFrom=g('m-order-id-from')?parseInt(g('m-order-id-from').value)||0:0;
        var idTo=g('m-order-id-to')?parseInt(g('m-order-id-to').value)||0:0;
        btn.disabled=true; btn.innerHTML='<span class="bsp bsp-d"></span> Preparing...';
        xpost({
            action:'bdopt_download_orders',nonce:NONCE,
            order_days:days||30, order_statuses:vals.join(','),
            order_from:fromVal, order_to:toVal,
            order_id_from:idFrom, order_id_to:idTo,
        },
        function(res){
            btn.disabled=false; btn.innerHTML=orig;
            if(res.success&&res.data.csv){
                var blob=new Blob(["\uFEFF"+res.data.csv],{type:'text/csv;charset=utf-8;'});
                var a=document.createElement('a');
                a.href=URL.createObjectURL(blob);
                a.download='orders-export-'+new Date().toISOString().slice(0,10)+'.csv';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                URL.revokeObjectURL(a.href);
                toast('\u2713 CSV downloaded!',false);
            } else {
                toast(res.data&&res.data.message?res.data.message:'No data found!',true);
            }
        },
        function(e){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); console.error(e); });
    });
}

// Update filter total when checkboxes change
function updateFilterTotal(){
    var cbs=document.querySelectorAll('.m-os-cb'), total=0;
    cbs.forEach(function(cb){
        if(cb.checked){
            var sp=cb.parentElement.querySelector('span');
            if(sp) total+=parseInt(sp.textContent.replace(/[^\d]/g,''))||0;
        }
    });
    var el=g('m-filter-total');
    if(el) el.textContent=total.toLocaleString();
}
document.getElementById('p-orders').addEventListener('change',function(e){
    if(e.target.classList.contains('m-os-cb')) updateFilterTotal();
});

/* ─── DB BREAKDOWN ──────────────────────────────────────── */
function loadBreakdown(){
    var el=g('brk-body');
    el.innerHTML='<div class="loading-row"><span class="bsp bsp-d"></span> Loading...</div>';
    xpost({action:'bdopt_get_breakdown',nonce:NONCE},function(res){
        if(!res.success||!res.data.tables.length){ el.innerHTML='<div class="loading-row">No tables found</div>'; return; }
        var rows=res.data.tables, maxMB=parseFloat(rows[0].size_mb)||0.001;
        var html='<table class="brk-tbl"><thead><tr><th>#</th><th>Table Name</th><th>Engine</th><th style="text-align:right">Rows</th><th style="text-align:right">Size</th><th style="width:130px">Usage</th></tr></thead><tbody>';
        rows.forEach(function(r,i){
            var mb=parseFloat(r.size_mb)||0;
            var pct=Math.max(2,Math.round(mb/maxMB*100));
            var eng=(r.engine||'').toLowerCase();
            var ec=eng==='myisam'?'ep-myisam':(eng==='innodb'?'ep-innodb':'ep-other');
            html+='<tr><td class="rank-cell">'+(i+1)+'</td><td class="mono">'+esc(r.table_name)+'</td>';
            html+='<td><span class="engine-pill '+ec+'">'+esc(r.engine||'?')+'</span></td>';
            html+='<td class="num-cell">'+Number(r.table_rows||0).toLocaleString()+'</td>';
            html+='<td class="num-cell">'+mb.toFixed(3)+' MB</td>';
            html+='<td><div class="bar-wrap"><div class="bar-fill" style="width:'+pct+'%"></div></div></td></tr>';
        });
        el.innerHTML=html+'</tbody></table>';
    },function(){ el.innerHTML='<div class="loading-row">Error loading. Please try again.</div>'; });
}
g('btn-brk-ref').addEventListener('click',function(){ brkLoaded=false; loadBreakdown(); brkLoaded=true; });

/* ─── OBJECT CACHE ──────────────────────────────────────── */
(function(){
    var en=g('btn-cache-enable'), dis=g('btn-cache-disable'), msg=g('cache-msg');
    function doCache(act){
        if(msg){ msg.style.display='block'; msg.textContent='Processing...'; }
        xpost({action:'bdopt_cache_action',nonce:NONCE,cache_action:act},
            function(res){
                if(msg){
                    if(res.success){
                        msg.style.color='#1a7d34';
                        msg.innerHTML='&#10003; '+esc(res.data.message);
                        setTimeout(function(){ location.reload(); },1200);
                    } else {
                        msg.style.color='#d63638';
                        msg.innerHTML='&#10007; '+esc(res.data.message);
                    }
                }
            },
            function(){ if(msg){ msg.style.display='block'; msg.style.color='#d63638'; msg.textContent='Network Error!'; } }
        );
    }
    if(en) en.addEventListener('click',function(){ doCache('enable'); });
    if(dis) dis.addEventListener('click',function(){ doCache('disable'); });
})();
/* --- CACHE PURGE --- */
document.getElementById('bdopt').addEventListener('click',function(e){
    var el=e.target.closest('[data-cache]');
    if(!el||el.disabled) return;
    var cache=el.dataset.cache;
    if(!cache) return;
    var orig=el.innerHTML;
    el.disabled=true; el.innerHTML='<span class="bsp bsp-d"></span> Purging...';
    xpost({action:'bdopt_purge_cache',nonce:NONCE,cache:cache},
        function(res){
            el.disabled=false; el.innerHTML=orig;
            if(res.success) toast('\u2713 '+res.data.message,false);
            else toast('\u2717 '+res.data.message,true);
        },
        function(){ el.disabled=false; el.innerHTML=orig; toast('Network Error!',true); }
    );
});
/* UTILS */
function g(id){ return document.getElementById(id); }
function setN(id,v){ var el=g(id); if(el) el.textContent=Number(v||0).toLocaleString(); }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function toast(msg,isErr){
    var n=g('bdopt-notice'), ic=n.querySelector('.n-ico');
    n.querySelector('.n-msg').textContent=msg;
    n.className=isErr?'notice-err':'';
    ic.className='dashicons n-ico '+(isErr?'dashicons-warning':'dashicons-yes-alt');
    void n.offsetWidth;
    n.classList.add('show');
    clearTimeout(n._t);
    n._t=setTimeout(function(){ n.classList.remove('show'); },4200);
}




})();
</script>
<?php
}

// ================================================================
// TEMPLATE HELPERS
// ================================================================
function bdopt_card($type,$icon,$theme,$title,$count,$desc){
    $cid='cnt-'.esc_attr(str_replace('_','-',$type));
    $num=is_numeric($count)?number_format((int)$count):esc_html($count);
    echo '<div class="card '.$theme.'">';
    echo '<div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-'.esc_attr($icon).'"></span></div><div class="card-ttl">'.esc_html($title).'</div></div>';
    echo '<div class="card-body"><div class="card-num" id="'.esc_attr($cid).'">'.$num.'</div><div class="card-desc">'.esc_html($desc).'</div>';
    echo '<button class="card-btn" type="button" data-type="'.esc_attr($type).'"><span class="dashicons dashicons-trash"></span> Clean</button>';
    echo '</div></div>';
}
function bdopt_card_opt(){
    echo '<div class="card c-opt"><div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-performance"></span></div><div class="card-ttl">Table Optimize</div></div>';
    echo '<div class="card-body"><div class="card-num" style="font-size:20px;margin-top:4px">OPTIMIZE</div><div class="card-desc">Rebuilds tables &amp; reclaims disk space<br>MyISAM + InnoDB</div>';
    echo '<button class="card-btn" type="button" data-type="optimize"><span class="dashicons dashicons-performance"></span> Run Optimize</button></div></div>';
}
function bdopt_setting_toggle($id,$label,$desc,$checked){
    echo '<div class="s-row"><div class="s-lbl">'.wp_kses_post($label).' <small>'.wp_kses_post($desc).'</small></div>';
    echo '<div class="s-ctrl"><label class="tog"><input type="checkbox" id="'.esc_attr($id).'" '.$checked.'><span class="tog-sl"></span></label></div></div>';
}

