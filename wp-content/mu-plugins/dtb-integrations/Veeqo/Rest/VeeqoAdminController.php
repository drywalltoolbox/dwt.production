<?php
/** Protected REST controller for the Veeqo wp-admin control center. */
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__ ) . '/Services/VeeqoAdminInventoryWorkspace.php';

final class DTB_Veeqo_Admin_Controller {
	private const NAMESPACE = 'dtb/v1';
	private const BASE = '/veeqo/admin/control-center';

	public static function register(): void {
		register_rest_route(self::NAMESPACE,self::BASE.'/overview',self::read([__CLASS__,'overview']));
		register_rest_route(self::NAMESPACE,self::BASE.'/inventory',self::read([__CLASS__,'inventory'],self::inventory_args()));
		register_rest_route(self::NAMESPACE,self::BASE.'/inventory/(?P<product_id>[\d]+)',self::read([__CLASS__,'inventory_item'],['product_id'=>['required'=>true,'type'=>'integer','minimum'=>1,'sanitize_callback'=>'absint']]));
		register_rest_route(self::NAMESPACE,self::BASE.'/inventory/bulk-preview',self::write([__CLASS__,'bulk_preview']));
		register_rest_route(self::NAMESPACE,self::BASE.'/inventory/bulk-apply',self::write([__CLASS__,'bulk_apply']));
		register_rest_route(self::NAMESPACE,self::BASE.'/orders',self::read([__CLASS__,'orders'],self::order_args()));
		register_rest_route(self::NAMESPACE,self::BASE.'/fulfillment',self::read([__CLASS__,'fulfillment'],self::order_args()));
		register_rest_route(self::NAMESPACE,self::BASE.'/settings',[
			['methods'=>WP_REST_Server::READABLE,'permission_callback'=>[__CLASS__,'can_manage'],'callback'=>[__CLASS__,'settings']],
			['methods'=>WP_REST_Server::EDITABLE,'permission_callback'=>[__CLASS__,'can_manage'],'callback'=>[__CLASS__,'save_settings'],'args'=>[
				'channel_id'=>['type'=>'integer','minimum'=>0,'sanitize_callback'=>'absint'],'warehouse_id'=>['type'=>'integer','minimum'=>0,'sanitize_callback'=>'absint'],'delivery_method_id'=>['type'=>'integer','minimum'=>0,'sanitize_callback'=>'absint']]],
		]);
		register_rest_route(self::NAMESPACE,self::BASE.'/connection/test',self::write([__CLASS__,'test_connection']));
		register_rest_route(self::NAMESPACE,self::BASE.'/inventory/reconcile',self::write([__CLASS__,'reconcile'],['dry_run'=>['type'=>'boolean','default'=>true,'sanitize_callback'=>'rest_sanitize_boolean']]));
		register_rest_route(self::NAMESPACE,self::BASE.'/operations',self::read([__CLASS__,'operations']));
		register_rest_route(self::NAMESPACE,self::BASE.'/operations/(?P<operation_id>[a-z0-9\-]+)',self::read([__CLASS__,'operation'],['operation_id'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_key']]));
		register_rest_route(self::NAMESPACE,self::BASE.'/sku',self::read([__CLASS__,'sku'],['sku'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field']]));
		register_rest_route(self::NAMESPACE,self::BASE.'/orders/(?P<order_id>[\d]+)/retry',self::write([__CLASS__,'retry_order'],['order_id'=>['required'=>true,'type'=>'integer','minimum'=>1,'sanitize_callback'=>'absint'],'reason'=>['type'=>'string','default'=>'operator_retry','sanitize_callback'=>'sanitize_text_field']]));
	}
	public static function can_manage(): bool { return current_user_can('manage_woocommerce'); }
	public static function overview(): WP_REST_Response { return rest_ensure_response(DTB_Veeqo_Admin_Read_Model::overview()); }
	public static function inventory(WP_REST_Request $r): WP_REST_Response { return rest_ensure_response(DTB_Veeqo_Admin_Inventory_Workspace::inventory($r->get_params())); }
	public static function inventory_item(WP_REST_Request $r){$x=DTB_Veeqo_Admin_Inventory_Workspace::item(absint($r['product_id']));return is_wp_error($x)?$x:rest_ensure_response($x);}
	public static function bulk_preview(WP_REST_Request $r){$p=$r->get_json_params();$x=DTB_Veeqo_Admin_Inventory_Workspace::preview(is_array($p)?$p:[]);return is_wp_error($x)?$x:rest_ensure_response($x);}
	public static function bulk_apply(WP_REST_Request $r){$p=$r->get_json_params();$x=DTB_Veeqo_Admin_Inventory_Workspace::apply(is_array($p)?$p:[]);return is_wp_error($x)?$x:new WP_REST_Response($x,200);}
	public static function orders(WP_REST_Request $r): WP_REST_Response { return rest_ensure_response(DTB_Veeqo_Admin_Read_Model::orders($r->get_params(),false)); }
	public static function fulfillment(WP_REST_Request $r): WP_REST_Response { return rest_ensure_response(DTB_Veeqo_Admin_Read_Model::orders($r->get_params(),true)); }
	public static function settings(): WP_REST_Response { return rest_ensure_response(DTB_Veeqo_Admin_Read_Model::settings()); }
	public static function save_settings(WP_REST_Request $r): WP_REST_Response {$s=DTB_Veeqo_Admin_Read_Model::save_settings($r->get_json_params()?:$r->get_params());if(is_wp_error($s))return $s;if(function_exists('dtb_veeqo_log'))dtb_veeqo_log('info','admin_configuration_saved','Veeqo operational IDs were updated from the DTB control center.',['operator_id'=>get_current_user_id(),'channel_id'=>absint($s['channel_id']??0),'warehouse_id'=>absint($s['warehouse_id']??0),'delivery_method_id'=>absint($s['delivery_method_id']??0)]);return rest_ensure_response(['success'=>true,'settings'=>$s,'message'=>'Veeqo operational settings saved.']);}
	public static function test_connection(): WP_REST_Response {if(!function_exists('dtb_veeqo_production_validate_configuration'))return new WP_REST_Response(['success'=>false,'code'=>'configuration_service_unavailable','message'=>'Veeqo configuration validation is unavailable.'],503);$d=dtb_veeqo_production_validate_configuration(true);return new WP_REST_Response(['success'=>!empty($d['ready']),'diagnostics'=>$d,'settings'=>DTB_Veeqo_Admin_Read_Model::settings()],200);}
	public static function reconcile(WP_REST_Request $r): WP_REST_Response {
		if(!class_exists('DTB_Veeqo_Operation_Store'))return new WP_REST_Response(['ok'=>false,'code'=>'operation_store_unavailable','message'=>'Veeqo operation storage is unavailable.'],503);
		$dry_run=rest_sanitize_boolean($r->get_param('dry_run'));
		$result=DTB_Veeqo_Operation_Store::enqueue($dry_run);
		if(function_exists('dtb_veeqo_log'))dtb_veeqo_log('info','admin_inventory_operation_requested','Operator requested a Veeqo inventory operation.',['operator_id'=>get_current_user_id(),'dry_run'=>$dry_run,'operation_id'=>sanitize_key((string)($result['operation']['operation_id']??''))]);
		return new WP_REST_Response($result,absint($result['status']??200));
	}
	public static function operations(): WP_REST_Response {$a=class_exists('DTB_Veeqo_Operation_Store')?DTB_Veeqo_Operation_Store::active():[];return rest_ensure_response(['active'=>empty($a)||!class_exists('DTB_Veeqo_Operation_Store')?null:DTB_Veeqo_Operation_Store::summary($a),'items'=>class_exists('DTB_Veeqo_Operation_Store')?DTB_Veeqo_Operation_Store::recent():[]]);}
	public static function operation(WP_REST_Request $r){$o=class_exists('DTB_Veeqo_Operation_Store')?DTB_Veeqo_Operation_Store::get((string)$r['operation_id']):[];return empty($o)?new WP_Error('operation_not_found','Veeqo operation not found.',['status'=>404]):rest_ensure_response($o);}
	public static function sku(WP_REST_Request $r){$x=DTB_Veeqo_Admin_Read_Model::exact_sku((string)$r->get_param('sku'));return is_wp_error($x)?$x:rest_ensure_response($x);}
	public static function retry_order(WP_REST_Request $r): WP_REST_Response {
		$id=absint($r['order_id']);$order=function_exists('wc_get_order')?wc_get_order($id):null;
		if(!$order instanceof WC_Order)return new WP_REST_Response(['success'=>false,'code'=>'order_not_found','message'=>'WooCommerce order not found.'],404);
		if(!function_exists('dtb_order_enqueue_job'))return new WP_REST_Response(['success'=>false,'code'=>'queue_unavailable','message'=>'The canonical DTB order queue is unavailable.'],503);
		$reason=substr(sanitize_text_field((string)$r->get_param('reason')),0,160);
		$job=dtb_order_enqueue_job('dtb_order_sync_veeqo',$id,['trigger'=>'veeqo_control_center','retry_reason'=>$reason,'operator_id'=>get_current_user_id()]);
		if(false===$job)return new WP_REST_Response(['success'=>false,'code'=>'queue_failed','message'=>'The Veeqo order retry could not be queued.'],503);
		if(function_exists('dtb_order_update_integration_state'))dtb_order_update_integration_state($id,'veeqo',['status'=>'queued','retry_reason'=>$reason,'operator_id'=>get_current_user_id()]);
		if(function_exists('dtb_order_append_event'))dtb_order_append_event($id,'integration.veeqo.retry_queued',['source'=>'veeqo_control_center','actor_type'=>'admin','actor_id'=>get_current_user_id(),'visibility'=>'operator','idempotency_key'=>'veeqo-control-center-retry:'.$id.':'.get_current_user_id().':'.gmdate('Y-m-d-H-i'),'payload'=>['retry_reason'=>$reason]]);
		return new WP_REST_Response(['success'=>true,'status'=>'queued','job_id'=>$job,'message'=>sprintf('Veeqo retry queued for order #%d.',$id)],202);
	}
	private static function read(callable $cb,array $args=[]): array {return ['methods'=>WP_REST_Server::READABLE,'permission_callback'=>[__CLASS__,'can_manage'],'callback'=>$cb,'args'=>$args];}
	private static function write(callable $cb,array $args=[]): array {return ['methods'=>WP_REST_Server::CREATABLE,'permission_callback'=>[__CLASS__,'can_manage'],'callback'=>$cb,'args'=>$args];}
	private static function inventory_args(): array {return ['page'=>['type'=>'integer','minimum'=>1,'default'=>1,'sanitize_callback'=>'absint'],'per_page'=>['type'=>'integer','minimum'=>10,'maximum'=>100,'default'=>50,'sanitize_callback'=>'absint'],'search'=>['type'=>'string','sanitize_callback'=>'sanitize_text_field'],'stock_status'=>['type'=>'string','enum'=>['','instock','lowstock','outofstock','onbackorder','negative','committed_exceeds_on_hand'],'sanitize_callback'=>'sanitize_key'],'mapping'=>['type'=>'string','enum'=>['','mapped','unmapped','mismatch'],'sanitize_callback'=>'sanitize_key'],'type'=>['type'=>'string','enum'=>['','simple','variation'],'sanitize_callback'=>'sanitize_key'],'orderby'=>['type'=>'string','enum'=>['name','sku','stock_quantity','stock_status','updated','on_hand','committed','available','incoming','mapping'],'default'=>'sku','sanitize_callback'=>'sanitize_key'],'order'=>['type'=>'string','enum'=>['asc','desc'],'default'=>'asc','sanitize_callback'=>'sanitize_key']];}
	private static function order_args(): array {return ['page'=>['type'=>'integer','minimum'=>1,'default'=>1,'sanitize_callback'=>'absint'],'per_page'=>['type'=>'integer','minimum'=>10,'maximum'=>100,'default'=>25,'sanitize_callback'=>'absint'],'status'=>['type'=>'string','sanitize_callback'=>'sanitize_key'],'search'=>['type'=>'string','sanitize_callback'=>'sanitize_text_field']];}
}
add_action('rest_api_init',['DTB_Veeqo_Admin_Controller','register'],50);
