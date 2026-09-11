# PayCow Non-Custodial Crypto Payment Gateway for WHMCS

Official WHMCS payment gateway module for PayCow non-custodial cryptocurrency payment infrastructure.

Enables web hosting providers, domain registrars, and SaaS merchants running WHMCS to accept direct, automated USDT deposits across TRC20 and ERC20 networks directly into merchant-controlled main wallets.

---

## Key Features

- **Non-Custodial Architecture**: Monitored payments settle directly on-chain to merchant main wallet addresses. PayCow never controls private keys or holds merchant balances.
- **Official PHP SDK Powered**: Built on top of the modular PayCow PHP SDK (`PayCow\PayCowClient`) for resilient HTTP transport, timing-safe cryptographic signature validation, and clean abstraction.
- **Multi-Network USDT Support**: Accept TRC20 (Tron, recommended for lower network fees) and ERC20 (Ethereum) stablecoin transactions, with fixed or customer-selected network modes.
- **Strict Currency Integrity**: Exclusively accepts invoices denominated in USDT to prevent fiat parity assumptions or currency discrepancies.
- **Invoice Accounting Accuracy**: Invoices are credited with the exact expected invoice amount upon on-chain block confirmation, preventing residual micro-balance pollution while logging full on-chain received amounts and transaction hashes.
- **Invoice Session Binding**: Prevents cross-session invoice hijacking by verifying session ID, payment reference, currency, and network against persisted bindings.
- **Replay & Tamper Protection**: Webhook payloads are verified using HMAC-SHA256 with timestamp replay prevention (300-second drift tolerance) and timing-safe string comparison.
- **Safe Administrative Database Setup**: Schema initialization is isolated to the administrative configuration view, preventing runtime DDL race conditions during checkout.

---

## System Requirements

- WHMCS 7.10, 8.x, or 9.x
- PHP 7.4 or higher
- PHP Extensions: `curl`, `json`, `openssl`, `hash`
- Active PayCow Merchant Account with at least one active main wallet
- WHMCS currency configured with Code: `USDT`

---

## Installation

1. Download the latest release archive (`paycow-whmcs-payment-gateway.zip`).
2. Extract the archive directly into your root WHMCS installation directory. The files will populate:
   ```text
   modules/gateways/paycow.php
   modules/gateways/callback/paycow.php
   modules/gateways/paycow/schema.sql
   modules/gateways/paycow/assets/icon.svg
   modules/gateways/paycow/assets/logo.svg
   modules/gateways/paycow/sdk/autoload.php
   modules/gateways/paycow/sdk/composer.json
   modules/gateways/paycow/sdk/src/
   ```
3. Ensure standard web server permissions are applied:
   - Directories: `755`
   - Files: `644`

---

## Database Initialization

- **Automatic Setup**: When an administrator activates or opens the PayCow gateway configuration in WHMCS Admin (`Configuration` -> `System Settings` -> `Payment Gateways`), the required tracking table `mod_paycow_sessions` is initialized automatically.
- **Manual Setup (Optional)**: If your WHMCS database user has restricted DDL permissions, your database administrator can manually execute the provided SQL script:
  ```bash
  mysql -u whmcs_user -p whmcs_database < modules/gateways/paycow/schema.sql
  ```

---

## Configuration

1. Log in to your WHMCS Admin Area.
2. Navigate to:
   - WHMCS 8.x+: **Configuration** -> **System Settings** -> **Payment Gateways**
   - WHMCS 7.x: **Setup** -> **Payments** -> **Payment Gateways**
3. Select the **All Payment Gateways** tab.
4. Locate **PayCow Non-Custodial Crypto** and click **Activate**.
5. Configure the following fields:
   - **Show on Order Form**: Check this box to enable the gateway for customer checkout.
   - **Display Name**: `PayCow Non-Custodial Crypto` (or your preferred checkout label).
   - **API Key**: Enter your Merchant API Key from the PayCow dashboard (Settings -> API Settings).
   - **API Secret**: Enter your Merchant API Secret.
   - **Webhook Secret**: Enter your Webhook Secret from the PayCow dashboard.
   - **Network Mode**: Choose `TRC20`, `ERC20`, or `Let Customer Choose (TRC20 or ERC20)`.
   - **Wallet Serial Number**: (Optional) Enter a specific main wallet serial number or leave blank for automatic rotation.
6. Click **Save Changes**.

---

## Currency Setup in WHMCS

PayCow cryptocurrency settlements are strictly denominated in **USDT**.

1. In WHMCS Admin, navigate to:
   - WHMCS 8.x+: **Configuration** -> **System Settings** -> **Currencies**
   - WHMCS 7.x: **Setup** -> **Payments** -> **Currencies**
2. Ensure a currency with Code `USDT` is created.
3. Invoices must be billed in USDT for customer crypto checkout. If an invoice in another currency is presented to the gateway, the customer will be prompted to switch their currency to USDT.

---

## Webhook Configuration in PayCow

To enable automated invoice settlement upon on-chain confirmation:

1. Copy your WHMCS Webhook Callback URL displayed in the gateway configuration instructions:
   ```text
   https://your-whmcs-domain.com/modules/gateways/callback/paycow.php
   ```
2. Log in to your **PayCow Merchant Dashboard**.
3. Navigate to **Settings** -> **Webhook Settings**.
4. Set the **Webhook URL** to the URL copied above.
5. Copy your **Webhook Secret** from the dashboard and paste it into the **Webhook Secret** field in your WHMCS gateway settings.
6. Click the **Send Test Webhook** button in the dashboard to verify connectivity. A successful ping will be logged in your WHMCS Gateway Log (`Billing` -> `Gateway Log`).

---

## Transaction Lifecycle & Webhook Handling

| Webhook Event | PayCow State | WHMCS Action |
|---|---|---|
| `payment.detected` | Unconfirmed on-chain transfer detected | Recorded in WHMCS Gateway Log |
| `payment.confirming` | Network block confirmations accruing | Recorded in WHMCS Gateway Log |
| `payment.paid` | Required confirmations met; funds delivered | Verifies session binding, validates received amount against payable amount, credits exact invoice USDT balance via `addInvoicePayment()`, marks invoice Paid |
| `payment.expired` | Payment window timed out without valid transfer | Logged in WHMCS Gateway Log |
| `payment.rejected` | Transaction failed or invalid | Logged in WHMCS Gateway Log |
| `webhook.test` | Dashboard connection ping | Logged in WHMCS Gateway Log; responds HTTP 200 |

---

## Security & Architecture

- **Dedicated PHP SDK**: Protocol-level HTTP serialization, error mapping, and HMAC verification are handled by the embedded `PayCow\PayCowClient` SDK rather than inline code.
- **Redacted Logging**: WHMCS transaction logs contain only public transaction hashes, session IDs, invoice IDs, and confirmation counts. Secret keys and credentials are never written to disk or database logs.
- **Idempotency Safeguard**: Payment creation requests include a deterministic `Idempotency-Key` header (`whmcs_inv_{invoiceId}_{network}_{amount}`) preventing accidental duplicate payment sessions upon browser refresh.

---

## License

This project is licensed under the MIT License.
