<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Redis-backed object cache drop-in for CloudZex DB Optimizer Pro.
 * Requires: PHP redis extension (phpredis).
 * Configuration (define in wp-config.php before the plugin):
 *   BDOPT_REDIS_HOST  (default: 127.0.0.1)
 *   BDOPT_REDIS_PORT  (default: 6379)
 *   BDOPT_REDIS_PREFIX (default: 'bdopt:')
 *   BDOPT_REDIS_DB    (default: 0)
 */

if ( ! class_exists( 'WP_Object_Cache' ) ) {
class WP_Object_Cache {

    private $redis;
    private $prefix;
    private $global_groups = array();
    private $non_persistent_groups = array();
    private $cache = array();
    private $stats = array( 'add' => 0, 'delete' => 0, 'get' => 0, 'set' => 0, 'flush' => 0 );
    private $connected = false;

    public function __construct() {
        $host   = defined( 'BDOPT_REDIS_HOST' ) ? BDOPT_REDIS_HOST : '127.0.0.1';
        $port   = defined( 'BDOPT_REDIS_PORT' ) ? BDOPT_REDIS_PORT : 6379;
        $db     = defined( 'BDOPT_REDIS_DB' ) ? BDOPT_REDIS_DB : 0;
        $prefix = defined( 'BDOPT_REDIS_PREFIX' ) ? BDOPT_REDIS_PREFIX : 'bdopt:';

        // Strip trailing : from site URL to build per-site prefix
        $site_url = parse_url( get_site_url(), PHP_URL_HOST );
        $this->prefix = $prefix . $site_url . ':';

        if ( class_exists( 'Redis' ) ) {
            $this->redis = new Redis();
            try {
                $this->connected = $this->redis->connect( $host, $port, 1.0 );
                if ( $this->connected && $db > 0 ) {
                    $this->redis->select( $db );
                }
            } catch ( Exception $e ) {
                $this->connected = false;
            }
        }
    }

    private function key( $key, $group = '' ) {
        if ( empty( $group ) ) {
            return $this->prefix . $key;
        }
        return $this->prefix . $group . ':' . $key;
    }

    public function add( $key, $data, $group = 'default', $expire = 0 ) {
        $this->stats['add']++;
        if ( isset( $this->cache[ $group ][ $key ] ) ) {
            return false;
        }
        $this->cache[ $group ][ $key ] = $data;
        if ( $this->connected && $this->is_persistent( $group ) ) {
            $k = $this->key( $key, $group );
            $v = maybe_serialize( $data );
            try {
                if ( ! $this->redis->setnx( $k, $v ) ) {
                    return false;
                }
                if ( $expire > 0 ) {
                    $this->redis->expire( $k, $expire );
                }
            } catch ( Exception $e ) {
                // fallback to in-memory only
            }
        }
        return true;
    }

    public function set( $key, $data, $group = 'default', $expire = 0 ) {
        $this->stats['set']++;
        $this->cache[ $group ][ $key ] = $data;
        if ( $this->connected && $this->is_persistent( $group ) ) {
            $k = $this->key( $key, $group );
            $v = maybe_serialize( $data );
            try {
                $this->redis->set( $k, $v );
                if ( $expire > 0 ) {
                    $this->redis->expire( $k, $expire );
                } else {
                    $this->redis->persist( $k );
                }
            } catch ( Exception $e ) {}
        }
        return true;
    }

    public function get( $key, $group = 'default', $force = false, &$found = null ) {
        $this->stats['get']++;
        if ( ! $force && isset( $this->cache[ $group ][ $key ] ) ) {
            $found = true;
            return $this->cache[ $group ][ $key ];
        }
        if ( $this->connected && $this->is_persistent( $group ) ) {
            $k = $this->key( $key, $group );
            try {
                $v = $this->redis->get( $k );
                if ( $v !== false ) {
                    $found = true;
                    $this->cache[ $group ][ $key ] = maybe_unserialize( $v );
                    return $this->cache[ $group ][ $key ];
                }
            } catch ( Exception $e ) {}
        }
        $found = false;
        return false;
    }

    public function delete( $key, $group = 'default' ) {
        $this->stats['delete']++;
        if ( isset( $this->cache[ $group ][ $key ] ) ) {
            unset( $this->cache[ $group ][ $key ] );
        }
        if ( $this->connected && $this->is_persistent( $group ) ) {
            $k = $this->key( $key, $group );
            try {
                return (bool) $this->redis->del( $k );
            } catch ( Exception $e ) {
                return false;
            }
        }
        return true;
    }

    public function flush( $delay = 0 ) {
        $this->stats['flush']++;
        $this->cache = array();
        if ( $this->connected ) {
            try {
                $keys = $this->redis->keys( $this->prefix . '*' );
                if ( ! empty( $keys ) ) {
                    $this->redis->del( $keys );
                }
            } catch ( Exception $e ) {}
        }
        return true;
    }

    public function close() {
        if ( $this->connected ) {
            try {
                $this->redis->close();
            } catch ( Exception $e ) {}
        }
        $this->connected = false;
    }

    public function add_global_groups( $groups ) {
        $groups = (array) $groups;
        $this->global_groups = array_merge( $this->global_groups, $groups );
    }

    public function add_non_persistent_groups( $groups ) {
        $groups = (array) $groups;
        $this->non_persistent_groups = array_merge( $this->non_persistent_groups, $groups );
    }

    public function is_persistent( $group ) {
        return ! in_array( $group, $this->non_persistent_groups, true );
    }

    public function stats() {
        echo '<p><strong>Redis Object Cache Stats</strong></p>';
        echo '<ul>';
        foreach ( $this->stats as $k => $v ) {
            echo '<li>' . esc_html( $k ) . ': ' . esc_html( $v ) . '</li>';
        }
        echo '<li>connected: ' . ( $this->connected ? 'yes' : 'no' ) . '</li>';
        echo '</ul>';
    }

    public function is_redis_connected() {
        return $this->connected;
    }

    public function __destruct() {
        $this->close();
    }
}
}
