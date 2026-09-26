<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package EVM_Payment_Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_evm_payment_settings' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'evp_check_transaction' );
}
