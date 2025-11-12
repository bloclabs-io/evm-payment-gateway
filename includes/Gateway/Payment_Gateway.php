<?php
namespace EVP\Gateway;

if (!defined('ABSPATH')) {
   exit;
}

class Payment_Gateway extends \WC_Payment_Gateway {
   /**
    * Logger instance.
    *
    * @var WC_Logger
    */
   private $logger;

   /**
    * Log a message if WP_DEBUG is enabled.
    *
    * @param string $message The message to log.
    */
   private function log($message) {
       if (defined('WP_DEBUG') && WP_DEBUG) {
           if (!$this->logger) {
               $this->logger = wc_get_logger();
           }
           $this->logger->info($message, array('source' => 'evm-payment-gateway'));
       }
   }

   public $target_address;
   public $contract_address;
   public $token_decimals;
   public $blockchain_network;
   public $abi_array;
   public $token_name;
   public $token_symbol;

   public function __construct() {
       $this->id                 = 'evm_payment';
       $this->icon              = apply_filters('evm_payment_icon', '');
       $this->has_fields        = false;
       $this->method_title      = __('EVM Token Payment', 'evm-payment-gateway');
       $this->method_description = __('Accept EVM-compatible token payments through MetaMask', 'evm-payment-gateway');

       $this->init_form_fields();
       $this->init_settings();

       $this->title             = $this->get_option('title');
       $this->description       = $this->get_option('description');
       $this->target_address    = $this->get_option('target_address');
       $this->contract_address  = $this->get_option('contract_address');
       $this->token_decimals    = $this->get_option('token_decimals', 18);
       $this->blockchain_network = $this->get_option('blockchain_network');
       $this->abi_array         = $this->get_option('abi_array');
       $this->token_name        = $this->get_option('token_name', 'Token');
       $this->token_symbol      = $this->get_option('token_symbol', 'TKN');

       add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
       add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
       add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

       $this->log('Payment gateway initialized');
   }

   public function init_form_fields() {
       $this->form_fields = array(
           'enabled' => array(
               'title'   => __('Enable/Disable', 'evm-payment-gateway'),
               'type'    => 'checkbox',
               'label'   => __('Enable EVM Token Payments', 'evm-payment-gateway'),
               'default' => 'no'
           ),
           'title' => array(
               'title'       => __('Title', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('This controls the title which the user sees during checkout.', 'evm-payment-gateway'),
               'default'     => __('EVM Token Payment', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'description' => array(
               'title'       => __('Description', 'evm-payment-gateway'),
               'type'        => 'textarea',
               'description' => __('Payment method description that the customer will see on your checkout.', 'evm-payment-gateway'),
               'default'     => __('Pay with your EVM-compatible wallet via MetaMask.', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'target_address' => array(
               'title'       => __('Recipient Address', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('Your EVM wallet address', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'contract_address' => array(
               'title'       => __('Token Contract', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('Token contract address', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'token_decimals' => array(
               'title'       => __('Token Decimals', 'evm-payment-gateway'),
               'type'        => 'number',
               'description' => __('The number of decimal places for the token.', 'evm-payment-gateway'),
               'default'     => '18',
               'desc_tip'    => true,
           ),
           'token_name' => array(
               'title'       => __('Token Name', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('The full name of the token (e.g., "Ethereum", "USD Coin").', 'evm-payment-gateway'),
               'default'     => 'Token',
               'desc_tip'    => true,
           ),
           'token_symbol' => array(
               'title'       => __('Token Symbol', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('The token symbol/abbreviation (e.g., "ETH", "USDC").', 'evm-payment-gateway'),
               'default'     => 'TKN',
               'desc_tip'    => true,
           ),
           'blockchain_network' => array(
               'title'       => __('Network ID', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('The blockchain network ID (e.g., 1 for Ethereum Mainnet)', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'abi_array' => array(
               'title'       => __('Contract ABI', 'evm-payment-gateway'),
               'type'        => 'textarea',
               'description' => __('The ABI of the token contract', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
       );
   }

   public function payment_scripts() {
       // Only load scripts on checkout and order received pages
       if (!is_checkout_pay_page() && !is_wc_endpoint_url('order-received')) {
           return;
       }

       // Enqueue Web3.js library
       wp_enqueue_script(
           'web3',
           'https://cdn.jsdelivr.net/npm/web3@1.10.3/dist/web3.min.js',
           array('jquery'),
           '1.10.3',
           true
       );

       // Register and enqueue our payment script
       wp_register_script(
           'evm-payments',
           plugins_url('/assets/js/payments.js', dirname(dirname(__FILE__))),
           array('jquery', 'web3'),
           EVP_VERSION,
           true
       );
       wp_localize_script('evm-payments', 'evmPaymentData', array(
           'ajaxUrl' => admin_url('admin-ajax.php'),
           'nonce' => wp_create_nonce('evm_payment_nonce'),
           'networkId' => $this->blockchain_network,
           'contractAddress' => $this->contract_address,
           'targetAddress' => $this->target_address,
           'tokenDecimals' => $this->token_decimals,
           'tokenName' => $this->token_name,
           'tokenSymbol' => $this->token_symbol,
           'abiArray' => json_decode($this->abi_array)
       ));
       wp_enqueue_script('evm-payments');

       // Enqueue payment styles
       wp_enqueue_style(
           'evm-payment-styles',
           plugins_url('/assets/css/styles.css', dirname(dirname(__FILE__))),
           array(),
           EVP_VERSION
       );
   }

   public function process_payment($order_id) {
       $this->log('Processing payment for order: ' . $order_id);
       
       $order = wc_get_order($order_id);
       $order->update_status('pending', __('Awaiting token payment', 'evm-payment-gateway'));
       
       return array(
           'result' => 'success',
           'redirect' => $this->get_return_url($order)
       );
   }

   public function thankyou_page($order_id) {
       if (!$order_id) {
           return;
       }

       $order = wc_get_order($order_id);
       if (!$order || !$order->needs_payment()) {
           return;
       }

       echo '<div id="evm-payment-container" class="evm-payment-wrapper">';
       echo '<h2>' . esc_html__('Complete Your Token Payment', 'evm-payment-gateway') . '</h2>';
       echo '<div class="evm-payment-info">';
       echo '<p><strong>' . esc_html__('Payment Amount:', 'evm-payment-gateway') . '</strong> ' .
            esc_html($order->get_total()) . ' ' . esc_html($this->token_symbol) . '</p>';
       echo '<p><strong>' . esc_html__('Token:', 'evm-payment-gateway') . '</strong> ' .
            esc_html($this->token_name) . ' (' . esc_html($this->token_symbol) . ')</p>';
       echo '</div>';
       echo '<div id="evm-payment-error" class="woocommerce-error" style="display:none;"></div>';

       wp_localize_script('evm-payments', 'evmPaymentConfig', array(
           'orderId' => $order_id,
           'amount' => $order->get_total()
       ));

       echo '<button type="button" class="button alt" id="evm-payment-button">' .
           esc_html__('Pay with MetaMask', 'evm-payment-gateway') .
       '</button>';
       echo '</div>';
   }
}
