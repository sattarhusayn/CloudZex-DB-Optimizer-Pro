if(typeof BDOPT_NONCE==='undefined')var BDOPT_NONCE='';
if(typeof BDOPT_AJAX==='undefined')var BDOPT_AJAX='';
var brkLoaded=false;

function xpost(data, ok, err){
    fetch(BDOPT_AJAX,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)})
    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(ok)
    .catch(err||function(e){ toast('Network Error!',true); console.error(e); });
}

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

function switchTab(pid){
    var btn=document.querySelector('#bdopt-tabs .tab[data-panel="'+pid+'"]');
    if(!btn) return;
    document.querySelectorAll('#bdopt .tab').forEach(function(t){ t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
    document.querySelectorAll('#bdopt .panel').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active'); btn.setAttribute('aria-selected','true');
    var pel=document.getElementById(pid);
    if(pel) pel.classList.add('active');
    if(pid==='p-breakdown'&&!brkLoaded){ brkLoaded=true; loadBreakdown(); }
    if(pid==='p-system'&&typeof loadSysPage==='function') loadSysPage();
    if(pid==='p-breakdown'&&typeof loadMySQLProcs==='function') setTimeout(loadMySQLProcs,300);
}
document.getElementById('bdopt-tabs').addEventListener('click',function(e){
    var btn=e.target.closest('.tab');
    if(!btn) return;
    var pid=btn.dataset.panel;
    if(history.replaceState) history.replaceState(null,'','#'+pid);
    else location.hash='#'+pid;
    switchTab(pid);
});
if(location.hash){
    var pid=location.hash.replace('#','');
    switchTab(pid);
    var ct=g('bdopt');
    if(ct&&ct.getBoundingClientRect().top<0) ct.scrollIntoView(true);
}

document.getElementById('badge-brk').addEventListener('click',function(){
    var t=document.querySelector('#bdopt-tabs .tab[data-panel="p-breakdown"]');
    if(t) t.click();
});

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
    var data={action:'bdopt_run_clean',nonce:BDOPT_NONCE,type:type};
    if(isAll){
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

function syncCounts(c){
    setN('cnt-sessions',   c.sessions);
    setN('cnt-orders',     c.orders);
    var ot=document.querySelector('#bdopt-tabs .tab[data-panel="p-orders"] .tbadge');
    if(c.orders>0){ if(!ot){ var otp=document.querySelector('#bdopt-tabs .tab[data-panel="p-orders"]'); if(otp){ var b=document.createElement('span'); b.className='tbadge'; b.textContent=Number(c.orders).toLocaleString(); otp.appendChild(b); } } else { ot.textContent=Number(c.orders).toLocaleString(); } }
    else if(ot){ ot.remove(); }
    if(c.order_statuses){
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

g('btn-save').addEventListener('click',function(){
    var btn=this, orig=btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="bsp bsp-d"></span> Saving...';
    xpost({
        action:'bdopt_save_settings',nonce:BDOPT_NONCE,
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
        backup_before_optimize: g('s-backup-before').checked?1:0,
        action_days:      g('s-action-days').value,
        log_days:         g('s-log-days').value,
        revision_keep:    g('s-rev-keep').value,
    },
    function(d){ btn.disabled=false; btn.innerHTML=orig; toast(d.success?'\u2713 '+d.data.message:'Error!',!d.success); },
    function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
});

var osBtn=g('btn-orders-save');
if(osBtn){
    osBtn.addEventListener('click',function(){
        var btn=this, orig=btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="bsp bsp-d"></span> Saving...';
        var osCbs=document.querySelectorAll('.s-os-cb:checked'), osVals=[];
        osCbs.forEach(function(cb){ osVals.push(cb.value); });
        xpost({
            action:'bdopt_save_settings',nonce:BDOPT_NONCE,
            clean_orders:     g('s-orders').checked?1:0,
            order_days:       g('s-order-days').value,
            order_statuses:   osVals.join(','),
        },
        function(d){ btn.disabled=false; btn.innerHTML=orig; toast(d.success?'\u2713 '+d.data.message:'Error!',!d.success); },
        function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
    });
}

var perfBtn=g('btn-perf-save');
if(perfBtn){
    perfBtn.addEventListener('click',function(){
        var btn=this, orig=btn.innerHTML;
        btn.disabled=true; btn.innerHTML='<span class="bsp bsp-d"></span> Saving...';
        xpost({
            action:'bdopt_save_settings',nonce:BDOPT_NONCE,
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
        if(fromVal||toVal) parts.push('Date: '+(fromVal||'*')+'\u2192'+(toVal||'*'));
        else { days=days||30; parts.push(days+'+ days'); }
        if(idFrom||idTo) parts.push('ID: '+(idFrom||'*')+'\u2192'+(idTo||'*'));
        if(!parts.length) parts.push(days+'+ days');
        var ftext=parts.join(', ');
        if(!confirm('['+ftext+'] / '+vals.join(', ')+' orders — permanently DELETE?\n\nThis cannot be undone!')) return;
        btn.disabled=true; btn.innerHTML='<span class="bsp"></span> Deleting...';
        xpost({
            action:'bdopt_run_clean',nonce:BDOPT_NONCE,type:'orders',
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

var daBtn=g('btn-delete-all-orders');
if(daBtn){
    daBtn.addEventListener('click',function(){
        var btn=this, orig=btn.innerHTML;
        if(!confirm('DELETE ALL ORDERS?\n\nThis will permanently delete EVERY order from HPOS + legacy tables including meta, items, addresses, and action scheduler hooks.\n\nThis CANNOT be undone!')) return;
        btn.disabled=true; btn.innerHTML='<span class="bsp"></span> Deleting all orders...';
        xpost({action:'bdopt_delete_all_orders',nonce:BDOPT_NONCE},
        function(res){
            btn.disabled=false; btn.innerHTML=orig;
            if(res.success){
                toast('\u2713 '+res.data.message,false);
                loadBreakdown();
            } else toast('\u2717 Delete failed!',true);
        },
        function(e){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); console.error(e); });
    });
}

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
            action:'bdopt_download_orders',nonce:BDOPT_NONCE,
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

function loadBreakdown(){
    var el=g('brk-body');
    el.innerHTML='<div class="loading-row"><span class="bsp bsp-d"></span> Loading...</div>';
    xpost({action:'bdopt_get_breakdown',nonce:BDOPT_NONCE},function(res){
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

(function(){
    var en=g('btn-cache-enable'), dis=g('btn-cache-disable'), msg=g('cache-msg');
    function doCache(act){
        if(msg){ msg.style.display='block'; msg.textContent='Processing...'; }
        xpost({action:'bdopt_cache_action',nonce:BDOPT_NONCE,cache_action:act},
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

document.getElementById('bdopt').addEventListener('click',function(e){
    var el=e.target.closest('[data-cache]');
    if(!el||el.disabled) return;
    var cache=el.dataset.cache;
    if(!cache) return;
    var orig=el.innerHTML;
    el.disabled=true; el.innerHTML='<span class="bsp bsp-d"></span> Purging...';
    xpost({action:'bdopt_purge_cache',nonce:BDOPT_NONCE,cache:cache},
        function(res){
            el.disabled=false; el.innerHTML=orig;
            if(res.success) toast('\u2713 '+res.data.message,false);
            else toast('\u2717 '+res.data.message,true);
        },
        function(){ el.disabled=false; el.innerHTML=orig; toast('Network Error!',true); }
    );
});

document.getElementById('bdopt').addEventListener('click',function(e){
    var el=e.target.closest('[data-wp-cache]');
    if(!el||el.disabled) return;
    var orig=el.innerHTML;
    el.disabled=true; el.innerHTML='<span class="bsp bsp-d"></span> Purging...';
    xpost({action:'bdopt_purge_wp_cache',nonce:BDOPT_NONCE},
        function(res){
            el.disabled=false; el.innerHTML=orig;
            if(res.success){ toast('\u2713 '+res.data.message,false); location.reload(); }
            else toast('\u2717 '+res.data.message,true);
        },
        function(){ el.disabled=false; el.innerHTML=orig; toast('Network Error!',true); }
    );
});

/* ─── Backup mode toggle auto-save ─── */
var bm=g('s-backup-mode');
if(bm){
    bm.addEventListener('change',function(){
        xpost({action:'bdopt_save_settings',nonce:BDOPT_NONCE,backup_mode:this.value},
        function(d){ if(d.success) toast('\u2713 Backup mode saved!',false); },
        function(){ toast('Network Error!',true); });
    });
}

(function(){
    var btn=g('btn-backup');
    if(!btn) return;
    var orig=btn.innerHTML;
    var cancelBtn=g('btn-cancel-db');
    if(cancelBtn){
        cancelBtn.addEventListener('click',function(){
            xpost({action:'bdopt_cancel_backup',nonce:BDOPT_NONCE},
            function(){ btn.disabled=false; btn.innerHTML=orig; cancelBtn.style.display='none'; toast('Backup cancelled.',false); },
            function(){ toast('Network Error!',true); });
        });
    }
    btn.addEventListener('click',function(){
        orig=btn.innerHTML;
        btn.disabled=true; btn.innerHTML='<span class="bsp"></span> Starting backup...';
        xpost({action:'bdopt_create_backup',nonce:BDOPT_NONCE},
        function(res){
            if(res.success&&res.data.started){
                btn.innerHTML='<span class="bsp"></span> Backing up <span id="bp-progress">0%</span>';
                pollBackup();
            } else if(res.success&&res.data.done){
                btn.disabled=false; btn.innerHTML=orig;
                toast('\u2713 Backup created: '+res.data.name,false);
                renderBackupsNow();
            } else {
                btn.disabled=false; btn.innerHTML=orig;
                toast('\u2717 Backup failed!',true);
            }
        },
        function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
        if(typeof BDOPT_BACKUP_MODE!=='undefined'&&BDOPT_BACKUP_MODE==='browser'){
            btn.innerHTML='<span class="bsp"></span> Backing up <span id="bp-progress">0%</span>';
            setTimeout(pollBackup,1000);
        }
    });
    function showCancel(){ if(cancelBtn) cancelBtn.style.display='inline-flex'; }
    function hideCancel(){ if(cancelBtn) cancelBtn.style.display='none'; }
    function pollBackup(){
        var cnt=parseInt(btn.dataset.bpPoll||0);
        if(cnt>=1500){ btn.disabled=false; btn.innerHTML=orig; hideCancel(); toast('Backup timed out.',true); return; }
        btn.dataset.bpPoll=cnt+1;
        xpost({action:'bdopt_backup_status',nonce:BDOPT_NONCE},
        function(res){
            if(!res.success){ btn.disabled=false; btn.innerHTML=orig; hideCancel(); toast('Status check failed!',true); return; }
            var p=res.data;
            if(p.status==='running'){
                showCancel();
                var pct=p.pct||0;
                var el=g('bp-progress');
                if(el) el.textContent=pct+'%';
                setTimeout(pollBackup,2000);
            } else if(p.status==='done'){
                hideCancel();
                btn.disabled=false; btn.innerHTML=orig;
                toast('\u2713 Backup created: '+p.file,false);
                renderBackupsNow();
            } else if(p.status==='error'){
                btn.disabled=false; btn.innerHTML=orig; hideCancel();
                toast('\u2717 '+(p.error||'Backup failed!'),true);
            } else {
                btn.disabled=false; btn.innerHTML=orig; hideCancel();
                renderBackupsNow();
            }
        },
        function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
    }
    function renderBackupsNow(){
        xpost({action:'bdopt_list_backups',nonce:BDOPT_NONCE},
        function(res){
            if(res.success) renderBackups(res.data.backups);
        });
    }
    document.getElementById('backup-list').addEventListener('click',function(ev){
        var dl=ev.target.closest('[data-dl]');
        if(dl&&!dl.disabled){
            var name=dl.dataset.dl;
            window.location.href=BDOPT_AJAX+'?action=bdopt_download_backup&file='+encodeURIComponent(name)+'&nonce='+BDOPT_NONCE;
            return;
        }
        var del=ev.target.closest('[data-backup]');
        if(!del||del.disabled) return;
        var name=del.dataset.backup;
        if(!confirm('Delete backup "'+name+'"? This cannot be undone.')) return;
        var orig=del.innerHTML;
        del.disabled=true; del.innerHTML='<span class="bsp bsp-d"></span>';
        xpost({action:'bdopt_delete_backup',nonce:BDOPT_NONCE,name:name},
        function(res){
            del.disabled=false; del.innerHTML=orig;
            if(res.success){
                toast('\u2713 Backup deleted.',false);
                renderBackups(res.data.backups);
            } else toast('\u2717 Delete failed!',true);
        },
        function(){ del.disabled=false; del.innerHTML=orig; toast('Network Error!',true); });
    });
    function renderBackups(backups){
        var el=g('backup-list');
        if(!backups||!backups.length){
            el.innerHTML='<div style="padding:10px 0;font-size:12px;color:#8c8f94">No backups yet.</div>';
            return;
        }
        function fmt(s){ if(s>=1073741824) return (s/1073741824).toFixed(2)+' GB'; if(s>=1048576) return (s/1048576).toFixed(1)+' MB'; if(s>=1024) return (s/1024).toFixed(0)+' KB'; return s+' B'; }
        var html='<table class="brk-tbl" style="font-size:12px"><thead><tr><th>File</th><th style="text-align:right">Size</th><th style="text-align:right">Date</th><th style="width:50px"></th><th style="width:60px"></th><th style="width:50px"></th></tr></thead><tbody>';
        backups.forEach(function(b){
            html+='<tr><td class="mono">'+esc(b.name)+'</td><td class="num-cell">'+fmt(parseInt(b.size)||0)+'</td><td class="num-cell">'+esc(b.date)+'</td><td class="num-cell"><button class="button button-small" type="button" data-dl="'+esc(b.name)+'" style="font-size:11px;padding-left:10px;padding-right:10px">Download</button></td><td class="num-cell"><button class="button button-small" type="button" data-restore="'+esc(b.name)+'" style="font-size:11px;padding-left:10px;padding-right:10px;color:#1a7d34;border-color:#1a7d34">Restore</button></td><td class="num-cell"><button class="button button-small" type="button" data-backup="'+esc(b.name)+'" style="color:#d63638;border-color:#d63638;font-size:11px;padding-left:10px;padding-right:10px">Delete</button></td></tr>';
        });
        el.innerHTML=html+'</tbody></table>';
    }
    xpost({action:'bdopt_backup_status',nonce:BDOPT_NONCE},function(res){
        if(!res.success) return;
        var p=res.data;
        if(p.status==='running'){
            showCancel();
            btn.disabled=true;
            btn.innerHTML='<span class="bsp"></span> Backing up <span id="bp-progress">'+(p.pct||0)+'%</span>';
            pollBackup();
        } else if(p.status==='done'){
            toast('\u2713 Backup completed: '+p.file,false);
            renderBackupsNow();
        } else if(p.status==='error'){
            toast('\u2717 '+(p.error||'Backup failed!'),true);
        }
    });
})();

(function(){
    var btn=g('btn-wp-backup');
    if(!btn) return;
    var orig=btn.innerHTML;
    var cancelBtn=g('btn-cancel-wp');
    if(cancelBtn){
        cancelBtn.addEventListener('click',function(){
            xpost({action:'bdopt_cancel_backup',nonce:BDOPT_NONCE},
            function(){ btn.disabled=false; btn.innerHTML=orig; cancelBtn.style.display='none'; toast('Backup cancelled.',false); },
            function(){ toast('Network Error!',true); });
        });
    }
    function showCancelWp(){ if(cancelBtn) cancelBtn.style.display='inline-flex'; }
    function hideCancelWp(){ if(cancelBtn) cancelBtn.style.display='none'; }
    btn.addEventListener('click',function(){
        orig=btn.innerHTML;
        btn.disabled=true; btn.innerHTML='<span class="bsp"></span> Scanning site...';
        xpost({action:'bdopt_create_wp_backup',nonce:BDOPT_NONCE},
        function(res){
            if(res.success&&res.data.started){
                btn.innerHTML='<span class="bsp"></span> Creating full backup <span id="wp-bp-progress">0%</span>';
                pollWpBackup();
            } else if(res.success&&res.data.done){
                btn.disabled=false; btn.innerHTML=orig;
                toast('\u2713 Full backup: '+res.data.name,false);
                refreshWpBackups();
            } else {
                btn.disabled=false; btn.innerHTML=orig;
                toast('\u2717 Full backup failed!',true);
            }
        },
        function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
        if(typeof BDOPT_BACKUP_MODE!=='undefined'&&BDOPT_BACKUP_MODE==='browser'){
            btn.innerHTML='<span class="bsp"></span> Creating full backup <span id="wp-bp-progress">0%</span>';
            setTimeout(pollWpBackup,1000);
        }
    });
    function pollWpBackup(){
        var cnt=parseInt(btn.dataset.wpPoll||0);
        if(cnt>=1500){ btn.disabled=false; btn.innerHTML=orig; hideCancelWp(); toast('Full backup timed out.',true); return; }
        btn.dataset.wpPoll=cnt+1;
        xpost({action:'bdopt_wp_backup_status',nonce:BDOPT_NONCE},
        function(res){
            if(!res.success){ btn.disabled=false; btn.innerHTML=orig; hideCancelWp(); toast('Status check failed!',true); return; }
            var p=res.data;
            if(p.status==='running'){
                showCancelWp();
                var el=g('wp-bp-progress');
                if(el) el.textContent=(p.pct||0)+'%';
                setTimeout(pollWpBackup,2000);
            } else if(p.status==='done'){
                btn.disabled=false; btn.innerHTML=orig; hideCancelWp();
                toast('\u2713 Full backup: '+p.file,false);
                refreshWpBackups();
            } else if(p.status==='error'){
                btn.disabled=false; btn.innerHTML=orig; hideCancelWp();
toast('\u2717 '+(p.error||'Full backup failed!'),true);
            } else {
                btn.disabled=false; btn.innerHTML=orig; hideCancelWp();
                refreshWpBackups();
            }
        },
        function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); });
    }
    function refreshWpBackups(){
        xpost({action:'bdopt_list_wp_backups',nonce:BDOPT_NONCE},
        function(res){
            if(res.success) renderWpBackups(res.data.backups);
        });
    }
    document.getElementById('wp-backup-list').addEventListener('click',function(ev){
        var dl=ev.target.closest('[data-dl-wp]');
        if(dl&&!dl.disabled){
            window.location.href=BDOPT_AJAX+'?action=bdopt_download_wp_backup&file='+encodeURIComponent(dl.dataset.dlWp)+'&nonce='+BDOPT_NONCE;
            return;
        }
        var rst=ev.target.closest('[data-restore-wp]');
        if(rst&&!rst.disabled){
            var rname=rst.dataset.restoreWp;
            if(!confirm('Restore full backup "'+rname+'"?\n\ndatabase.sql will be extracted and imported into your database.\nThis will OVERWRITE your current database!')) return;
            var rorig=rst.innerHTML;
            rst.disabled=true; rst.innerHTML='<span class="bsp bsp-d"></span> Restoring...';
            xpost({action:'bdopt_restore_wp_backup',nonce:BDOPT_NONCE,name:rname},
            function(res){
                if(res.success&&res.data.started){
                    pollImportStatus(function(){
                        rst.disabled=false; rst.innerHTML=rorig;
                        toast('\u2713 ZIP restore complete!',false);
                    },function(){
                        rst.disabled=false; rst.innerHTML=rorig;
                    },0);
                } else {
                    rst.disabled=false; rst.innerHTML=rorig;
                    toast('\u2717 Restore failed to start!',true);
                }
            },
            function(){ rst.disabled=false; rst.innerHTML=rorig; toast('Network Error!',true); });
            return;
        }
        var del=ev.target.closest('[data-del-wp]');
        if(!del||del.disabled) return;
        var name=del.dataset.delWp;
        if(!confirm('Delete "'+name+'"? This cannot be undone.')) return;
        var orig=del.innerHTML;
        del.disabled=true; del.innerHTML='<span class="bsp bsp-d"></span>';
        xpost({action:'bdopt_delete_wp_backup',nonce:BDOPT_NONCE,name:name},
        function(res){
            del.disabled=false; del.innerHTML=orig;
            if(res.success){
                toast('\u2713 Deleted.',false);
                refreshWpBackups();
            } else toast('\u2717 Delete failed!',true);
        },
        function(){ del.disabled=false; del.innerHTML=orig; toast('Network Error!',true); });
    });
    function renderWpBackups(backups){
        var el=g('wp-backup-list');
        if(!backups||!backups.length){
            el.innerHTML='<div style="padding:10px 0;font-size:12px;color:#8c8f94">No backups yet.</div>';
            return;
        }
        function fmt(s){ if(s>=1073741824) return (s/1073741824).toFixed(2)+' GB'; if(s>=1048576) return (s/1048576).toFixed(1)+' MB'; if(s>=1024) return (s/1024).toFixed(0)+' KB'; return s+' B'; }
        var html='<table class="brk-tbl" style="font-size:12px"><thead><tr><th>File</th><th style="text-align:right">Size</th><th style="text-align:right">Date</th><th style="width:50px"></th><th style="width:60px"></th><th style="width:50px"></th></tr></thead><tbody>';
        backups.forEach(function(b){
            html+='<tr><td class="mono">'+esc(b.name)+'</td><td class="num-cell">'+fmt(parseInt(b.size)||0)+'</td><td class="num-cell">'+esc(b.date)+'</td><td class="num-cell"><button class="button button-small" type="button" data-dl-wp="'+esc(b.name)+'" style="font-size:11px;padding-left:10px;padding-right:10px">Download</button></td><td class="num-cell"><button class="button button-small" type="button" data-restore-wp="'+esc(b.name)+'" style="font-size:11px;padding-left:10px;padding-right:10px;color:#1a7d34;border-color:#1a7d34">Restore</button></td><td class="num-cell"><button class="button button-small" type="button" data-del-wp="'+esc(b.name)+'" style="color:#d63638;border-color:#d63638;font-size:11px;padding-left:10px;padding-right:10px">Delete</button></td></tr>';
        });
        el.innerHTML=html+'</tbody></table>';
    }
    xpost({action:'bdopt_wp_backup_status',nonce:BDOPT_NONCE},function(res){
        if(!res.success) return;
        var p=res.data;
        if(p.status==='running'){
            showCancelWp();
            btn.disabled=true;
            btn.innerHTML='<span class="bsp"></span> Backing up <span id="wp-bp-progress">'+(p.pct||0)+'%</span>';
            pollWpBackup();
        } else if(p.status==='done'){
            toast('\u2713 Full backup completed: '+p.file,false);
            refreshWpBackups();
        } else if(p.status==='error'){
            toast('\u2717 '+(p.error||'wp-content backup failed!'),true);
        }
    });
})();

/* ─── DB RESTORE ─────────────────────────────────────────── */
document.getElementById('backup-list').addEventListener('click',function(ev){
    var el=ev.target.closest('[data-restore]');
    if(!el||el.disabled) return;
    var name=el.dataset.restore;
    if(!confirm('Restore backup "'+name+'"?\n\nThis will OVERWRITE your current database!\nMake sure you have a recent backup first.')) return;
    var orig=el.innerHTML;
    el.disabled=true; el.innerHTML='<span class="bsp bsp-d"></span> Restoring...';
    xpost({action:'bdopt_restore_backup',nonce:BDOPT_NONCE,name:name},
    function(res){
        if(res.success&&res.data.started){
            pollImportStatus(function(){
                el.disabled=false; el.innerHTML=orig;
                toast('\u2713 Restore complete!',false);
                xpost({action:'bdopt_get_breakdown',nonce:BDOPT_NONCE},function(){});
            },function(){
                el.disabled=false; el.innerHTML=orig;
            },0);
        } else {
            el.disabled=false; el.innerHTML=orig;
            toast('\u2717 Restore failed to start!',true);
        }
    },
    function(){ el.disabled=false; el.innerHTML=orig; toast('Network Error!',true); });
});

/* ─── IMPORT (chunked upload) ────────────────────────────── */
(function(){
    var input=g('import-file-input'), btn=g('btn-import-start'), cancelBtn=g('btn-import-cancel');
    var wrap=g('import-progress-wrap'), bar=g('import-progress-bar'), statusText=g('import-status-text'), pctText=g('import-pct-text');
    var uploadId=null, polling=false;

    if(!btn) return;

    btn.addEventListener('click',function(){
        var file=input.files[0];
        if(!file){ toast('Select a .sql or .sql.gz file first.',true); return; }

        btn.style.display='none';
        cancelBtn.style.display='inline-flex';
        wrap.style.display='block';
        updateImportUI('Uploading...',0);

        uploadId='imp_'+Date.now()+'_'+Math.random().toString(36).slice(2,8);
        var chunkSize=1024*1024;
        var totalChunks=Math.ceil(file.size/chunkSize);
        var current=0;

        function uploadNext(){
            if(current>=totalChunks) return;
            var start=current*chunkSize;
            var end=Math.min(start+chunkSize,file.size);
            var blob=file.slice(start,end);
            var reader=new FileReader();
            reader.onload=function(e){
                var base64=e.target.result.split(',')[1];
                var fext=input.files[0].name.split('.').pop();
                xpost({
                    action:'bdopt_upload_import_chunk',nonce:BDOPT_NONCE,
                    upload_id:uploadId, chunk_index:current, total_chunks:totalChunks,
                    data:base64, ext:fext,
                },
                function(res){
                    if(!res.success){
                        toast('\u2717 Upload error: '+(res.data&&res.data.message?res.data.message:'Unknown'),true);
                        resetImportUI();
                        return;
                    }
                    current++;
                    var pct=Math.round(current/totalChunks*50);
                    updateImportUI('Uploading... ('+current+'/'+totalChunks+')',pct);
                    if(current<totalChunks){
                        uploadNext();
                    } else {
                        updateImportUI('Importing database...',50);
                        pollImportStatus(function(){
                            resetImportUI();
                            toast('\u2713 Import complete!',false);
                        },function(){
                            resetImportUI();
                        });
                    }
                },
                function(){
                    toast('Network Error during upload!',true);
                    resetImportUI();
                });
            };
            reader.readAsDataURL(blob);
        }

        uploadNext();
    });

    cancelBtn.addEventListener('click',function(){
        xpost({action:'bdopt_cancel_import',nonce:BDOPT_NONCE},function(){
            resetImportUI();
            toast('Import cancelled.',false);
        });
    });

    function updateImportUI(msg,pct){
        if(statusText) statusText.textContent=msg;
        if(pctText) pctText.textContent=pct+'%';
        if(bar) bar.style.width=pct+'%';
    }

    function resetImportUI(){
        btn.style.display='inline-flex';
        cancelBtn.style.display='none';
        wrap.style.display='none';
        if(input) input.value='';
        polling=false;
    }
})();

/* ─── IMPORT POLLING (shared: restore + upload) ──────────── */
function pollImportStatus(onDone,onError,basePct){
    basePct=basePct||50;
    var wrap=g('import-progress-wrap');
    if(wrap) wrap.style.display='block';
    var cnt=0;
    (function poll(){
        cnt++;
        if(cnt>1500){
            toast('Import timed out.',true);
            if(onError) onError();
            return;
        }
        xpost({action:'bdopt_import_status',nonce:BDOPT_NONCE},
        function(res){
            if(!res.success){ if(onError) onError(); return; }
            var p=res.data;
            if(p.status==='running'){
                var el=g('import-progress-bar');
                var st=g('import-status-text');
                var pt=g('import-pct-text');
                var w=basePct+Math.round(p.pct*(100-basePct)/100);
                if(el) el.style.width=w+'%';
                if(st) st.textContent=p.msg||'Importing...';
                if(pt) pt.textContent=w+'%';
                setTimeout(poll,2000);
            } else if(p.status==='done'){
                if(onDone) onDone();
            } else if(p.status==='error'){
                toast('\u2717 Import error: '+(p.error||'Unknown error'),true);
                if(onError) onError();
            } else {
                if(onDone) onDone();
            }
        },
        function(){ if(onError) onError(); });
    })();
}

/* ─── Orphan Media scan ─── */
(function(){
    var scanBtn=g('orphan-scan-btn');
    var delBtn=g('orphan-delete-btn');
    if(!scanBtn) return;
    var scanning=false;
    var orphanFiles=[];

    function updateUI(data){
        var info=g('orphan-info');
        var cnt=g('orphan-count');
        var sz=g('orphan-size');
        if(cnt) cnt.textContent=data.total||data.orphans||0;
        if(sz) sz.textContent=data.human||data.orphan_human||'0 B';
        if(data.total>0||data.orphans>0){
            if(info) info.style.display='block';
            if(delBtn) delBtn.style.display='inline-flex';
        } else {
            if(info) info.style.display='none';
            if(delBtn) delBtn.style.display='none';
        }
    }

    function setProgress(pct,msg){
        var bar=g('orphan-bar');
        var pctEl=g('orphan-pct');
        var msgEl=g('orphan-msg');
        var prog=g('orphan-progress');
        if(prog) prog.style.display='block';
        if(bar) bar.style.width=pct+'%';
        if(pctEl) pctEl.textContent=pct+'%';
        if(msgEl) msgEl.textContent=msg;
    }

    scanBtn.addEventListener('click',function(){
        if(scanning) return;
        scanning=true;
        scanBtn.disabled=true;
        scanBtn.innerHTML='<span class="bsp bsp-d"></span> Scanning...';
        if(delBtn) delBtn.style.display='none';
        setProgress(0,'Starting scan...');

        xpost({action:'bdopt_orphan_scan',nonce:BDOPT_NONCE},
            function(res){
                if(!res.success){ scanning=false; scanBtn.disabled=false; scanBtn.innerHTML='<span class="dashicons dashicons-search"></span> Scan'; toast('\u2717 '+res.data.message,true); return; }
                /* start background process */
                xpost({action:'bdopt_orphan_process',nonce:BDOPT_NONCE},
                    function(pr){
                        if(!pr.success){ scanning=false; scanBtn.disabled=false; scanBtn.innerHTML='<span class="dashicons dashicons-search"></span> Scan'; toast('\u2717 '+pr.data.message,true); return; }
                        pollStatus();
                    },
                    function(){ scanning=false; scanBtn.disabled=false; scanBtn.innerHTML='<span class="dashicons dashicons-search"></span> Scan'; toast('Network Error!',true); }
                );
            },
            function(){ scanning=false; scanBtn.disabled=false; scanBtn.innerHTML='<span class="dashicons dashicons-search"></span> Scan'; toast('Network Error!',true); }
        );
    });

    function pollStatus(){
        xpost({action:'bdopt_orphan_status',nonce:BDOPT_NONCE},
            function(res){
                if(!res.success){ scanning=false; scanBtn.disabled=false; scanBtn.innerHTML='<span class="dashicons dashicons-search"></span> Scan'; return; }
                var d=res.data;
                if(d.status==='running'){
                    setProgress(d.pct||0,d.msg||'Scanning...');
                    setTimeout(pollStatus,2000);
                } else if(d.status==='done'){
                    scanning=false;
                    scanBtn.disabled=false;
                    scanBtn.innerHTML='<span class="dashicons dashicons-search"></span> Scan';
                    setProgress(100,'Complete');
                    setTimeout(function(){
                        var prog=g('orphan-progress');
                        if(prog) prog.style.display='none';
                    },2000);
                    if(d.data){
                        orphanFiles=d.data.orphans||[];
                        orphanSizeHuman=d.data.size?szfmt(d.data.size):'0 B';
                        updateUI({total:orphanFiles.length,human:orphanSizeHuman});
                        if(orphanFiles.length===0) toast('\u2713 No orphan files found.',false);
                        else toast('\u2713 Found '+orphanFiles.length+' orphan files ('+orphanSizeHuman+').',false);
                    }
                } else {
                    /* no transient — scan hasn't started yet or already done */
                    scanning=false;
                    scanBtn.disabled=false;
                    scanBtn.innerHTML='<span class="dashicons dashicons-search"></span> Scan';
                    var prog=g('orphan-progress');
                    if(prog) prog.style.display='none';
                }
            },
            function(){ scanning=false; scanBtn.disabled=false; scanBtn.innerHTML='<span class="dashicons dashicons-search"></span> Scan'; toast('Network Error!',true); }
        );
    }

    var orphanSizeHuman='0 B';
    if(delBtn){
        delBtn.addEventListener('click',function(){
            if(orphanFiles.length===0) return;
            if(!confirm('Delete '+orphanFiles.length+' orphan media files ('+orphanSizeHuman+')? This cannot be undone.')) return;
            delBtn.disabled=true;
            delBtn.innerHTML='<span class="bsp bsp-d"></span> Deleting...';
            xpost({action:'bdopt_orphan_delete',nonce:BDOPT_NONCE,files_json:JSON.stringify(orphanFiles)},
                function(res){
                    delBtn.disabled=false;
                    delBtn.innerHTML='<span class="dashicons dashicons-trash"></span> Delete';
                    if(res.success){
                        toast('\u2713 Deleted '+res.data.deleted+' files ('+res.data.human+').',false);
                        orphanFiles=[];
                        updateUI({total:0,human:'0 B'});
                        if(delBtn) delBtn.style.display='none';
                    } else {
                        toast('\u2717 '+res.data.message,true);
                    }
                },
                function(){ delBtn.disabled=false; delBtn.innerHTML='<span class="dashicons dashicons-trash"></span> Delete'; toast('Network Error!',true); }
            );
        });
    }

    function szfmt(bytes){
        if(bytes>=1073741824) return (bytes/1073741824).toFixed(1)+' GB';
        if(bytes>=1048576) return (bytes/1048576).toFixed(1)+' MB';
        if(bytes>=1024) return (bytes/1024).toFixed(1)+' KB';
        return bytes+' B';
    }
})();

/* ─── Table Check & Repair ─── */
(function(){
    var chkBtn=g('btn-check-tables');
    var repBtn=g('btn-repair-tables');
    function run(repair){
        var btn=repair?repBtn:chkBtn;
        if(!btn) return;
        var orig=btn.innerHTML;
        btn.disabled=true;
        btn.innerHTML='<span class="bsp bsp-d"></span> '+ (repair?'Checking &amp; Repairing...':'Checking...');
        var resDiv=g(repair?'repair-results':'check-results');
        if(resDiv) resDiv.style.display='block';
        xpost({action:'bdopt_check_tables',nonce:BDOPT_NONCE,repair:repair?'1':'0'},
            function(res){
                btn.disabled=false;
                btn.innerHTML=orig;
                if(!res.success){ toast('\u2717 '+res.data.message,true); return; }
                var d=res.data;
                if(resDiv){
                    var html='<table style="width:100%;border-collapse:collapse;font-size:11px">';
                    for(var i=0;i<d.tables.length;i++){
                        var t=d.tables[i];
                        var ok=t.status==='OK'||t.status==='Table is already up to date';
                        html+='<tr style="border-bottom:1px solid #f0f0f1"><td style="padding:4px 8px;font-family:monospace">'+esc(t.table)+'</td><td style="padding:4px 8px;text-align:right;color:'+(ok?'#1a7d34':'#b32d2e')+'">'+esc(t.status)+'</td></tr>';
                    }
                    html+='</table>';
                    resDiv.innerHTML=html;
                }
                if(d.repaired>0) toast('\u2713 Repaired '+d.repaired+' table(s)',false);
                else if(!repair) toast('\u2713 Check complete — no issues',false);
            },
            function(){ btn.disabled=false; btn.innerHTML=orig; toast('Network Error!',true); }
        );
    }
    if(chkBtn) chkBtn.addEventListener('click',function(){ run(false); });
    if(repBtn) repBtn.addEventListener('click',function(){ run(true); });
})();

/* ─── MySQL Process List ─── */
var loadMySQLProcs;
(function(){
    loadMySQLProcs=function(){
        var body=g('proc-body');
        if(!body) return;
        var ld=body.querySelector('.loading-row');
        if(ld) ld.style.display='block';
        xpost({action:'bdopt_mysql_processes',nonce:BDOPT_NONCE},
            function(res){
                if(ld) ld.style.display='none';
                if(!res.success){ body.innerHTML='<div style="padding:10px;color:#b32d2e">Error loading processes.</div>'; return; }
                var d=res.data;
                if(d.count===0){ body.innerHTML='<div style="padding:10px;font-size:12px;color:#8c8f94">No active processes.</div>'; return; }
                var html='<table class="brk-tbl" style="font-size:11px"><thead><tr><th>Id</th><th>User</th><th>Host</th><th>DB</th><th>Command</th><th>Time</th><th>State</th><th style="width:60px"></th></tr></thead><tbody>';
                for(var i=0;i<d.processes.length;i++){
                    var p=d.processes[i];
                    html+='<tr><td>'+esc(p.Id)+'</td><td>'+esc(p.User)+'</td><td style="font-size:10px">'+esc(p.Host)+'</td><td>'+esc(p.db||'')+'</td><td>'+esc(p.Command)+'</td><td class="num-cell">'+esc(p.Time)+'s</td><td>'+esc(p.State||'')+'</td>';
                    html+='<td class="num-cell"><button class="button button-small" data-kill="'+esc(p.Id)+'" style="color:#d63638;border-color:#d63638;font-size:10px;padding:0 6px">Kill</button></td></tr>';
                }
                html+='</tbody></table>';
                body.innerHTML=html;
                Array.prototype.forEach.call(body.querySelectorAll('[data-kill]'),function(el){
                    el.addEventListener('click',function(){
                        var pid=this.getAttribute('data-kill');
                        if(!confirm('Kill MySQL process '+pid+'?')) return;
                        var orig=this.innerHTML;
                        this.disabled=true; this.innerHTML='Killing...';
                        xpost({action:'bdopt_mysql_kill',nonce:BDOPT_NONCE,pid:pid},
                            function(r){
                                if(r.success) toast('\u2713 '+r.data.message,false);
                                else toast('\u2717 '+r.data.message,true);
                                loadMySQLProcs();
                            },
                            function(){ toast('Network Error!',true); loadMySQLProcs(); }
                        );
                    });
                });
            },
            function(){ if(ld) ld.style.display='none'; body.innerHTML='<div style="padding:10px;color:#b32d2e">Network error.</div>'; toast('Network Error!',true); }
        );
    }
    var refBtn=g('btn-proc-ref');
    if(refBtn) refBtn.addEventListener('click',loadMySQLProcs);

    /* load when breakdown tab becomes active */
    var tab=document.querySelector('.tab[data-panel="p-breakdown"]');
    if(tab&&tab.classList.contains('active')) setTimeout(loadMySQLProcs,500);

    /* also load when breakdown loads */
    var brkBody=g('brk-body');
    if(brkBody){
        var obs=new MutationObserver(function(){
            if(refBtn) setTimeout(loadMySQLProcs,500);
            obs.disconnect();
        });
        obs.observe(brkBody,{childList:true});
    }
})();

/* ─── System Tab ─── */
var loadSysPage;
(function(){
    loadSysPage=function(){
        var sysBody=g('sys-info-body');
        if(!sysBody) return;
        sysBody.innerHTML='<div class="loading-row"><span class="bsp bsp-d"></span> Loading...</div>';
        xpost({action:'bdopt_health_check',nonce:BDOPT_NONCE},
            function(res){
                if(!res.success){ sysBody.innerHTML='<div style="padding:10px;color:#b32d2e">Error loading system info.</div>'; return; }
                var d=res.data, info=d.info;
                /* System Info */
                var html='<div style="padding:14px 18px;font-size:12px"><table class="brk-tbl" style="width:auto">';
                var items=[
                    ['Site URL',info.site_url],
                    ['WP Version',info.wp_ver],
                    ['WP Memory Limit',info.wp_mem_limit],
                    ['PHP Version',info.php_ver],
                    ['Server Software',info.server_software],
                    ['Server OS',info.server_os],
                    ['MySQL Version',info.mysql_ver],
                    ['Database',info.db_name],
                    ['Table Prefix',info.table_prefix],
                    ['PHP Memory Limit',info.memory_limit],
                    ['Max Execution Time',info.max_exec+'s'],
                    ['Max Upload Size',info.max_upload],
                    ['ABSPATH',info.abs_path],
                    ['WP_CONTENT_DIR',info.wp_content_dir],
                ];
                for(var i=0;i<items.length;i++){
                    html+='<tr><td style="padding:4px 12px 4px 0;font-weight:600;white-space:nowrap">'+esc(items[i][0])+'</td><td style="padding:4px 0;font-family:monospace">'+esc(items[i][1])+'</td></tr>';
                }
                html+='</table></div>';
                sysBody.innerHTML=html;

                /* Health Check */
                var hBody=g('health-body');
                if(hBody){
                    html='<div style="padding:14px 18px">';
                    var chks=d.checks;
                    html+='<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px">';
                    for(var i=0;i<chks.length;i++){
                        var c=chks[i];
                        html+='<div style="border:1px solid '+(c.ok?'#c3c4c7':'#f0c')+';border-radius:6px;padding:12px;background:'+(c.ok?'#f6f7f7':'#fef3cd')+'"><div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><span style="color:'+(c.ok?'#1a7d34':'#b32d2e')+';font-size:16px">'+(c.ok?'&#10003;':'&#10007;')+'</span><span style="font-weight:600;font-size:13px">'+esc(c.name)+'</span></div><div style="font-size:11px;color:#646970">'+esc(c.detail)+'</div></div>';
                    }
                    html+='</div></div>';
                    hBody.innerHTML=html;
                }

                /* Multisite */
                var ms=d.multisite;
                if(hBody) hBody.innerHTML+='<div style="margin:14px 18px;border:1px solid #c3c4c7;border-radius:6px;background:'+(ms.is_multisite?'#fef3cd':'#f6f7f7')+';padding:12px;font-size:12px"><strong>'+(ms.is_multisite?'&#9888; Multisite:':'&#10003; Single Site:')+'</strong> '+esc(ms.message)+'</div>';

                /* Activity Log */
                loadLog();
            },
            function(){
                sysBody.innerHTML='<div style="padding:10px;color:#b32d2e">Network error loading system info.</div>';
                toast('Network Error!',true);
            }
        );
    }

    function loadLog(){
        var body=g('log-body');
        var badge=g('log-count-badge');
        if(!body) return;
        body.innerHTML='<div class="loading-row"><span class="bsp bsp-d"></span> Loading...</div>';
        xpost({action:'bdopt_get_log',nonce:BDOPT_NONCE},
            function(res){
                if(!res.success){ body.innerHTML='<div style="padding:10px;color:#b32d2e">Error loading log.</div>'; return; }
                var d=res.data;
                if(badge) badge.textContent='('+d.count+')';
                if(d.count===0){ body.innerHTML='<div style="padding:14px 18px;font-size:12px;color:#8c8f94">No activity recorded yet.</div>'; return; }
                var html='<div style="padding:0 18px 14px;max-height:400px;overflow-y:auto"><table class="brk-tbl" style="font-size:11px"><thead><tr><th style="width:160px">Time</th><th style="width:80px">Type</th><th>Message</th></tr></thead><tbody>';
                for(var i=0;i<d.log.length;i++){
                    var l=d.log[i];
                    var t=l.type||'';
                    var cls=t==='error'||t==='kill'?' style="color:#b32d2e"':t==='repair'?' style="color:#7a5c00"':'';
                    html+='<tr'+cls+'><td style="white-space:nowrap">'+esc(l.time)+'</td><td><code>'+esc(t)+'</code></td><td>'+esc(l.msg)+'</td></tr>';
                }
                html+='</tbody></table></div>';
                body.innerHTML=html;
            },
            function(){ body.innerHTML='<div style="padding:10px;color:#b32d2e">Network error.</div>'; toast('Network Error!',true); }
        );
    }

    /* Clear log */
    var clrBtn=g('btn-log-clear');
    if(clrBtn){
        clrBtn.addEventListener('click',function(){
            if(!confirm('Clear the entire activity log?')) return;
            xpost({action:'bdopt_clear_log',nonce:BDOPT_NONCE},
                function(res){
                    if(res.success){ toast('\u2713 Log cleared.',false); loadLog(); }
                    else toast('\u2717 '+res.data.message,true);
                },
                function(){ toast('Network Error!',true); }
            );
        });
    }

    var refBtn=g('btn-log-ref');
    if(refBtn) refBtn.addEventListener('click',loadLog);

    /* load immediately if system tab is already active (e.g. page load with hash) */
    var sysBtn=document.querySelector('.tab[data-panel="p-system"]');
    if(sysBtn&&sysBtn.classList.contains('active')) loadSysPage();
})();