# EVM Payment Gateway for WooCommerce

A secure WordPress plugin enabling EVM-compatible token payments through WooCommerce using MetaMask.

## Features

- Support for any EVM-compatible blockchain (Private/Public Besu, Public Ethereum, BSC, Polygon, etc.)
- MetaMask integration for secure transactions
- Configurable token contract settings
- **Customizable token name and symbol display**
- Transaction verification and order status management
- Enhanced security with proper nonce verification
- Improved logging with WooCommerce logger
- HPOS (High-Performance Order Storage) compatibility
- Detailed payment logging and error handling

## Requirements

- WordPress 6.0 or higher
- WooCommerce 7.0 or higher (tested up to 9.6)
- PHP 7.4 or higher
- MetaMask browser extension

## Installation

1. Download the plugin
2. Upload to your WordPress site
3. Activate the plugin through WordPress admin
4. Configure the payment gateway settings in WooCommerce > Settings > Payments

## Configuration

1. Enable the payment gateway
2. Set your wallet address (recipient address)
3. Configure token contract details:
   - Contract address
   - Token decimals
   - **Token name** (e.g., "Ethereum", "USD Coin")
   - **Token symbol** (e.g., "ETH", "USDC")
   - ABI (JSON format)
4. Set blockchain network ID
5. Save changes

## Usage

1. Customers select "EVM Token Payment" at checkout
2. MetaMask prompts for connection
3. Customer confirms the transaction
4. Order is automatically updated upon successful payment

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test
