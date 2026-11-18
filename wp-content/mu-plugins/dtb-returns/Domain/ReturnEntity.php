<?php
/**
 * DTB Returns — ReturnEntity
 *
 * Thin read model built from a WP_Post (dtb_return CPT).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

class DTB_Return_Entity {

	public int    $id;
	public int    $order_id;
	public string $order_number;
	public string $customer_name;
	public string $customer_email;
	public string $request_type;
	public string $reason;
	public string $notes;
	public array  $items;
	public string $resolution;  // refund | exchange | store_credit
	public DTB_Return_Status $status;
	public string $created_at;
	public string $updated_at;

	private function __construct() {}

	public static function from_post( WP_Post $post ): self {
		$e                 = new self();
		$e->id             = $post->ID;
		$e->order_id       = (int) get_post_meta( $post->ID, '_dtb_return_order_id', true );
		$e->order_number   = (string) get_post_meta( $post->ID, '_dtb_return_order_number', true );
		$e->customer_name  = (string) get_post_meta( $post->ID, '_dtb_return_customer_name', true );
		$e->customer_email = (string) get_post_meta( $post->ID, '_dtb_return_customer_email', true );
		$e->request_type   = (string) get_post_meta( $post->ID, '_dtb_return_request_type', true );
		$e->reason         = (string) get_post_meta( $post->ID, '_dtb_return_reason', true );
		$e->notes          = (string) get_post_meta( $post->ID, '_dtb_return_notes', true );
		$e->resolution     = (string) get_post_meta( $post->ID, '_dtb_return_resolution', true );
		$e->status         = new DTB_Return_Status( (string) get_post_meta( $post->ID, '_dtb_return_status', true ) );
		$e->created_at     = $post->post_date;
		$e->updated_at     = $post->post_modified;

		$items = json_decode( (string) get_post_meta( $post->ID, '_dtb_return_items', true ), true );
		$e->items = is_array( $items ) ? $items : [];
		return $e;
	}

	public function to_array(): array {
		return [
			'id'             => $this->id,
			'order_id'       => $this->order_id,
			'order_number'   => $this->order_number,
			'customer_name'  => $this->customer_name,
			'customer_email' => $this->customer_email,
			'request_type'   => $this->request_type,
			'reason'         => $this->reason,
			'notes'          => $this->notes,
			'items'          => $this->items,
			'resolution'     => $this->resolution,
			'status'         => $this->status->value(),
			'status_label'   => $this->status->label(),
			'created_at'     => $this->created_at,
			'updated_at'     => $this->updated_at,
		];
	}
}
