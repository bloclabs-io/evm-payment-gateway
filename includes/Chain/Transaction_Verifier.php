<?php
/**
 * Verifies ERC-20 transfers on an EVM chain through a JSON-RPC endpoint.
 *
 * @package EVM_Payment_Gateway
 */

namespace EVP\Chain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Transaction_Verifier {

	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_PENDING   = 'pending';
	const STATUS_FAILED    = 'failed';

	/** Keccak-256 of Transfer(address,address,uint256). */
	const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

	/** Allowed clock skew between the shop and the chain when comparing timestamps. */
	const TIMESTAMP_TOLERANCE = 300;

	/** @var string */
	private $rpc_url;

	/** @var int */
	private $chain_id;

	/** @var string */
	private $token;

	/** @var string */
	private $recipient;

	/** @var int */
	private $min_confirmations;

	/**
	 * @param string $rpc_url           JSON-RPC endpoint.
	 * @param int    $chain_id          Expected chain ID.
	 * @param string $token             ERC-20 contract address.
	 * @param string $recipient         Merchant address.
	 * @param int    $min_confirmations Required confirmations.
	 */
	public function __construct( $rpc_url, $chain_id, $token, $recipient, $min_confirmations ) {
		$this->rpc_url           = $rpc_url;
		$this->chain_id          = (int) $chain_id;
		$this->token             = strtolower( $token );
		$this->recipient         = strtolower( $recipient );
		$this->min_confirmations = max( 1, (int) $min_confirmations );
	}

	/**
	 * Checks that $tx_hash transferred at least $min_amount_hex tokens from
	 * $payer to the merchant, in a block mined no earlier than $not_before.
	 *
	 * Transient RPC problems are reported as pending so the check is retried.
	 *
	 * @param string $tx_hash        Transaction hash.
	 * @param string $payer          Expected sender address.
	 * @param string $min_amount_hex Minimum amount in base units (hex).
	 * @param int    $not_before     Unix timestamp the transfer must not predate.
	 * @return array{status: string, message: string}
	 */
	public function verify( $tx_hash, $payer, $min_amount_hex, $not_before ) {
		try {
			$chain_id = (int) hexdec( (string) $this->rpc( 'eth_chainId', array() ) );
			if ( $chain_id !== $this->chain_id ) {
				return $this->result( self::STATUS_PENDING, sprintf( 'RPC endpoint is on chain %d, expected %d.', $chain_id, $this->chain_id ) );
			}

			$receipt = $this->rpc( 'eth_getTransactionReceipt', array( $tx_hash ) );
			if ( empty( $receipt ) || ! is_array( $receipt ) ) {
				return $this->result( self::STATUS_PENDING, 'Transaction not mined yet.' );
			}

			if ( ! isset( $receipt['status'] ) || '0x1' !== strtolower( $receipt['status'] ) ) {
				return $this->result( self::STATUS_FAILED, 'Transaction reverted.' );
			}

			if ( ! $this->has_matching_transfer( $receipt, strtolower( $payer ), $min_amount_hex ) ) {
				return $this->result( self::STATUS_FAILED, 'Transaction does not contain a matching token transfer to the merchant.' );
			}

			$block = $this->rpc( 'eth_getBlockByNumber', array( $receipt['blockNumber'], false ) );
			if ( ! is_array( $block ) || empty( $block['timestamp'] ) ) {
				return $this->result( self::STATUS_PENDING, 'Block not available yet.' );
			}
			if ( hexdec( $block['timestamp'] ) + self::TIMESTAMP_TOLERANCE < $not_before ) {
				return $this->result( self::STATUS_FAILED, 'Transaction predates the order.' );
			}

			$confirmations = (int) hexdec( (string) $this->rpc( 'eth_blockNumber', array() ) ) - (int) hexdec( $receipt['blockNumber'] ) + 1;
			if ( $confirmations < $this->min_confirmations ) {
				return $this->result( self::STATUS_PENDING, sprintf( 'Waiting for confirmations (%d/%d).', max( 0, $confirmations ), $this->min_confirmations ) );
			}

			return $this->result( self::STATUS_CONFIRMED, sprintf( 'Confirmed with %d confirmations.', $confirmations ) );
		} catch ( \RuntimeException $e ) {
			return $this->result( self::STATUS_PENDING, 'RPC error: ' . $e->getMessage() );
		}
	}

	/**
	 * Looks for a Transfer(payer -> recipient, value >= min) log emitted by the token contract.
	 *
	 * @param array  $receipt        Transaction receipt.
	 * @param string $payer          Lowercase sender address.
	 * @param string $min_amount_hex Minimum amount (hex).
	 * @return bool
	 */
	private function has_matching_transfer( array $receipt, $payer, $min_amount_hex ) {
		if ( empty( $receipt['logs'] ) || ! is_array( $receipt['logs'] ) ) {
			return false;
		}

		foreach ( $receipt['logs'] as $log ) {
			if ( empty( $log['address'] ) || strtolower( $log['address'] ) !== $this->token ) {
				continue;
			}
			$topics = isset( $log['topics'] ) ? array_map( 'strtolower', (array) $log['topics'] ) : array();
			if ( count( $topics ) !== 3 || self::TRANSFER_TOPIC !== $topics[0] ) {
				continue;
			}
			if ( self::topic_to_address( $topics[1] ) !== $payer || self::topic_to_address( $topics[2] ) !== $this->recipient ) {
				continue;
			}
			if ( Token_Amount::compare_hex( isset( $log['data'] ) ? $log['data'] : '0x0', $min_amount_hex ) >= 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $topic 32-byte indexed address topic.
	 * @return string
	 */
	private static function topic_to_address( $topic ) {
		return '0x' . substr( $topic, -40 );
	}

	/**
	 * Performs a JSON-RPC call.
	 *
	 * @param string $method RPC method.
	 * @param array  $params RPC params.
	 * @return mixed
	 * @throws \RuntimeException On transport or RPC errors.
	 */
	private function rpc( $method, array $params ) {
		$response = wp_remote_post(
			$this->rpc_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => $method,
						'params'  => $params,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			throw new \RuntimeException( esc_html( sprintf( '%s returned HTTP %d.', $method, $code ) ) );
		}
		if ( isset( $body['error'] ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'unknown error';
			throw new \RuntimeException( esc_html( $method . ': ' . $message ) );
		}

		return isset( $body['result'] ) ? $body['result'] : null;
	}

	/**
	 * @param string $status  Status constant.
	 * @param string $message Human-readable detail (for logs/order notes).
	 * @return array{status: string, message: string}
	 */
	private function result( $status, $message ) {
		return array(
			'status'  => $status,
			'message' => $message,
		);
	}
}
