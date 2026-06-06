<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BDOPT Redis Object Cache — backed by phpredis.
 * Called from inc/object-cache.php drop-in bootstrap.
 */

// Guard: if WP core already loaded its own WP_Object_Cache, we still
// define our own class under a unique name and take over via wp_cache_init().
if ( ! class_exists( 'BDOPT_Redis_Object_Cache' ) ) {

class BDOPT_Redis_Object_Cache {

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
        if ( $expire === 0 && defined( 'BDOPT_CACHE_TTL' ) && BDOPT_CACHE_TTL > 0 ) {
            $expire = (int) BDOPT_CACHE_TTL;
        }
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
            } catch ( Exception $e ) {}
        }
        return true;
    }

    public function set( $key, $data, $group = 'default', $expire = 0 ) {
        $this->stats['set']++;
        /* Apply default TTL from BDOPT_CACHE_TTL constant if no explicit expire */
        if ( $expire === 0 && defined( 'BDOPT_CACHE_TTL' ) && BDOPT_CACHE_TTL > 0 ) {
            $expire = (int) BDOPT_CACHE_TTL;
        }
        $this->cache[ $group ][ $key ] = $data;
        if ( $this->connected && $this->is_persistent( $group ) ) {
            $k = $this->key( $key, $group );
            $v = maybe_serialize( $data );
            try {
                $this->redis->set( $k, $v );
                if ( $expire > 0 ) {
                    $this->redis->expire( $k, $expire );
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

    public function incr( $key, $offset = 1, $group = 'default' ) {
        return $this->incr_desr( $key, $offset, $group, true );
    }

    public function decr( $key, $offset = 1, $group = 'default' ) {
        return $this->incr_desr( $key, $offset, $group, false );
    }

    private function incr_desr( $key, $offset = 1, $group = 'default', $incr = true ) {
        $cache_val = $this->get( $key, $group );
        if ( false === $cache_val ) {
            return false;
        }
        if ( ! is_numeric( $cache_val ) ) {
            $cache_val = 0;
        }
        $offset = (int) $offset;
        $cache_val = $incr ? $cache_val + $offset : $cache_val - $offset;
        if ( $cache_val < 0 ) {
            $cache_val = 0;
        }
        $this->set( $key, $cache_val, $group );
        return $cache_val;
    }

    public function replace( $key, $data, $group = 'default', $expire = 0 ) {
        if ( ! isset( $this->cache[ $group ][ $key ] ) ) {
            return false;
        }
        return $this->set( $key, $data, $group, (int) $expire );
    }

    public function add_multiple( array $data, $group = '', $expire = 0 ) {
        $values = array();
        foreach ( $data as $key => $value ) {
            $values[ $key ] = $this->add( $key, $value, $group, $expire );
        }
        return $values;
    }

    public function set_multiple( array $data, $group = '', $expire = 0 ) {
        $values = array();
        foreach ( $data as $key => $value ) {
            $values[ $key ] = $this->set( $key, $value, $group, $expire );
        }
        return $values;
    }

    public function get_multiple( $keys, $group = 'default', $force = false ) {
        $values = array();
        foreach ( $keys as $key ) {
            $values[ $key ] = $this->get( $key, $group, $force );
        }
        return $values;
    }

    public function delete_multiple( array $keys, $group = '' ) {
        $values = array();
        foreach ( $keys as $key ) {
            $values[ $key ] = $this->delete( $key, $group );
        }
        return $values;
    }

    public function flush_runtime() {
        $this->cache = array();
        return true;
    }

    public function flush_group( $group ) {
        if ( isset( $this->cache[ $group ] ) ) {
            unset( $this->cache[ $group ] );
        }
        return true;
    }

    public function switch_to_blog( $blog_id ) {
        // No-op for single-site; multisite handled by prefix
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

    /**
     * Singleton — called from wp_cache_init().
     */
    public static function get_instance() {
        static $_instance = null;
        if ( null === $_instance ) {
            $_instance = new self();
        }
        return $_instance;
    }
}

}

// ----- wp_cache_* functions (override WordPress defaults) -----

if ( ! function_exists( 'wp_cache_init' ) ) {
function wp_cache_init() {
    $GLOBALS['wp_object_cache'] = BDOPT_Redis_Object_Cache::get_instance();
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
    global $wp_object_cache;
    return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_delete( $key, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->delete( $key, $group );
}

function wp_cache_flush() {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_close() {
    return true;
}

function wp_cache_add_global_groups( $groups ) {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups( $groups );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->add_multiple( $data, $group, $expire );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->set_multiple( $data, $group, $expire );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
    global $wp_object_cache;
    return $wp_object_cache->get_multiple( $keys, $group, $force );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->delete_multiple( $keys, $group );
}

function wp_cache_flush_runtime() {
    global $wp_object_cache;
    return $wp_object_cache->flush_runtime();
}

function wp_cache_flush_group( $group ) {
    global $wp_object_cache;
    return $wp_object_cache->flush_group( $group );
}

function wp_cache_switch_to_blog( $blog_id ) {
    global $wp_object_cache;
    $wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_supports( $feature ) {
    switch ( $feature ) {
        case 'add_multiple':
        case 'set_multiple':
        case 'get_multiple':
        case 'delete_multiple':
        case 'flush_runtime':
            return true;
        case 'flush_group':
        default:
            return false;
    }
}
}
