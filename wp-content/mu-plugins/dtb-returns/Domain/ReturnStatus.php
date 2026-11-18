<?php
/**
 * DTB Returns — ReturnStatus value object
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_Return_Status {

	const PENDING_REVIEW    = 'pending_review';
	const APPROVED          = 'approved';
	const REJECTED          = 'rejected';
	const AWAITING_ITEM     = 'awaiting_item';
	const ITEM_RECEIVED     = 'item_received';
	const REFUND_ISSUED     = 'refund_issued';
	const EXCHANGE_SENT     = 'exchange_sent';
	const CLOSED            = 'closed';

	private string $value;

	public function __construct( string $value ) {
		if ( ! in_array( $value, self::all(), true ) ) {
			$value = self::PENDING_REVIEW;
		}
		$this->value = $value;
	}

	public function value(): string {
		return $this->value;
	}

	public function label(): string {
		return self::labels()[ $this->value ] ?? ucwords( str_replace( '_', ' ', $this->value ) );
	}

	public static function all(): array {
		return [
			self::PENDING_REVIEW,
			self::APPROVED,
			self::REJECTED,
			self::AWAITING_ITEM,
			self::ITEM_RECEIVED,
			self::REFUND_ISSUED,
			self::EXCHANGE_SENT,
			self::CLOSED,
		];
	}

	public static function labels(): array {
		return [
			self::PENDING_REVIEW => __( 'Pending Review',   'drywall-toolbox' ),
			self::APPROVED       => __( 'Approved',         'drywall-toolbox' ),
			self::REJECTED       => __( 'Rejected',         'drywall-toolbox' ),
			self::AWAITING_ITEM  => __( 'Awaiting Item',    'drywall-toolbox' ),
			self::ITEM_RECEIVED  => __( 'Item Received',    'drywall-toolbox' ),
			self::REFUND_ISSUED  => __( 'Refund Issued',    'drywall-toolbox' ),
			self::EXCHANGE_SENT  => __( 'Exchange Sent',    'drywall-toolbox' ),
			self::CLOSED         => __( 'Closed',           'drywall-toolbox' ),
		];
	}
}
