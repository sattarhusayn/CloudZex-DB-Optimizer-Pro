<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bdopt_perf_init() {
    $s = wp_parse_args( (array) get_option( 'bdopt_settings', array() ), bdopt_defaults() );

    if ( ! empty( $s['perf_heartbeat'] ) ) {
        add_action( 'init', function() {
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_deregister_script( 'heartbeat' );
            }
        }, 1 );
    }

    if ( ! empty( $s['perf_xmlrpc'] ) ) {
        add_filter( 'xmlrpc_enabled', '__return_false' );
    }

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

    if ( ! empty( $s['perf_qs'] ) ) {
        add_filter( 'script_loader_src', 'bdopt_remove_qs', 15 );
        add_filter( 'style_loader_src',  'bdopt_remove_qs', 15 );
    }

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