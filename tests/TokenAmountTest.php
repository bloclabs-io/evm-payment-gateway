<?php
/**
 * @package EVM_Payment_Gateway
 */

use EVP\Chain\Token_Amount;
use PHPUnit\Framework\TestCase;

class TokenAmountTest extends TestCase {

	/**
	 * @dataProvider base_unit_cases
	 */
	public function test_to_base_units( $amount, $decimals, $expected ) {
		$this->assertSame( $expected, Token_Amount::to_base_units( $amount, $decimals ) );
	}

	public function base_unit_cases() {
		return array(
			'whole, 18 decimals'      => array( '12', 18, '12000000000000000000' ),
			'fraction, 18 decimals'   => array( '12.50', 18, '12500000000000000000' ),
			'6 decimals'              => array( '0.01', 6, '10000' ),
			'0 decimals'              => array( '7', 0, '7' ),
			'rounds up excess digits' => array( '1.005', 2, '101' ),
			'drops zero excess'       => array( '1.500', 2, '150' ),
			'carry on round up'       => array( '0.999', 2, '100' ),
			'zero'                    => array( '0.00', 18, '0' ),
			'leading dot'             => array( '.5', 1, '5' ),
		);
	}

	public function test_to_base_units_rejects_invalid_input() {
		$this->expectException( InvalidArgumentException::class );
		Token_Amount::to_base_units( '1e5', 18 );
	}

	/**
	 * @dataProvider hex_cases
	 */
	public function test_dec_to_hex( $dec, $hex ) {
		$this->assertSame( $hex, Token_Amount::dec_to_hex( $dec ) );
	}

	public function hex_cases() {
		return array(
			array( '0', '0' ),
			array( '15', 'f' ),
			array( '255', 'ff' ),
			array( '12500000000000000000', 'ad78ebc5ac620000' ),
			// 2^256 - 1.
			array( '115792089237316195423570985008687907853269984665640564039457584007913129639935', str_repeat( 'f', 64 ) ),
		);
	}

	public function test_compare_hex() {
		$this->assertSame( 0, Token_Amount::compare_hex( '0x00ff', 'FF' ) );
		$this->assertSame( 1, Token_Amount::compare_hex( '0x0100', '0xff' ) );
		$this->assertSame( -1, Token_Amount::compare_hex( '0x' . str_repeat( '0', 63 ) . '1', '0x2' ) );
		$this->assertSame( 0, Token_Amount::compare_hex( '0x', '0x0' ) );
	}
}
