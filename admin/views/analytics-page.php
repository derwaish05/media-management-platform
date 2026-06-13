<?php
/**
 * Media Analytics Dashboard — admin page view.
 *
 * Variables available from AnalyticsDashboard::renderPage():
 *   (none — data is loaded via REST API calls from JS on the page)
 *
 * @package MMP\Analytics
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Insufficient permissions.', 'media-management-platform') );
}

$restUrl      = rest_url( 'mmp/v1/analytics' );
$exportUrl    = rest_url( 'mmp/v1/analytics/export' );
$backfillUrl  = rest_url( 'mmp/v1/analytics/backfill-sizes' );
$usageScanUrl = rest_url( 'mmp/v1/usage/scan/advance' );
$nonce        = wp_create_nonce( 'wp_rest' );
?>
<div class="wrap mmp-analytics-wrap">

    <h1><?php esc_html_e( 'Media Analytics', 'media-management-platform'); ?></h1>

    <!-- ── Date range filter ──────────────────────────────────────────── -->
    <div class="mmp-analytics-toolbar" style="display:flex;align-items:center;gap:12px;margin:16px 0 24px;">
        <label for="mmp-range-select" style="font-weight:600"><?php esc_html_e( 'Date range:', 'media-management-platform'); ?></label>
        <select id="mmp-range-select" style="height:32px;padding:0 8px;border-radius:4px;border:1px solid #8c8f94;">
            <option value="7d"><?php esc_html_e( 'Last 7 days', 'media-management-platform'); ?></option>
            <option value="30d" selected><?php esc_html_e( 'Last 30 days', 'media-management-platform'); ?></option>
            <option value="90d"><?php esc_html_e( 'Last 90 days', 'media-management-platform'); ?></option>
            <option value="all"><?php esc_html_e( 'All time', 'media-management-platform'); ?></option>
        </select>
        <button id="mmp-rebuild-usage-btn" class="button" style="margin-left:auto"
                title="<?php esc_attr_e( 'Scan all posts and pages to rebuild the media usage index. Run this once after install so the “Unused Media” list is accurate.', 'media-management-platform'); ?>">
            ↻ <?php esc_html_e( 'Rebuild Usage Index', 'media-management-platform'); ?>
        </button>
        <button id="mmp-export-btn" class="button">
            ⬇ <?php esc_html_e( 'Export CSV', 'media-management-platform'); ?>
        </button>
        <span id="mmp-usage-scan-status" style="display:none;color:#646970;font-size:12px"></span>
        <span id="mmp-analytics-loading" style="display:none;color:#8c8f94">
            <?php esc_html_e( 'Loading…', 'media-management-platform'); ?>
        </span>
    </div>

    <!-- ── Summary cards ─────────────────────────────────────────────── -->
    <div id="mmp-summary-cards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
        <?php foreach ( [
            'total_files'    => __( 'Total Files', 'media-management-platform'),
            'total_storage'  => __( 'Total Storage', 'media-management-platform'),
            'ev_inserts'     => __( 'Inserts', 'media-management-platform'),
            'ev_downloads'   => __( 'Downloads', 'media-management-platform'),
        ] as $key => $label ) : ?>
            <div class="mmp-stat-card" data-key="<?php echo esc_attr( $key ); ?>"
                 style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;text-align:center;">
                <div class="mmp-stat-value" style="font-size:28px;font-weight:700;line-height:1.1;color:#1d2327">—</div>
                <div class="mmp-stat-label" style="margin-top:4px;font-size:12px;color:#646970"><?php echo esc_html( $label ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Charts row 1: Upload activity + Storage by type ───────────── -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;">
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;">
            <h3 style="margin:0 0 12px"><?php esc_html_e( 'Upload Activity', 'media-management-platform'); ?></h3>
            <canvas id="mmp-chart-uploads" height="120"></canvas>
        </div>
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;">
            <h3 style="margin:0 0 12px"><?php esc_html_e( 'Storage by File Type', 'media-management-platform'); ?></h3>
            <canvas id="mmp-chart-types" height="180"></canvas>
        </div>
    </div>

    <!-- ── Charts row 2: Storage by folder ───────────────────────────── -->
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:24px;">
        <h3 style="margin:0 0 12px"><?php esc_html_e( 'Storage by Folder', 'media-management-platform'); ?></h3>
        <canvas id="mmp-chart-folders" height="80"></canvas>
    </div>

</div><!-- .mmp-analytics-wrap -->

<!-- ── Inline styles ──────────────────────────────────────────────────── -->
<style>
.mmp-analytics-wrap .mmp-stat-card:hover { border-color: #2271b1; }
.mmp-analytics-wrap h3 { font-size: 14px; color: #1d2327; }
.mmp-analytics-wrap .mmp-thumb {
    width: 40px; height: 40px; object-fit: cover;
    border-radius: 3px; background: #f0f0f1;
}
</style>

<!-- Chart.js is bundled locally and enqueued via AnalyticsDashboard::enqueueAssets(). -->

<!-- ── Dashboard JS ──────────────────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    var REST_BASE      = <?php echo wp_json_encode( $restUrl ); ?>;
    var EXPORT_URL     = <?php echo wp_json_encode( $exportUrl ); ?>;
    var BACKFILL_URL   = <?php echo wp_json_encode( $backfillUrl ); ?>;
    var USAGE_SCAN_URL = <?php echo wp_json_encode( $usageScanUrl ); ?>;
    var NONCE          = <?php echo wp_json_encode( $nonce ); ?>;

    // Chart instances (kept to allow destroy on refresh).
    var chartUploads = null;
    var chartTypes   = null;
    var chartFolders = null;

    // -----------------------------------------------------------------------
    // Utility helpers
    // -----------------------------------------------------------------------

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        if (i < 0) i = 0;
        if (i >= units.length) i = units.length - 1;
        return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function formatNumber(n) {
        return n.toLocaleString();
    }

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    function setCard(key, value) {
        var card = document.querySelector('[data-key="' + key + '"] .mmp-stat-value');
        if (card) card.textContent = value;
    }

    // -----------------------------------------------------------------------
    // Fetch & render
    // -----------------------------------------------------------------------

    function loadDashboard() {
        var range = document.getElementById('mmp-range-select').value;
        var spinner = document.getElementById('mmp-analytics-loading');
        spinner.style.display = 'inline';

        fetch(REST_BASE + '/stats?range=' + encodeURIComponent(range), {
            headers: { 'X-WP-Nonce': NONCE },
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            spinner.style.display = 'none';
            if (!json.success || !json.data) return;
            var d = json.data;
            renderSummary(d.summary);
            renderUploadsChart(d.upload_activity);
            renderTypesChart(d.storage_type);
            renderFoldersChart(d.storage_folder);
        })
        .catch(function () {
            spinner.style.display = 'none';
        });
    }

    // -----------------------------------------------------------------------
    // Summary cards
    // -----------------------------------------------------------------------

    function renderSummary(s) {
        setCard('total_files',   formatNumber(s.total_files   || 0));
        setCard('total_storage', formatBytes(s.total_bytes    || 0));
        setCard('ev_inserts',    formatNumber((s.events && s.events.insert)   || 0));
        setCard('ev_downloads',  formatNumber((s.events && s.events.download) || 0));
    }

    // -----------------------------------------------------------------------
    // Upload activity line chart
    // -----------------------------------------------------------------------

    function renderUploadsChart(rows) {
        var ctx = document.getElementById('mmp-chart-uploads').getContext('2d');
        if (chartUploads) chartUploads.destroy();

        var labels = (rows || []).map(function (r) { return r.date; });
        var data   = (rows || []).map(function (r) { return r.count; });

        chartUploads = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: <?php echo wp_json_encode( __( 'Uploads', 'media-management-platform') ); ?>,
                    data:            data,
                    borderColor:     '#2271b1',
                    backgroundColor: 'rgba(34,113,177,0.08)',
                    fill:            true,
                    tension:         0.35,
                    pointRadius:     3,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 } },
                },
            },
        });
    }

    // -----------------------------------------------------------------------
    // Storage by type — doughnut chart
    // -----------------------------------------------------------------------

    var TYPE_COLORS = {
        image:       '#2271b1',
        video:       '#00a32a',
        audio:       '#dba617',
        application: '#8c5fd3',
        text:        '#d63638',
    };

    function renderTypesChart(rows) {
        var ctx = document.getElementById('mmp-chart-types').getContext('2d');
        if (chartTypes) chartTypes.destroy();

        var labels = (rows || []).map(function (r) {
            return r.type.charAt(0).toUpperCase() + r.type.slice(1) + ' (' + formatBytes(r.bytes) + ')';
        });
        var data   = (rows || []).map(function (r) { return r.bytes; });
        var colors = (rows || []).map(function (r) { return TYPE_COLORS[r.type] || '#8c8f94'; });

        chartTypes = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: data, backgroundColor: colors, borderWidth: 2 }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + formatBytes(ctx.raw);
                            },
                        },
                    },
                },
            },
        });
    }

    // -----------------------------------------------------------------------
    // Storage by folder — horizontal bar chart
    // -----------------------------------------------------------------------

    function renderFoldersChart(rows) {
        var ctx = document.getElementById('mmp-chart-folders').getContext('2d');
        if (chartFolders) chartFolders.destroy();

        var labels = (rows || []).map(function (r) { return r.folder_name; });
        var data   = (rows || []).map(function (r) { return r.bytes; });

        chartFolders = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: <?php echo wp_json_encode( __( 'Storage', 'media-management-platform') ); ?>,
                    data:            data,
                    backgroundColor: '#2271b1',
                    borderRadius:    3,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ' ' + formatBytes(ctx.raw); },
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { callback: function (v) { return formatBytes(v); } },
                    },
                },
            },
        });
    }

    // -----------------------------------------------------------------------
    // Export CSV
    // -----------------------------------------------------------------------

    document.getElementById('mmp-export-btn').addEventListener('click', function () {
        var range = document.getElementById('mmp-range-select').value;
        var url   = EXPORT_URL + '?range=' + encodeURIComponent(range) + '&_wpnonce=' + encodeURIComponent(NONCE);
        window.location.href = url;
    });

    // -----------------------------------------------------------------------
    // Rebuild usage index (chunked)
    // -----------------------------------------------------------------------
    // Scans existing posts/pages in batches to rebuild the media usage table,
    // so the "Unused Media" list reflects real usage. No cron required.

    (function () {
        var btn    = document.getElementById('mmp-rebuild-usage-btn');
        var status = document.getElementById('mmp-usage-scan-status');
        if (!btn) return;

        function runBatch(offset, totalRefs) {
            fetch(USAGE_SCAN_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify({ offset: offset }),
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success || !json.data) {
                    throw new Error('bad response');
                }
                var d    = json.data;
                var refs = totalRefs + (d.references || 0);

                if (!d.done) {
                    status.textContent = 'Scanning ' + d.processed + ' / ' + (d.total || '?') + '…';
                    runBatch(d.processed, refs);
                } else {
                    status.textContent = '✓ Done — ' + d.processed + ' posts scanned, ' + refs + ' references indexed.';
                    btn.disabled = false;
                }
            })
            .catch(function () {
                status.textContent = <?php echo wp_json_encode( esc_html__( 'Scan failed. Please try again.', 'media-management-platform') ); ?>;
                btn.disabled = false;
            });
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            status.style.display = 'inline';
            status.textContent = <?php echo wp_json_encode( esc_html__( 'Starting…', 'media-management-platform') ); ?>;
            runBatch(0, 0);
        });
    }());

    // -----------------------------------------------------------------------
    // Range selector
    // -----------------------------------------------------------------------

    document.getElementById('mmp-range-select').addEventListener('change', loadDashboard);

    // -----------------------------------------------------------------------
    // Filesize backfill
    // -----------------------------------------------------------------------
    // Media uploaded before the plugin was active has no recorded file size,
    // which leaves the storage stats empty. Populate it in batches, then
    // refresh the dashboard once complete.

    function backfillSizes(onDone) {
        fetch(BACKFILL_URL, {
            method:  'POST',
            headers: { 'X-WP-Nonce': NONCE },
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json && json.success && json.data && json.data.remaining > 0) {
                backfillSizes(onDone); // more batches remain
            } else {
                onDone();
            }
        })
        .catch(function () { onDone(); });
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    function boot() {
        // Show whatever we have immediately, then backfill missing sizes and
        // refresh so storage figures fill in.
        loadDashboard();
        backfillSizes(loadDashboard);
    }

    // Chart.js is enqueued in the footer (mmp-chartjs), so wait for it.
    if (document.readyState === 'complete') {
        boot();
    } else {
        window.addEventListener('load', boot);
    }

}());
</script>
