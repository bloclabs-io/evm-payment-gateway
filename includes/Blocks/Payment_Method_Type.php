<?php
/**
 * Registers the gateway with the WooCommerce Cart/Checkout blocks.
 *
 * @package EVM_Payment_Gateway
 */

namespace EVP\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Method_Type extends AbstractPaymentMethodType {

	/** @var string */
	protected $name = EVP_GATEWAY_ID;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . EVP_GATEWAY_ID . '_settings', array() );
	}

	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled' )
			&& preg_match( '/^0x[0-9a-fA-F]{40}$/', (string) $this->get_setting( 'target_address' ) )
			&& preg_match( '/^0x[0-9a-fA-F]{40}$/', (string) $this->get_setting( 'contract_address' ) )
			&& (int) $this->get_setting( 'blockchain_network' ) > 0
			&& '' !== (string) $this->get_setting( 'rpc_url' );
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'evm-payment-blocks',
			EVP_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			EVP_VERSION,
			true
		);

		return array( 'evm-payment-blocks' );
	}

	public function get_payment_method_data() {
		$gateway = evp_get_gateway();

		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'icon'        => $gateway ? $gateway->icon : '',
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}
