<?php
/**
 * Marketplace — AuditService
 *
 * Writes per-operator audit log entries to wp_dtb_marketplace_audit.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceAuditService' ) ) {
	final class DTB_MarketplaceAuditService {

		/**
		 * Write an audit entry.
		 *
		 * @param string $action      Action slug, e.g. 'message.send'.
		 * @param string $object_type Object type, e.g. 'marketplace_message'.
		 * @param int    $object_id   Object ID.
		 * @param string $channel_key Channel key.
		 * @param array  $options {
		 *   @type array $before     Before state (safe, no secrets).
		 *   @type array $after      After state.
		 *   @type int   $actor_id   WP user ID (defaults to current_user_id).
		 * }
		 */
		public static function write( string $action, string $object_type, int $object_id, string $channel_key, array $options = [] ): void {
			global $wpdb;
			$table    = $wpdb->prefix . 'dtb_marketplace_audit';
			$actor_id = (int) ( $options['actor_id'] ?? get_current_user_id() );
			$ip_raw   = sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			$ua_raw   = sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				[
					'actor_id'     => $actor_id ?: null,
					'actor_type'   => $actor_id ? 'operator' : 'system',
					'action'       => sanitize_text_field( $action ),
					'object_type'  => sanitize_text_field( $object_type ),
					'object_id'    => $object_id ?: null,
					'channel_key'  => sanitize_key( $channel_key ),
					'before_json'  => isset( $options['before'] ) ? wp_json_encode( $options['before'] ) : null,
					'after_json'   => isset( $options['after'] )  ? wp_json_encode( $options['after'] )  : null,
					'ip_hash'      => '' !== $ip_raw ? hash( 'sha256', $ip_raw ) : '',
					'ua_hash'      => '' !== $ua_raw ? hash( 'sha256', $ua_raw ) : '',
					'created_at'   => current_time( 'mysql', true ),
				],
				[ '%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s' ]
			);
		}

		/**
		 * Return recent audit entries for an object.
		 *
		 * @param string $object_type Object type.
		 * @param int    $object_id   Object ID.
		 * @param int    $limit       Max rows.
		 * @return array[]
		 */
		public static function get_for_object( string $object_type, int $object_id, int $limit = 50 ): array {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_audit';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, actor_id, actor_type, action, object_type, object_id, channel_key, after_json, created_at
				 FROM {$table}
				 WHERE object_type = %s AND object_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d",
				$object_type, $object_id, $limit
			), ARRAY_A );
		}
	}
}
