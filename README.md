# EVM Payment Gateway for WooCommerce

A WordPress plugin that lets WooCommerce stores accept ERC-20 token payments on any EVM-compatible network, with server-side on-chain verification.

## Features

- Any EVM-compatible chain (Ethereum, Polygon, BNB Chain, private/public Besu, …)
- Block-based Cart & Checkout and classic checkout
- HPOS (High-Performance Order Storage) compatible
- EIP-6963 wallet discovery (MetaMask, Rabby, Coinbase Wallet, …) and automatic network switching
- On-chain verification: receipt status, token contract, sender, recipient, amount, block time and confirmations
- Background re-checks of unconfirmed transactions via Action Scheduler
- Logging through the WooCommerce logger (WooCommerce > Status > Logs)

## Requirements

- WordPress 6.5 or higher
- WooCommerce 8.3 or higher
- PHP 7.4 or higher (8.1+ recommended)
- A JSON-RPC endpoint for the chain you accept payments on

## Installation

1. Upload the plugin to `wp-content/plugins/evm-payment-gateway`
2. Activate it (WooCommerce must be active)
3. Configure it under WooCommerce > Settings > Payments > EVM Token Payment

## Configuration

| Setting | Description |
| --- | --- |
| Recipient Address | Wallet that receives payments |
| Token Contract | ERC-20 contract address |
| Token Decimals | Token precision (usually 18, 6 for USDC/USDT) |
| Chain ID | e.g. `1` Ethereum, `137` Polygon, `56` BNB Chain |
| RPC URL | Endpoint the server uses to verify transactions |
| Required Confirmations | Blocks before a payment is accepted |
| Block Explorer URL | Optional, e.g. `https://etherscan.io`, for transaction links |
| Debug Log | Writes details to the WooCommerce log |

The gateway is only offered at checkout once the addresses, chain ID and RPC URL are set.

## Payment flow

1. The customer places the order with "EVM Token Payment" and is sent to the order-pay page.
2. They connect a wallet; the plugin switches it to the configured chain and records the paying address on the order.
3. The wallet sends `transfer(recipient, amount)` to the token contract.
4. The transaction hash is submitted to the server, which verifies it over JSON-RPC.
5. Once confirmed, the order is marked as paid. Unconfirmed transactions are re-checked every minute for up to an hour.

The order total is charged 1:1 in the token. Use the `evm_payment_token_amount` filter to apply an exchange rate:

```php
add_filter( 'evm_payment_token_amount', function ( $amount, $order ) {
    return (string) round( (float) $amount * 1.0, 6 ); // Apply your rate here.
}, 10, 2 );
```

## Development

```bash
composer install
composer test    # PHPUnit
composer phpcs   # WordPress Coding Standards + PHP compatibility
```
