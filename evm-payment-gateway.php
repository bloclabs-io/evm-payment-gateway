<?php
/**
 * Plugin Name: EVM Payment Gateway
 * Plugin URI: https://github.com/stcchain/evm-payment-gateway
 * Description: A secure WordPress plugin for EVM-compatible token payments through WooCommerce
 * Version: 1.1.0
 * Author: stcchain
 * Author URI: https://github.com/stcchain
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: evm-payment-gateway
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 *
 * @package EVM_Payment_Gateway
 */

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('EVP_PLUGIN_FILE', __FILE__);
define('EVP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EVP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EVP_VERSION', '1.1.0');

// Ensure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'EVP\\';
    $base_dir = EVP_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
function evp_init() {
    load_plugin_textdomain('evm-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Add payment gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'EVP\\Gateway\\Payment_Gateway';
        return $gateways;
    });
}
add_action('plugins_loaded', 'evp_init');

// Register AJAX handlers
function evp_register_ajax_handlers() {
    add_action('wp_ajax_verify_payment', 'evp_verify_payment_ajax');
    add_action('wp_ajax_nopriv_verify_payment', 'evp_verify_payment_ajax');
}
add_action('init', 'evp_register_ajax_handlers');

// AJAX handler for payment verification
function evp_verify_payment_ajax() {
    // Verify nonce for security
    check_ajax_referer('evm_payment_nonce', 'nonce');

    try {
        // Sanitize and validate input
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $tx_hash = isset($_POST['tx']) ? sanitize_text_field(wp_unslash($_POST['tx'])) : '';

        // Validate required data
        if (!$order_id || !$tx_hash) {
            wp_send_json_error([
                'message' => __('Missing required data', 'evm-payment-gateway')
            ]);
        }

        // Validate transaction hash format (0x + 64 hex characters)
        if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $tx_hash)) {
            wp_send_json_error([
                'message' => __('Invalid transaction hash format', 'evm-payment-gateway')
            ]);
        }

        // Get and validate order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error([
                'message' => __('Invalid order', 'evm-payment-gateway')
            ]);
        }

        // Check if order needs payment
        if (!$order->needs_payment()) {
            wp_send_json_error([
                'message' => __('Order already paid', 'evm-payment-gateway')
            ]);
        }

        // Verify the order uses the EVM payment gateway
        if ($order->get_payment_method() !== 'evm_payment') {
            wp_send_json_error([
                'message' => __('Invalid payment method', 'evm-payment-gateway')
            ]);
        }

        // Mark the order as complete
        $order->payment_complete($tx_hash);
        $order->add_order_note(
            sprintf(
                /* translators: %s: transaction hash */
                __('EVM Payment completed - Transaction Hash: %s', 'evm-payment-gateway'),
                esc_html($tx_hash)
            )
        );

        // Log the transaction hash as order meta
        $order->update_meta_data('_evm_transaction_hash', $tx_hash);
        $order->update_meta_data('_evm_payment_timestamp', current_time('mysql'));
        $order->save();

        // Return success response
        wp_send_json_success([
            'message' => __('Payment verified successfully', 'evm-payment-gateway'),
            'redirect' => $order->get_checkout_order_received_url()
        ]);

    } catch (Exception $e) {
        // Log error for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EVM Payment Gateway Error: ' . $e->getMessage());
        }

        wp_send_json_error([
            'message' => __('Payment verification failed. Please contact support.', 'evm-payment-gateway')
        ]);
    }
}

// Activation hook
register_activation_hook(__FILE__, function() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('This plugin requires WooCommerce to be installed and active.', 'evm-payment-gateway'),
            'Plugin dependency check',
            array('back_link' => true)
        );
    }
    
    // Create necessary database tables or options
    do_action('evp_activate');
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    do_action('evp_deactivate');
});

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=evm_payment">' . 
        __('Settings', 'evm-payment-gateway') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
