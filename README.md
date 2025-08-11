# LedgerDirect Payment plugin for WooCommerce

LedgerDirect is a payment plugin for WooCommerce. Receive crypto and stablecoin payments directly – without middlemen, 
intermediary wallets, extra servers or external payment providers. Maximum control, minimal detours!

Project Website: https://www.ledger-direct.com

Plugin URL: placeholder for now, will be available in the WordPress Plugin Store after Beta.

GitHub: https://github.com/ledger-direct/ledger-direct-woocommerce

## Available currencies:
- XRP (XRP Ledger)
- RLUSD (XRP Ledger)

## Install & setup instructions

### 1. Install the plugin
- Download the plugin from the [WordPress Plugin Store](https://wordpress.org/plugins/ledger-direct/) or the [GitHub releases page](https://github.com/ledger-direct/ledger-direct-woocommerce).
- Upload the plugin to your WordPress installation:
  - Go to your WordPress admin panel
  - Navigate to "Plugins" > "Add New"
  - Click on "Upload Plugin"
  - Select the downloaded ZIP file and click "Install Now"
  - Activate the plugin after installation
  - Alternatively, you can install the plugin directly from the WordPress Plugin Store by searching for "LedgerDirect" in the "Add New" plugins section.

### 2. Configure the plugin
- Go to "WooCommerce" > "Settings" > "Payments"
- Find "LedgerDirect" in the list of payment methods and click "Manage"
- Enter your Merchant Wallet Address (the address where you want to receive payments)
- Configure any additional settings as needed (e.g., which network to use (Testnet or Mainnet), which currencies to accept, etc.)

## Accepting Stablecoin Payments
- To accept stablecoin payments, ensure you have the corresponding currencies (RLUSD, USDC, EURC etc.) enabled in the plugin settings
- The merchant wallet address needs to have the corresponding trust lines set up for the stablecoins you want to accept
