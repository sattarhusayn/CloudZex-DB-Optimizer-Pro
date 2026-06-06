<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bdopt_clean_sessions() {
    global $wpdb;
    $t = $wpdb->prefix . 'woocommerce_sessions';
    if ( ! bdopt_table_exists( $t ) ) return 0;
    return (int) $wpdb->query( "DELETE FROM `{$t}` WHERE session_expiry < UNIX_TIMESTAMP()" );
}

function bdopt_clean_orders( $days = 30, $statuses = array(), $from = '', $to = '', $id_from = 0, $id_to = 0 ) {
    if ( ! class_exists( 'WooCommerce' ) ) return 0;
    $days = max( 1, (int) $days );
    $id_from = max( 0, (int) $id_from );
    $id_to   = max( 0, (int) $id_to );
    if ( empty( $statuses ) ) {
        $saved = bdopt_s( 'order_statuses', 'completed,cancelled,refunded,failed' );
        $statuses = array_map( 'trim', explode( ',', $saved ) );
    }
    $raw_statuses = array_map( function( $s ) { return 'wc-' . trim( $s ); }, $statuses );

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
    $raw_statuses = array_map( function( $s ) { return 'wc-' . trim( $s ); }, $statuses );

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

function bdopt_clean_actions( $days = 7 ) {
    global $wpdb;
    $t  = $wpdb->prefix . 'actionscheduler_actions';
    $lt = $wpdb->prefix . 'actionscheduler_logs';
    if ( ! bdopt_table_exists( $t ) ) return 0;
    $days = max( 1, (int) $days );
    $del  = 0;

    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.action_id, a.status, a.scheduled_date_gmt
         FROM `{$t}` a
         WHERE a.status IN ('complete','failed','canceled')
           AND a.scheduled_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
         ORDER BY a.action_id ASC
         LIMIT 500",
        $days
    ) );

    foreach ( $results as $row ) {
        $aid = (int) $row->action_id;
        if ( bdopt_table_exists( $lt ) ) {
            $wpdb->delete( $lt, array( 'action_id' => $aid ), array( '%d' ) );
        }
        if ( $wpdb->delete( $t, array( 'action_id' => $aid ), array( '%d' ) ) ) {
            $del++;
        }
    }

    $past_due = $wpdb->get_results( $wpdb->prepare(
        "SELECT action_id FROM `{$t}`
         WHERE status = 'pending'
           AND scheduled_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
         LIMIT 500",
        $days
    ) );

    foreach ( $past_due as $row ) {
        $aid = (int) $row->action_id;
        if ( $wpdb->delete( $t, array( 'action_id' => $aid ), array( '%d' ) ) ) {
            $del++;
        }
    }

    return $del;
}

function bdopt_clean_logs( $days = 7 ) {
    global $wpdb;
    $t  = $wpdb->prefix . 'actionscheduler_logs';
    $at = $wpdb->prefix . 'actionscheduler_actions';
    if ( ! bdopt_table_exists( $t ) ) return 0;
    $days = max( 1, (int) $days );
    $del  = 0;

    $log_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT l.log_id
         FROM `{$t}` l
         LEFT JOIN `{$at}` a ON a.action_id = l.action_id
         WHERE a.action_id IS NULL
            OR ( a.status IN ('complete','failed','canceled')
                 AND a.scheduled_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) )
         LIMIT 1000",
        $days
    ) );

    foreach ( $log_ids as $lid ) {
        $lid = (int) $lid;
        if ( $lid <= 0 ) continue;
        if ( $wpdb->delete( $t, array( 'log_id' => $lid ), array( '%d' ) ) ) {
            $del++;
        }
    }

    return $del;
}

function bdopt_clean_revisions( $keep = 3 ) {
    global $wpdb;
    $keep  = max( 0, (int) $keep );
    $del   = 0;
    $posts = $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'post' AND post_status IN ('publish','draft')" );
    foreach ( $posts as $pid ) {
        $revs = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC",
            (int) $pid
        ) );
        if ( count( $revs ) <= $keep ) continue;
        $to_del = array_slice( $revs, $keep );
        foreach ( $to_del as $rid ) {
            wp_delete_post_revision( (int) $rid );
            $del++;
        }
        if ( $del >= 5000 ) break;
    }
    return $del;
}

function bdopt_clean_autodraft() {
    global $wpdb;
    return (int) $wpdb->query(
        "DELETE FROM `{$wpdb->posts}` WHERE post_status = 'auto-draft'"
    );
}

function bdopt_clean_spam_comments() {
    global $wpdb;
    return (int) $wpdb->query(
        "DELETE FROM `{$wpdb->comments}` WHERE comment_approved IN ('spam','trash')"
    );
}

function bdopt_clean_trashed() {
    global $wpdb;
    $ids = $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_status = 'trash'" );
    $n = 0;
    foreach ( $ids as $id ) {
        if ( wp_delete_post( (int) $id, true ) ) $n++;
    }
    return $n;
}

function bdopt_clean_orphan_meta() {
    global $wpdb;
    $del  = (int) $wpdb->query(
        "DELETE pm FROM `{$wpdb->postmeta}` pm LEFT JOIN `{$wpdb->posts}` p ON pm.post_id = p.ID WHERE p.ID IS NULL"
    );
    $del += (int) $wpdb->query(
        "DELETE cm FROM `{$wpdb->commentmeta}` cm LEFT JOIN `{$wpdb->comments}` c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"
    );
    $del += (int) $wpdb->query(
        "DELETE um FROM `{$wpdb->usermeta}` um LEFT JOIN `{$wpdb->users}` u ON um.user_id = u.ID WHERE u.ID IS NULL"
    );
    $orders_table = $wpdb->prefix . 'wc_orders';
    $meta_table   = $wpdb->prefix . 'wc_orders_meta';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$meta_table}'" ) === $meta_table ) {
        $del += (int) $wpdb->query(
            "DELETE om FROM `{$meta_table}` om LEFT JOIN `{$orders_table}` o ON om.order_id = o.id WHERE o.id IS NULL"
        );
    }
    return $del;
}

function bdopt_clean_oembed() {
    global $wpdb;
    return (int) $wpdb->query(
        "DELETE FROM `{$wpdb->postmeta}` WHERE meta_key LIKE '_oembed_%'"
    );
}

function bdopt_clean_personal_data() {
    global $wpdb;
    return (int) $wpdb->query(
        "DELETE FROM `{$wpdb->posts}`
         WHERE post_type = 'user_request'
           AND post_status IN ('request-completed','request-confirmed')
           AND post_date < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
}

function bdopt_optimize_tables() {
    global $wpdb;
    $tables = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A );
    $cnt    = 0;
    foreach ( $tables as $tbl ) {
        $name = $tbl['Name'];
        $wpdb->query( "OPTIMIZE TABLE `{$name}`" );
        $cnt++;
    }
    return $cnt;
}

function bdopt_run_all_clean() {
    $r = array();
    if ( (int) bdopt_s('clean_sessions') )    $r['sessions']    = bdopt_clean_sessions();
    if ( (int) bdopt_s('clean_transients') )  $r['transients']  = bdopt_clean_transients();
    if ( (int) bdopt_s('clean_actions') )     $r['actions']     = bdopt_clean_actions( (int) bdopt_s('action_days',7) );
    if ( (int) bdopt_s('clean_logs') )        $r['logs']        = bdopt_clean_logs( (int) bdopt_s('log_days',7) );
    if ( (int) bdopt_s('clean_revisions') )   $r['revisions']   = bdopt_clean_revisions( (int) bdopt_s('revision_keep',3) );
    if ( (int) bdopt_s('clean_autodraft') )   $r['autodraft']   = bdopt_clean_autodraft();
    if ( (int) bdopt_s('clean_spam') )        $r['spam']        = bdopt_clean_spam_comments();
    if ( (int) bdopt_s('clean_trashed') )     $r['trashed']     = bdopt_clean_trashed();
    if ( (int) bdopt_s('clean_orphan_meta') ) $r['orphan_meta'] = bdopt_clean_orphan_meta();
    $r['oembed'] = bdopt_clean_oembed();
    $r['personal_data'] = bdopt_clean_personal_data();
    if ( (int) bdopt_s('clean_orders') )      $r['orders']      = bdopt_clean_orders( (int) bdopt_s('order_days',30) );
    if ( (int) bdopt_s('optimize_tables') )   $r['optimized']   = bdopt_optimize_tables();
    update_option( 'bdopt_last_run', array( 'time' => current_time('mysql'), 'results' => $r ) );
    return $r;
}

function bdopt_get_counts() {
    global $wpdb;
    $d = array();

    $t = $wpdb->prefix . 'woocommerce_sessions';
    $d['sessions'] = bdopt_table_exists($t) ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}` WHERE session_expiry < UNIX_TIMESTAMP()") : 0;

    $d['orders'] = 0;
    $d['order_statuses'] = array();
    if ( class_exists( 'WooCommerce' ) ) {
        $all_stat = wc_get_order_statuses();
        foreach ( $all_stat as $sk => $sl ) {
            $raw       = $sk;
            $sk        = str_replace( 'wc-', '', $sk );
            $count     = 0;
            $result = wc_get_orders( array(
                'status'  => array( $raw ),
                'limit'   => 1,
                'paginate' => true,
                'return'  => 'ids',
            ) );
            if ( ! empty( $result ) && is_object( $result ) && isset( $result->total ) ) {
                $count = (int) $result->total;
            } else {
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

    $o = $wpdb->prefix . 'options'; $now = time();
    $d['transients'] =
        (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$o}` WHERE option_name LIKE '_transient_timeout_%' AND CAST(option_value AS UNSIGNED)<%d",$now)) +
        (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$o}` WHERE option_name LIKE '_site_transient_timeout_%' AND CAST(option_value AS UNSIGNED)<%d",$now));

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

    $d['logs'] = 0;
    if ( defined('WC_LOG_DIR') && is_dir(WC_LOG_DIR) ) {
        $f = glob( WC_LOG_DIR . '*.log' );
        $d['logs'] = $f ? count($f) : 0;
    }

    $d['revisions']   = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type='revision'");
    $d['autodraft']   = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_status='auto-draft'");
    $d['spam']        = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->comments}` WHERE comment_approved IN('spam','trash')");
    $d['trashed']     = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_status='trash'");
    $d['orphan_meta'] = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM `{$wpdb->postmeta}` pm LEFT JOIN `{$wpdb->posts}` p ON pm.post_id=p.ID WHERE p.ID IS NULL"
    );
    $hpos_meta = $wpdb->prefix . 'wc_orders_meta';
    $hpos_orders = $wpdb->prefix . 'wc_orders';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos_meta}'" ) === $hpos_meta ) {
        $d['orphan_meta'] += (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM `{$hpos_meta}` om LEFT JOIN `{$hpos_orders}` o ON om.order_id = o.id WHERE o.id IS NULL"
        );
    }
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

/* ─── Table Check & Repair ─── */
function bdopt_check_repair_tables( $repair = false ) {
    global $wpdb;
    $like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
    $tables = $wpdb->get_col( $wpdb->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s",
        DB_NAME, $like
    ) );
    $results = array();
    $repaired = 0;
    foreach ( $tables as $tbl ) {
        $check = $wpdb->get_row( "CHECK TABLE `{$tbl}`" );
        $status = isset( $check->Msg_text ) ? $check->Msg_text : 'Unknown';
        $needs_repair = ( stripos( $status, 'corrupt' ) !== false || stripos( $status, 'error' ) !== false || stripos( $status, 'warning' ) !== false );
        if ( $repair && $needs_repair ) {
            $wpdb->query( "REPAIR TABLE `{$tbl}`" );
            $repaired++;
            $results[] = array( 'table' => $tbl, 'status' => 'Repaired', 'original' => $status );
        } else {
            $results[] = array( 'table' => $tbl, 'status' => $status );
        }
    }
    if ( $repaired > 0 ) bdopt_add_log( 'repair', "Repaired {$repaired} table(s)" );
    else bdopt_add_log( 'check', 'Table check completed — all OK' );
    return array( 'success' => true, 'tables' => $results, 'repaired' => $repaired );
}

/* ─── MySQL Process List ─── */
function bdopt_get_mysql_processes() {
    global $wpdb;
    $processes = $wpdb->get_results( "SHOW FULL PROCESSLIST", ARRAY_A );
    $filtered = array();
    foreach ( (array)$processes as $p ) {
        if ( isset( $p['Command'] ) && $p['Command'] === 'Sleep' && ( isset( $p['Time'] ) && (int)$p['Time'] < 10 ) ) continue;
        $filtered[] = $p;
    }
    return $filtered;
}

function bdopt_kill_mysql_process( $id ) {
    global $wpdb;
    $id = (int) $id;
    if ( $id <= 0 ) return array( 'success' => false, 'message' => 'Invalid process ID.' );
    if ( $wpdb->query( "KILL {$id}" ) === false ) return array( 'success' => false, 'message' => 'Failed to kill process.' );
    bdopt_add_log( 'kill', "Killed MySQL process {$id}" );
    return array( 'success' => true, 'message' => "Process {$id} killed." );
}