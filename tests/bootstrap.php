<?php
/**
 * PHPUnit bootstrap for unit tests that don't need WordPress.
 *
 * @package EVM_Payment_Gateway
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Minimal stand-in for the WordPress function.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
