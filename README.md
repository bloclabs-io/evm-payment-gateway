# EVM Payment Gateway for WooCommerce

A WordPress plugin that lets WooCommerce stores accept ERC-20 token payments on any EVM-compatible network, with server-side on-chain verification.

> **Status:** version 1.2.0 passes its unit tests and coding-standards checks and targets WordPress 7.1 / WooCommerce 11.1, but has not yet been run on a live store. Test it on a staging site and a testnet (see [First-time testing](#first-time-testing)) before accepting real payments.

## Features

- Any EVM-compatible chain (Ethereum, Polygon, BNB Chain, private/public Besu, …)
- Block-based Cart & Checkout and classic checkout
- HPOS (High-Performance Order Storage) compatible
- EIP-6963 wallet discovery (MetaMask, Rabby, Coinbase Wallet, …) and automatic network switching
- On-chain verification: receipt status, token contract, sender, recipient, amount, block time and confirmations
- Background re-checks of unconfirmed transactions via Action Scheduler, with a manual "Verify token payment on-chain" order action
- Can add a missing network to the customer's wallet (`wallet_addEthereumChain`)
- Logging through the WooCommerce logger (WooCommerce > Status > Logs)

## Requirements

- WordPress 6.5 or higher (tested up to 7.1)
- WooCommerce 8.3 or higher (tested up to 11.1)
- PHP 7.4 or higher (8.1+ recommended)
- A JSON-RPC endpoint for the chain you accept payments on
- HTTPS on the store: browser wallets only inject into secure pages

## Installation

The plugin has no runtime dependencies, so `composer install` is only needed for development.

1. Download the repository as a ZIP (**Code > Download ZIP** on GitHub).
2. Unzip it and rename the folder from `evm-payment-gateway-main` to `evm-payment-gateway`, then zip that folder again. WordPress uses the folder name as the plugin's identity, so keep this name for future updates.
3. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, upload the ZIP and activate it. WooCommerce must be installed and active first.
4. Configure it under **WooCommerce > Settings > Payments > EVM Token Payment** (see below).

Alternatively, clone the repository directly into `wp-content/plugins/evm-payment-gateway`.

Files only used for development (`tests/`, `.github/`, `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`) are harmless on a live site but can be left out of the ZIP.

## Configuration

| Setting | Description |
| --- | --- |
| Recipient Address | Wallet that receives payments |
| Token Contract | ERC-20 contract address |
| Token Decimals | Token precision (usually 18, 6 for USDC/USDT) |
| Token Symbol | Optional, shown next to the amount due on the pay page (e.g. `USDC`) |
| Chain ID | e.g. `1` Ethereum, `137` Polygon, `56` BNB Chain |
| RPC URL | Endpoint the server uses to verify transactions |
| Required Confirmations | Blocks before a payment is accepted |
| Block Explorer URL | Optional, e.g. `https://etherscan.io`, for transaction links |
| Network Name, Native Currency Symbol, Public RPC URL | Optional. When all three are set, a wallet that does not know the chain is asked to add it. Customers can see these values, so use a public RPC endpoint here, never one with an API key. |
| Debug Log | Writes details to the WooCommerce log |

The gateway is only offered at checkout once the addresses, chain ID and RPC URL are set.

Use an RPC endpoint you trust (your own node or a provider such as Infura or Alchemy): the server relies on it to decide whether an order has been paid. While a payment confirms, each check makes up to three RPC calls (receipt, block, latest block number); the chain ID check is cached for ten minutes.

## First-time testing

Before accepting real payments, run through this on a staging site:

1. Deploy a test ERC-20 token on a testnet such as Sepolia (or use one you already hold), and set the gateway to that chain ID, token contract, decimals and an RPC URL for the testnet.
2. Enable **Debug Log**.
3. Place an order with the **block-based Checkout** and pay from a wallet. Check that the order moves to *Processing* and gets a "Token payment confirmed on-chain" note.
4. Repeat with the **classic checkout** (`[woocommerce_checkout]` shortcode), if your store uses it.
5. Check the failure cases:
   - reject the transaction in the wallet: the button should re-enable with an error;
   - start with the wallet on the wrong network: it should ask to switch.
6. Review **WooCommerce > Status > Logs** (source `evm-payment-gateway`), then turn Debug Log off and switch the settings to your production chain.

## Handling payments that need attention

- **Unconfirmed after an hour.** Background re-checks run every minute for an hour. If the transaction is still unconfirmed, the order is moved to *On hold* with a note. Look the transaction up on the block explorer, then use the **Verify token payment on-chain** action in the order's *Order actions* box: it re-checks immediately and restarts the background checks. A confirmed transaction marks the order paid; a rejected one moves the order to *Failed* so the customer can pay again from *My Account*.
- **Paid from a different wallet.** The wallet address connected on the pay page is bound to the order before the transfer is sent, so a transfer seen in the mempool can't be claimed by another order. If the customer switches accounts in the wallet between connecting and confirming, the tokens arrive but the order note reads "sent from 0x…, not from the wallet connected to this order". The customer can pay again from the bound wallet, or you can refund the stray transfer and mark the order paid by hand.
- **Amount too low.** A transfer below the order amount is rejected with an "amount is smaller than the order amount" note and the order is released for another attempt.

## Upgrading

### From 1.1.0

No action is required. Optionally set **Token Symbol** so the pay page shows amounts as e.g. `12.5 USDC`, and the **Wallet network details** so wallets that don't know your chain can add it.

### From 1.0.0

- Enter an **RPC URL** after updating; the gateway stays hidden at checkout until it is set.
- The **Contract ABI** setting is no longer used and can be ignored.
- Customers now pay on the order-pay page right after checkout instead of on the thank-you page.

## Payment flow

1. The customer places the order with "EVM Token Payment" and is sent to the order-pay page.
2. They connect a wallet; the plugin switches it to the configured chain (adding the network first when the wallet lacks it and the network details are configured) and records the paying address on the order.
3. The wallet sends `transfer(recipient, amount)` to the token contract.
4. The transaction hash is submitted to the server, which verifies it over JSON-RPC.
5. Once confirmed, the order is marked as paid. Unconfirmed transactions are re-checked every minute for up to an hour, after which the order is put on hold for manual review (see above).

The order total is charged 1:1 in the token. To apply an exchange rate, use the `evm_payment_token_amount` filter. It receives the order total as a decimal string and must return a decimal amount of tokens:

```php
// Example: 1 unit of store currency = 2.5 tokens.
add_filter( 'evm_payment_token_amount', function ( $amount, $order ) {
    return number_format( (float) $amount * 2.5, 6, '.', '' );
}, 10, 2 );
```

## Development

```bash
composer install
composer test    # PHPUnit
composer phpcs   # WordPress Coding Standards + PHP compatibility
```

## Credits

Developed by [blocLabs.io](https://github.com/bloclabs-io).
