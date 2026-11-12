<?php
/**
 * Standalone contract checks for DTB Veeqo WordPress Abilities.
 *
 * Run: php scripts/tests/test-veeqo-mcp-abilities.php
 * Requires PHP 8.0+ only. No WordPress runtime or Veeqo calls.
 */
declare(strict_types=1);
define('ABSPATH', '/virtual/wordpress/');
$GLOBALS['dtb_mcp_test_abilities'] = [];
$GLOBALS['dtb_mcp_test_allowed'] = false;

function add_action(string $hook, $callback): void {}
function wp_register_ability_category(string $slug, array $args): void {}
function wp_register_ability(string $slug, array $args): void { $GLOBALS['dtb_mcp_test_abilities'][$slug] = $args; }
function is_user_logged_in(): bool { return $GLOBALS['dtb_mcp_test_allowed']; }
function current_user_can(string $cap): bool { return $GLOBALS['dtb_mcp_test_allowed'] && $cap === 'manage_woocommerce'; }
function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }

class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
class WP_REST_Response {
    public function __construct(private array $value) {}
    public function get_data(): array { return $this->value; }
}
class WP_REST_Request {
    private array $params = [];
    public function __construct(public string $method, public string $route) {}
    public function set_header(string $name, string $value): void {}
    public function set_body(string $body): void {}
    public function set_param(string $name, $value): void { $this->params[$name] = $value; }
    public function get_params(): array { return $this->params; }
    public function get_param(string $name) { return $this->params[$name] ?? null; }
}
class DTB_Veeqo_Admin_Controller {
    public static function overview(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(['ok' => true, 'method' => $request->method]);
    }
}
function fail_test(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function ensure(bool $condition, string $message): void { if (!$condition) fail_test($message); }

require dirname(__DIR__, 2) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-integrations/Veeqo/Rest/VeeqoOperationsMcpAbilities.php';
DTB_Veeqo_Operations_MCP_Abilities::register();

$abilities = $GLOBALS['dtb_mcp_test_abilities'];
$expected = ['overview', 'settings', 'inventory', 'inventory-item', 'orders', 'fulfillment', 'operations', 'operation', 'sku', 'mapping-diagnostics', 'inventory-preview', 'inventory-apply'];
ensure(count($abilities) === count($expected), 'Expected 12 distinct registered operations abilities');
foreach ($expected as $slug) {
    $name = 'dtb-veeqo-operations/' . $slug;
    ensure(isset($abilities[$name]), 'Missing ' . $name);
    $ability = $abilities[$name];
    ensure($ability['input_schema']['additionalProperties'] === false, "$slug must reject unexpected arguments");
    ensure($ability['meta']['show_in_rest'] === true, "$slug must be discoverable");
    ensure(($ability['permission_callback'])() === false, "$slug must refuse anonymous users");
    ensure(($ability['execute_callback'])([]) instanceof WP_Error, "$slug must reject unauthorized execution");
}
$read = $abilities['dtb-veeqo-operations/overview'];
ensure(($read['meta']['annotations']['readonly'] ?? false) === true, 'Overview must be marked read-only');
foreach (['inventory-preview', 'inventory-apply'] as $slug) {
    $a = $abilities['dtb-veeqo-operations/' . $slug];
    ensure(($a['meta']['annotations']['readonly'] ?? true) === false, "$slug must be annotated as a write");
    ensure(($a['meta']['annotations']['destructive'] ?? false) === true, "$slug must be annotated as consequential");
}
$GLOBALS['dtb_mcp_test_allowed'] = true;
ensure(($read['permission_callback'])() === true, 'Authorized WooCommerce operator should pass');
$answer = ($read['execute_callback'])([]);
ensure(($answer['ok'] ?? false) === true, 'Existing controller delegation failed');
ensure(($answer['method'] ?? '') === 'GET', 'Overview must use read-only request method');
fwrite(STDOUT, 'PASS: 12 abilities registered, schemas bounded, unauthorized users denied, write hints correct, controller delegation works.' . PHP_EOL);
