<?php
/**
 * PayCow Non-Custodial Crypto Payment Gateway for WHMCS
 *
 * Enables web hosting providers and SaaS merchants to accept direct,
 * non-custodial cryptocurrency payments (USDT on TRC20 and ERC20 networks)
 * with automated on-chain verification and invoice settlement.
 *
 * @package    PayCow\WHMCS
 * @author     PayCow Team
 * @copyright  2026 PayCow
 * @license    MIT
 * @version    1.0.0
 * @link       https://paycow.net
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Load embedded PayCow PHP SDK
require_once __DIR__ . '/paycow/sdk/autoload.php';

use PayCow\PayCowClient;
use PayCow\Payments\PaymentService;
use PayCow\Exceptions\PayCowException;
use WHMCS\Database\Capsule;

/**
 * Define gateway module metadata.
 *
 * @return array
 */
function paycow_MetaData()
{
    return [
        'DisplayName' => 'PayCow Non-Custodial Crypto Gateway',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'RequiresServerSync' => false,
    ];
}

/**
 * Ensure database schema exists for invoice-session bindings.
 * Executed solely in the administrative setup context (paycow_config).
 * Runtime client checkout never executes DDL.
 */
function paycow_ensure_database_table()
{
    if (!class_exists('\WHMCS\Database\Capsule')) {
        return;
    }

    try {
        if (!Capsule::schema()->hasTable('mod_paycow_sessions')) {
            Capsule::schema()->create('mod_paycow_sessions', function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->index();
                $table->string('session_id', 64)->unique();
                $table->string('payment_reference', 32)->nullable()->index();
                $table->string('network', 16);
                $table->string('currency', 16)->default('USDT');
                $table->string('amount', 32);
                $table->string('payable_amount', 32)->nullable();
                $table->string('tx_hash', 128)->nullable();
                $table->string('status', 32)->default('pending');
                $table->timestamps();
            });
        }
    } catch (\Exception $e) {
        // Log administrative table creation warning without breaking config page
        if (function_exists('logTransaction')) {
            logTransaction('paycow', [
                'error' => 'Unable to automatically create mod_paycow_sessions table: ' . $e->getMessage(),
                'notice' => 'For restricted DB users, please execute modules/gateways/paycow/schema.sql manually.',
            ], 'Schema Setup Notice');
        }
    }
}

/**
 * Define gateway configuration options in WHMCS Admin Area.
 *
 * @return array
 */
function paycow_config()
{
    // Initialize or verify database schema in administrative context
    paycow_ensure_database_table();

    $systemUrl = '';
    if (class_exists('\App')) {
        $systemUrl = \App::getSystemURL();
    }
    $callbackUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/paycow.php';

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'PayCow Non-Custodial Crypto',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Your PayCow Merchant API Key from dashboard API Settings.',
        ],
        'apiSecret' => [
            'FriendlyName' => 'API Secret',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Your PayCow Merchant API Secret.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Cryptographic signing secret for HMAC-SHA256 signature verification.',
        ],
        'network' => [
            'FriendlyName' => 'Network Mode',
            'Type' => 'dropdown',
            'Options' => [
                'TRC20' => 'TRC20 (Tron - Recommended, Low Fees)',
                'ERC20' => 'ERC20 (Ethereum)',
                'customer_choice' => 'Let Customer Choose (TRC20 or ERC20)',
            ],
            'Default' => 'TRC20',
            'Description' => 'Select default network or allow the customer to choose between TRC20 and ERC20 before session creation.',
        ],
        'walletSerial' => [
            'FriendlyName' => 'Wallet Serial Number',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '',
            'Description' => 'Optional: specific main wallet serial number. Leave blank for automatic rotation across your active wallets.',
        ],
        'instructions' => [
            'FriendlyName' => 'Webhook Notification URL',
            'Type' => 'textarea',
            'Rows' => '2',
            'Cols' => '60',
            'Description' => 'Copy this Webhook Callback URL and paste it into your PayCow Merchant Dashboard under Webhook Settings:<br><code style="color:#0f172a; font-weight:600; font-size:13px;">' . htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8') . '</code>',
        ],
    ];
}

/**
 * Generate payment button / link on WHMCS client invoice page.
 *
 * @param array $params WHMCS payment gateway parameters
 * @return string HTML button or redirect form
 */
function paycow_link($params)
{
    // 1. Check invoice status
    if (isset($params['status']) && strcasecmp($params['status'], 'Paid') === 0) {
        return '<div class="alert alert-success" style="color:#15803d; background:#f0fdf4; border:1px solid #bbf7d0; padding:10px 16px; border-radius:6px; font-size:14px; font-weight:600;">This invoice is already paid.</div>';
    }

    // 2. Gateway Configuration Validation
    $apiKey = trim($params['apiKey'] ?? '');
    $apiSecret = trim($params['apiSecret'] ?? '');

    if (empty($apiKey) || empty($apiSecret)) {
        return '<div class="alert alert-warning" style="color:#b45309; background:#fffbeb; border:1px solid #fde68a; padding:10px 16px; border-radius:6px; font-size:13px;">PayCow gateway is not configured. Please set your API Key and API Secret in WHMCS admin.</div>';
    }

    // 3. Currency Validation: Strictly USDT
    $currency = strtoupper(trim((string) ($params['currency'] ?? '')));
    if ($currency !== 'USDT') {
        return '<div class="alert alert-warning" style="color:#b45309; background:#fffbeb; border:1px solid #fde68a; padding:12px 16px; border-radius:6px; font-size:13px; line-height:1.5;">'
            . 'PayCow crypto payments are strictly denominated in <strong>USDT</strong>. This invoice is currently denominated in <strong>' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . '</strong>. '
            . 'To settle with cryptocurrency, please switch your invoice currency to USDT or contact billing support.'
            . '</div>';
    }

    // 4. Invoice Amount Validation
    $invoiceId = (int) ($params['invoiceid'] ?? 0);
    $amountStr = trim((string) ($params['amount'] ?? '0'));

    if ($invoiceId <= 0 || !is_numeric($amountStr) || PaymentService::compareDecimalStrings($amountStr, '0') <= 0) {
        return '';
    }

    // 5. Network Selection Resolution
    $configuredNetwork = $params['network'] ?? 'TRC20';
    $network = null;

    if ($configuredNetwork === 'customer_choice') {
        $requestedNetwork = strtoupper(trim((string) ($_POST['paycow_network'] ?? $_GET['paycow_network'] ?? '')));
        if ($requestedNetwork === 'TRC20' || $requestedNetwork === 'ERC20') {
            $network = $requestedNetwork;
        } else {
            // Render explicit customer network selection UI (No silent fallback to TRC20)
            $actionUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8');
            $html = '<div class="paycow-network-selector" style="margin: 14px 0; padding: 18px; border: 1px solid #cbd5e1; border-radius: 10px; background: #f8fafc; max-width: 480px;">';
            $html .= '<form method="POST" action="' . $actionUrl . '">';
            $html .= '<div style="font-weight: 700; color: #0f172a; margin-bottom: 10px; font-size: 14px;">Select USDT Crypto Network:</div>';
            $html .= '<div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px;">';
            $html .= '<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: #ffffff; font-size: 13px;">';
            $html .= '<input type="radio" name="paycow_network" value="TRC20" checked style="accent-color: #0f172a;">';
            $html .= '<span style="font-weight: 600; color: #0f172a;">USDT (TRC20 - Tron)</span>';
            $html .= '<span style="color: #64748b; font-size: 11px; margin-left: auto;">Low transaction fees</span>';
            $html .= '</label>';
            $html .= '<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: #ffffff; font-size: 13px;">';
            $html .= '<input type="radio" name="paycow_network" value="ERC20" style="accent-color: #0f172a;">';
            $html .= '<span style="font-weight: 600; color: #0f172a;">USDT (ERC20 - Ethereum)</span>';
            $html .= '</label>';
            $html .= '</div>';
            $html .= '<button type="submit" class="btn btn-primary" style="background: #0f172a; border-color: #0f172a; color: #ffffff; font-weight: 600; font-size: 14px; padding: 10px 22px; border-radius: 6px; cursor: pointer;">';
            $html .= 'Proceed to Crypto Payment';
            $html .= '</button>';
            $html .= '</form>';
            $html .= '</div>';
            return $html;
        }
    } else {
        $network = ($configuredNetwork === 'ERC20') ? 'ERC20' : 'TRC20';
    }

    $walletSerial = !empty($params['walletSerial']) ? trim((string) $params['walletSerial']) : null;

    try {
        $client = new PayCowClient([
            'apiKey' => $apiKey,
            'apiSecret' => $apiSecret,
        ]);

        // Deterministic Idempotency Key binding invoice ID, network, and amount
        $idempotencyKey = 'whmcs_inv_' . $invoiceId . '_' . $network . '_' . $amountStr;

        $createParams = [
            'userId' => 'whmcs_inv_' . $invoiceId,
            'amount' => $amountStr,
            'currency' => 'USDT',
            'network' => $network,
            'idempotencyKey' => $idempotencyKey,
        ];

        if (!empty($walletSerial)) {
            $createParams['addressSerialNo'] = $walletSerial;
        }

        $session = $client->payments()->create($createParams);
        $sessionId = $session->getSessionId();
        $paymentUrl = htmlspecialchars($session->getPaymentUrl(), ENT_QUOTES, 'UTF-8');
        $paymentRef = $session->getPaymentReference();
        $payableAmount = $session->getPayableAmount();
        $networkLabel = htmlspecialchars($network, ENT_QUOTES, 'UTF-8');

        // Persist session binding in mod_paycow_sessions (Fail Closed)
        if (!class_exists('\WHMCS\Database\Capsule')) {
            if (function_exists('logTransaction')) {
                logTransaction('paycow', [
                    'invoiceId' => $invoiceId,
                    'sessionId' => $sessionId,
                    'error' => 'WHMCS database Capsule is unavailable; cannot persist session binding.',
                ], 'Database Unavailable');
            }
            return '<div class="alert alert-danger" style="color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; padding:12px 16px; border-radius:6px; font-size:13px;">Unable to initialize payment session: database service is unavailable. Please contact billing support.</div>';
        }

        try {
            Capsule::table('mod_paycow_sessions')->updateOrInsert(
                ['session_id' => $sessionId],
                [
                    'invoice_id' => $invoiceId,
                    'payment_reference' => $paymentRef,
                    'network' => $network,
                    'currency' => 'USDT',
                    'amount' => $amountStr,
                    'payable_amount' => $payableAmount,
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Exception $dbEx) {
            if (function_exists('logTransaction')) {
                logTransaction('paycow', [
                    'invoiceId' => $invoiceId,
                    'sessionId' => $sessionId,
                    'error' => 'Failed to persist session binding in mod_paycow_sessions: ' . $dbEx->getMessage(),
                ], 'Database Binding Error');
            }
            return '<div class="alert alert-danger" style="color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; padding:12px 16px; border-radius:6px; font-size:13px;">Unable to initialize payment session: database persistence failed. Please try again or contact billing support.</div>';
        }

        $html = '<div class="paycow-payment-wrapper" style="margin: 12px 0;">';
        $html .= '<form method="GET" action="' . $paymentUrl . '">';
        $html .= '<button type="submit" class="btn btn-primary" style="background:#0f172a; border-color:#0f172a; color:#ffffff; font-weight:600; font-size:15px; padding:12px 28px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; text-decoration:none; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);">';
        $html .= '<span>Pay with USDT (' . $networkLabel . ')</span>';
        $html .= '</button>';
        $html .= '</form>';
        $html .= '<div style="margin-top:8px; font-size:12px; color:#64748b;">Direct non-custodial crypto checkout. Settles automatically to merchant wallet upon on-chain block confirmation.</div>';
        $html .= '</div>';

        return $html;
    } catch (PayCowException $e) {
        if (function_exists('logTransaction')) {
            logTransaction('paycow', [
                'invoiceId' => $invoiceId,
                'amount' => $amountStr,
                'network' => $network,
                'error' => $e->getMessage(),
            ], 'Payment Link Error');
        }

        return '<div class="alert alert-danger" style="color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; padding:12px 16px; border-radius:6px; font-size:13px;">Unable to initialize crypto checkout: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    } catch (\Exception $e) {
        if (function_exists('logTransaction')) {
            logTransaction('paycow', [
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage(),
            ], 'Unexpected Error');
        }

        return '<div class="alert alert-danger" style="color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; padding:12px 16px; border-radius:6px; font-size:13px;">Payment system encountered an unexpected error. Please contact billing support.</div>';
    }
}
