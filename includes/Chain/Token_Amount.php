<?php
/**
 * Arbitrary-precision helpers for token amounts.
 *
 * Token amounts routinely exceed PHP_INT_MAX (18 decimals), so everything is
 * handled as digit strings without depending on bcmath or gmp.
 *
 * @package EVM_Payment_Gateway
 */

namespace EVP\Chain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Token_Amount {

	/**
	 * Converts a decimal amount (e.g. "12.50") to the token's base units as a
	 * decimal integer string (e.g. "12500000000000000000" for 18 decimals).
	 *
	 * Fractional digits beyond the token's precision are rounded up so the
	 * merchant is never under-paid.
	 *
	 * @param string $amount   Non-negative decimal amount.
	 * @param int    $decimals Token decimals.
	 * @return string
	 * @throws \InvalidArgumentException When the amount is not a plain decimal.
	 */
	public static function to_base_units( $amount, $decimals ) {
		$amount = trim( (string) $amount );
		if ( ! preg_match( '/^(\d*)(?:\.(\d*))?$/', $amount, $m ) || '' === $m[1] . ( isset( $m[2] ) ? $m[2] : '' ) ) {
			throw new \InvalidArgumentException( 'Invalid amount: ' . $amount );
		}

		$whole    = '' === $m[1] ? '0' : $m[1];
		$fraction = isset( $m[2] ) ? $m[2] : '';
		$round_up = false;

		if ( strlen( $fraction ) > $decimals ) {
			$round_up = '' !== trim( substr( $fraction, $decimals ), '0' );
			$fraction = substr( $fraction, 0, $decimals );
		}

		$units = self::strip_zeros( $whole . str_pad( $fraction, $decimals, '0' ) );

		return $round_up ? self::increment( $units ) : $units;
	}

	/**
	 * Converts a decimal integer string to lowercase hex (no 0x prefix).
	 *
	 * @param string $dec Decimal integer string.
	 * @return string
	 */
	public static function dec_to_hex( $dec ) {
		$dec = self::strip_zeros( $dec );
		$hex = '';

		while ( '0' !== $dec ) {
			$quotient  = '';
			$remainder = 0;
			$len       = strlen( $dec );
			for ( $i = 0; $i < $len; $i++ ) {
				$remainder = $remainder * 10 + (int) $dec[ $i ];
				$quotient .= (string) intdiv( $remainder, 16 );
				$remainder = $remainder % 16;
			}
			$hex = dechex( $remainder ) . $hex;
			$dec = self::strip_zeros( $quotient );
		}

		return '' === $hex ? '0' : $hex;
	}

	/**
	 * Compares two hex integers (with or without 0x prefix).
	 *
	 * @param string $a First value.
	 * @param string $b Second value.
	 * @return int -1, 0 or 1.
	 */
	public static function compare_hex( $a, $b ) {
		$a = self::normalize_hex( $a );
		$b = self::normalize_hex( $b );

		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) < strlen( $b ) ? -1 : 1;
		}

		return max( -1, min( 1, strcmp( $a, $b ) ) );
	}

	/**
	 * Lowercases a hex value, removes the 0x prefix and leading zeros.
	 *
	 * @param string $hex Hex value.
	 * @return string
	 */
	public static function normalize_hex( $hex ) {
		$hex = strtolower( (string) $hex );
		if ( 0 === strpos( $hex, '0x' ) ) {
			$hex = substr( $hex, 2 );
		}
		$hex = ltrim( $hex, '0' );
		return '' === $hex ? '0' : $hex;
	}

	/**
	 * Removes leading zeros from a decimal integer string.
	 *
	 * @param string $dec Decimal integer string.
	 * @return string
	 */
	private static function strip_zeros( $dec ) {
		$dec = ltrim( (string) $dec, '0' );
		return '' === $dec ? '0' : $dec;
	}

	/**
	 * Adds one to a decimal integer string.
	 *
	 * @param string $dec Decimal integer string.
	 * @return string
	 */
	private static function increment( $dec ) {
		$i = strlen( $dec ) - 1;
		while ( $i >= 0 && '9' === $dec[ $i ] ) {
			$dec[ $i ] = '0';
			--$i;
		}
		return $i < 0 ? '1' . $dec : substr_replace( $dec, (string) ( (int) $dec[ $i ] + 1 ), $i, 1 );
	}
}
