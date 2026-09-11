<?php
/**
 * PayCow WHMCS Callback / Webhook Endpoint
 *
 * Receives and cryptographically verifies incoming webhook notifications
 * from the PayCow payment gateway, updating WHMCS invoices automatically.
 *
 * @package    PayCow\WHMCS
 * @author     PayCow Team
 * @copyright  2026 PayCow
 * @license    MIT
 * @version    1.0.0
 * @link       https://paycow.net
 */

// Bootstrap WHMCS Core
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Gateway module identifier (strictly 'paycow')
$gatewayModuleName = 'paycow';

// Retrieve module configuration settings
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Reject if gateway module is deactivated
if (empty($gatewayParams['type'])) {
    http_response_code(403);
    die('PayCow gateway module is not activated in WHMCS.');
}

// Load embedded PayCow PHP SDK
require_once __DIR__ . '/../paycow/sdk/autoload.php';

use PayCow\PayCowClient;
use PayCow\Payments\PaymentService;
use PayCow\Exceptions\WebhookVerificationException;
use PayCow\Exceptions\PayCowException;
use WHMCS\Database\Capsule;

// Read raw request payload
$rawPayload = file_get_contents('php://input');

// Collect incoming HTTP request headers for SDK verification
$headers = $_SERVER;
if (function_exists('getallheaders')) {
    $allHeaders = getallheaders();
    if (is_array($allHeaders)) {
        $headers = array_merge($headers, $allHeaders);
    }
}

$signatureHeader = $headers['HTTP_PAYCOW_SIGNATURE'] ?? $headers['PayCow-Signature'] ?? '';

// Ensure Webhook Secret is configured
$webhookSecret = trim($gatewayParams['webhookSecret'] ?? '');
if (empty($webhookSecret)) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, [
            'error' => 'Webhook Secret is not configured in WHMCS PayCow Gateway settings.',
        ], 'Configuration Error');
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Webhook Secret is not configured in WHMCS',
    ]);
    exit;
}

// Verify Webhook Signature via PayCow PHP SDK
// Embedded SDK signature: verify(string $rawBody, array $headers, string $webhookSecret, int $toleranceSeconds = 300)
try {
    $client = new PayCowClient([
        'apiKey' => $gatewayParams['apiKey'] ?? '',
        'apiSecret' => $gatewayParams['apiSecret'] ?? '',
        'webhookSecret' => $webhookSecret,
    ]);

    $event = $client->webhooks()->verify($rawPayload, $headers, $webhookSecret);
} catch (WebhookVerificationException $e) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, [
            'error' => $e->getMessage(),
            'signatureHeader' => $signatureHeader,
            'payloadExcerpt' => substr($rawPayload, 0, 300),
        ], 'Invalid Signature');
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
    exit;
} catch (\Exception $e) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, [
            'error' => $e->getMessage(),
        ], 'Verification System Error');
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal webhook verification failure',
    ]);
    exit;
}

// Extract event metadata
$eventType = $event->getType();
$sessionId = $event->getSessionId();
$userId = $event->getUserId() ?? '';

// Build sanitized transaction log data (strictly excludes API keys or secrets)
$sanitizedLogData = [
    'eventId' => $event->getId(),
    'eventType' => $eventType,
    'sessionId' => $sessionId,
    'userId' => $userId,
    'network' => $event->getNetwork(),
    'currency' => $event->getCurrency(),
    'status' => $event->getStatus(),
    'amountRequested' => $event->getAmountRequested(),
    'payableAmount' => $event->getPayableAmount(),
    'amountReceived' => $event->getAmountReceived(),
    'txHash' => $event->getTransactionHash(),
    'confirmations' => $event->getConfirmations(),
];

// 1. Handle Webhook Test Ping
if ($event->isTest()) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, $sanitizedLogData, 'Test Ping Received');
    }
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'message' => 'PayCow test webhook received and verified successfully',
    ]);
    exit;
}

// 2. Resolve WHMCS Invoice ID strictly from userId pattern: ^whmcs_inv_([1-9][0-9]*)$
$invoiceId = null;
if (preg_match('/^whmcs_inv_([1-9][0-9]*)$/', (string) $userId, $matches)) {
    $invoiceId = (int) $matches[1];
}

if (!$invoiceId || $invoiceId <= 0) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
            'error' => 'Unable to resolve valid WHMCS invoice ID from userId. Strictly requires format whmcs_inv_<id>. Received: ' . $userId,
        ]), 'Invoice ID Resolution Failed');
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid or missing invoice ID in webhook payload. Strictly requires whmcs_inv_<id>',
    ]);
    exit;
}

// 3. Verify Invoice ID exists and belongs to this gateway module
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

// 4. Verify Invoice Is Not Already Paid in WHMCS
if (class_exists('\WHMCS\Database\Capsule')) {
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($invoice && strcasecmp((string) $invoice->status, 'Paid') === 0) {
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
                'notice' => 'Invoice #' . $invoiceId . ' is already marked Paid in WHMCS. Ignoring webhook.',
            ]), 'Already Paid');
        }
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'already_paid',
            'message' => 'Invoice is already marked Paid in WHMCS',
        ]);
        exit;
    }
}

// 5. Verify Currency is Strictly USDT
$eventCurrency = strtoupper(trim((string) $event->getCurrency()));
if ($eventCurrency !== 'USDT') {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
            'error' => 'Currency mismatch: PayCow webhook specified ' . $eventCurrency . ', but only USDT is supported.',
        ]), 'Currency Mismatch');
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid currency in webhook. Only USDT is supported.',
    ]);
    exit;
}

// 6. Verify Session Binding against mod_paycow_sessions (Fail Closed)
if (!class_exists('\WHMCS\Database\Capsule')) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
            'error' => 'WHMCS database Capsule is unavailable; cannot verify session binding.',
        ]), 'Database Unavailable');
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Database service unavailable for session binding verification',
    ]);
    exit;
}

$binding = null;
try {
    $binding = Capsule::table('mod_paycow_sessions')->where('session_id', $sessionId)->first();
} catch (\Exception $dbEx) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
            'error' => 'Failed to query mod_paycow_sessions: ' . $dbEx->getMessage(),
        ]), 'Database Binding Query Error');
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to query session binding table',
    ]);
    exit;
}

if (!$binding) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
            'error' => 'Session ID ' . $sessionId . ' not found in mod_paycow_sessions.',
        ]), 'Unrecognized Session ID');
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Unrecognized payment session ID',
    ]);
    exit;
}

if ((int) $binding->invoice_id !== (int) $invoiceId) {
    if (function_exists('logTransaction')) {
        logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
            'error' => 'Invoice binding mismatch: session belongs to invoice #' . $binding->invoice_id . ', received #' . $invoiceId,
        ]), 'Invoice Binding Mismatch');
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Session does not belong to the target invoice',
    ]);
    exit;
}

// Strictly enforce payment reference presence and match
$storedRef = trim((string) ($binding->payment_reference ?? ''));
if ($storedRef !== '') {
    $eventRef = trim((string) ($event->getPaymentReference() ?? ''));
    if ($eventRef === '' || $storedRef !== $eventRef) {
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
                'error' => 'Payment reference mismatch or missing. Expected: ' . $storedRef . ', received: ' . $eventRef,
            ]), 'Payment Reference Mismatch');
        }
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Payment reference mismatch or missing in webhook payload',
        ]);
        exit;
    }
}

// Strictly enforce network presence and match
$storedNet = strtoupper(trim((string) ($binding->network ?? '')));
if ($storedNet !== '') {
    $eventNet = strtoupper(trim((string) ($event->getNetwork() ?? '')));
    if ($eventNet === '' || $storedNet !== $eventNet) {
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
                'error' => 'Network mismatch or missing: session expected ' . $storedNet . ', received: ' . $eventNet,
            ]), 'Network Mismatch');
        }
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Network mismatch or missing in webhook payload',
        ]);
        exit;
    }
}

// 7. Canonical Transaction Identifier: strictly PayCow sessionId
$canonicalTransId = $sessionId;

// 8. Process Event by Lifecycle State
switch ($eventType) {
    case 'payment.detected':
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, $sanitizedLogData, 'Payment Detected On-Chain');
        }
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'message' => 'Payment detected on-chain, awaiting confirmations',
        ]);
        exit;

    case 'payment.confirming':
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, $sanitizedLogData, 'Payment Confirming (' . $event->getConfirmations() . ' confirmations)');
        }
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'message' => 'Payment confirming',
        ]);
        exit;

    case 'payment.paid':
        // Prevent duplicate transaction processing using canonical sessionId
        checkCbTransID($canonicalTransId);

        // Safe decimal string monetary comparison
        $payableAmount = (string) ($event->getPayableAmount() ?: $event->getAmountRequested());
        $rawAmountReceived = $event->getAmountReceived();

        // Reject missing or empty amountReceived on payment.paid (Never substitute with payableAmount)
        if ($rawAmountReceived === null || trim((string) $rawAmountReceived) === '') {
            if (function_exists('logTransaction')) {
                logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
                    'error' => 'payment.paid event rejected: missing or empty amountReceived in payload.',
                ]), 'Missing amountReceived');
            }
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing or empty amountReceived in payment.paid payload',
            ]);
            exit;
        }

        $amountReceived = trim((string) $rawAmountReceived);

        // Detect underpayment using pure decimal string comparator
        if (PaymentService::compareDecimalStrings($amountReceived, $payableAmount) < 0) {
            if (function_exists('logTransaction')) {
                logTransaction($gatewayModuleName, array_merge($sanitizedLogData, [
                    'error' => 'Underpayment detected. Required payable ' . $payableAmount . ' USDT, received on-chain ' . $amountReceived . ' USDT.',
                ]), 'Underpaid Warning');
            }
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'ignored',
                'message' => 'Underpayment detected. Invoice was not marked paid.',
            ]);
            exit;
        }

        // Apply exact invoice USDT amount to WHMCS invoice (prevents micro-balance residual pollution)
        $invoiceAccountingAmount = $binding ? (string) $binding->amount : (string) ($event->getAmountRequested() ?: $payableAmount);
        $fee = 0.00;

        addInvoicePayment(
            $invoiceId,
            $canonicalTransId,
            $invoiceAccountingAmount,
            $fee,
            $gatewayModuleName
        );

        // Update database session binding status and on-chain txHash
        if (class_exists('\WHMCS\Database\Capsule')) {
            try {
                Capsule::table('mod_paycow_sessions')->where('session_id', $sessionId)->update([
                    'status' => 'paid',
                    'tx_hash' => $event->getTransactionHash(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $dbEx) {
                // Non-fatal
            }
        }

        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, $sanitizedLogData, 'Successful Payment');
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Invoice payment added successfully',
            'invoiceId' => $invoiceId,
            'transId' => $canonicalTransId,
        ]);
        exit;

    case 'payment.expired':
        if (class_exists('\WHMCS\Database\Capsule')) {
            try {
                Capsule::table('mod_paycow_sessions')->where('session_id', $sessionId)->update([
                    'status' => 'expired',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $dbEx) {}
        }
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, $sanitizedLogData, 'Session Expired');
        }
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'message' => 'Session expired without full on-chain payment',
        ]);
        exit;

    case 'payment.rejected':
        if (class_exists('\WHMCS\Database\Capsule')) {
            try {
                Capsule::table('mod_paycow_sessions')->where('session_id', $sessionId)->update([
                    'status' => 'rejected',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $dbEx) {}
        }
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, $sanitizedLogData, 'Payment Rejected');
        }
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'message' => 'Payment session was rejected',
        ]);
        exit;

    default:
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, $sanitizedLogData, 'Ignored Event: ' . $eventType);
        }
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'message' => 'Event acknowledged but no action required',
        ]);
        exit;
}
