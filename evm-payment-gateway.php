<?php
/**
 * Plugin Name: EVM Payment Gateway
 * Plugin URI: https://github.com/bloclabs-io/evm-payment-gateway
 * Description: Accept ERC-20 token payments on any EVM-compatible network through WooCommerce, with on-chain payment verification.
 * Version: 1.1.0
 * Author: blocLabs.io
 * Author URI: https://github.com/bloclabs-io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: evm-payment-gateway
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.3
 * WC tested up to: 9.6
 *
 * @package EVM_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EVP_PLUGIN_FILE', __FILE__ );
define( 'EVP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EVP_VERSION', '1.1.0' );
define( 'EVP_GATEWAY_ID', 'evm_payment' );

// Autoloader for the EVP\ namespace (PSR-4, rooted at includes/).
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'EVP\\';
		$len    = strlen( $prefix );
		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
			return;
		}

		$file = EVP_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, $len ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Declare compatibility with HPOS and the Cart/Checkout blocks.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Returns the gateway instance registered with WooCommerce, if available.
 *
 * @return \EVP\Gateway\Payment_Gateway|null
 */
function evp_get_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}

	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways[ EVP_GATEWAY_ID ] ) ? $gateways[ EVP_GATEWAY_ID ] : null;
}

/**
 * Bootstraps the plugin once all plugins are loaded.
 */
function evp_bootstrap() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	add_filter(
		'woocommerce_payment_gateways',
		function ( $gateways ) {
			$gateways[] = \EVP\Gateway\Payment_Gateway::class;
			return $gateways;
		}
	);

	// Front-end payment page.
	add_action(
		'wp_enqueue_scripts',
		function () {
			$gateway = is_checkout_pay_page() ? evp_get_gateway() : null;
			if ( $gateway ) {
				$gateway->payment_scripts();
			}
		}
	);
	add_action(
		'woocommerce_receipt_' . EVP_GATEWAY_ID,
		function ( $order_id ) {
			$gateway = evp_get_gateway();
			if ( $gateway ) {
				$gateway->receipt_page( $order_id );
			}
		}
	);
	add_action(
		'woocommerce_thankyou_' . EVP_GATEWAY_ID,
		function ( $order_id ) {
			$gateway = evp_get_gateway();
			if ( $gateway ) {
				$gateway->thankyou_page( $order_id );
			}
		}
	);

	// AJAX endpoints. Guests are allowed; every request is authorised by nonce + order key.
	$ajax_actions = array(
		'evp_prepare_payment'    => 'ajax_prepare_payment',
		'evp_submit_transaction' => 'ajax_submit_transaction',
	);
	foreach ( $ajax_actions as $action => $method ) {
		$handler = function () use ( $method ) {
			$gateway = evp_get_gateway();
			if ( ! $gateway ) {
				wp_send_json_error( array( 'message' => __( 'Payment method unavailable.', 'evm-payment-gateway' ) ), 400 );
			}
			$gateway->$method();
		};
		add_action( 'wp_ajax_' . $action, $handler );
		add_action( 'wp_ajax_nopriv_' . $action, $handler );
	}

	// Background re-check of transactions that were not yet confirmed (Action Scheduler).
	add_action(
		'evp_check_transaction',
		function ( $order_id ) {
			$gateway = evp_get_gateway();
			if ( $gateway ) {
				$gateway->scheduled_check( (int) $order_id );
			}
		}
	);

	// Don't let WooCommerce auto-cancel a pending order whose transaction is awaiting confirmation.
	add_filter(
		'woocommerce_cancel_unpaid_order',
		function ( $cancel, $order ) {
			if ( $order instanceof WC_Order && EVP_GATEWAY_ID === $order->get_payment_method() && $order->get_transaction_id() ) {
				return false;
			}
			return $cancel;
		},
		10,
		2
	);

	// Cart/Checkout blocks integration.
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new \EVP\Blocks\Payment_Method_Type() );
		}
	);

	add_filter(
		'plugin_action_links_' . plugin_basename( __FILE__ ),
		function ( $links ) {
			$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . EVP_GATEWAY_ID );
			array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'evm-payment-gateway' ) . '</a>' );
			return $links;
		}
	);
}
add_action( 'plugins_loaded', 'evp_bootstrap' );

// Translations must not be loaded before `init` (WordPress 6.7+).
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'evm-payment-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

register_activation_hook(
	__FILE__,
	function () {
		do_action( 'evp_activate' );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'evp_check_transaction' );
		}
		do_action( 'evp_deactivate' );
	}
);
