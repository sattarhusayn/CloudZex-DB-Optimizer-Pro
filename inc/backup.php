<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bdopt_backup_dir() {
    $dir = WP_CONTENT_DIR . '/cz-backups';
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0755, true );
    }
    $ht = $dir . '/.htaccess';
    if ( ! file_exists( $ht ) ) {
        file_put_contents( $ht, "Deny from all\nRequire all denied\n" );
    }
    $idx = $dir . '/index.php';
    if ( ! file_exists( $idx ) ) {
        file_put_contents( $idx, "<?php // Silence\n" );
    }
    return $dir;
}

function bdopt_get_backups() {
    $dir = bdopt_backup_dir();
    $files = glob( $dir . '/bd-backup-*.sql.gz' );
    if ( empty( $files ) ) return array();
    $backups = array();
    foreach ( $files as $f ) {
        $backups[] = array(
            'name' => basename( $f ),
            'size' => filesize( $f ),
            'date' => date( 'Y-m-d H:i:s', filemtime( $f ) ),
        );
    }
    usort( $backups, function( $a, $b ) {
        return strcmp( $b['name'], $a['name'] );
    } );
    return $backups;
}

function bdopt_delete_backup( $name ) {
    $dir  = realpath( bdopt_backup_dir() );
    $path = realpath( $dir . DIRECTORY_SEPARATOR . basename( $name ) );
    if ( false === $path || strpos( $path, $dir . DIRECTORY_SEPARATOR ) !== 0 ) return false;
    $ok = @unlink( $path );
    if ( ! $ok ) {
        /* retry once after short delay (Windows file locks) */
        usleep( 250000 );
        $ok = @unlink( $path );
    }
    return $ok;
}

function bdopt_create_backup( $progress = null ) {
    global $wpdb;
    $dir   = bdopt_backup_dir();
    $time  = current_time( 'Y-m-d-H-i-s' );
    $gz    = "bd-backup-{$time}.sql.gz";
    $tmp   = $dir . '/' . $gz . '.tmp';
    $gzpath = $dir . '/' . $gz;

    foreach ( glob( $dir . '/bd-backup-*.sql.gz.tmp' ) as $stale ) { @unlink( $stale ); }

    if ( ! extension_loaded( 'zlib' ) ) { bdopt_add_log( 'error', 'DB backup failed: zlib extension required' ); return false; }

    $free = @disk_free_space( $dir );
    if ( $free !== false && $free < 20 * 1024 * 1024 ) {
        bdopt_add_log( 'error', 'DB backup failed: Low disk space (' . size_format( $free, 1 ) . ' free)' );
        return false;
    }

    $handle = gzopen( $tmp, 'w9' );
    if ( ! $handle ) { bdopt_add_log( 'error', 'DB backup failed: Cannot open temp file' ); return false; }

    set_time_limit( 0 );
    ignore_user_abort( true );

    $w = function( $s ) use ( $handle ) { gzwrite( $handle, $s ); };

    $w( "-- CloudZex DB Optimizer Backup\n" );
    $w( "-- Date: " . current_time( 'mysql' ) . "\n" );
    $w( "-- Host: " . DB_HOST . "\n" );
    $w( "-- Database: " . DB_NAME . "\n\n" );
    $w( "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" );

    $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
    if ( empty( $tables ) ) {
        gzclose( $handle );
        @unlink( $tmp );
        bdopt_add_log( 'error', 'DB backup failed: No tables found' );
        return false;
    }

    $total = count( $tables );
    $i     = 0;
    foreach ( $tables as $tbl ) {
        $i++;
        $name = $tbl[0];

        if ( is_callable( $progress ) ) {
            $progress( $i, $total, $name );
        }

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$name}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) continue;

        $w( "\n--\n-- Table: {$name}\n--\n" );
        $w( "DROP TABLE IF EXISTS `{$name}`;\n" );
        $w( $create[1] . ";\n\n" );

        $cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" );
        if ( $cnt === 0 ) continue;

        $offset     = 0;
        $chunk      = 500;
        while ( $offset < $cnt ) {
            $rows = $wpdb->get_results( "SELECT * FROM `{$name}` LIMIT {$offset}, {$chunk}", ARRAY_N );
            if ( empty( $rows ) ) break;

            foreach ( $rows as $row ) {
                $vals = array();
                foreach ( $row as $v ) {
                    $vals[] = ( $v === null ) ? 'NULL' : "'" . esc_sql( (string) $v ) . "'";
                }
                $w( "INSERT INTO `{$name}` VALUES (" . implode( ',', $vals ) . ");\n" );
            }
            $offset += $chunk;
        }
        $w( "\n" );
    }

    $w( "\nSET FOREIGN_KEY_CHECKS=1;\n" );
    gzclose( $handle );

    rename( $tmp, $gzpath );
    $fsize = filesize( $gzpath );
    bdopt_add_log( 'backup', "DB Backup created: {$gz} (" . size_format( $fsize, 1 ) . ')' );

    return array(
        'name' => $gz,
        'size' => $fsize,
        'date' => date( 'Y-m-d H:i:s', filemtime( $gzpath ) ),
    );
}

function bdopt_wp_backup_dir() {
    return bdopt_backup_dir();
}

function bdopt_get_wp_backups() {
    $dir = bdopt_wp_backup_dir();
    $files = glob( $dir . '/wp-backup-*.zip' );
    if ( empty( $files ) ) return array();
    $backups = array();
    foreach ( $files as $f ) {
        $backups[] = array(
            'name' => basename( $f ),
            'size' => filesize( $f ),
            'date' => date( 'Y-m-d H:i:s', filemtime( $f ) ),
        );
    }
    usort( $backups, function( $a, $b ) {
        return strcmp( $b['name'], $a['name'] );
    } );
    return $backups;
}

function bdopt_delete_wp_backup( $name ) {
    $dir  = realpath( bdopt_wp_backup_dir() );
    $path = realpath( $dir . DIRECTORY_SEPARATOR . basename( $name ) );
    if ( false === $path || strpos( $path, $dir . DIRECTORY_SEPARATOR ) !== 0 ) return false;
    $ok = @unlink( $path );
    if ( ! $ok ) {
        usleep( 250000 );
        $ok = @unlink( $path );
    }
    return $ok;
}

function bdopt_create_wp_backup( $progress = null ) {
    global $wpdb;
    $dir     = bdopt_wp_backup_dir();
    $time    = current_time( 'Y-m-d-H-i-s' );
    $zipname = "wp-backup-{$time}.zip";
    $zippath = $dir . '/' . $zipname;
    $tmp     = $dir . '/' . $zipname . '.tmp';

    foreach ( glob( $dir . '/wp-backup-*.zip.tmp' ) as $stale ) { @unlink( $stale ); }

    set_time_limit( 0 );
    ignore_user_abort( true );

    if ( ! class_exists( 'ZipArchive' ) ) { bdopt_add_log( 'error', 'Full backup failed: ZipArchive class not found' ); return false; }

    /* Check free disk space (need at least DB size + 50MB overhead) */
    $free = @disk_free_space( $dir );
    if ( $free !== false && $free < 50 * 1024 * 1024 ) {
        bdopt_add_log( 'error', 'Full backup failed: Insufficient disk space (' . size_format( $free, 1 ) . ' free)' );
        return false;
    }

    /* ── dump database to temp .sql ── */
    $dbtmp = $dir . '/.dbdump-' . $time . '.sql';
    $handle = fopen( $dbtmp, 'w' );
    if ( ! $handle ) { bdopt_add_log( 'error', 'Full backup failed: Cannot write temp DB dump' ); return false; }

    $w = function( $s ) use ( $handle ) { fwrite( $handle, $s ); };

    $w( "-- CloudZex DB Optimizer Full Backup\n" );
    $w( "-- Date: " . current_time( 'mysql' ) . "\n" );
    $w( "-- Host: " . DB_HOST . "\n" );
    $w( "-- Database: " . DB_NAME . "\n\n" );
    $w( "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" );

    $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
    if ( ! empty( $tables ) ) {
        $total_tbl = count( $tables );
        $ti = 0;
        $db_size = 0;
        foreach ( $tables as $tbl ) {
            $ti++;
            $name = $tbl[0];
            if ( is_callable( $progress ) ) $progress( $ti, 1, "Dumping DB table: {$name}" );

            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$name}`", ARRAY_N );
            if ( ! $create || empty( $create[1] ) ) continue;

            $w( "\n--\n-- Table: {$name}\n--\n" );
            $w( "DROP TABLE IF EXISTS `{$name}`;\n" );
            $w( $create[1] . ";\n\n" );

            $cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" );
            if ( $cnt === 0 ) continue;

            $offset = 0;
            $chunk  = 500;
            while ( $offset < $cnt ) {
                $rows = $wpdb->get_results( "SELECT * FROM `{$name}` LIMIT {$offset}, {$chunk}", ARRAY_N );
                if ( empty( $rows ) ) break;
                foreach ( $rows as $row ) {
                    $vals = array();
                    foreach ( $row as $v ) {
                        $vals[] = ( $v === null ) ? 'NULL' : "'" . esc_sql( (string) $v ) . "'";
                    }
                    $w( "INSERT INTO `{$name}` VALUES (" . implode( ',', $vals ) . ");\n" );
                }
                $offset += $chunk;
            }
            $w( "\n" );
        }
    }
    $w( "\nSET FOREIGN_KEY_CHECKS=1;\n" );
    fclose( $handle );
    $db_size = filesize( $dbtmp );

    /* Check free space again — estimate 1.5x DB +30% wp-content overhead */
    $free = @disk_free_space( $dir );
    $est = $db_size * 2 + 100 * 1024 * 1024;
    if ( $free !== false && $free < $est ) {
        @unlink( $dbtmp );
        bdopt_add_log( 'error', 'Full backup failed: Not enough disk space (need ~' . size_format( $est, 1 ) . ', have ' . size_format( $free, 1 ) . ')' );
        return false;
    }

    /* ── create ZIP with database.sql + wp-content ── */
    $zip = new ZipArchive();
    if ( $zip->open( $tmp, ZipArchive::CREATE ) !== true ) { @unlink( $dbtmp ); bdopt_add_log( 'error', 'Full backup failed: Cannot create ZIP archive' ); return false; }
    $zip->setArchiveComment( 'CloudZex Full Site Backup - ' . $time );

    /* add database.sql */
    $zip->addFile( $dbtmp, 'database.sql' );

    /* add wp-content files — stream directly, no intermediate array */
    $source  = rtrim( WP_CONTENT_DIR, '\\/' );
    $exclude = rtrim( $dir, '\\/' );
    $skip_dirs = array( 'cache', 'upgrade', 'cz-backups', '.trash', 'node_modules', '.git' );

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $added = 0;
    $max_add = 150000;
    $last_pct = 0;

    foreach ( $it as $file ) {
        if ( $file->isDir() ) continue;
        $rp = $file->getPathname();

        /* skip files inside backup directory */
        if ( strpos( $rp, $exclude ) === 0 ) continue;

        /* skip files inside excluded directories */
        $local = ltrim( substr( $rp, strlen( $source ) + 1 ), '\\/' );
        $skip = false;
        foreach ( $skip_dirs as $sd ) {
            if ( strpos( $local, $sd . '/' ) === 0 || strpos( $local, $sd . '\\' ) === 0 ) {
                $skip = true;
                break;
            }
        }
        if ( $skip ) continue;

        if ( $zip->addFile( $rp, 'wp-content/' . $local ) !== true ) continue;
        $added++;

        if ( $added % 500 === 0 && is_callable( $progress ) ) {
            $pct = round( $added / max( $added, 1000 ) * 50, 1 );
            if ( $pct > $last_pct ) { $last_pct = $pct; $progress( $pct, 100, "Adding files: {$added}" ); }
        }
        if ( $added >= $max_add ) break;
    }

    $zip->close();
    @unlink( $dbtmp );

    if ( ! file_exists( $tmp ) || filesize( $tmp ) == 0 ) {
        @unlink( $tmp );
        bdopt_add_log( 'error', 'Full backup failed: ZIP file is empty' );
        return false;
    }

    rename( $tmp, $zippath );
    $fsize = filesize( $zippath );
    bdopt_add_log( 'backup', "Full Site Backup created: {$zipname} (" . size_format( $fsize, 1 ) . ', ' . $added . ' files)' );

    return array(
        'name' => $zipname,
        'size' => $fsize,
        'date' => date( 'Y-m-d H:i:s', filemtime( $zippath ) ),
    );
}

function bdopt_restore_backup( $name, $progress = null ) {
    $dir  = realpath( bdopt_backup_dir() );
    $path = realpath( $dir . DIRECTORY_SEPARATOR . basename( $name ) );
    if ( false === $path || strpos( $path, $dir . DIRECTORY_SEPARATOR ) !== 0 || ! file_exists( $path ) ) {
        return array( 'success' => false, 'error' => 'Backup file not found.' );
    }

    set_time_limit( 0 );
    ignore_user_abort( true );

    $ext = pathinfo( $path, PATHINFO_EXTENSION );
    if ( $ext === 'gz' ) {
        $handle = gzopen( $path, 'r' );
        $is_gz = true;
    } else {
        $handle = fopen( $path, 'r' );
        $is_gz = false;
    }
    if ( ! $handle ) {
        return array( 'success' => false, 'error' => 'Could not open backup file.' );
    }

    $filesize  = filesize( $path );
    $read      = 0;
    $statement = '';
    $total     = 0;

    if ( is_callable( $progress ) ) {
        $progress( 0, 100, 'Starting import...' );
    }

    while ( true ) {
        $line = $is_gz ? gzgets( $handle, 65536 ) : fgets( $handle, 65536 );
        if ( $line === false ) break;

        $read += strlen( $line );
        $line = trim( $line );

        if ( empty( $line ) || strpos( $line, '--' ) === 0 || strpos( $line, '#' ) === 0 ) {
            continue;
        }

        $statement .= $line;

        if ( substr( $line, -1 ) === ';' ) {
            $stmt = trim( $statement );
            $statement = '';
            if ( empty( $stmt ) ) continue;

            global $wpdb;
            $result = $wpdb->query( $stmt );
            if ( $result === false ) {
                $is_gz ? gzclose( $handle ) : fclose( $handle );
                return array( 'success' => false, 'error' => $wpdb->last_error, 'at' => $total + 1 );
            }
            $total++;

            if ( is_callable( $progress ) && $filesize > 0 ) {
                $adj = $is_gz ? $read / 6 : $read;
                $pct = min( 99, round( $adj / $filesize * 100 ) );
                $progress( $pct, 100, "Executed {$total} queries..." );
            }
        }
    }

    $is_gz ? gzclose( $handle ) : fclose( $handle );

    if ( is_callable( $progress ) ) {
        $progress( 100, 100, "Import complete! {$total} queries executed." );
    }

    return array( 'success' => true, 'queries' => $total );
}

function bdopt_handle_upload_chunk( $upload_id, $chunk_index, $total_chunks, $data, $ext = 'sql' ) {
    $tmp_dir = WP_CONTENT_DIR . '/cz-backups/.import-tmp';
    if ( ! is_dir( $tmp_dir ) ) {
        mkdir( $tmp_dir, 0755, true );
    }
    $part_file = $tmp_dir . '/' . $upload_id . '.part';
    $done_file = $tmp_dir . '/' . $upload_id . '.done';

    $chunk = base64_decode( $data );
    if ( $chunk === false ) {
        return array( 'success' => false, 'error' => 'Invalid chunk data.' );
    }

    $fp = fopen( $part_file, 'ab' );
    if ( ! $fp ) {
        return array( 'success' => false, 'error' => 'Could not open temp file.' );
    }
    fwrite( $fp, $chunk );
    fclose( $fp );

    $chunks_done = (int) get_option( '_bdopt_import_chunks_' . $upload_id, 0 );
    $chunks_done++;
    update_option( '_bdopt_import_chunks_' . $upload_id, $chunks_done );

    if ( $chunks_done >= $total_chunks ) {
        $final_path = $tmp_dir . '/' . $upload_id . '.' . $ext;
        rename( $part_file, $final_path );
        delete_option( '_bdopt_import_chunks_' . $upload_id );
        file_put_contents( $done_file, $final_path );
        return array( 'success' => true, 'done' => true, 'path' => $final_path, 'ext' => $ext );
    }

    return array( 'success' => true, 'done' => false, 'received' => $chunks_done, 'total' => $total_chunks );
}

function bdopt_detect_and_replace_prefix( $path, $progress = null ) {
    global $wpdb;
    $current = $wpdb->prefix;
    if ( empty( $current ) ) return $path;

    $is_gz = pathinfo( $path, PATHINFO_EXTENSION ) === 'gz';
    $handle = $is_gz ? gzopen( $path, 'r' ) : fopen( $path, 'r' );
    if ( ! $handle ) return $path;

    $header = ( $is_gz ? gzread( $handle, 16384 ) : fread( $handle, 16384 ) );
    $is_gz ? gzclose( $handle ) : fclose( $handle );

    if ( preg_match( '/`([a-zA-Z0-9_]+?)(?:' . implode( '|', array(
        'options', 'posts', 'postmeta', 'users', 'usermeta',
        'comments', 'commentmeta', 'terms', 'termmeta',
        'term_taxonomy', 'term_relationships', 'links',
    ) ) . ')`/i', $header, $m ) ) {
        $old = $m[1];
    } else {
        $old = null;
    }

    if ( ! $old || $old === $current ) return $path;

    if ( is_callable( $progress ) ) $progress( 0, 100, "Adapting prefix {$old} → {$current}..." );

    $new_path = $path . '.pfx';
    $in  = $is_gz ? gzopen( $path, 'r' ) : fopen( $path, 'r' );
    $out = fopen( $new_path, 'w' );
    if ( ! $in || ! $out ) { @fclose( $in ); @fclose( $out ); return $path; }

    $filesize = filesize( $path );
    $written  = 0;
    while ( ! feof( $in ) ) {
        $chunk = ( $is_gz ? gzread( $in, 65536 ) : fread( $in, 65536 ) );
        if ( $chunk === false || $chunk === '' ) break;
        $chunk = preg_replace( '/`' . preg_quote( $old, '/' ) . '/', '`' . $current, $chunk );
        fwrite( $out, $chunk );
        $written += strlen( $chunk );
        if ( is_callable( $progress ) && $filesize > 0 ) {
            $pct = min( 99, round( $written / $filesize * 100 ) );
            $progress( $pct, 100, "Adapting prefix: {$old} → {$current}" );
        }
    }
    fclose( $in );
    fclose( $out );

    if ( $is_gz ) {
        $final = $path . '.gz';
        $gz = gzopen( $final, 'w9' );
        $in2 = fopen( $new_path, 'r' );
        while ( ! feof( $in2 ) ) {
            $chunk = fread( $in2, 65536 );
            if ( $chunk === false || $chunk === '' ) break;
            gzwrite( $gz, $chunk );
        }
        fclose( $in2 );
        gzclose( $gz );
        @unlink( $new_path );
        @unlink( $path );
        return $final;
    }

    @unlink( $path );
    rename( $new_path, $path );
    return $path;
}

function bdopt_restore_from_zip( $zippath, $progress = null ) {
    if ( ! class_exists( 'ZipArchive' ) ) {
        return array( 'success' => false, 'error' => 'ZipArchive PHP extension required to import ZIP files.' );
    }

    if ( is_callable( $progress ) ) $progress( 0, 100, 'Extracting ZIP file...' );

    $zip = new ZipArchive();
    if ( $zip->open( $zippath ) !== true ) {
        return array( 'success' => false, 'error' => 'Could not open ZIP file.' );
    }

    $index = $zip->locateName( 'database.sql' );
    if ( $index === false ) {
        $zip->close();
        return array( 'success' => false, 'error' => 'database.sql not found inside ZIP. This ZIP does not appear to be a CloudZex backup.' );
    }

    $content = $zip->getFromIndex( $index );
    if ( $content === false || strlen( $content ) === 0 ) {
        $zip->close();
        return array( 'success' => false, 'error' => 'Could not extract database.sql from ZIP.' );
    }

    $extracted = dirname( $zippath ) . '/' . pathinfo( $zippath, PATHINFO_FILENAME ) . '.sql';
    file_put_contents( $extracted, $content );
    unset( $content );

    if ( is_callable( $progress ) ) $progress( 10, 100, 'ZIP extracted, detecting prefix...' );

    bdopt_detect_and_replace_prefix( $extracted, function( $pct, $total, $msg ) use ( $progress ) {
        if ( is_callable( $progress ) ) $progress( 10 + round( $pct * 0.05 ), 100, $msg );
    } );

    if ( is_callable( $progress ) ) $progress( 15, 100, 'Starting import...' );

    $result = bdopt_import_sql( $extracted, function( $pct, $total, $msg ) use ( $progress ) {
        if ( is_callable( $progress ) ) {
            $adjusted = 15 + round( $pct * 0.7 );
            $progress( $adjusted, 100, $msg );
        }
    } );

    @unlink( $extracted );

    if ( ! $result['success'] ) {
        $zip->close();
        return $result;
    }

    /* extract wp-content/ from ZIP */
    if ( is_callable( $progress ) ) $progress( 85, 100, 'Extracting wp-content files...' );

    $entries = array();
    for ( $i = 0; $i < $zip->numEntries; $i++ ) {
        $name = $zip->getNameIndex( $i );
        if ( $name === 'database.sql' ) continue;
        if ( strpos( $name, 'wp-content/' ) === 0 ) {
            $entries[] = $name;
        }
    }

    if ( ! empty( $entries ) ) {
        $total_extract = count( $entries );
        /* extract one by one so we can report progress */
        for ( $ei = 0; $ei < $total_extract; $ei++ ) {
            $zip->extractTo( ABSPATH, $entries[ $ei ] );
            if ( is_callable( $progress ) && $total_extract > 0 ) {
                $epct = 85 + round( ( $ei + 1 ) / $total_extract * 14 );
                $progress( $epct, 100, "Extracting wp-content... {$ei}/{$total_extract}" );
            }
        }
    }
    $zip->close();

    if ( is_callable( $progress ) ) $progress( 100, 100, "Import complete! {$result['queries']} queries executed, wp-content extracted." );

    return array( 'success' => true, 'queries' => $result['queries'], 'extracted' => count( $entries ) );
}

function bdopt_import_sql( $filepath, $progress = null ) {
    if ( ! file_exists( $filepath ) ) {
        return array( 'success' => false, 'error' => 'File not found: ' . $filepath );
    }

    set_time_limit( 0 );
    ignore_user_abort( true );

    $ext = pathinfo( $filepath, PATHINFO_EXTENSION );
    if ( $ext === 'gz' ) {
        $handle = gzopen( $filepath, 'r' );
        $is_gz = true;
    } else {
        $handle = fopen( $filepath, 'r' );
        $is_gz = false;
    }
    if ( ! $handle ) {
        return array( 'success' => false, 'error' => 'Could not open file.' );
    }

    $filesize  = filesize( $filepath );
    $read      = 0;
    $statement = '';
    $total     = 0;

    if ( is_callable( $progress ) ) {
        $progress( 0, 100, 'Starting import...' );
    }

    while ( true ) {
        $line = $is_gz ? gzgets( $handle, 65536 ) : fgets( $handle, 65536 );
        if ( $line === false ) break;

        $read += strlen( $line );
        $line = trim( $line );

        if ( empty( $line ) || strpos( $line, '--' ) === 0 || strpos( $line, '#' ) === 0 ) {
            continue;
        }

        $statement .= $line;

        if ( substr( $line, -1 ) === ';' ) {
            $stmt = trim( $statement );
            $statement = '';
            if ( empty( $stmt ) ) continue;

            global $wpdb;
            $result = $wpdb->query( $stmt );
            if ( $result === false ) {
                $is_gz ? gzclose( $handle ) : fclose( $handle );
                return array( 'success' => false, 'error' => $wpdb->last_error, 'at' => $total + 1 );
            }
            $total++;

            if ( is_callable( $progress ) && $filesize > 0 ) {
                $adj = $is_gz ? $read / 6 : $read;
                $pct = min( 99, round( $adj / $filesize * 100 ) );
                $progress( $pct, 100, "Executed {$total} queries..." );
            }
        }
    }

    $is_gz ? gzclose( $handle ) : fclose( $handle );

    if ( is_callable( $progress ) ) {
        $progress( 100, 100, "Import complete! {$total} queries executed." );
    }

    return array( 'success' => true, 'queries' => $total );
}

function bdopt_restore_wp_backup( $name, $progress = null ) {
    $dir  = realpath( bdopt_backup_dir() );
    $path = realpath( $dir . DIRECTORY_SEPARATOR . basename( $name ) );
    if ( false === $path || strpos( $path, $dir . DIRECTORY_SEPARATOR ) !== 0 || ! file_exists( $path ) ) {
        return array( 'success' => false, 'error' => 'Backup file not found.' );
    }
    return bdopt_restore_from_zip( $path, $progress );
}

function bdopt_migrate_domain( $old_url, $new_url, $progress = null ) {
    global $wpdb;

    if ( empty( $old_url ) || empty( $new_url ) ) {
        return array( 'success' => false, 'error' => 'Old and new URLs are required.' );
    }

    if ( is_callable( $progress ) ) $progress( 0, 100, 'Starting domain migration...' );

    $old_url = rtrim( $old_url, '/' );
    $new_url = rtrim( $new_url, '/' );
    if ( $old_url === $new_url ) {
        return array( 'success' => true, 'message' => 'URLs are the same, nothing to replace.', 'total' => 0 );
    }

    set_time_limit( 0 );
    ignore_user_abort( true );

    /* ── Helper: recursively replace in arrays/objects ── */
    $rec_replace = function( $data ) use ( $old_url, $new_url, &$rec_replace ) {
        if ( is_string( $data ) ) {
            return str_replace( $old_url, $new_url, $data );
        }
        if ( is_array( $data ) ) {
            $r = array();
            foreach ( $data as $k => $v ) {
                $rk = is_string( $k ) ? $rec_replace( $k ) : $k;
                $r[$rk] = $rec_replace( $v );
            }
            return $r;
        }
        if ( is_object( $data ) ) {
            $r = new stdClass;
            foreach ( get_object_vars( $data ) as $k => $v ) {
                $rk = is_string( $k ) ? $rec_replace( $k ) : $k;
                $r->$rk = $rec_replace( $v );
            }
            return $r;
        }
        return $data;
    };

    /* ── Helper: search & replace a field, handling serialized data ── */
    $safereplace = function( $value ) use ( $old_url, $new_url, $rec_replace ) {
        if ( ! is_string( $value ) ) return $value;
        $un = @unserialize( $value );
        if ( $un !== false ) {
            return serialize( $rec_replace( $un ) );
        }
        return str_replace( $old_url, $new_url, $value );
    };

    $total   = 0;
    $steps   = 0;
    $tables  = array();

    $prefix = $wpdb->prefix;

    /* ── Step 1: wp_options ── */
    if ( is_callable( $progress ) ) $progress( 5, 100, 'Migrating wp_options...' );
    $rows = $wpdb->get_results( "SELECT option_id, option_value FROM {$prefix}options WHERE option_value LIKE '%{$old_url}%'" );
    foreach ( $rows as $r ) {
        $new_val = $safereplace( $r->option_value );
        if ( $new_val !== $r->option_value ) {
            $wpdb->update( $prefix . 'options', array( 'option_value' => $new_val ), array( 'option_id' => $r->option_id ) );
            $total++;
        }
    }
    $steps++;

    /* ── Step 2: wp_posts ── */
    if ( is_callable( $progress ) ) $progress( 20, 100, 'Migrating wp_posts...' );
    foreach ( array( 'post_content', 'post_excerpt', 'guid' ) as $col ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, {$col} FROM {$prefix}posts WHERE {$col} LIKE '%%%s%%'", $old_url
        ) );
        foreach ( $rows as $r ) {
            $new_val = str_replace( $old_url, $new_url, $r->$col );
            if ( $new_val !== $r->$col ) {
                $wpdb->update( $prefix . 'posts', array( $col => $new_val ), array( 'ID' => $r->ID ) );
                $total++;
            }
        }
    }
    $steps++;

    /* ── Step 3: wp_postmeta ── */
    if ( is_callable( $progress ) ) $progress( 40, 100, 'Migrating wp_postmeta...' );
    $rows = $wpdb->get_results( "SELECT meta_id, meta_value FROM {$prefix}postmeta WHERE meta_value LIKE '%{$old_url}%'" );
    foreach ( $rows as $r ) {
        $new_val = $safereplace( $r->meta_value );
        if ( $new_val !== $r->meta_value ) {
            $wpdb->update( $prefix . 'postmeta', array( 'meta_value' => $new_val ), array( 'meta_id' => $r->meta_id ) );
            $total++;
        }
    }
    $steps++;

    /* ── Step 4: wp_comments ── */
    if ( is_callable( $progress ) ) $progress( 55, 100, 'Migrating wp_comments...' );
    foreach ( array( 'comment_content', 'comment_author_url' ) as $col ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT comment_ID, {$col} FROM {$prefix}comments WHERE {$col} LIKE '%%%s%%'", $old_url
        ) );
        foreach ( $rows as $r ) {
            $new_val = str_replace( $old_url, $new_url, $r->$col );
            if ( $new_val !== $r->$col ) {
                $wpdb->update( $prefix . 'comments', array( $col => $new_val ), array( 'comment_ID' => $r->comment_ID ) );
                $total++;
            }
        }
    }
    $steps++;

    /* ── Step 5: wp_usermeta ── */
    if ( is_callable( $progress ) ) $progress( 70, 100, 'Migrating wp_usermeta...' );
    $rows = $wpdb->get_results( "SELECT umeta_id, meta_value FROM {$prefix}usermeta WHERE meta_value LIKE '%{$old_url}%'" );
    foreach ( $rows as $r ) {
        $new_val = $safereplace( $r->meta_value );
        if ( $new_val !== $r->meta_value ) {
            $wpdb->update( $prefix . 'usermeta', array( 'meta_value' => $new_val ), array( 'umeta_id' => $r->umeta_id ) );
            $total++;
        }
    }
    $steps++;

    /* ── Step 6: wp_termmeta ── */
    if ( bdopt_table_exists( $prefix . 'termmeta' ) ) {
        if ( is_callable( $progress ) ) $progress( 85, 100, 'Migrating wp_termmeta...' );
        $rows = $wpdb->get_results( "SELECT meta_id, meta_value FROM {$prefix}termmeta WHERE meta_value LIKE '%{$old_url}%'" );
        foreach ( $rows as $r ) {
            $new_val = $safereplace( $r->meta_value );
            if ( $new_val !== $r->meta_value ) {
                $wpdb->update( $prefix . 'termmeta', array( 'meta_value' => $new_val ), array( 'meta_id' => $r->meta_id ) );
                $total++;
            }
        }
    }
    $steps++;

    if ( is_callable( $progress ) ) $progress( 100, 100, "Domain migration complete! {$total} fields updated." );

    return array( 'success' => true, 'message' => "Domain migrated from {$old_url} to {$new_url}. {$total} fields updated.", 'total' => $total );
}

function bdopt_get_wp_cache_folder_info() {
    $dir = WP_CONTENT_DIR . '/cache';
    if ( ! is_dir( $dir ) ) {
        return array( 'size' => 0, 'human' => '0 B', 'count' => 0 );
    }
    $size = 0;
    $count = 0;
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
    foreach ( $it as $f ) {
        if ( $f->isFile() ) {
            $size += $f->getSize();
            $count++;
        }
    }
    return array( 'size' => $size, 'human' => size_format( $size, 1 ), 'count' => $count );
}

function bdopt_get_orphan_media_stats() {
    $dir = WP_CONTENT_DIR . '/uploads';
    if ( ! is_dir( $dir ) ) return array( 'size' => 0, 'human' => '0 B', 'count' => 0, 'orphans' => 0, 'orphan_size' => 0, 'orphan_human' => '0 B' );
    $size = 0; $count = 0;
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
    foreach ( $it as $f ) {
        if ( $f->isFile() ) { $size += $f->getSize(); $count++; }
    }
    return array( 'size' => $size, 'human' => size_format( $size, 1 ), 'count' => $count,
        'orphans' => 0, 'orphan_size' => 0, 'orphan_human' => '0 B' );
}

function bdopt_scan_orphan_media( $progress = null ) {
    global $wpdb;
    $dir = WP_CONTENT_DIR . '/uploads';
    if ( ! is_dir( $dir ) ) return array( 'success' => true, 'orphans' => array(), 'total' => 0, 'size' => 0 );

    set_time_limit( 0 );
    ignore_user_abort( true );

    if ( is_callable( $progress ) ) $progress( 0, 100, 'Scanning uploads...' );

    /* collect all attachment file paths from DB */
    $attached = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );
    $db_files = array();
    foreach ( $attached as $v ) {
        $db_files[ basename( $v ) ] = true;
    }

    /* also check guid-based filenames */
    $guids = $wpdb->get_col( "SELECT guid FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
    foreach ( $guids as $g ) {
        $bn = basename( $g );
        if ( $bn ) $db_files[ $bn ] = true;
    }

    /* scan uploads for orphans */
    $orphans = array();
    $total_size = 0;
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
    $checked = 0;
    foreach ( $it as $f ) {
        if ( ! $f->isFile() ) continue;
        $checked++;
        $bn = $f->getFilename();
        if ( ! isset( $db_files[ $bn ] ) ) {
            $rp = $f->getPathname();
            $orphans[] = $rp;
            $total_size += $f->getSize();
        }
        if ( $checked % 500 === 0 && is_callable( $progress ) ) {
            $pct = min( 99, round( $checked / max( 1, $checked + 100 ) * 100 ) );
            $progress( $pct, 100, "Scanned {$checked} files..." );
        }
    }

    if ( is_callable( $progress ) ) $progress( 100, 100, "Found " . count( $orphans ) . " orphan files (" . size_format( $total_size, 1 ) . ")" );
    return array( 'success' => true, 'orphans' => $orphans, 'total' => count( $orphans ), 'size' => $total_size );
}

function bdopt_delete_orphan_media( $files ) {
    $deleted = 0;
    $size = 0;
    foreach ( $files as $f ) {
        $rp = realpath( $f );
        if ( $rp === false ) continue;
        $dir = realpath( WP_CONTENT_DIR . '/uploads' );
        if ( $dir === false || strpos( $rp, $dir ) !== 0 ) continue;
        $s = filesize( $rp );
        if ( @unlink( $rp ) ) {
            $deleted++;
            $size += $s;
        }
        /* remove empty parent dirs */
        $parent = dirname( $rp );
        while ( $parent !== $dir ) {
            if ( @rmdir( $parent ) ) { $parent = dirname( $parent ); } else break;
        }
    }
    bdopt_add_log( 'clean', "Orphan Media: {$deleted} file(s) deleted (" . size_format( $size, 1 ) . ')' );
    return array( 'success' => true, 'deleted' => $deleted, 'size' => $size, 'human' => size_format( $size, 1 ) );
}