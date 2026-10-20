<?php
/**
 * Durable QuickBooks accounting document ledger and policy store.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_QBO_ACCOUNTING_DB_VERSION' ) ) {
	define( 'DTB_QBO_ACCOUNTING_DB_VERSION', '1.0.0' );
}

final class DTB_QBO_AccountingLedger {
	private const TABLE_SUFFIX = 'dtb_accounting_documents';

	public static function register(): void {
		add_action( 'plugins_loaded', [ self::class, 'maybe_install' ], 6 );
	}

	public static function maybe_install(): void {
		if ( DTB_QBO_ACCOUNTING_DB_VERSION === (string) get_option( 'dtb_qbo_accounting_db_version', '' ) ) {
			return;
		}

		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
environment varchar(20) NOT NULL,
realm_hash char(64) NOT NULL,
source_type varchar(30) NOT NULL,
source_key varchar(191) NOT NULL,
source_id bigint(20) unsigned NOT NULL DEFAULT 0,
order_id bigint(20) unsigned NOT NULL DEFAULT 0,
document_number varchar(40) NOT NULL,
direction varchar(20) NOT NULL,
currency char(3) NOT NULL DEFAULT 'USD',
txn_date date DEFAULT NULL,
expected_total decimal(20,6) NOT NULL DEFAULT 0,
payload_total decimal(20,6) NOT NULL DEFAULT 0,
qbo_total decimal(20,6) DEFAULT NULL,
variance decimal(20,6) NOT NULL DEFAULT 0,
subtotal decimal(20,6) NOT NULL DEFAULT 0,
tax_total decimal(20,6) NOT NULL DEFAULT 0,
refunded_tax decimal(20,6) NOT NULL DEFAULT 0,
discount_total decimal(20,6) NOT NULL DEFAULT 0,
shipping_total decimal(20,6) NOT NULL DEFAULT 0,
fee_total decimal(20,6) NOT NULL DEFAULT 0,
state varchar(40) NOT NULL DEFAULT 'pending',
exception_code varchar(100) DEFAULT NULL,
error_message text,
retryable tinyint(1) NOT NULL DEFAULT 0,
attempt int(10) unsigned NOT NULL DEFAULT 0,
qbo_entity_type varchar(40) DEFAULT NULL,
qbo_entity_id varchar(100) DEFAULT NULL,
qbo_sync_token varchar(40) DEFAULT NULL,
payload_hash char(64) DEFAULT NULL,
policy_version varchar(50) NOT NULL,
safe_snapshot_json longtext,
tax_json longtext,
trace_id varchar(191) DEFAULT NULL,
action_id bigint(20) unsigned DEFAULT NULL,
external_state varchar(50) DEFAULT NULL,
reviewed_by bigint(20) unsigned DEFAULT NULL,
reviewed_at datetime DEFAULT NULL,
posted_at datetime DEFAULT NULL,
reconciled_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY (id),
UNIQUE KEY source_identity (environment, realm_hash, source_type, source_key),
KEY order_id (order_id),
KEY state_updated (state, updated_at),
KEY txn_date (txn_date),
KEY qbo_entity (qbo_entity_type, qbo_entity_id),
KEY external_state (external_state)
) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'dtb_qbo_accounting_db_version', DTB_QBO_ACCOUNTING_DB_VERSION, false );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Insert or update one environment- and realm-bound accounting document.
	 *
	 * @param array<string,mixed> $record Record values.
	 */
	public static function upsert( array $record ): int|false {
		global $wpdb;
		$now         = current_time( 'mysql', true );
		$environment = sanitize_key( (string) ( $record['environment'] ?? dtb_qbo_environment() ) );
		$realm_hash  = sanitize_text_field( (string) ( $record['realm_hash'] ?? self::realm_hash() ) );
		$source_type = sanitize_key( (string) ( $record['source_type'] ?? 'order' ) );
		$source_key  = sanitize_text_field( (string) ( $record['source_key'] ?? '' ) );

		if ( '' === $realm_hash || '' === $source_key ) {
			return false;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE environment = %s AND realm_hash = %s AND source_type = %s AND source_key = %s',
				$environment,
				$realm_hash,
				$source_type,
				$source_key
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$row = self::sanitize_record(
			array_merge(
				$record,
				[
					'environment' => $environment,
					'realm_hash'  => $realm_hash,
					'source_type' => $source_type,
					'source_key'  => $source_key,
					'updated_at'  => $now,
				]
			)
		);

		if ( $existing ) {
			$wpdb->update( self::table(), $row, [ 'id' => (int) $existing ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->last_error ? false : (int) $existing;
		}

		$row['created_at'] = $now;
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->last_error ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? self::decode_record( $row ) : null;
	}

	public static function find_source( string $source_type, string $source_key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE environment = %s AND realm_hash = %s AND source_type = %s AND source_key = %s',
				dtb_qbo_environment(),
				self::realm_hash(),
				sanitize_key( $source_type ),
				sanitize_text_field( $source_key )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? self::decode_record( $row ) : null;
	}

	public static function find_entity( string $entity_type, string $entity_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE environment = %s AND realm_hash = %s AND qbo_entity_type = %s AND qbo_entity_id = %s LIMIT 1',
				dtb_qbo_environment(),
				self::realm_hash(),
				sanitize_text_field( $entity_type ),
				sanitize_text_field( $entity_id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? self::decode_record( $row ) : null;
	}

	/**
	 * Query the accounting ledger with indexed filters.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;
		$page   = max( 1, absint( $args['page'] ?? 1 ) );
		$limit  = min( 100, max( 1, absint( $args['limit'] ?? 25 ) ) );
		$offset = ( $page - 1 ) * $limit;
		$where  = [
			$wpdb->prepare( 'environment = %s', dtb_qbo_environment() ),
			$wpdb->prepare( 'realm_hash = %s', self::realm_hash() ),
		];

		if ( ! empty( $args['state'] ) ) {
			$where[] = $wpdb->prepare( 'state = %s', sanitize_key( $args['state'] ) );
		}
		if ( ! empty( $args['states'] ) && is_array( $args['states'] ) ) {
			$states = array_values( array_filter( array_map( 'sanitize_key', $args['states'] ) ) );
			if ( $states ) {
				$where[] = 'state IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')';
				$where[ array_key_last( $where ) ] = $wpdb->prepare( $where[ array_key_last( $where ) ], ...$states );
			}
		}
		if ( ! empty( $args['source_type'] ) ) {
			$where[] = $wpdb->prepare( 'source_type = %s', sanitize_key( $args['source_type'] ) );
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[] = $wpdb->prepare( 'txn_date >= %s', self::date( $args['date_from'] ) );
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[] = $wpdb->prepare( 'txn_date <= %s', self::date( $args['date_to'] ) );
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where[] = $wpdb->prepare( '(source_key LIKE %s OR document_number LIKE %s OR qbo_entity_id LIKE %s)', $like, $like, $like );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$table     = self::table();
		$total     = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$table} {$where_sql}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows      = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY txn_date DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return [
			'rows'  => array_map( [ self::class, 'decode_record' ], $rows ),
			'page'  => $page,
			'limit' => $limit,
			'total' => $total,
			'pages' => max( 1, (int) ceil( $total / $limit ) ),
		];
	}

	/**
	 * Period totals for the accounting cockpit.
	 *
	 * @return array<string,mixed>
	 */
	public static function metrics( string $date_from, string $date_to ): array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
SUM(CASE WHEN direction = 'sale' THEN expected_total ELSE 0 END) AS sales,
SUM(CASE WHEN direction = 'refund' THEN expected_total ELSE 0 END) AS refunds,
SUM(CASE WHEN direction = 'settlement' THEN fee_total ELSE 0 END) AS fees,
SUM(CASE WHEN direction = 'sale' THEN tax_total WHEN direction = 'refund' THEN -refunded_tax ELSE 0 END) AS tax_collected,
SUM(CASE WHEN state IN ('exception','failed','pending','queued') THEN ABS(variance) ELSE 0 END) AS unreconciled,
SUM(CASE WHEN state = 'reconciled' THEN 1 ELSE 0 END) AS reconciled_count,
SUM(CASE WHEN state IN ('exception','failed') THEN 1 ELSE 0 END) AS exception_count,
COUNT(1) AS document_count
FROM {$table}
WHERE environment = %s AND realm_hash = %s AND txn_date BETWEEN %s AND %s",
				dtb_qbo_environment(),
				self::realm_hash(),
				self::date( $date_from ),
				self::date( $date_to )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array_map(
			static fn( mixed $value ): float|int => is_numeric( $value ) ? ( false !== str_contains( (string) $value, '.' ) ? (float) $value : (int) $value ) : 0,
			(array) $row
		);
	}

	public static function realm_hash(): string {
		$config = function_exists( 'dtb_qbo_config' ) ? dtb_qbo_config() : [];
		$realm  = preg_replace( '/[^0-9]/', '', (string) ( $config['realm_id'] ?? '' ) );
		return '' === $realm ? '' : hash( 'sha256', dtb_qbo_environment() . '|' . $realm );
	}

	/** @return array<string,mixed> */
	public static function policy(): array {
		$defaults = [
			'policy_version'       => DTB_QBO_AccountingMath::POLICY_VERSION,
			'tax_code_id'         => '',
			'tax_code_name'       => '',
			'deposit_account_id'  => '',
			'deposit_account_name'=> '',
			'clearing_account_id' => '',
			'clearing_account_name'=> '',
			'fee_account_id'      => '',
			'fee_account_name'    => '',
			'bank_account_id'     => '',
			'bank_account_name'   => '',
			'auto_sync'           => true,
			'daily_reconcile'     => true,
			'stripe_sync'         => false,
			'closed_through'      => '',
			'approved_by'        => 0,
			'approved_at'        => '',
		];
		$stored = get_option( dtb_qbo_option_name( 'accounting_policy' ), [] );
		return is_array( $stored ) ? array_replace( $defaults, $stored ) : $defaults;
	}

	/** @param array<string,mixed> $policy */
	public static function save_policy( array $policy, int $user_id ): array {
		$current = self::policy();
		$keys    = [
			'tax_code_id', 'tax_code_name', 'deposit_account_id', 'deposit_account_name',
			'clearing_account_id', 'clearing_account_name', 'fee_account_id', 'fee_account_name',
			'bank_account_id', 'bank_account_name', 'closed_through',
		];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $policy ) ) {
				$current[ $key ] = sanitize_text_field( (string) $policy[ $key ] );
			}
		}
		foreach ( [ 'auto_sync', 'daily_reconcile', 'stripe_sync' ] as $key ) {
			if ( array_key_exists( $key, $policy ) ) {
				$current[ $key ] = rest_sanitize_boolean( $policy[ $key ] );
			}
		}
		$current['policy_version'] = DTB_QBO_AccountingMath::POLICY_VERSION;
		$current['approved_by']    = $user_id;
		$current['approved_at']    = gmdate( 'c' );
		update_option( dtb_qbo_option_name( 'accounting_policy' ), $current, false );
		return $current;
	}

	private static function sanitize_record( array $record ): array {
		$allowed = [
			'environment', 'realm_hash', 'source_type', 'source_key', 'source_id', 'order_id',
			'document_number', 'direction', 'currency', 'txn_date', 'expected_total', 'payload_total',
			'qbo_total', 'variance', 'subtotal', 'tax_total', 'refunded_tax', 'discount_total',
			'shipping_total', 'fee_total', 'state', 'exception_code', 'error_message', 'retryable',
			'attempt', 'qbo_entity_type', 'qbo_entity_id', 'qbo_sync_token', 'payload_hash',
			'policy_version', 'safe_snapshot_json', 'tax_json', 'trace_id', 'action_id',
			'external_state', 'reviewed_by', 'reviewed_at', 'posted_at', 'reconciled_at',
			'created_at', 'updated_at',
		];
		$row     = [];
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $record ) ) {
				continue;
			}
			$value = $record[ $key ];
			if ( in_array( $key, [ 'source_id', 'order_id', 'attempt', 'action_id', 'reviewed_by' ], true ) ) {
				$row[ $key ] = absint( $value );
			} elseif ( in_array( $key, [ 'expected_total', 'payload_total', 'qbo_total', 'variance', 'subtotal', 'tax_total', 'refunded_tax', 'discount_total', 'shipping_total', 'fee_total' ], true ) ) {
				$row[ $key ] = null === $value ? null : (float) $value;
			} elseif ( 'retryable' === $key ) {
				$row[ $key ] = $value ? 1 : 0;
			} elseif ( in_array( $key, [ 'safe_snapshot_json', 'tax_json' ], true ) ) {
				$row[ $key ] = is_string( $value ) ? $value : wp_json_encode( $value );
			} elseif ( 'error_message' === $key ) {
				$row[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 2000 );
			} else {
				$row[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $row;
	}

	private static function decode_record( array $row ): array {
		$row['id']            = absint( $row['id'] ?? 0 );
		$row['source_id']     = absint( $row['source_id'] ?? 0 );
		$row['order_id']      = absint( $row['order_id'] ?? 0 );
		$row['attempt']       = absint( $row['attempt'] ?? 0 );
		$row['retryable']     = ! empty( $row['retryable'] );
		$row['safe_snapshot'] = json_decode( (string) ( $row['safe_snapshot_json'] ?? '' ), true ) ?: [];
		$row['taxes']         = json_decode( (string) ( $row['tax_json'] ?? '' ), true ) ?: [];
		unset( $row['realm_hash'], $row['safe_snapshot_json'], $row['tax_json'] );
		return $row;
	}

	private static function date( mixed $value ): string {
		$date = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : gmdate( 'Y-m-d' );
	}
}

DTB_QBO_AccountingLedger::register();
