<?php
/**
 * WooCommerce payment gateway for ERC-20 tokens on EVM chains.
 *
 * @package EVM_Payment_Gateway
 */

namespace EVP\Gateway;

use EVP\Chain\Token_Amount;
use EVP\Chain\Transaction_Verifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payment_Gateway extends \WC_Payment_Gateway {

	/** Order meta: wallet the customer committed to pay from. */
	const META_PAYER = '_evp_payer_address';

	/** Order meta: number of background verification attempts. */
	const META_ATTEMPTS = '_evp_check_attempts';

	/** Seconds between background verification attempts. */
	const CHECK_INTERVAL = 60;

	/** Background attempts before giving up (about one hour). */
	const MAX_ATTEMPTS = 60;

	/** @var string */
	public $target_address;

	/** @var string */
	public $contract_address;

	/** @var int */
	public $token_decimals;

	/** @var int */
	public $blockchain_network;

	/** @var string */
	public $rpc_url;

	/** @var int */
	public $confirmations;

	/** @var string */
	public $explorer_url;

	/** @var bool */
	public $debug;

	/** @var string */
	public $token_symbol;

	/** @var string */
	public $chain_name;

	/** @var string */
	public $native_symbol;

	/** @var string */
	public $public_rpc_url;

	public function __construct() {
		$this->id                 = EVP_GATEWAY_ID;
		$this->icon               = apply_filters( 'evm_payment_icon', '' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->method_title       = __( 'EVM Token Payment', 'evm-payment-gateway' );
		$this->method_description = __( 'Accept ERC-20 token payments on EVM-compatible networks. Payments are verified on-chain before orders are marked as paid.', 'evm-payment-gateway' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->target_address     = $this->get_option( 'target_address' );
		$this->contract_address   = $this->get_option( 'contract_address' );
		$this->token_decimals     = (int) $this->get_option( 'token_decimals', 18 );
		$this->blockchain_network = (int) $this->get_option( 'blockchain_network' );
		$this->rpc_url            = $this->get_option( 'rpc_url' );
		$this->confirmations      = max( 1, (int) $this->get_option( 'confirmations', 3 ) );
		$this->explorer_url       = untrailingslashit( $this->get_option( 'explorer_url' ) );
		$this->debug              = 'yes' === $this->get_option( 'debug' );
		$this->token_symbol       = $this->get_option( 'token_symbol' );
		$this->chain_name         = $this->get_option( 'chain_name' );
		$this->native_symbol      = $this->get_option( 'native_symbol' );
		$this->public_rpc_url     = $this->get_option( 'public_rpc_url' );

		// Lets WooCommerce link the transaction ID in the admin order screen.
		if ( $this->explorer_url ) {
			$this->view_transaction_url = $this->explorer_url . '/tx/%s';
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable/Disable', 'evm-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable EVM Token Payments', 'evm-payment-gateway' ),
				'default' => 'no',
			),
			'title'              => array(
				'title'       => __( 'Title', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'evm-payment-gateway' ),
				'default'     => __( 'EVM Token Payment', 'evm-payment-gateway' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __( 'Description', 'evm-payment-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on your checkout.', 'evm-payment-gateway' ),
				'default'     => __( 'Pay with ERC-20 tokens from your browser wallet (e.g. MetaMask).', 'evm-payment-gateway' ),
				'desc_tip'    => true,
			),
			'target_address'     => array(
				'title'       => __( 'Recipient Address', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'The wallet address that receives payments.', 'evm-payment-gateway' ),
				'placeholder' => '0x…',
				'desc_tip'    => true,
			),
			'contract_address'   => array(
				'title'       => __( 'Token Contract', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'The ERC-20 token contract address. The order total is charged 1:1 in this token.', 'evm-payment-gateway' ),
				'placeholder' => '0x…',
				'desc_tip'    => true,
			),
			'token_decimals'     => array(
				'title'             => __( 'Token Decimals', 'evm-payment-gateway' ),
				'type'              => 'number',
				'description'       => __( 'The number of decimal places for the token.', 'evm-payment-gateway' ),
				'default'           => '18',
				'custom_attributes' => array(
					'min' => 0,
					'max' => 36,
				),
				'desc_tip'          => true,
			),
			'token_symbol'       => array(
				'title'       => __( 'Token Symbol', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Optional. Shown next to the amount on the pay page, e.g. USDC.', 'evm-payment-gateway' ),
				'placeholder' => 'USDC',
				'desc_tip'    => true,
			),
			'blockchain_network' => array(
				'title'             => __( 'Chain ID', 'evm-payment-gateway' ),
				'type'              => 'number',
				'description'       => __( 'The chain ID of the network (e.g. 1 for Ethereum Mainnet, 137 for Polygon).', 'evm-payment-gateway' ),
				'custom_attributes' => array( 'min' => 1 ),
				'desc_tip'          => true,
			),
			'rpc_url'            => array(
				'title'       => __( 'RPC URL', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'JSON-RPC endpoint the server uses to verify transactions on this chain.', 'evm-payment-gateway' ),
				'placeholder' => 'https://',
				'desc_tip'    => true,
			),
			'confirmations'      => array(
				'title'             => __( 'Required Confirmations', 'evm-payment-gateway' ),
				'type'              => 'number',
				'description'       => __( 'Number of blocks (including the transaction block) before a payment is accepted.', 'evm-payment-gateway' ),
				'default'           => '3',
				'custom_attributes' => array(
					'min' => 1,
					'max' => 1000,
				),
				'desc_tip'          => true,
			),
			'explorer_url'       => array(
				'title'       => __( 'Block Explorer URL', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Optional. Used to link transactions in order notes, e.g. https://etherscan.io', 'evm-payment-gateway' ),
				'placeholder' => 'https://',
				'desc_tip'    => true,
			),
			'network_section'    => array(
				'title'       => __( 'Wallet network details', 'evm-payment-gateway' ),
				'type'        => 'title',
				'description' => __( 'Optional. When all three fields are set, the pay page can add the network to a wallet that does not know it yet (wallet_addEthereumChain). Customers see these values, so use a public RPC endpoint here, never one with an API key.', 'evm-payment-gateway' ),
			),
			'chain_name'         => array(
				'title'       => __( 'Network Name', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Name the wallet shows for the network, e.g. Polygon Mainnet.', 'evm-payment-gateway' ),
				'desc_tip'    => true,
			),
			'native_symbol'      => array(
				'title'       => __( 'Native Currency Symbol', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Symbol of the gas token, e.g. ETH, POL or BNB.', 'evm-payment-gateway' ),
				'desc_tip'    => true,
			),
			'public_rpc_url'     => array(
				'title'       => __( 'Public RPC URL', 'evm-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Public JSON-RPC endpoint handed to the wallet when it adds the network. This is visible to customers.', 'evm-payment-gateway' ),
				'placeholder' => 'https://',
				'desc_tip'    => true,
			),
			'debug'              => array(
				'title'       => __( 'Debug Log', 'evm-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'evm-payment-gateway' ),
				'default'     => 'no',
				/* translators: %s: WooCommerce > Status > Logs */
				'description' => sprintf( __( 'Log payment events to %s.', 'evm-payment-gateway' ), __( 'WooCommerce > Status > Logs', 'evm-payment-gateway' ) ),
			),
		);
	}

	/**
	 * Settings validation: invalid values are rejected and the previous value is kept.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_target_address_field( $key, $value ) {
		return $this->validate_address( $key, $value, __( 'Recipient Address', 'evm-payment-gateway' ) );
	}

	public function validate_contract_address_field( $key, $value ) {
		return $this->validate_address( $key, $value, __( 'Token Contract', 'evm-payment-gateway' ) );
	}

	public function validate_token_decimals_field( $key, $value ) {
		return $this->validate_int( $key, $value, 0, 36, __( 'Token Decimals', 'evm-payment-gateway' ) );
	}

	public function validate_blockchain_network_field( $key, $value ) {
		return $this->validate_int( $key, $value, 1, PHP_INT_MAX, __( 'Chain ID', 'evm-payment-gateway' ) );
	}

	public function validate_confirmations_field( $key, $value ) {
		return $this->validate_int( $key, $value, 1, 1000, __( 'Required Confirmations', 'evm-payment-gateway' ) );
	}

	public function validate_rpc_url_field( $key, $value ) {
		return $this->validate_url( $key, $value, __( 'RPC URL', 'evm-payment-gateway' ) );
	}

	public function validate_explorer_url_field( $key, $value ) {
		return $this->validate_url( $key, $value, __( 'Block Explorer URL', 'evm-payment-gateway' ) );
	}

	public function validate_public_rpc_url_field( $key, $value ) {
		return $this->validate_url( $key, $value, __( 'Public RPC URL', 'evm-payment-gateway' ) );
	}

	public function validate_token_symbol_field( $key, $value ) {
		return $this->validate_symbol( $key, $value, __( 'Token Symbol', 'evm-payment-gateway' ) );
	}

	public function validate_native_symbol_field( $key, $value ) {
		return $this->validate_symbol( $key, $value, __( 'Native Currency Symbol', 'evm-payment-gateway' ) );
	}

	public function validate_chain_name_field( $key, $value ) {
		return trim( sanitize_text_field( (string) $value ) );
	}

	private function validate_address( $key, $value, $label ) {
		$value = trim( (string) $value );
		if ( '' === $value || self::is_address( $value ) ) {
			return $value;
		}
		/* translators: %s: field label */
		\WC_Admin_Settings::add_error( sprintf( __( '%s must be a 0x-prefixed, 40 character hex address.', 'evm-payment-gateway' ), $label ) );
		return $this->get_option( $key );
	}

	private function validate_int( $key, $value, $min, $max, $label ) {
		$value = trim( (string) $value );
		if ( '' === $value || ( ctype_digit( $value ) && (int) $value >= $min && (int) $value <= $max ) ) {
			return $value;
		}
		/* translators: %s: field label */
		\WC_Admin_Settings::add_error( sprintf( __( '%s has an invalid value.', 'evm-payment-gateway' ), $label ) );
		return $this->get_option( $key );
	}

	private function validate_symbol( $key, $value, $label ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value || preg_match( '/^[A-Za-z0-9.$-]{1,11}$/', $value ) ) {
			return $value;
		}
		/* translators: %s: field label */
		\WC_Admin_Settings::add_error( sprintf( __( '%s must be 1 to 11 letters or digits.', 'evm-payment-gateway' ), $label ) );
		return $this->get_option( $key );
	}

	private function validate_url( $key, $value, $label ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( $url && wp_http_validate_url( $url ) ) {
			return $url;
		}
		/* translators: %s: field label */
		\WC_Admin_Settings::add_error( sprintf( __( '%s must be a valid http(s) URL.', 'evm-payment-gateway' ), $label ) );
		return $this->get_option( $key );
	}

	/**
	 * Only offer the gateway when it is fully configured.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && $this->is_configured();
	}

	/**
	 * @return bool
	 */
	public function is_configured() {
		return self::is_address( $this->target_address )
			&& self::is_address( $this->contract_address )
			&& $this->blockchain_network > 0
			&& ! empty( $this->rpc_url );
	}

	/**
	 * @param string $value Candidate address.
	 * @return bool
	 */
	private static function is_address( $value ) {
		return (bool) preg_match( '/^0x[0-9a-fA-F]{40}$/', (string) $value );
	}

	/**
	 * Logs through the WooCommerce logger. Debug/info messages only when debug logging is on.
	 *
	 * @param string $message Message.
	 * @param string $level   PSR-3 level.
	 */
	private function log( $message, $level = 'info' ) {
		if ( ! $this->debug && in_array( $level, array( 'debug', 'info', 'notice' ), true ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'evm-payment-gateway' ) );
	}

	/**
	 * Marks the order as awaiting payment and sends the customer to the order-pay page.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$order->update_status( 'pending', __( 'Awaiting token payment.', 'evm-payment-gateway' ) );
		$this->log( sprintf( 'Order %d created, awaiting token payment.', $order->get_id() ) );

		// Payment happens on the order-pay (receipt) page, where the wallet is connected.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Enqueues the wallet script on this gateway's order-pay page.
	 */
	public function payment_scripts() {
		if ( ! is_checkout_pay_page() ) {
			return;
		}

		$order = $this->get_payable_order_from_url();
		if ( ! $order || $order->get_transaction_id() ) {
			return;
		}

		wp_enqueue_style( 'evm-payment-styles', EVP_PLUGIN_URL . 'assets/css/styles.css', array(), EVP_VERSION );
		wp_register_script(
			'evm-payments',
			EVP_PLUGIN_URL . 'assets/js/payments.js',
			array(),
			EVP_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$units  = $this->get_order_amount_units( $order );
		$config = array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'evp_payment' ),
			'orderId'     => $order->get_id(),
			'orderKey'    => $order->get_order_key(),
			'chainId'     => '0x' . dechex( $this->blockchain_network ),
			'token'       => strtolower( $this->contract_address ),
			'recipient'   => strtolower( $this->target_address ),
			'amountHex'   => '0x' . Token_Amount::dec_to_hex( $units ),
			'amount'      => $this->format_token_amount( $units ),
			'network'     => $this->get_wallet_network(),
			'receivedUrl' => $order->get_checkout_order_received_url(),
			'i18n'        => array(
				'noWallet'       => __( 'No browser wallet found. Please install MetaMask or another EVM wallet.', 'evm-payment-gateway' ),
				'rejected'       => __( 'The request was rejected in your wallet.', 'evm-payment-gateway' ),
				'addNetwork'     => __( 'Please add the required network to your wallet and try again.', 'evm-payment-gateway' ),
				'addingNetwork'  => __( 'Please approve adding the network in your wallet.', 'evm-payment-gateway' ),
				'processing'     => __( 'Processing…', 'evm-payment-gateway' ),
				'confirmWallet'  => __( 'Please confirm the transaction in your wallet.', 'evm-payment-gateway' ),
				'waiting'        => __( 'Transaction sent. Waiting for blockchain confirmation…', 'evm-payment-gateway' ),
				'stillWaiting'   => __( 'Your transaction is still being confirmed. You can safely leave this page; the order will update automatically.', 'evm-payment-gateway' ),
				'payButton'      => __( 'Pay with wallet', 'evm-payment-gateway' ),
				'paidButton'     => __( 'Payment complete', 'evm-payment-gateway' ),
				'successTitle'   => __( 'Payment successful!', 'evm-payment-gateway' ),
				'successMessage' => __( 'Your payment has been confirmed on the blockchain.', 'evm-payment-gateway' ),
				'transactionId'  => __( 'Transaction ID:', 'evm-payment-gateway' ),
				'close'          => __( 'Close', 'evm-payment-gateway' ),
				'viewOrder'      => __( 'View order details', 'evm-payment-gateway' ),
				'genericError'   => __( 'Something went wrong. Please try again.', 'evm-payment-gateway' ),
			),
		);
		wp_add_inline_script( 'evm-payments', 'window.evpPayment = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_enqueue_script( 'evm-payments' );
	}

	/**
	 * Renders the payment UI on the order-pay page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() ) {
			echo '<p class="woocommerce-message">' . esc_html__( 'This order has already been paid.', 'evm-payment-gateway' ) . '</p>';
			return;
		}

		if ( $order->get_transaction_id() ) {
			$this->render_pending_notice( $order );
			return;
		}

		?>
		<div id="evm-payment-container" class="evm-payment-wrapper">
			<h2><?php esc_html_e( 'Complete your token payment', 'evm-payment-gateway' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: token amount with symbol, 2: order total in store currency */
					esc_html__( 'Amount due: %1$s (order total %2$s)', 'evm-payment-gateway' ),
					'<strong>' . esc_html( $this->format_token_amount( $this->get_order_amount_units( $order ) ) ) . '</strong>',
					wp_kses_post( $order->get_formatted_order_total() )
				);
				?>
			</p>
			<div id="evm-payment-notice" role="status" aria-live="polite"></div>
			<p id="evm-wallet-picker" hidden>
				<label for="evm-wallet-select"><?php esc_html_e( 'Wallet', 'evm-payment-gateway' ); ?></label>
				<select id="evm-wallet-select"></select>
			</p>
			<button type="button" class="button alt" id="evm-payment-button"><?php esc_html_e( 'Pay with wallet', 'evm-payment-gateway' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Shows the payment status on the order-received page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		if ( $this->is_awaiting_verification( $order ) ) {
			$this->render_pending_notice( $order );
			return;
		}

		if ( ! $order->needs_payment() ) {
			return;
		}

		printf(
			'<p class="woocommerce-info">%s <a class="button" href="%s">%s</a></p>',
			esc_html__( 'This order has not been paid yet.', 'evm-payment-gateway' ),
			esc_url( $order->get_checkout_payment_url( true ) ),
			esc_html__( 'Pay now', 'evm-payment-gateway' )
		);
	}

	/**
	 * @param \WC_Order $order Order awaiting confirmation.
	 */
	private function render_pending_notice( $order ) {
		$tx = $order->get_transaction_id();
		if ( $order->has_status( 'on-hold' ) ) {
			$message = __( 'Your transaction could not be confirmed automatically and is being verified by the store. You will be notified once it is confirmed.', 'evm-payment-gateway' );
		} else {
			$message = __( 'Your payment has been sent and is awaiting blockchain confirmation. This page will show the order as paid once it is confirmed.', 'evm-payment-gateway' );
		}
		echo '<p class="woocommerce-info">' . esc_html( $message );
		$url = $this->get_explorer_url( $tx );
		if ( $url ) {
			echo ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View transaction', 'evm-payment-gateway' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Binds the customer's wallet address to the order before the transfer is sent,
	 * so a transaction observed in the mempool can't be claimed for another order.
	 */
	public function ajax_prepare_payment() {
		$order = $this->get_order_from_request();

		$wallet = isset( $_POST['wallet'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['wallet'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in get_order_from_request().
		if ( ! self::is_address( $wallet ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid wallet address.', 'evm-payment-gateway' ) ), 400 );
		}

		$order->update_meta_data( self::META_PAYER, $wallet );
		$order->save();
		$this->log( sprintf( 'Order %d: payer wallet set to %s.', $order->get_id(), $wallet ) );

		wp_send_json_success();
	}

	/**
	 * Accepts a transaction hash for an order and verifies it on-chain.
	 */
	public function ajax_submit_transaction() {
		$order = $this->get_order_from_request();

		$tx = isset( $_POST['tx'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['tx'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in get_order_from_request().
		if ( ! preg_match( '/^0x[0-9a-f]{64}$/', $tx ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid transaction hash.', 'evm-payment-gateway' ) ), 400 );
		}

		if ( ! $order->get_meta( self::META_PAYER ) ) {
			wp_send_json_error( array( 'message' => __( 'Wallet not connected for this order.', 'evm-payment-gateway' ) ), 400 );
		}

		$existing = $order->get_transaction_id();
		if ( $existing && $existing !== $tx ) {
			wp_send_json_error( array( 'message' => __( 'A different transaction has already been submitted for this order.', 'evm-payment-gateway' ) ), 409 );
		}

		if ( ! $existing ) {
			if ( $this->is_transaction_used( $tx, $order->get_id() ) ) {
				wp_send_json_error( array( 'message' => __( 'This transaction has already been used for another order.', 'evm-payment-gateway' ) ), 409 );
			}

			$order->set_transaction_id( $tx );
			$order->update_meta_data( self::META_ATTEMPTS, 0 );
			$order->add_order_note(
				/* translators: %s: transaction hash */
				sprintf( __( 'Customer submitted transaction %s. Awaiting on-chain confirmation.', 'evm-payment-gateway' ), $this->format_transaction( $tx ) )
			);
			$order->save();
		}

		$status = $this->check_order_transaction( $order );

		if ( Transaction_Verifier::STATUS_FAILED === $status ) {
			wp_send_json_error( array( 'message' => __( 'The transaction could not be verified as a valid payment for this order. Please contact the store if you believe this is an error.', 'evm-payment-gateway' ) ), 422 );
		}

		wp_send_json_success(
			array(
				'status'   => $status,
				'redirect' => $order->get_checkout_order_received_url(),
			)
		);
	}

	/**
	 * Loads and authorises the order referenced by an AJAX request.
	 * Ends the request with an error response when the check fails.
	 *
	 * @return \WC_Order
	 */
	private function get_order_from_request() {
		if ( ! check_ajax_referer( 'evp_payment', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'evm-payment-gateway' ) ), 403 );
		}

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order     = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $order_key || ! hash_equals( $order->get_order_key(), $order_key ) || $this->id !== $order->get_payment_method() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'evm-payment-gateway' ) ), 403 );
		}

		if ( ! $order->needs_payment() ) {
			wp_send_json_error( array( 'message' => __( 'This order does not require payment.', 'evm-payment-gateway' ) ), 409 );
		}

		return $order;
	}

	/**
	 * Action Scheduler callback: re-checks an order's pending transaction.
	 *
	 * @param int $order_id Order ID.
	 */
	public function scheduled_check( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->is_awaiting_verification( $order ) ) {
			return;
		}

		$attempts = (int) $order->get_meta( self::META_ATTEMPTS ) + 1;
		$order->update_meta_data( self::META_ATTEMPTS, $attempts );
		$order->save();

		$status = $this->check_order_transaction( $order, $attempts < self::MAX_ATTEMPTS );

		if ( Transaction_Verifier::STATUS_PENDING === $status && $attempts >= self::MAX_ATTEMPTS ) {
			// Park the order where the merchant will see it instead of leaving it pending forever.
			$order->update_status(
				'on-hold',
				__( 'Transaction could not be confirmed automatically within an hour. Check it on the block explorer, then use the "Verify token payment on-chain" order action to re-check it.', 'evm-payment-gateway' )
			);
			$this->log( sprintf( 'Order %d: giving up after %d attempts, order put on hold.', $order->get_id(), $attempts ), 'warning' );
		}
	}

	/**
	 * Order action "Verify token payment on-chain": re-checks the transaction now and
	 * restarts the background re-checks if it is still pending.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function admin_verify_transaction( $order ) {
		if ( ! $order instanceof \WC_Order || ! $this->is_awaiting_verification( $order ) ) {
			return;
		}

		$order->update_meta_data( self::META_ATTEMPTS, 0 );
		$order->save();

		$status = $this->check_order_transaction( $order, true );

		if ( Transaction_Verifier::STATUS_PENDING === $status ) {
			$order->add_order_note( __( 'Manual check: transaction still unconfirmed. Background re-checks have been restarted; see the log for details.', 'evm-payment-gateway' ) );
		}
	}

	/**
	 * Whether the order has a submitted transaction that is not yet confirmed or rejected.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_awaiting_verification( $order ) {
		return $this->id === $order->get_payment_method()
			&& $order->get_transaction_id()
			&& $order->has_status( array( 'pending', 'on-hold', 'failed' ) );
	}

	/**
	 * Verifies the order's transaction and updates the order accordingly.
	 *
	 * @param \WC_Order $order      Order with a transaction ID.
	 * @param bool      $reschedule Whether to schedule a re-check while pending.
	 * @return string One of the Transaction_Verifier::STATUS_* constants.
	 */
	private function check_order_transaction( $order, $reschedule = true ) {
		$tx     = $order->get_transaction_id();
		$result = $this->get_verifier()->verify(
			$tx,
			$order->get_meta( self::META_PAYER ),
			Token_Amount::dec_to_hex( $this->get_order_amount_units( $order ) ),
			$order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0
		);

		$this->log( sprintf( 'Order %d, tx %s: %s (%s)', $order->get_id(), $tx, $result['status'], $result['message'] ) );

		switch ( $result['status'] ) {
			case Transaction_Verifier::STATUS_CONFIRMED:
				$order->payment_complete( $tx );
				$order->add_order_note(
					/* translators: %s: transaction hash */
					sprintf( __( 'Token payment confirmed on-chain: %s', 'evm-payment-gateway' ), $this->format_transaction( $tx ) )
				);
				break;

			case Transaction_Verifier::STATUS_FAILED:
				// Release the order so the customer can pay again with a valid transaction.
				$order->set_transaction_id( '' );
				$order->add_order_note(
					/* translators: 1: transaction hash, 2: reason */
					sprintf( __( 'Transaction %1$s rejected: %2$s', 'evm-payment-gateway' ), $this->format_transaction( $tx ), esc_html( $result['message'] ) )
				);
				if ( $order->has_status( 'on-hold' ) ) {
					$order->set_status( 'failed', __( 'Rejected after manual verification; the customer can pay again.', 'evm-payment-gateway' ) );
				}
				$order->save();
				$this->log( sprintf( 'Order %d: transaction %s rejected: %s', $order->get_id(), $tx, $result['message'] ), 'warning' );
				break;

			default:
				if ( $reschedule ) {
					$this->schedule_check( $order->get_id() );
				}
		}

		return $result['status'];
	}

	/**
	 * @param int $order_id Order ID.
	 */
	private function schedule_check( $order_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->log( sprintf( 'Order %d: Action Scheduler unavailable, background re-check not scheduled.', $order_id ), 'error' );
			return;
		}
		$args = array( 'order_id' => $order_id );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'evp_check_transaction', $args, 'evm-payment-gateway' ) ) {
			return;
		}
		as_schedule_single_action( time() + self::CHECK_INTERVAL, 'evp_check_transaction', $args, 'evm-payment-gateway' );
	}

	/**
	 * @param string $tx       Transaction hash.
	 * @param int    $order_id Order to exclude.
	 * @return bool
	 */
	private function is_transaction_used( $tx, $order_id ) {
		$ids = wc_get_orders(
			array(
				'transaction_id' => $tx,
				'return'         => 'ids',
				'limit'          => 2,
				'status'         => array_keys( wc_get_order_statuses() ),
			)
		);
		return (bool) array_diff( array_map( 'intval', $ids ), array( (int) $order_id ) );
	}

	/**
	 * @return Transaction_Verifier
	 */
	private function get_verifier() {
		return new Transaction_Verifier( $this->rpc_url, $this->blockchain_network, $this->contract_address, $this->target_address, $this->confirmations );
	}

	/**
	 * Order total in token base units (1:1 with the order currency).
	 *
	 * @param \WC_Order $order Order.
	 * @return string Decimal integer string.
	 */
	private function get_order_amount_units( $order ) {
		$amount = apply_filters( 'evm_payment_token_amount', wc_format_decimal( $order->get_total(), wc_get_price_decimals() ), $order );
		return Token_Amount::to_base_units( wc_format_decimal( $amount ), $this->token_decimals );
	}

	/**
	 * Token amount for display, e.g. "12.5 USDC".
	 *
	 * @param string $units Amount in base units (decimal integer string).
	 * @return string
	 */
	private function format_token_amount( $units ) {
		$symbol = '' !== $this->token_symbol ? $this->token_symbol : __( 'tokens', 'evm-payment-gateway' );
		return Token_Amount::from_base_units( $units, $this->token_decimals ) . ' ' . $symbol;
	}

	/**
	 * Network details for wallet_addEthereumChain, or null when not fully configured.
	 *
	 * @return array|null
	 */
	private function get_wallet_network() {
		if ( '' === $this->chain_name || '' === $this->native_symbol || '' === $this->public_rpc_url ) {
			return null;
		}
		return array(
			'chainName'    => $this->chain_name,
			'nativeSymbol' => $this->native_symbol,
			'rpcUrl'       => $this->public_rpc_url,
			'explorerUrl'  => $this->explorer_url ? $this->explorer_url : '',
		);
	}

	/**
	 * Returns the order being paid on the current order-pay page, if valid for this gateway.
	 *
	 * @return \WC_Order|null
	 */
	private function get_payable_order_from_url() {
		global $wp;

		$order_id  = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the capability.
		$order     = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return null;
		}
		if ( $this->id !== $order->get_payment_method() || ! $order->needs_payment() ) {
			return null;
		}

		return $order;
	}

	/**
	 * @param string $tx Transaction hash.
	 * @return string Explorer URL, or empty when no explorer is configured.
	 */
	private function get_explorer_url( $tx ) {
		return $this->explorer_url && $tx ? $this->explorer_url . '/tx/' . rawurlencode( $tx ) : '';
	}

	/**
	 * Transaction hash for order notes, linked to the explorer when configured.
	 *
	 * @param string $tx Transaction hash.
	 * @return string
	 */
	private function format_transaction( $tx ) {
		$url = $this->get_explorer_url( $tx );
		return $url ? sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html( $tx ) ) : esc_html( $tx );
	}
}
