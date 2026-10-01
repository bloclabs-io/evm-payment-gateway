=== EVM Payment Gateway ===
Contributors: bloclabs
Tags: woocommerce, payment gateway, crypto, ethereum, erc20
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept ERC-20 token payments on any EVM-compatible network in WooCommerce, verified on-chain.

== Description ==

EVM Payment Gateway lets customers pay for WooCommerce orders with an ERC-20 token from their browser wallet (MetaMask or any EIP-6963 compatible wallet).

* Works on any EVM chain: Ethereum, Polygon, BNB Chain, Hyperledger Besu and more.
* Supports the block-based Cart & Checkout and the classic checkout.
* Compatible with High-Performance Order Storage (HPOS).
* Every payment is verified on-chain by the server (receipt status, token contract, sender, recipient, amount, confirmations) before the order is marked as paid.
* Transactions that are still being confirmed are re-checked in the background with Action Scheduler.

Developed by blocLabs.io.

The order total is charged 1:1 in the configured token. Developers can change the amount with the `evm_payment_token_amount` filter.

= External services =

To verify payments, the plugin sends JSON-RPC requests (transaction hash, block number) from your server to the RPC endpoint you configure in the settings. The customer's wallet communicates with its own RPC provider. No other data is sent to third parties.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin to `/wp-content/plugins/evm-payment-gateway` and activate it.
3. Go to WooCommerce > Settings > Payments > EVM Token Payment.
4. Enter the recipient address, token contract, token decimals, chain ID and an RPC URL for that chain, then enable the gateway.

== Frequently Asked Questions ==

= When is an order marked as paid? =

After the server has found a successful `Transfer` of at least the order amount from the customer's wallet to your address, with the configured number of confirmations.

= Where are the logs? =

Enable "Debug Log" in the gateway settings and open WooCommerce > Status > Logs, source `evm-payment-gateway`.

== Changelog ==

= 1.2.0 =
* New: the pay page shows the amount due in tokens (optional Token Symbol setting) next to the order total.
* New: optional Network Name, Native Currency Symbol and Public RPC URL settings let the pay page add a missing network to the customer's wallet.
* New: "Verify token payment on-chain" order action to re-check a transaction by hand.
* Changed: orders whose transaction cannot be confirmed within an hour are put on hold instead of staying pending indefinitely.
* Changed: rejection notes now say why (sent from another wallet, amount too low, no transfer found).
* Changed: receipts without a status field (pre-Byzantium chains) are judged by their transfer logs instead of being treated as reverted.
* Changed: the RPC chain ID check is cached for ten minutes to cut RPC traffic while a payment confirms.
* Fixed: background re-checks are skipped with a logged error, instead of a fatal error, if Action Scheduler is unavailable.
* Tested with WordPress 7.1 and WooCommerce 11.1.

= 1.1.0 =
* Security: payments are now verified on-chain before orders are marked as paid.
* Security: AJAX requests require a valid nonce and order key; transactions can't be reused across orders.
* Security: removed request logging to publicly accessible files; logging now uses the WooCommerce logger.
* New: Cart & Checkout block support.
* New: EIP-6963 multi-wallet discovery and automatic network switching.
* New: RPC URL, required confirmations, block explorer and debug settings.
* Changed: removed the web3.js dependency and the Contract ABI setting.
* Changed: payment now happens on the order-pay page.
* Changed: requires WordPress 6.5+ and WooCommerce 8.3+.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
No action required. Optionally set Token Symbol and the wallet network details in the gateway settings.

= 1.1.0 =
Security release. After updating, enter an RPC URL in the gateway settings; the gateway stays hidden at checkout until it is configured.
