<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bdopt_render_page() {
    if ( ! current_user_can('manage_options') ) return;

    $s        = wp_parse_args( (array) get_option('bdopt_settings', array()), bdopt_defaults() );
    $last_run = get_option('bdopt_last_run', null);
    $db_size  = bdopt_get_db_size();
    $counts   = bdopt_get_counts();
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
    <button class="tab" type="button" data-panel="p-backup" role="tab" aria-selected="false">
        <span class="dashicons dashicons-backup"></span> Backup
    </button>
    <button class="tab" type="button" data-panel="p-system" role="tab" aria-selected="false">
        <span class="dashicons dashicons-info-outline"></span> System
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
            bdopt_card('autodraft',   'edit-page',        'c-dft', 'Auto Drafts',           $counts['autodraft'],   'WordPress auto-saved draft posts',
                '<label style="display:flex;align-items:center;gap:7px;font-size:12px;margin-top:12px;cursor:pointer;padding-top:11px;border-top:1px solid #f0f0f1"><span class="tog"><input type="checkbox" id="s-autodraft" ' . checked( (int) bdopt_s('clean_autodraft'), 1, false ) . '><span class="tog-sl"></span></span> Include in Clean All</label>');
            bdopt_card('spam',        'shield-alt',       'c-spm', 'Spam & Trash Comments', $counts['spam'],        'All spam & trash comments');
            bdopt_card('trashed',     'trash',            'c-tsh', 'Trashed Posts',         $counts['trashed'],     'All posts & pages in trash',
                '<label style="display:flex;align-items:center;gap:7px;font-size:12px;margin-top:12px;cursor:pointer;padding-top:11px;border-top:1px solid #f0f0f1"><span class="tog"><input type="checkbox" id="s-trashed" ' . checked( (int) bdopt_s('clean_trashed'), 1, false ) . '><span class="tog-sl"></span></span> Include in Clean All</label>');
            bdopt_card('orphan_meta', 'admin-site-alt3',  'c-orp', 'Orphan Meta',           $counts['orphan_meta'], 'Postmeta/commentmeta/usermeta/HPOS order meta with no parent');
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
            $cf = bdopt_get_wp_cache_folder_info();
            ?>
            <div class="card">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-folder-open"></span></div><div class="card-ttl">wp-content/cache/</div></div>
                <div class="card-body">
                    <div style="font-size:12px;color:#646970;margin-bottom:13px;line-height:1.5">
                        <strong><?php echo esc_html( $cf['human'] ); ?></strong> &middot; <?php echo esc_html( $cf['count'] ); ?> files
                    </div>
                    <button class="card-btn" type="button" data-wp-cache="1" style="--cc:#2271b1;--bg:#f0f6fc"<?php echo $cf['count'] === 0 ? ' disabled' : ''; ?>>
                        <span class="dashicons dashicons-trash"></span> Purge Folder
                    </button>
                </div>
            </div>
            <div class="card" id="orphan-media-card">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-media-default"></span></div><div class="card-ttl">Orphan Media</div></div>
                <div class="card-body">
                    <div style="font-size:12px;color:#646970;margin-bottom:13px;line-height:1.5">
                        Files in <code>uploads/</code> not referenced in the database. These are leftovers from previous imports or manual uploads.
                    </div>
                    <div id="orphan-info" style="font-size:13px;margin-bottom:13px;display:none">
                        <strong id="orphan-count">0</strong> orphan files &middot; <strong id="orphan-size">0 B</strong>
                    </div>
                    <div id="orphan-progress" style="display:none;margin-bottom:13px">
                        <div style="display:flex;justify-content:space-between;font-size:12px;color:#646970;margin-bottom:4px"><span id="orphan-msg">Scanning...</span><span id="orphan-pct">0%</span></div>
                        <div style="height:6px;background:#e0e0e0;border-radius:3px"><div id="orphan-bar" style="height:6px;background:#2271b1;border-radius:3px;width:0%"></div></div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="card-btn" type="button" id="orphan-scan-btn" style="--cc:#2271b1;--bg:#f0f6fc">
                            <span class="dashicons dashicons-search"></span> Scan
                        </button>
                        <button class="card-btn" type="button" id="orphan-delete-btn" style="--cc:#b32d2e;--bg:#fcf1f1;display:none">
                            <span class="dashicons dashicons-trash"></span> Delete
                        </button>
                    </div>
                </div>
            </div>
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
            <div style="display:flex;gap:8px;align-items:center">
                <button class="button" type="button" id="btn-download-orders" style="color:#2271b1;border-color:#2271b1">
                    <span class="dashicons dashicons-download" style="margin-top:-1px;vertical-align:middle"></span> Download CSV
                </button>
                <button class="button" type="button" id="btn-manual-delete" style="color:#fff;background:#d63638;border-color:#d63638">
                    <span class="dashicons dashicons-trash" style="margin-top:-1px;vertical-align:middle"></span> Delete Selected Orders
                </button>
                <button class="button" type="button" id="btn-delete-all-orders" style="color:#b32d2e;border-color:#b32d2e">
                    <span class="dashicons dashicons-warning" style="margin-top:-1px;vertical-align:middle"></span> Delete ALL Orders
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

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-admin-tools"></span> Table Tools</div></div>
        <div class="cards">
            <div class="card">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-yes-alt" style="color:#1a7d34"></span></div><div class="card-ttl">Check Tables</div></div>
                <div class="card-body">
                    <div style="font-size:12px;color:#646970;margin-bottom:13px;line-height:1.5">Runs <code>CHECK TABLE</code> on all WordPress tables to detect corruption.</div>
                    <div id="check-results" style="font-size:12px;margin-bottom:12px;max-height:200px;overflow-y:auto;display:none"></div>
                    <button class="card-btn" type="button" id="btn-check-tables" style="--cc:#2271b1;--bg:#f0f6fc"><span class="dashicons dashicons-search"></span> Check Tables</button>
                </div>
            </div>
            <div class="card">
                <div class="card-hd"><div class="card-ico"><span class="dashicons dashicons-warning" style="color:#b32d2e"></span></div><div class="card-ttl">Repair Tables</div></div>
                <div class="card-body">
                    <div style="font-size:12px;color:#646970;margin-bottom:13px;line-height:1.5">Runs <code>CHECK</code> then <code>REPAIR TABLE</code> on corrupted tables only.</div>
                    <div id="repair-results" style="font-size:12px;margin-bottom:12px;max-height:200px;overflow-y:auto;display:none"></div>
                    <button class="card-btn" type="button" id="btn-repair-tables" style="--cc:#b32d2e;--bg:#fcf1f1"><span class="dashicons dashicons-performance"></span> Check &amp; Repair</button>
                </div>
            </div>
        </div>
    </div>

    <div class="box">
        <div class="box-hd">
            <div class="box-hd-l"><span class="dashicons dashicons-networking"></span> MySQL Processes</div>
            <button class="btn-ref" type="button" id="btn-proc-ref"><span class="dashicons dashicons-update"></span> Refresh</button>
        </div>
        <div id="proc-body" style="padding:0 18px 14px"><div class="loading-row" style="display:none"><span class="bsp bsp-d"></span> Loading...</div></div>
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

<!-- ═══ PANEL: BACKUP ════════════════════════════════════════ -->
<div id="p-backup" class="panel" role="tabpanel">
    <div class="box" style="margin-bottom:8px;padding:10px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <span style="font-size:12px;font-weight:600;white-space:nowrap">Backup Mode:</span>
        <select id="s-backup-mode" class="sel" style="font-size:11px;padding:2px 20px 2px 6px;height:28px">
            <option value="background" <?php selected( bdopt_s('backup_mode','background'), 'background' ); ?>>Background (default)</option>
            <option value="browser" <?php selected( bdopt_s('backup_mode','background'), 'browser' ); ?>>Browser</option>
        </select>
        <span style="font-size:11px;color:#646970"><strong>Background</strong> = connection closes, progress polls in background (you can leave the page). <strong>Browser</strong> = stays open, wait on page until done.</span>
    </div>
    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-backup"></span> Database Backup</div></div>
        <div class="save-row" style="gap:12px">
            <button class="button button-primary" type="button" id="btn-backup" style="padding-left:20px;padding-right:20px;height:36px">
                <span class="dashicons dashicons-backup"></span> Create Backup Now
            </button>
            <button class="button button-small" type="button" id="btn-cancel-db" style="display:none;color:#d63638;border-color:#d63638;font-size:11px">Cancel</button>
            <span style="font-size:12px;color:#646970">Stored in <code>wp-content/cz-backups/</code> — large DBs may take a few minutes</span>
        </div>
        <div id="backup-list" style="padding:0 18px 14px">
            <?php
            $backups = bdopt_get_backups();
            if ( empty( $backups ) ) : ?>
                <div style="padding:10px 0;font-size:12px;color:#8c8f94">No backups yet.</div>
            <?php else : ?>
                <table class="brk-tbl" style="font-size:12px">
                    <thead><tr><th>File</th><th style="text-align:right">Size</th><th style="text-align:right">Date</th><th style="width:50px"></th><th style="width:60px"></th><th style="width:50px"></th></tr></thead>
                    <tbody>
                    <?php foreach ( $backups as $b ) : ?>
                        <tr>
                            <td class="mono"><?php echo esc_html( $b['name'] ); ?></td>
                            <td class="num-cell"><?php echo esc_html( size_format( $b['size'], 1 ) ); ?></td>
                            <td class="num-cell"><?php echo esc_html( $b['date'] ); ?></td>
                            <td class="num-cell"><button class="button button-small" type="button" data-dl="<?php echo esc_attr( $b['name'] ); ?>" style="font-size:11px;padding-left:10px;padding-right:10px">Download</button></td>
                            <td class="num-cell"><button class="button button-small" type="button" data-restore="<?php echo esc_attr( $b['name'] ); ?>" style="font-size:11px;padding-left:10px;padding-right:10px;color:#1a7d34;border-color:#1a7d34">Restore</button></td>
                            <td class="num-cell"><button class="button button-small" type="button" data-backup="<?php echo esc_attr( $b['name'] ); ?>" style="color:#d63638;border-color:#d63638;font-size:11px;padding-left:10px;padding-right:10px">Delete</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-portfolio"></span> Full Site Backup (DB + wp-content)</div></div>
        <div class="save-row" style="gap:12px">
            <button class="button button-primary" type="button" id="btn-wp-backup" style="padding-left:20px;padding-right:20px;height:36px">
                <span class="dashicons dashicons-portfolio"></span> Create Full Backup Now
            </button>
            <button class="button button-small" type="button" id="btn-cancel-wp" style="display:none;color:#d63638;border-color:#d63638;font-size:11px">Cancel</button>
            <span style="font-size:12px;color:#646970">Creates <code>database.sql</code> + <code>wp-content/</code> inside ZIP — site migration ready!</span>
        </div>
        <div id="wp-backup-list" style="padding:0 18px 14px">
            <?php
            $wp_backups = bdopt_get_wp_backups();
            if ( empty( $wp_backups ) ) : ?>
                <div style="padding:10px 0;font-size:12px;color:#8c8f94">No backups yet.</div>
            <?php else : ?>
                <table class="brk-tbl" style="font-size:12px">
                    <thead><tr><th>File</th><th style="text-align:right">Size</th><th style="text-align:right">Date</th><th style="width:50px"></th><th style="width:60px"></th><th style="width:50px"></th></tr></thead>
                    <tbody>
                    <?php foreach ( $wp_backups as $b ) : ?>
                        <tr>
                            <td class="mono"><?php echo esc_html( $b['name'] ); ?></td>
                            <td class="num-cell"><?php echo esc_html( size_format( $b['size'], 1 ) ); ?></td>
                            <td class="num-cell"><?php echo esc_html( $b['date'] ); ?></td>
                            <td class="num-cell"><button class="button button-small" type="button" data-dl-wp="<?php echo esc_attr( $b['name'] ); ?>" style="font-size:11px;padding-left:10px;padding-right:10px">Download</button></td>
                            <td class="num-cell"><button class="button button-small" type="button" data-restore-wp="<?php echo esc_attr( $b['name'] ); ?>" style="font-size:11px;padding-left:10px;padding-right:10px;color:#1a7d34;border-color:#1a7d34">Restore</button></td>
                            <td class="num-cell"><button class="button button-small" type="button" data-del-wp="<?php echo esc_attr( $b['name'] ); ?>" style="color:#d63638;border-color:#d63638;font-size:11px;padding-left:10px;padding-right:10px">Delete</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-upload"></span> Upload &amp; Import</div></div>
        <div class="save-row" style="gap:12px;flex-wrap:wrap">
            <input type="file" id="import-file-input" accept=".sql,.sql.gz,.gz,.zip" style="font-size:13px;flex:1;min-width:200px">
            <span style="font-size:12px;color:#646970;flex:0 0 100%">Accepts <code>.sql</code>, <code>.sql.gz</code> (DB backup) or <code>.zip</code> (Full Site Backup) — old site URL auto-detected from backup, no manual entry needed</span>
            <button class="button button-primary" type="button" id="btn-import-start" style="padding-left:20px;padding-right:20px;height:36px">
                <span class="dashicons dashicons-upload"></span> Upload &amp; Import
            </button>
            <button class="button button-secondary" type="button" id="btn-import-cancel" style="display:none;color:#d63638;border-color:#d63638;padding-left:20px;padding-right:20px;height:36px">
                <span class="dashicons dashicons-no"></span> Cancel
            </button>
        </div>
        <div id="import-progress-wrap" style="display:none;padding:10px 18px 18px">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
                <span id="import-status-text" style="font-size:12px;color:#646970">Uploading...</span>
                <span id="import-pct-text" style="font-size:12px;font-weight:600;color:#2271b1">0%</span>
            </div>
            <div style="width:100%;height:8px;background:#e5e5e5;border-radius:10px;overflow:hidden">
                <div id="import-progress-bar" style="width:0%;height:8px;background:linear-gradient(90deg,#2271b1,#00a0d2);border-radius:10px;transition:width .3s"></div>
            </div>
        </div>
    </div>
</div><!-- /p-backup -->

<!-- ═══ PANEL: SYSTEM ════════════════════════════════════════ -->
<div id="p-system" class="panel" role="tabpanel">
    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-info-outline"></span> System Information</div></div>
        <div id="sys-info-body"><div class="loading-row"><span class="bsp bsp-d"></span> Loading...</div></div>
    </div>

    <div class="box">
        <div class="box-hd"><div class="box-hd-l"><span class="dashicons dashicons-heart"></span> Health Check</div></div>
        <div id="health-body"><div class="loading-row"><span class="bsp bsp-d"></span> Loading...</div></div>
    </div>

    <div class="box">
        <div class="box-hd">
            <div class="box-hd-l"><span class="dashicons dashicons-list-view"></span> Activity Log <span id="log-count-badge" style="font-size:11px;font-weight:400;color:#646970"></span></div>
            <button class="btn-ref" type="button" id="btn-log-ref" style="font-size:11px"><span class="dashicons dashicons-update"></span> Refresh</button>
            <button class="btn-ref" type="button" id="btn-log-clear" style="font-size:11px;color:#d63638"><span class="dashicons dashicons-trash"></span> Clear</button>
        </div>
        <div id="log-body"><div class="loading-row"><span class="bsp bsp-d"></span> Loading...</div></div>
    </div>
</div><!-- /p-system -->

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
        bdopt_setting_toggle('s-spam','Spam &amp; Trash Comments','Delete all spam &amp; trash comments',$chk('clean_spam'));
        bdopt_setting_toggle('s-orphan','Orphan Meta','Delete postmeta/commentmeta/usermeta/HPOS order meta with no parent',$chk('clean_orphan_meta'));
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
<?php
}