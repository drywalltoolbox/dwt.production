<?php
/**
 * DTB Daily Briefings — private ChatGPT report publisher and admin archive.
 * WooCommerce and DTB domain records are never mutated by this module.
 */
defined( 'ABSPATH' ) || exit;

function dtb_daily_briefings_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'dtb_daily_briefings';
}

add_action( 'init', static function (): void {
    if ( get_option( 'dtb_daily_briefings_schema' ) === '1' ) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = dtb_daily_briefings_table();
    dbDelta( "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        report_date date NOT NULL,
        idempotency_key varchar(100) NOT NULL,
        report_status varchar(16) NOT NULL,
        generated_at datetime NOT NULL,
        payload_hash char(64) NOT NULL,
        payload longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY report_date (report_date),
        UNIQUE KEY idempotency_key (idempotency_key),
        KEY created_at (created_at)
    ) " . $wpdb->get_charset_collate() . ';' );
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
        update_option( 'dtb_daily_briefings_schema', '1', false );
    }
}, 20 );

function dtb_daily_briefings_url( ?string $date = null ): string {
    $url = admin_url( 'admin.php?page=dtb-daily-briefings' );
    return $date ? add_query_arg( 'date', $date, $url ) : $url;
}

/** Restrict model-authored material to small, typed, plain-text report fields. */
function dtb_daily_briefings_validate( array $data ) {
    $date = $data['report_date'] ?? null;
    $key = $data['idempotency_key'] ?? null;
    $status = $data['status'] ?? null;
    $at = $data['generated_at'] ?? null;
    if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ||
        ! checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ) {
        return new WP_Error( 'dtb_briefing_date', 'Invalid YYYY-MM-DD report_date.', [ 'status' => 400 ] );
    }
    if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_.:-]{8,100}$/D', $key ) ||
        ! in_array( $status, [ 'complete', 'partial', 'failed' ], true ) ) {
        return new WP_Error( 'dtb_briefing_identity', 'Invalid idempotency_key or status.', [ 'status' => 400 ] );
    }
    if ( ! is_string( $at ) || strlen( $at ) > 40 ||
        ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $at ) ) {
        return new WP_Error( 'dtb_briefing_time', 'ISO 8601 generated_at with timezone required.', [ 'status' => 400 ] );
    }
    try {
        $timestamp = new DateTimeImmutable( $at );
        $chicago = new DateTimeZone( 'America/Chicago' );
        $report_day = new DateTimeImmutable( $date, $chicago );
        $today = new DateTimeImmutable( 'today', $chicago );
        $days_from_today = (int) $report_day->diff( $today )->format( '%r%a' );
        if ( $timestamp->setTimezone( $chicago )->format( 'Y-m-d' ) !== $date || abs( $days_from_today ) > 2 ) {
            return new WP_Error( 'dtb_briefing_window', 'Report date does not match the generation day or permitted window.', [ 'status' => 400 ] );
        }
    } catch ( Exception $e ) {
        return new WP_Error( 'dtb_briefing_time', 'Invalid generated_at timestamp.', [ 'status' => 400 ] );
    }
    $summary = is_string( $data['summary'] ?? null ) ? sanitize_textarea_field( $data['summary'] ) : '';
    $priorities = $data['priorities'] ?? [];
    $coverage = $data['coverage'] ?? [];
    $metrics = $data['metrics'] ?? [];
    if ( ! $summary || strlen( $summary ) > 8000 ||
        ! is_array( $priorities ) || ! array_is_list( $priorities ) || count( $priorities ) > 50 ||
        ! is_array( $coverage ) || count( $coverage ) > 25 ||
        ! is_array( $metrics ) || count( $metrics ) > 30 ) {
        return new WP_Error( 'dtb_briefing_shape', 'Missing or oversized report fields.', [ 'status' => 400 ] );
    }
    $modules = [ 'orders', 'repairs', 'returns', 'support', 'refunds', 'fulfillment', 'integrations', 'general' ];
    $safe_priorities = [];
    foreach ( $priorities as $p ) {
        if ( ! is_array( $p ) || ! in_array( $p['severity'] ?? '', [ 'critical', 'high', 'medium', 'low' ], true ) ||
            ! in_array( $p['module'] ?? '', $modules, true ) ) {
            return new WP_Error( 'dtb_briefing_priority', 'Invalid priority classification.', [ 'status' => 400 ] );
        }
        $title = sanitize_text_field( (string) ( $p['title'] ?? '' ) );
        $reason = sanitize_textarea_field( (string) ( $p['reason'] ?? '' ) );
        $ref = sanitize_text_field( (string) ( $p['reference'] ?? '' ) );
        if ( ! $title || strlen( $title ) > 180 || strlen( $reason ) > 1500 || strlen( $ref ) > 100 ) {
            return new WP_Error( 'dtb_briefing_priority', 'Invalid priority content.', [ 'status' => 400 ] );
        }
        $safe_priorities[] = [ 'title' => $title, 'reason' => $reason, 'severity' => $p['severity'],
            'module' => $p['module'], 'reference' => $ref ];
    }
    $safe_coverage = [];
    foreach ( $coverage as $source => $value ) {
        if ( ! is_string( $source ) || ! preg_match( '/^[a-z0-9_-]{2,40}$/D', $source ) ||
            ! is_array( $value ) || ! in_array( $value['status'] ?? '', [ 'complete', 'partial', 'unavailable', 'not_checked' ], true ) ) {
            return new WP_Error( 'dtb_briefing_coverage', 'Invalid evidence coverage.', [ 'status' => 400 ] );
        }
        $safe_coverage[ $source ] = [ 'status' => $value['status'],
            'note' => substr( sanitize_text_field( (string) ( $value['note'] ?? '' ) ), 0, 300 ) ];
    }
    $safe_metrics = [];
    foreach ( $metrics as $name => $value ) {
        if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9_-]{2,50}$/D', $name ) ||
            ( ! is_int( $value ) && null !== $value ) || ( is_int( $value ) && $value < 0 ) ) {
            return new WP_Error( 'dtb_briefing_metrics', 'Metrics must be nonnegative integers or null.', [ 'status' => 400 ] );
        }
        $safe_metrics[ $name ] = $value;
    }
    $prose = $summary . ' ' . wp_json_encode( $safe_priorities ) . ' ' . wp_json_encode( $safe_coverage );
    if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $prose ) ||
        preg_match( '/\b(?:\+?1[.\- ]?)?\(?\d{3}\)?[.\- ]?\d{3}[.\- ]?\d{4}\b/', $prose ) ) {
        return new WP_Error( 'dtb_briefing_personal_data', 'Redact personal contact information before publication.', [ 'status' => 400 ] );
    }
    return [ 'report_date' => $date, 'idempotency_key' => $key, 'status' => $status,
        'generated_at' => $timestamp->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        'summary' => $summary, 'priorities' => $safe_priorities, 'coverage' => $safe_coverage,
        'metrics' => $safe_metrics ];
}

function dtb_daily_briefings_row( object $row ): array {
    $report = json_decode( $row->payload, true );
    return [ 'id' => (int) $row->id, 'report_date' => $row->report_date,
        'status' => $row->report_status, 'generated_at' => $row->generated_at . ' UTC',
        'payload_hash' => $row->payload_hash, 'report' => is_array( $report ) ? $report : [],
        'admin_url' => dtb_daily_briefings_url( $row->report_date ) ];
}

add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'dtb/v1', '/admin/briefings', [
        [ 'methods' => WP_REST_Server::READABLE, 'callback' => 'dtb_daily_briefings_list',
          'permission_callback' => static fn() => current_user_can( 'dtb_view_command_center' ) ],
        [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dtb_daily_briefings_publish',
          'permission_callback' => static fn() => current_user_can( 'manage_options' ) ],
    ] );
    register_rest_route( 'dtb/v1', '/admin/briefings/(?P<id>\d+)', [
        'methods' => WP_REST_Server::READABLE, 'callback' => 'dtb_daily_briefings_detail',
        'permission_callback' => static fn() => current_user_can( 'dtb_view_command_center' ),
    ] );
} );

function dtb_daily_briefings_publish( WP_REST_Request $request ) {
    global $wpdb;
    $input = $request->get_json_params();
    if ( ! is_array( $input ) ) {
        return new WP_Error( 'dtb_briefing_json', 'JSON object required.', [ 'status' => 400 ] );
    }
    $report = dtb_daily_briefings_validate( $input );
    if ( is_wp_error( $report ) ) { return $report; }
    $json = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( ! is_string( $json ) || strlen( $json ) > 64000 ) {
        return new WP_Error( 'dtb_briefing_limit', 'Report exceeds 64 KB.', [ 'status' => 413 ] );
    }
    $hash = hash( 'sha256', $json );
    $table = dtb_daily_briefings_table();
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE report_date = %s OR idempotency_key = %s LIMIT 1", $report['report_date'], $report['idempotency_key'] ) );
    if ( $existing ) {
        if ( $existing->idempotency_key === $report['idempotency_key'] && hash_equals( $existing->payload_hash, $hash ) ) {
            return new WP_REST_Response( [ 'created' => false, 'briefing' => dtb_daily_briefings_row( $existing ) ], 200 );
        }
        return new WP_Error( 'dtb_briefing_conflict', 'An immutable briefing already exists for this date or key.', [ 'status' => 409 ] );
    }
    $saved = $wpdb->insert( $table, [
        'report_date' => $report['report_date'], 'idempotency_key' => $report['idempotency_key'],
        'report_status' => $report['status'], 'generated_at' => $report['generated_at'],
        'payload_hash' => $hash, 'payload' => $json, 'created_at' => current_time( 'mysql', true ),
    ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
    if ( ! $saved ) {
        $winner = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE report_date = %s OR idempotency_key = %s LIMIT 1", $report['report_date'], $report['idempotency_key'] ) );
        if ( $winner && $winner->idempotency_key === $report['idempotency_key'] && hash_equals( $winner->payload_hash, $hash ) ) {
            return new WP_REST_Response( [ 'created' => false, 'briefing' => dtb_daily_briefings_row( $winner ) ], 200 );
        }
        return new WP_Error( 'dtb_briefing_write', 'Report persistence failed or a conflicting report exists.', [ 'status' => $winner ? 409 : 500 ] );
    }
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ) );
    return new WP_REST_Response( [ 'created' => true, 'briefing' => dtb_daily_briefings_row( $row ) ], 201 );
}

function dtb_daily_briefings_list( WP_REST_Request $request ): WP_REST_Response {
    global $wpdb;
    $page = max( 1, min( 10000, (int) $request->get_param( 'page' ) ) );
    $size = max( 1, min( 30, (int) ( $request->get_param( 'per_page' ) ?: 10 ) ) );
    $table = dtb_daily_briefings_table();
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY report_date DESC LIMIT %d OFFSET %d", $size, ( $page - 1 ) * $size ) );
    return new WP_REST_Response( [ 'items' => array_map( 'dtb_daily_briefings_row', $rows ),
        'total' => $total, 'page' => $page, 'per_page' => $size, 'total_pages' => (int) ceil( $total / $size ) ], 200 );
}

function dtb_daily_briefings_detail( WP_REST_Request $request ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dtb_daily_briefings_table() . ' WHERE id = %d', (int) $request['id'] ) );
    return $row ? new WP_REST_Response( dtb_daily_briefings_row( $row ), 200 )
        : new WP_Error( 'dtb_briefing_missing', 'Report not found.', [ 'status' => 404 ] );
}

// Register in the established DTB Operations library, immediately after Command Center.
add_action( 'admin_menu', static function (): void {
    if ( ! function_exists( 'dtb_register_admin_page' ) ) { return; }
    dtb_register_admin_page( [ 'library' => 'operations', 'slug' => 'dtb-daily-briefings',
        'title' => 'Daily Briefings', 'menu_title' => 'Daily Briefings',
        'capability' => 'dtb_view_command_center', 'callback' => 'dtb_daily_briefings_render',
        'position' => 15, 'template' => 'dashboard', 'section' => 'Operations',
        'icon' => 'dashicons-analytics' ] );
}, 6 );

function dtb_daily_briefings_render(): void {
    if ( ! current_user_can( 'dtb_view_command_center' ) ) { wp_die( esc_html__( 'Access denied.', 'drywall-toolbox' ) ); }
    global $wpdb;
    $table = dtb_daily_briefings_table();
    $requested = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    $row = $requested && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $requested )
        ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE report_date = %s LIMIT 1", $requested ) )
        : $wpdb->get_row( "SELECT * FROM {$table} ORDER BY report_date DESC LIMIT 1" );
    $archive = $wpdb->get_results( "SELECT report_date, report_status FROM {$table} ORDER BY report_date DESC LIMIT 30" );
    dtb_admin_shell_open( [ 'title' => __( 'Daily Briefings', 'drywall-toolbox' ),
        'subtitle' => __( 'Private morning reports, action priorities, and audit history.', 'drywall-toolbox' ),
        'section' => 'operations', 'page' => 'dtb-daily-briefings', 'template' => 'dashboard',
        'icon' => 'dashicons-analytics',
        'actions' => [ dtb_admin_ui_button( __( 'Command Center', 'drywall-toolbox' ),
            [ 'href' => admin_url( 'admin.php?page=dtb-command-center' ), 'type' => 'secondary', 'size' => 'sm' ] ) ] ] );
    echo '<div class="dtb-grid dtb-grid--two dtb-briefings-layout"><main class="dtb-briefings-main">';
    if ( ! $row ) {
        echo '<div class="dtb-card dtb-briefing-card"><div class="dtb-card__body"><h2>' . esc_html__( 'Awaiting the first briefing', 'drywall-toolbox' ) . '</h2><p>' .
            esc_html__( 'The scheduled ChatGPT agent will publish its verified report here when its authenticated MCP connection is available.', 'drywall-toolbox' ) . '</p></div></div>';
    } else {
        $payload = json_decode( $row->payload, true );
        echo '<div class="dtb-card dtb-briefing-card"><div class="dtb-card__body"><h2>' . esc_html( $row->report_date ) .
            '</h2><p><strong>' . esc_html( strtoupper( $row->report_status ) ) . '</strong> · ' .
            esc_html( $row->generated_at . ' UTC' ) . '</p><p class="dtb-briefing-summary">' .
            esc_html( $payload['summary'] ?? '' ) . '</p>';
        if ( ! empty( $payload['metrics'] ) ) {
            echo '<h3>' . esc_html__( 'Verified indicators', 'drywall-toolbox' ) . '</h3><div class="dtb-briefing-metrics">';
            foreach ( $payload['metrics'] as $label => $value ) {
                echo '<div class="dtb-briefing-metric"><strong>' .
                    esc_html( null === $value ? 'N/A' : (string) $value ) . '</strong><br><small>' .
                    esc_html( ucwords( str_replace( '_', ' ', $label ) ) ) . '</small></div>';
            }
            echo '</div>';
        }
        echo '</div></div><div class="dtb-card dtb-briefing-card"><div class="dtb-card__body"><h3>' .
            esc_html__( 'Admin priorities', 'drywall-toolbox' ) . '</h3>';
        $pages = [ 'orders' => 'dtb-orders', 'repairs' => 'dtb-repairs', 'returns' => 'dtb-returns',
            'support' => 'dtb-support', 'refunds' => 'dtb-returns', 'fulfillment' => 'dtb-orders',
            'integrations' => 'dtb-system-manager', 'general' => 'dtb-command-center' ];
        if ( empty( $payload['priorities'] ) ) {
            echo '<p>' . esc_html__( 'No priority actions reported.', 'drywall-toolbox' ) . '</p>';
        }
        foreach ( $payload['priorities'] ?? [] as $priority ) {
            $target = admin_url( 'admin.php?page=' . ( $pages[ $priority['module'] ] ?? 'dtb-command-center' ) );
            echo '<div class="dtb-briefing-priority"><strong>' .
                esc_html( strtoupper( $priority['severity'] ) ) . '</strong> · <a href="' . esc_url( $target ) . '">' .
                esc_html( $priority['title'] ) . '</a> ' .
                ( ! empty( $priority['reference'] ) ? '<code>' . esc_html( $priority['reference'] ) . '</code>' : '' ) .
                '<p>' . esc_html( $priority['reason'] ) . '</p></div>';
        }
        echo '</div></div><div class="dtb-card dtb-briefing-card"><div class="dtb-card__body"><h3>' .
            esc_html__( 'Source coverage', 'drywall-toolbox' ) . '</h3>';
        foreach ( $payload['coverage'] ?? [] as $source => $detail ) {
            echo '<p><strong>' . esc_html( ucwords( str_replace( '_', ' ', $source ) ) ) . '</strong>: ' .
                esc_html( $detail['status'] ) .
                ( ! empty( $detail['note'] ) ? ' — ' . esc_html( $detail['note'] ) : '' ) . '</p>';
        }
        echo '</div></div>';
    }
    echo '</main><aside class="dtb-card dtb-briefing-card dtb-briefing-archive"><div class="dtb-card__body"><h3>' .
        esc_html__( 'Briefing archive', 'drywall-toolbox' ) . '</h3>';
    if ( ! $archive ) { echo '<p>' . esc_html__( 'No reports yet.', 'drywall-toolbox' ) . '</p>'; }
    foreach ( $archive as $entry ) {
        echo '<p><a href="' . esc_url( dtb_daily_briefings_url( $entry->report_date ) ) . '">' .
            esc_html( $entry->report_date ) . '</a> <small>· ' . esc_html( $entry->report_status ) . '</small></p>';
    }
    echo '</div></aside></div>';
    dtb_admin_shell_close();
}
