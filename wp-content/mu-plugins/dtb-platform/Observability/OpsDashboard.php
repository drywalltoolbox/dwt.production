<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Ops Dashboard — Must-Use Plugin
 *
 * Centerpiece operations dashboard for Drywall Toolbox site administrators.
 * Provides KPI panels, order/inventory/repair/rewards summaries,
 * audit logging, AJAX data endpoints, cron refresh, and a REST health route.
 *
 * Sections:
 *   1.  Constants & configuration
 *   2.  Database: audit log table creation
 *   3.  Admin menu & sub-menus (slug: dtb-ops)
 *   4.  Enqueue dashboard assets
 *   5.  Dashboard page render
 *   6.  AJAX: KPI data endpoint
 *   7.  AJAX: Audit log endpoint
 *   8.  Audit log writer
 *   9.  Cron: background KPI refresh
 *   10. REST: dtb/v1/health endpoint
 *   11. Capability helpers
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) && ! dtb_is_rest_api_request() ) {
	return;
}

// =============================================================================
// SECTION 1 — CONSTANTS & CONFIGURATION
// =============================================================================

if ( ! defined( 'DTB_OPS_VERSION' ) )    define( 'DTB_OPS_VERSION',    '2.0.0' );
if ( ! defined( 'DTB_OPS_DB_VERSION' ) ) define( 'DTB_OPS_DB_VERSION', '1' );

// Custom capabilities.
if ( ! defined( 'DTB_CAP_OPS_ADMIN' ) )   define( 'DTB_CAP_OPS_ADMIN',   'dtb_admin_ops' );
if ( ! defined( 'DTB_CAP_ACCOUNTING' ) )  define( 'DTB_CAP_ACCOUNTING',  'dtb_accounting' );
if ( ! defined( 'DTB_CAP_SUPPORT' ) )     define( 'DTB_CAP_SUPPORT',     'dtb_support' );
if ( ! defined( 'DTB_CAP_CATALOG' ) )     define( 'DTB_CAP_CATALOG',     'dtb_catalog' );

// Cache TTLs (seconds).
if ( ! defined( 'DTB_OPS_TTL_KPIS' ) )      define( 'DTB_OPS_TTL_KPIS',      900 );
if ( ! defined( 'DTB_OPS_TTL_ORDERS' ) )    define( 'DTB_OPS_TTL_ORDERS',    300 );
if ( ! defined( 'DTB_OPS_TTL_INVENTORY' ) ) define( 'DTB_OPS_TTL_INVENTORY', 300 );
if ( ! defined( 'DTB_OPS_TTL_REPAIRS' ) )   define( 'DTB_OPS_TTL_REPAIRS',   300 );

// Audit log.
if ( ! defined( 'DTB_OPS_AUDIT_RETENTION' ) ) define( 'DTB_OPS_AUDIT_RETENTION', 90 );

// =============================================================================
// SECTION 2 — DATABASE: AUDIT LOG TABLE
// =============================================================================

add_action( 'admin_init', 'dtb_ops_maybe_create_db' );

/**
 * Create or upgrade the {prefix}dtb_audit_log table if needed.
 */
function dtb_ops_maybe_create_db(): void {
	if ( (string) get_option( 'dtb_ops_db_version', '' ) === DTB_OPS_DB_VERSION ) {
		return;
	}

	global $wpdb;

	$table   = $wpdb->prefix . 'dtb_audit_log';
	$charset = $wpdb->get_charset_collate();

	// dbDelta requires exactly 2 spaces before PRIMARY KEY and KEY lines.
	$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  log_timestamp datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(120) NOT NULL DEFAULT '',
  context longtext NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY log_timestamp (log_timestamp),
  KEY user_id (user_id),
  KEY event (event)
) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'dtb_ops_db_version', DTB_OPS_DB_VERSION );
	update_option( 'dtb_ops_version',    DTB_OPS_VERSION );
}

// =============================================================================
// SECTION 3 — ADMIN MENU
// =============================================================================

add_action( 'admin_menu', 'dtb_ops_register_menu', 6 );

/**
 * Register the DTB Ops top-level menu and submenus.
 */
function dtb_ops_register_menu(): void {
	if ( ! dtb_ops_can( 'manage_options' ) ) {
		return;
	}

	add_menu_page(
		__( 'DTB Ops', 'dtb' ),
		__( 'DTB Ops', 'dtb' ),
		'manage_options',
		'dtb-ops',
		'dtb_ops_render_dashboard',
		'dashicons-chart-area',
		25
	);

	add_submenu_page(
		'dtb-ops',
		__( 'Dashboard', 'dtb' ),
		__( 'Dashboard', 'dtb' ),
		'manage_options',
		'dtb-ops',
		'dtb_ops_render_dashboard'
	);

	add_submenu_page(
		'dtb-ops',
		__( 'Audit Log', 'dtb' ),
		__( 'Audit Log', 'dtb' ),
		'manage_options',
		'dtb-ops-audit',
		'dtb_ops_render_audit_page'
	);
}

// =============================================================================
// SECTION 4 — ENQUEUE ASSETS
// =============================================================================

add_action( 'admin_enqueue_scripts', 'dtb_ops_enqueue_assets' );

/**
 * Enqueue dashboard JS/CSS only on DTB Ops pages.
 *
 * @param string $hook Current admin page hook.
 */
function dtb_ops_enqueue_assets( string $hook ): void {
	if ( false === strpos( $hook, 'dtb-ops' ) ) {
		return;
	}

	wp_enqueue_script( 'jquery' );

	$bootstrap = [
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'dtb_ops_nonce' ),
		'pollInterval'=> 180000,
		'version'     => DTB_OPS_VERSION,
	];

	wp_add_inline_script(
		'jquery',
		'window.dtbOps = ' . wp_json_encode( $bootstrap ) . ';',
		'before'
	);

	wp_add_inline_script(
		'jquery',
		dtb_ops_inline_js(),
		'after'
	);
}

/**
 * Inline CSS for the ops dashboard — v2 design system.
 *
 * @return string
 */
function dtb_ops_inline_css(): string {
	return <<<'CSS'
/* ── DTB Ops v2 Design System ──────────────────────────────────── */
.dtb-ops-wrap{--dtb-bg:#f1f5f9;--dtb-card:#fff;--dtb-border:#e2e8f0;--dtb-text:#1e293b;--dtb-muted:#64748b;--dtb-primary:#4f46e5;--dtb-primary-lt:#eef2ff;--dtb-success:#10b981;--dtb-success-lt:#ecfdf5;--dtb-warning:#f59e0b;--dtb-warning-lt:#fffbeb;--dtb-danger:#ef4444;--dtb-danger-lt:#fef2f2;--dtb-info:#0ea5e9;--dtb-info-lt:#f0f9ff;--dtb-purple:#8b5cf6;--dtb-purple-lt:#f5f3ff;--dtb-radius:12px;--dtb-radius-sm:8px;--dtb-shadow:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.05);--dtb-shadow-md:0 4px 16px rgba(0,0,0,.1);--dtb-font:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;--dtb-mono:"SF Mono",SFMono-Regular,ui-monospace,"Courier New",monospace}

/* ── Layout ────────────────────────────────────────────────────── */
.dtb-ops-wrap{margin:0 0 40px;padding:0;font-family:var(--dtb-font);color:var(--dtb-text);background:var(--dtb-bg);min-height:70vh}
.dtb-ops-wrap *{box-sizing:border-box}

/* ── Page Header ───────────────────────────────────────────────── */
.dtb-ops-header{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);color:#fff;padding:22px 28px;display:flex;align-items:center;justify-content:space-between;border-radius:0 0 18px 18px;margin-bottom:28px;box-shadow:0 6px 24px rgba(15,23,42,.35)}
.dtb-ops-header-left{display:flex;align-items:center;gap:16px}
.dtb-ops-logo{width:52px;height:52px;background:rgba(255,255,255,.12);border-radius:14px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.2);flex-shrink:0}
.dtb-ops-logo .dashicons{font-size:26px;width:26px;height:26px;color:#818cf8}
.dtb-ops-header h1{margin:0;font-size:22px;font-weight:800;color:#fff;letter-spacing:-.02em;text-shadow:none;line-height:1.2}
.dtb-ops-header-sub{display:flex;align-items:center;gap:8px;margin-top:4px}
.dtb-version-badge{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;letter-spacing:.04em;color:rgba(255,255,255,.9)}
.dtb-ops-header-tagline{font-size:12px;color:rgba(255,255,255,.55)}
.dtb-ops-header-right{display:flex;align-items:center;gap:12px;flex-shrink:0}
.dtb-live-dot{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#86efac;letter-spacing:.08em;text-transform:uppercase}
.dtb-live-dot::before{content:'';width:7px;height:7px;background:#4ade80;border-radius:50%;box-shadow:0 0 0 2px rgba(74,222,128,.3);animation:dtbPulse 2s ease-in-out infinite}
.dtb-last-updated{font-size:11px;color:rgba(255,255,255,.5)}
.dtb-btn-refresh{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);color:#fff;border-radius:9px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background .15s ease;line-height:1;font-family:var(--dtb-font)}
.dtb-btn-refresh:hover{background:rgba(255,255,255,.22);color:#fff}
.dtb-btn-refresh .dtb-spin-icon{font-size:15px;width:15px;height:15px;line-height:1;display:inline-block}
.dtb-btn-refresh.dtb-loading .dtb-spin-icon{animation:dtbSpin .65s linear infinite}

/* ── System Status Row ─────────────────────────────────────────── */
.dtb-status-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin-bottom:24px}
.dtb-status-card{background:var(--dtb-card);border:1px solid var(--dtb-border);border-radius:var(--dtb-radius-sm);padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:var(--dtb-shadow)}
.dtb-status-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dtb-status-icon--ok{background:#ecfdf5;color:#10b981}
.dtb-status-icon--info{background:#f0f9ff;color:#0ea5e9}
.dtb-status-icon--warn{background:#fffbeb;color:#f59e0b}
.dtb-status-icon .dashicons{font-size:18px;width:18px;height:18px}
.dtb-status-label{font-size:11px;font-weight:600;color:var(--dtb-muted);text-transform:uppercase;letter-spacing:.05em}
.dtb-status-value{font-size:14px;font-weight:700;color:var(--dtb-text);margin-top:1px}

/* ── KPI Grid ──────────────────────────────────────────────────── */
.dtb-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(182px,1fr));gap:16px;margin-bottom:28px}
.dtb-kpi-card{background:var(--dtb-card);border:1px solid var(--dtb-border);border-radius:var(--dtb-radius);padding:20px;display:flex;flex-direction:column;gap:14px;box-shadow:var(--dtb-shadow);transition:box-shadow .2s ease,transform .2s ease;position:relative;overflow:hidden}
.dtb-kpi-card:hover{box-shadow:var(--dtb-shadow-md);transform:translateY(-2px)}
.dtb-kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--dtb-radius) var(--dtb-radius) 0 0}
.dtb-kpi-card--primary::before{background:linear-gradient(90deg,#4f46e5,#818cf8)}
.dtb-kpi-card--success::before{background:linear-gradient(90deg,#10b981,#34d399)}
.dtb-kpi-card--warning::before{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
.dtb-kpi-card--danger::before{background:linear-gradient(90deg,#ef4444,#f87171)}
.dtb-kpi-card--info::before{background:linear-gradient(90deg,#0ea5e9,#38bdf8)}
.dtb-kpi-card--purple::before{background:linear-gradient(90deg,#8b5cf6,#a78bfa)}
.dtb-kpi-card-top{display:flex;align-items:flex-start;justify-content:space-between}
.dtb-kpi-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dtb-kpi-icon .dashicons{font-size:20px;width:20px;height:20px;margin:0}
.dtb-kpi-card--primary .dtb-kpi-icon{background:#eef2ff;color:#4f46e5}
.dtb-kpi-card--success .dtb-kpi-icon{background:#ecfdf5;color:#10b981}
.dtb-kpi-card--warning .dtb-kpi-icon{background:#fffbeb;color:#f59e0b}
.dtb-kpi-card--danger  .dtb-kpi-icon{background:#fef2f2;color:#ef4444}
.dtb-kpi-card--info    .dtb-kpi-icon{background:#f0f9ff;color:#0ea5e9}
.dtb-kpi-card--purple  .dtb-kpi-icon{background:#f5f3ff;color:#8b5cf6}
.dtb-kpi-badge{font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;padding:3px 9px;border-radius:20px;flex-shrink:0}
.dtb-kpi-badge--ok  {background:#ecfdf5;color:#059669}
.dtb-kpi-badge--warn{background:#fffbeb;color:#d97706}
.dtb-kpi-badge--crit{background:#fef2f2;color:#dc2626}
.dtb-kpi-badge--info{background:#f0f9ff;color:#0284c7}
.dtb-kpi-value{font-size:32px;font-weight:900;line-height:1;color:var(--dtb-text);letter-spacing:-.03em;transition:color .25s}
.dtb-kpi-label{font-size:11px;font-weight:700;color:var(--dtb-muted);text-transform:uppercase;letter-spacing:.06em;margin-top:3px}

/* ── Section Cards ─────────────────────────────────────────────── */
.dtb-section{background:var(--dtb-card);border:1px solid var(--dtb-border);border-radius:var(--dtb-radius);margin-bottom:24px;box-shadow:var(--dtb-shadow);overflow:hidden}
.dtb-section-header{padding:15px 20px;border-bottom:1px solid var(--dtb-border);display:flex;align-items:center;justify-content:space-between;background:#fafbfc}
.dtb-section-title{font-size:14px;font-weight:700;color:var(--dtb-text);display:flex;align-items:center;gap:8px;margin:0;line-height:1}
.dtb-section-title .dashicons{font-size:16px;width:16px;height:16px;color:var(--dtb-muted)}
.dtb-section-body{padding:16px 20px}

/* ── Modern Table ──────────────────────────────────────────────── */
.dtb-table{width:100%;border-collapse:collapse;font-size:13px}
.dtb-table thead th{background:#f8fafc;text-align:left;padding:10px 16px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--dtb-muted);border-bottom:1px solid var(--dtb-border);white-space:nowrap}
.dtb-table tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
.dtb-table tbody tr:last-child{border-bottom:none}
.dtb-table tbody tr:hover{background:#f8fafc}
.dtb-table tbody td{padding:11px 16px;color:var(--dtb-text);vertical-align:middle}
.dtb-code{font-family:var(--dtb-mono,"Courier New");font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:5px;color:#4f46e5;white-space:nowrap;display:inline-block}
.dtb-text-muted{color:var(--dtb-muted);font-size:12px}
.dtb-text-mono{font-family:var(--dtb-mono,"Courier New");font-size:11px}
.dtb-event-tag{display:inline-flex;align-items:center;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:3px 9px;font-size:11px;font-weight:600;color:var(--dtb-text);font-family:var(--dtb-mono,"Courier New")}

/* ── Buttons ───────────────────────────────────────────────────── */
.dtb-btn-sm{font-size:12px;font-weight:700;color:var(--dtb-primary);text-decoration:none;padding:6px 14px;border:1px solid var(--dtb-border);border-radius:7px;background:var(--dtb-card);transition:all .15s;display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-family:var(--dtb-font);line-height:1}
.dtb-btn-sm:hover{background:var(--dtb-primary-lt);border-color:var(--dtb-primary);color:var(--dtb-primary)}

/* ── Pagination ─────────────────────────────────────────────────── */
.dtb-pagination{display:flex;align-items:center;gap:5px;padding-top:16px;flex-wrap:wrap}
.dtb-page-link,.dtb-page-current{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border-radius:7px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid var(--dtb-border);color:var(--dtb-muted);background:var(--dtb-card);transition:all .15s;padding:0 10px}
.dtb-page-link:hover{background:#f1f5f9;color:var(--dtb-text)}
.dtb-page-current{background:var(--dtb-primary);color:#fff;border-color:var(--dtb-primary)}
.dtb-page-ellipsis{display:inline-flex;align-items:center;justify-content:center;height:30px;padding:0 6px;color:var(--dtb-muted);font-size:13px}
.dtb-pagination-info{font-size:12px;color:var(--dtb-muted);margin-left:8px}

/* ── Loading / Shimmer ─────────────────────────────────────────── */
.dtb-kpi-grid.dtb-loading .dtb-kpi-value{background:linear-gradient(90deg,#e2e8f0 25%,#f1f5f9 50%,#e2e8f0 75%);background-size:200% 100%;animation:dtbShimmer 1.4s ease-in-out infinite;border-radius:6px;color:transparent;user-select:none;min-height:32px;width:70%}
.dtb-kpi-grid.dtb-loading .dtb-kpi-badge{visibility:hidden}

/* ── Toast Notifications ───────────────────────────────────────── */
.dtb-toast{position:fixed;bottom:28px;right:28px;background:#1e293b;color:#fff;padding:13px 18px;border-radius:11px;font-size:13px;font-weight:500;box-shadow:0 8px 28px rgba(0,0,0,.28);z-index:99999;display:flex;align-items:center;gap:9px;animation:dtbToastIn .22s ease;max-width:320px;font-family:var(--dtb-font)}
.dtb-toast--success .dtb-toast-icon{color:#4ade80}
.dtb-toast--error   .dtb-toast-icon{color:#f87171}
.dtb-toast .dashicons{font-size:17px;width:17px;height:17px;flex-shrink:0}

/* ── Empty State ───────────────────────────────────────────────── */
.dtb-empty{text-align:center;padding:44px 20px;color:var(--dtb-muted)}
.dtb-empty .dashicons{font-size:38px;width:38px;height:38px;margin-bottom:12px;opacity:.35}
.dtb-empty p{margin:0;font-size:13px}

/* ── Audit Page Wrap ───────────────────────────────────────────── */
.dtb-audit-header{margin-bottom:24px}
.dtb-audit-count-badge{background:var(--dtb-primary-lt);color:var(--dtb-primary);border:1px solid #c7d2fe;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700}
.dtb-table .dtb-ip-cell{font-family:var(--dtb-mono,"Courier New");font-size:11px;color:var(--dtb-muted)}
.dtb-context-cell{font-family:var(--dtb-mono,"Courier New");font-size:11px;word-break:break-all;max-width:400px;white-space:normal;color:var(--dtb-muted)}

/* ── Animations ─────────────────────────────────────────────────── */
@keyframes dtbPulse{0%,100%{box-shadow:0 0 0 0 rgba(74,222,128,.4)}50%{box-shadow:0 0 0 5px rgba(74,222,128,0)}}
@keyframes dtbSpin{to{transform:rotate(360deg)}}
@keyframes dtbShimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes dtbToastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* ── Responsive ─────────────────────────────────────────────────── */
@media(max-width:782px){
  .dtb-ops-header{flex-direction:column;gap:14px;align-items:flex-start;border-radius:0 0 14px 14px;padding:18px 20px}
  .dtb-ops-header-right{width:100%;justify-content:space-between}
  .dtb-kpi-grid{grid-template-columns:1fr 1fr}
  .dtb-status-grid{grid-template-columns:1fr 1fr}
  .dtb-section{overflow-x:auto}
  .dtb-section-header{padding:12px 14px;flex-wrap:wrap;gap:8px}
  .dtb-section-title{font-size:13px}
  .dtb-section-body{padding:12px 14px}
  .dtb-table{min-width:520px}
  .dtb-oo-wrap{overflow-x:auto}
  .dtb-pagination{padding-top:12px;flex-wrap:wrap}
}
@media(max-width:480px){
  .dtb-kpi-grid,.dtb-status-grid{grid-template-columns:1fr}
  .dtb-ops-header{padding:14px 16px}
  .dtb-kpi-card{padding:14px}
  .dtb-kpi-value{font-size:26px}
  .dtb-btn-refresh{padding:6px 12px;font-size:11px}
  .dtb-ops-header h1{font-size:18px}
}
CSS;
}

/**
 * Inline JS for the ops dashboard: polling, Page Visibility API, AJAX fetch,
 * toast notifications, shimmer loading, and animated badge states.
 *
 * @return string
 */
function dtb_ops_inline_js(): string {
	return <<<'JS'
(function ($) {
    'use strict';

    var pollTimer  = null;
    var paused     = false;
    var didInitial = false;

    /* ── Toast ──────────────────────────────────────────────────── */
    function showToast(msg, type) {
        var icon = (type === 'success') ? 'yes-alt' : 'warning';
        var $t = $('<div class="dtb-toast dtb-toast--' + type + '">' +
            '<span class="dashicons dashicons-' + icon + '"></span>' +
            '<span>' + msg + '</span>' +
            '</div>');
        $('body').append($t);
        setTimeout(function () { $t.fadeOut(300, function () { $(this).remove(); }); }, 3200);
    }

    /* ── Timestamp ──────────────────────────────────────────────── */
    function updateTimestamp() {
        var d = new Date();
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        var ss = String(d.getSeconds()).padStart(2, '0');
        $('#dtb-last-updated').text('Updated ' + hh + ':' + mm + ':' + ss);
    }

    /* ── Render KPIs ─────────────────────────────────────────────── */
    function renderKpis(data) {
        $.each(data, function (key, kpi) {
            var $card  = $('#dtb-kpi-' + key);
            if (!$card.length) { return; }

            $card.find('.dtb-kpi-value').text(kpi.value);

            var $badge = $card.find('.dtb-kpi-badge');
            $badge.removeClass('dtb-kpi-badge--ok dtb-kpi-badge--warn dtb-kpi-badge--crit dtb-kpi-badge--info');
            if (kpi.warn) {
                $badge.addClass('dtb-kpi-badge--warn').text('WARN');
            } else {
                $badge.addClass('dtb-kpi-badge--ok').text('OK');
            }
        });
    }

    /* ── Load KPIs ──────────────────────────────────────────────── */
    function loadKpis() {
        var $grid = $('#dtb-ops-kpis');
        var $btn  = $('#dtb-ops-refresh-btn');
        $grid.addClass('dtb-loading');
        $btn.addClass('dtb-loading');

        $.post(dtbOps.ajaxUrl, {
            action: 'dtb_ops_kpis',
            nonce:  dtbOps.nonce
        }, function (res) {
            $grid.removeClass('dtb-loading');
            $btn.removeClass('dtb-loading');
            if (res && res.success && res.data) {
                renderKpis(res.data);
                updateTimestamp();
                if (didInitial) {
                    showToast('Dashboard refreshed', 'success');
                }
                didInitial = true;
            }
        }).fail(function () {
            $grid.removeClass('dtb-loading');
            $btn.removeClass('dtb-loading');
            showToast('Failed to load KPI data — check your connection', 'error');
        });
    }

    /* ── Polling ────────────────────────────────────────────────── */
    function startPolling() {
        loadKpis();
        pollTimer = setInterval(function () {
            if (!paused) { loadKpis(); }
        }, dtbOps.pollInterval);
    }

    /* Page Visibility API — pause when tab is hidden. */
    document.addEventListener('visibilitychange', function () {
        paused = (document.visibilityState === 'hidden');
        if (!paused && !pollTimer) { startPolling(); }
    });

    $(document).ready(function () {
        if (typeof dtbOps !== 'undefined') {
            startPolling();
        }

        $(document).on('click', '#dtb-ops-refresh-btn', function (e) {
            e.preventDefault();
            loadKpis();
        });
    });

}(jQuery));
JS;
}

// =============================================================================
// SECTION 5 — DASHBOARD PAGE RENDER
// =============================================================================

/**
 * Render the DTB Ops main dashboard page.
 */
function dtb_ops_render_dashboard(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'dtb' ) );
	}
	?>
	<div class="wrap" style="padding:0;margin:0">
	<div class="dtb-ops-wrap">

		<!-- ── Header ──────────────────────────────────────────── -->
		<div class="dtb-ops-header">
			<div class="dtb-ops-header-left">
				<div class="dtb-ops-logo">
					<span class="dashicons dashicons-chart-area"></span>
				</div>
				<div>
					<h1><?php esc_html_e( 'DTB Operations', 'dtb' ); ?></h1>
					<div class="dtb-ops-header-sub">
						<span class="dtb-version-badge">v<?php echo esc_html( DTB_OPS_VERSION ); ?></span>
						<span class="dtb-ops-header-tagline"><?php esc_html_e( 'Drywall Toolbox Control Center', 'dtb' ); ?></span>
					</div>
				</div>
			</div>
			<div class="dtb-ops-header-right">
				<span class="dtb-live-dot"><?php esc_html_e( 'Live', 'dtb' ); ?></span>
				<span class="dtb-last-updated" id="dtb-last-updated"><?php esc_html_e( 'Initializing…', 'dtb' ); ?></span>
				<button type="button" id="dtb-ops-refresh-btn" class="dtb-btn-refresh">
					<span class="dashicons dashicons-update dtb-spin-icon"></span>
					<?php esc_html_e( 'Refresh', 'dtb' ); ?>
				</button>
			</div>
		</div>

		<!-- ── Order Operations Overview Cards (Top Row) ───────── -->
		<div class="dtb-oo-wrap dtb-oo-wrap--embedded dtb-oo-wrap--top-overview">
			<div id="dtb-oo-overview-kpis-top" class="dtb-oo-kpi-grid">
				<p class="dtb-oo-loading"><?php esc_html_e( 'Loading KPIs…', 'dtb' ); ?></p>
			</div>
		</div>

		<!-- ── Order Operations (Unified) ─────────────────────── -->
		<div class="dtb-section" id="dtb-order-operations-section">
			<div class="dtb-section-header">
				<h2 class="dtb-section-title">
					<span class="dashicons dashicons-hammer"></span>
					<?php esc_html_e( 'Order Operations', 'dtb' ); ?>
				</h2>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dtb-ops-order-operations' ) ); ?>" class="dtb-btn-sm">
					<?php esc_html_e( 'Open Fullscreen →', 'dtb' ); ?>
				</a>
			</div>
			<div class="dtb-section-body">
				<?php
				if ( function_exists( 'dtb_oo_render_embedded_section' ) ) {
					dtb_oo_render_embedded_section();
				} else {
					echo '<p class="dtb-text-muted">' . esc_html__( 'Order Operations module is unavailable.', 'dtb' ) . '</p>';
				}
				?>
			</div>
		</div>

		<!-- ── Recent Audit Events ──────────────────────────────── -->
		<div class="dtb-section">
			<div class="dtb-section-header">
				<h2 class="dtb-section-title">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'Recent Audit Events', 'dtb' ); ?>
				</h2>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dtb-ops-audit' ) ); ?>" class="dtb-btn-sm">
					<?php esc_html_e( 'View Full Log →', 'dtb' ); ?>
				</a>
			</div>
			<div style="padding:0;">
				<?php dtb_ops_render_recent_audit(); ?>
			</div>
		</div>

	</div><!-- /.dtb-ops-wrap -->
	</div><!-- /.wrap -->
	<?php
}

/**
 * Render the audit log admin page.
 */
function dtb_ops_render_audit_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'dtb' ) );
	}

	$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$per_page = 50;
	$offset   = ( $page - 1 ) * $per_page;
	$rows     = dtb_ops_get_audit_log( $per_page, $offset );

	// Total count for pagination.
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_audit_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	?>
	<div class="wrap" style="padding:0;margin:0">
	<div class="dtb-ops-wrap">

		<!-- ── Header ──────────────────────────────────────────── -->
		<div class="dtb-ops-header">
			<div class="dtb-ops-header-left">
				<div class="dtb-ops-logo">
					<span class="dashicons dashicons-list-view"></span>
				</div>
				<div>
					<h1><?php esc_html_e( 'Audit Log', 'dtb' ); ?></h1>
					<div class="dtb-ops-header-sub">
						<span class="dtb-version-badge">v<?php echo esc_html( DTB_OPS_VERSION ); ?></span>
						<span class="dtb-ops-header-tagline">
							<?php
							printf(
								/* translators: %1$s: total events, %2$s: retention days */
								esc_html__( '%1$s total events · %2$s-day retention', 'dtb' ),
								esc_html( number_format( $total ) ),
								esc_html( DTB_OPS_AUDIT_RETENTION )
							);
							?>
						</span>
					</div>
				</div>
			</div>
			<div class="dtb-ops-header-right">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dtb-ops' ) ); ?>" class="dtb-btn-refresh">
					<span class="dashicons dashicons-arrow-left-alt" style="font-size:15px;width:15px;height:15px;"></span>
					<?php esc_html_e( 'Back to Dashboard', 'dtb' ); ?>
				</a>
			</div>
		</div>

		<!-- ── Audit Table ──────────────────────────────────────── -->
		<div class="dtb-section">
			<div class="dtb-section-header">
				<h2 class="dtb-section-title">
					<span class="dashicons dashicons-shield-alt"></span>
					<?php esc_html_e( 'Security &amp; Operations Events', 'dtb' ); ?>
					<span class="dtb-audit-count-badge"><?php echo esc_html( number_format( $total ) ); ?></span>
				</h2>
				<span class="dtb-text-muted" style="font-size:12px;">
					<?php
					printf(
						/* translators: %1$d: page, %2$d: total pages */
						esc_html__( 'Page %1$d of %2$d', 'dtb' ),
						(int) $page,
						(int) $total_pages
					);
					?>
				</span>
			</div>

			<?php if ( empty( $rows ) ) : ?>
			<div class="dtb-empty">
				<span class="dashicons dashicons-list-view"></span>
				<p><?php esc_html_e( 'No audit events have been recorded yet.', 'dtb' ); ?></p>
			</div>
			<?php else : ?>
			<table class="dtb-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time (UTC)', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'User', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'Event', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'Context', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'dtb' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					if ( $row->user_id > 0 ) {
						$_u      = get_userdata( $row->user_id );
						$_label  = ( $_u instanceof WP_User ) ? $_u->user_login : (string) $row->user_id;
					} else {
						$_label = '—';
					}
					?>
					<tr>
						<td class="dtb-text-muted dtb-text-mono"><?php echo esc_html( $row->log_timestamp ); ?></td>
						<td><?php echo esc_html( $_label ); ?></td>
						<td><span class="dtb-event-tag"><?php echo esc_html( $row->event ); ?></span></td>
						<td class="dtb-context-cell"><?php echo esc_html( $row->context ); ?></td>
						<td class="dtb-ip-cell"><?php echo esc_html( ( $row->ip !== '' ) ? $row->ip : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<!-- ── Pagination ──────────────────────────────────── -->
			<?php if ( $total_pages > 1 ) : ?>
			<div class="dtb-section-body">
				<div class="dtb-pagination">
					<?php if ( $page > 1 ) : ?>
					<a class="dtb-page-link" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">&lsaquo;</a>
					<?php endif; ?>

					<?php
					$range   = 2;
					$show_first = ( $page - $range > 1 );
					$show_last  = ( $page + $range < $total_pages );

					if ( $show_first ) {
						echo '<a class="dtb-page-link" href="' . esc_url( add_query_arg( 'paged', 1 ) ) . '">1</a>';
						if ( $page - $range > 2 ) {
							echo '<span class="dtb-page-ellipsis">&hellip;</span>';
						}
					}

					for ( $i = max( 1, $page - $range ); $i <= min( $total_pages, $page + $range ); $i++ ) :
						if ( $i === $page ) :
							echo '<span class="dtb-page-current">' . esc_html( $i ) . '</span>';
						else :
							echo '<a class="dtb-page-link" href="' . esc_url( add_query_arg( 'paged', $i ) ) . '">' . esc_html( $i ) . '</a>';
						endif;
					endfor;

					if ( $show_last ) {
						if ( $page + $range < $total_pages - 1 ) {
							echo '<span class="dtb-page-ellipsis">&hellip;</span>';
						}
						echo '<a class="dtb-page-link" href="' . esc_url( add_query_arg( 'paged', $total_pages ) ) . '">' . esc_html( $total_pages ) . '</a>';
					}

					if ( $page < $total_pages ) :
					?>
					<a class="dtb-page-link" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>">&rsaquo;</a>
					<?php endif; ?>

					<span class="dtb-pagination-info">
						<?php
						$first = $offset + 1;
						$last  = min( $total, $offset + $per_page );
						printf(
							/* translators: %1$d: first, %2$d: last, %3$d: total */
							esc_html__( '%1$d–%2$d of %3$d events', 'dtb' ),
							(int) $first,
							(int) $last,
							(int) $total
						);
						?>
					</span>
				</div>
			</div>
			<?php endif; ?>

			<?php endif; ?>
		</div>

	</div><!-- /.dtb-ops-wrap -->
	</div><!-- /.wrap -->
	<?php
}

/**
 * Render the most recent 10 audit entries inline in the dashboard.
 */
function dtb_ops_render_recent_audit(): void {
	$rows = dtb_ops_get_audit_log( 10 );
	if ( empty( $rows ) ) {
		echo '<div class="dtb-empty"><span class="dashicons dashicons-list-view"></span><p>' . esc_html__( 'No events recorded yet.', 'dtb' ) . '</p></div>';
		return;
	}
	echo '<table class="dtb-table">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Time (UTC)', 'dtb' ) . '</th>';
	echo '<th>' . esc_html__( 'Event', 'dtb' ) . '</th>';
	echo '<th>' . esc_html__( 'Context', 'dtb' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr>';
		echo '<td class="dtb-text-muted dtb-text-mono">' . esc_html( $row->log_timestamp ) . '</td>';
		echo '<td><span class="dtb-event-tag">' . esc_html( $row->event ) . '</span></td>';
		echo '<td class="dtb-context-cell">' . esc_html( substr( $row->context, 0, 180 ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

// =============================================================================
// SECTION 6 — AJAX: KPI DATA ENDPOINT
// =============================================================================

add_action( 'wp_ajax_dtb_ops_kpis', 'dtb_ops_ajax_kpis' );

/**
 * AJAX handler: return all KPI values as JSON.
 */
function dtb_ops_ajax_kpis(): void {
	check_ajax_referer( 'dtb_ops_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	$kpis = dtb_ops_get_all_kpis();
	wp_send_json_success( $kpis );
}

/**
 * Aggregate all KPI values, using cached data where available.
 *
 * @return array<string, array{ label: string, value: string, warn: bool }>
 */
function dtb_ops_get_all_kpis(): array {
	$kpis = [];

	// Orders today.
	$orders_today = function_exists( 'dtb_woo_get_orders_today' ) ? dtb_woo_get_orders_today() : '—';
	$kpis['orders_today'] = [
		'label' => 'Orders Today',
		'value' => (string) $orders_today,
		'warn'  => false,
	];

	// Orders by status (processing + on-hold).
	if ( function_exists( 'dtb_woo_get_orders_by_status' ) ) {
		$by_status = dtb_woo_get_orders_by_status( [ 'processing', 'on-hold' ] );
		$kpis['orders_processing'] = [
			'label' => 'Processing',
			'value' => (string) ( $by_status['processing'] ?? 0 ),
			'warn'  => ( ( $by_status['processing'] ?? 0 ) > 50 ),
		];
		$kpis['orders_on_hold'] = [
			'label' => 'On Hold',
			'value' => (string) ( $by_status['on-hold'] ?? 0 ),
			'warn'  => ( ( $by_status['on-hold'] ?? 0 ) > 10 ),
		];
	}

	// Low stock.
	if ( function_exists( 'dtb_woo_get_low_stock_count' ) ) {
		$low_stock = dtb_woo_get_low_stock_count();
		$kpis['low_stock'] = [
			'label' => 'Low Stock SKUs',
			'value' => (string) $low_stock,
			'warn'  => ( $low_stock > 0 ),
		];
	}

	// Pending repairs (Veeqo).
	if ( function_exists( 'dtb_veeqo_get_pending_repairs_count' ) ) {
		$repairs = dtb_veeqo_get_pending_repairs_count();
		$kpis['pending_repairs'] = [
			'label' => 'Pending Repairs',
			'value' => (string) $repairs,
			'warn'  => ( $repairs > 20 ),
		];
	}

	// Rewards liability.
	if ( function_exists( 'dtb_rewards_get_total_liability' ) ) {
		$liability = dtb_rewards_get_total_liability();
		$kpis['rewards_liability'] = [
			'label' => 'Rewards Liability',
			'value' => '$' . number_format( $liability, 2 ),
			'warn'  => ( $liability > 5000 ),
		];
	}

	// Image sync health.
	if ( function_exists( 'dtb_image_sync_get_status' ) ) {
		$sync = dtb_image_sync_get_status();
		$kpis['image_sync'] = [
			'label' => 'Image Sync',
			'value' => $sync['health'],
			'warn'  => ( 'ok' !== $sync['health'] && 'never' !== $sync['health'] ),
		];
	}

	return $kpis;
}

/**
 * Return the KPI definition map (label, dashicon, color variant — values filled by AJAX).
 *
 * @return array<string, array{ label: string, icon: string, color: string }>
 */
function dtb_ops_kpi_definitions(): array {
	return [
		'orders_today'      => [ 'label' => 'Orders Today',     'icon' => 'cart',        'color' => 'primary' ],
		'orders_processing' => [ 'label' => 'Processing',        'icon' => 'update',      'color' => 'info'    ],
		'orders_on_hold'    => [ 'label' => 'On Hold',           'icon' => 'clock',       'color' => 'warning' ],
		'low_stock'         => [ 'label' => 'Low Stock SKUs',    'icon' => 'warning',     'color' => 'danger'  ],
		'pending_repairs'   => [ 'label' => 'Pending Repairs',   'icon' => 'admin-tools', 'color' => 'purple'  ],
		'rewards_liability' => [ 'label' => 'Rewards Liability', 'icon' => 'awards',      'color' => 'success' ],
		'image_sync'        => [ 'label' => 'Image Sync',        'icon' => 'images-alt2', 'color' => 'info'    ],
	];
}

// =============================================================================
// SECTION 7 — AJAX: AUDIT LOG ENDPOINT
// =============================================================================

add_action( 'wp_ajax_dtb_ops_audit_log', 'dtb_ops_ajax_audit_log' );

/**
 * AJAX handler: return paginated audit log as JSON.
 */
function dtb_ops_ajax_audit_log(): void {
	check_ajax_referer( 'dtb_ops_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page = min( 100, max( 10, (int) ( $_POST['per_page'] ?? 25 ) ) );
	$offset   = ( $page - 1 ) * $per_page;
	$rows     = dtb_ops_get_audit_log( $per_page, $offset );

	wp_send_json_success( [
		'rows'    => $rows,
		'page'    => $page,
		'per_page'=> $per_page,
	] );
}

// =============================================================================
// SECTION 8 — AUDIT LOG WRITER
// =============================================================================

/**
 * Write an entry to the DTB audit log table.
 *
 * @param string $event   Short event identifier (e.g. 'qbo_sync_complete').
 * @param array  $context Optional additional context (will be JSON-encoded).
 * @param int    $user_id WordPress user ID (0 = system/cron).
 */
function dtb_ops_audit_log( string $event, array $context = [], int $user_id = 0 ): void {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_audit_log';

	if ( 0 === $user_id ) {
		$user_id = get_current_user_id();
	}

	$ip = function_exists( 'dtb_anonymise_ip' )
		? dtb_anonymise_ip( function_exists( 'dtb_get_client_ip' ) ? dtb_get_client_ip() : '' )
		: '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$table,
		[
			'log_timestamp' => current_time( 'mysql', true ),
			'user_id'       => $user_id,
			'event'         => sanitize_key( $event ),
			'context'       => wp_json_encode( $context ) ?: '',
			'ip'            => sanitize_text_field( $ip ),
		],
		[ '%s', '%d', '%s', '%s', '%s' ]
	);
}

/**
 * Read recent audit log entries.
 *
 * @param int $limit  Number of rows to return.
 * @param int $offset Query offset (for pagination).
 * @return object[]   Array of stdClass rows.
 */
function dtb_ops_get_audit_log( int $limit = 50, int $offset = 0 ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_audit_log';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, log_timestamp, user_id, event, context, ip
			 FROM {$table}
			 ORDER BY id DESC
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		)
	);

	return is_array( $rows ) ? $rows : [];
}

/**
 * Purge audit log entries older than DTB_OPS_AUDIT_RETENTION days.
 *
 * Scheduled via cron (dtb_ops_audit_purge).
 */
function dtb_ops_purge_old_audit_entries(): void {
	global $wpdb;

	$table    = $wpdb->prefix . 'dtb_audit_log';
	$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . DTB_OPS_AUDIT_RETENTION . ' days' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE log_timestamp < %s",
			$cutoff
		)
	);
}

// =============================================================================
// SECTION 9 — CRON: BACKGROUND KPI REFRESH & AUDIT PURGE
// =============================================================================

add_action( 'init', 'dtb_ops_schedule_cron' );

/**
 * Register cron jobs if not already scheduled.
 */
function dtb_ops_schedule_cron(): void {
	if ( ! wp_next_scheduled( 'dtb_ops_refresh_kpis' ) ) {
		wp_schedule_event( time(), 'dtb_ops_every_5_min', 'dtb_ops_refresh_kpis' );
	}

	if ( ! wp_next_scheduled( 'dtb_ops_audit_purge' ) ) {
		wp_schedule_event( time(), 'daily', 'dtb_ops_audit_purge' );
	}
}

add_filter( 'cron_schedules', 'dtb_ops_add_cron_intervals' );

/**
 * Register a 5-minute cron interval.
 *
 * @param array $schedules Existing WordPress cron schedules.
 * @return array Modified schedules.
 */
function dtb_ops_add_cron_intervals( array $schedules ): array {
	if ( ! isset( $schedules['dtb_ops_every_5_min'] ) ) {
		$schedules['dtb_ops_every_5_min'] = [
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (DTB Ops)', 'dtb' ),
		];
	}
	return $schedules;
}

add_action( 'dtb_ops_refresh_kpis', 'dtb_ops_cron_refresh_kpis' );

/**
 * Cron callback: warm the KPI cache so the dashboard loads instantly.
 */
function dtb_ops_cron_refresh_kpis(): void {
	dtb_ops_get_all_kpis();
	dtb_ops_audit_log( 'cron_kpi_refresh', [ 'ts' => gmdate( 'c' ) ], 0 );
}

add_action( 'dtb_ops_audit_purge', 'dtb_ops_purge_old_audit_entries' );

// =============================================================================
// SECTION 10 — REST: dtb/v1/health
// =============================================================================

add_action( 'rest_api_init', 'dtb_ops_register_health_route' );

/**
 * Register the public health-check REST endpoint.
 */
function dtb_ops_register_health_route(): void {
	register_rest_route(
		'dtb/v1',
		'/health',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_ops_rest_health',
			'permission_callback' => '__return_true',
		]
	);
}

/**
 * REST callback for GET /dtb/v1/health.
 *
 * @return WP_REST_Response
 */
function dtb_ops_rest_health(): WP_REST_Response {
	global $wpdb;

	$db_ok = false;
	try {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$db_ok = ( 1 === (int) $wpdb->get_var( 'SELECT 1' ) );
	} catch ( \Throwable $e ) {
		$db_ok = false;
	}

	$data = [
		'status'      => $db_ok ? 'ok' : 'degraded',
		'version'     => DTB_OPS_VERSION,
		'db_version'  => DTB_OPS_DB_VERSION,
		'db_ok'       => $db_ok,
		'php_version' => PHP_VERSION,
		'wp_version'  => get_bloginfo( 'version' ),
		'checked_at'  => gmdate( 'c' ),
	];

	return new WP_REST_Response( $data, $db_ok ? 200 : 503 );
}

// =============================================================================
// SECTION 11 — CAPABILITY HELPERS
// =============================================================================

/**
 * Check whether the current user has the given WordPress capability
 * OR any of the custom DTB ops capabilities.
 *
 * Falls back gracefully when the DTB_CAP_* constants are not yet defined
 * (e.g. early-loading edge cases on shared hosting).
 *
 * @param string $fallback_cap Standard WordPress capability to check first.
 * @return bool
 */
if ( ! function_exists( 'dtb_ops_can' ) ) {
	function dtb_ops_can( string $fallback_cap = 'manage_options' ): bool {
		if ( current_user_can( $fallback_cap ) ) {
			return true;
		}

		$dtb_caps = [];
		foreach ( [ 'DTB_CAP_OPS_ADMIN', 'DTB_CAP_ACCOUNTING', 'DTB_CAP_SUPPORT', 'DTB_CAP_CATALOG' ] as $const ) {
			if ( defined( $const ) ) {
				$dtb_caps[] = constant( $const );
			}
		}

		foreach ( $dtb_caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}

		return false;
	}
}
