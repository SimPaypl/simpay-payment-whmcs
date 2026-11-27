<?php
/**
 * SimPay.pl Payment Gateway — IPN Callback Handler
 *
 * Handles two types of requests:
 * 1. IPN webhooks from SimPay (POST with JSON body)
 * 2. Transaction creation with channel pre-selection (POST with form data)
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

// Resolve WHMCS root — works both with symlinks and direct installs
$whmcsRoot = dirname($_SERVER['SCRIPT_FILENAME'], 4);
if (!file_exists($whmcsRoot . '/init.php')) {
    // Fallback to __DIR__ based resolution (standard WHMCS path)
    $whmcsRoot = __DIR__ . '/../../..';
}

require_once $whmcsRoot . '/init.php';
require_once $whmcsRoot . '/includes/gatewayfunctions.php';
require_once $whmcsRoot . '/includes/invoicefunctions.php';
require_once __DIR__ . '/../simpaypayment/vendor/autoload.php';
require_once __DIR__ . '/../simpaypayment/helpers.php';
require_once __DIR__ . '/../simpaypayment/transaction.php';

use SimPay\SDK\Exception\IpnException;
use SimPay\SDK\Exception\IpNotAllowedException;
use SimPay\SDK\IpnPayload;
use WHMCS\Database\Capsule;

$gatewayModuleName = 'simpaypayment';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

// ─────────────────────────────────────────────────────────────────────────────
// Route: Transaction creation with channel selection (form POST from invoice)
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    simpaypayment_handle_create($gatewayParams);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Route: IPN webhook from SimPay
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

simpaypayment_handle_ipn($gatewayParams, $gatewayModuleName);

// ─────────────────────────────────────────────────────────────────────────────
// Handler: Create transaction with pre-selected channel
// ─────────────────────────────────────────────────────────────────────────────

function simpaypayment_handle_create(array $gatewayParams): void
{
    $payloadRaw = $_POST['payload'] ?? '';
    $sig = $_POST['sig'] ?? '';
    $channelId = trim($_POST['channel'] ?? '');

    if ((string) $gatewayParams['simpay_payment_service_hash'] === '') {
        http_response_code(503);
        die('Gateway not configured');
    }

    // Verify HMAC signature to prevent tampering
    $expectedSig = simpaypayment_sign_payload($payloadRaw, (string) $gatewayParams['simpay_payment_service_hash']);
    if (!hash_equals($expectedSig, (string) $sig)) {
        logModuleCall('simpaypayment', 'create:invalid_signature', $_POST, '');
        http_response_code(400);
        die('Invalid signature');
    }

    $data = json_decode((string) base64_decode($payloadRaw, true), true);
    if (empty($data) || empty($data['invoiceid']) || empty($data['returnurl'])) {
        http_response_code(400);
        die('Invalid payload');
    }

    $returnUrl = (string) $data['returnurl'];
    $failUrl = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'paymentfailed=true';

    $payload = [];

    try {
        $simpay = simpaypayment_get_sdk($gatewayParams);
        $payload = simpaypayment_build_transaction($simpay, $data, $channelId)->toArray();
        $response = $simpay->client()->createTransaction($payload);
        $redirectUrl = $response['data']['redirectUrl'] ?? null;

        logModuleCall('simpaypayment', 'create:success', $payload, $response);

        if (empty($redirectUrl)) {
            header('Location: ' . $failUrl);
            exit;
        }

        header('Location: ' . $redirectUrl);
        exit;
    } catch (\Throwable $e) {
        logModuleCall('simpaypayment', 'create:error', $payload, $e->getMessage());
        header('Location: ' . $failUrl);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Handler: IPN webhook
// ─────────────────────────────────────────────────────────────────────────────

function simpaypayment_handle_ipn(array $gatewayParams, string $gatewayModuleName): void
{
    $simpay = simpaypayment_get_sdk($gatewayParams);

    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);

    if (empty($payload)) {
        logModuleCall($gatewayModuleName, 'ipn:empty_payload', $rawPayload, '');
        http_response_code(400);
        die('Cannot read payload');
    }

    // REMOTE_ADDR only — X-Forwarded-For is set by the client and would let anyone bypass the check.
    // Behind Cloudflare / a reverse proxy the option should be disabled (see the hint in the config).
    $remoteIp = $gatewayParams['simpay_payment_ipn_check_ip'] === 'on' ? ($_SERVER['REMOTE_ADDR'] ?? '') : null;

    try {
        $ipn = $simpay->handleIpn(
            payload: $payload,
            userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
            remoteIp: $remoteIp
        );
    } catch (IpNotAllowedException $e) {
        logModuleCall($gatewayModuleName, 'ipn:ip_blocked', $remoteIp, $e->getMessage());
        http_response_code(403);
        die($e->getMessage());
    } catch (IpnException $e) {
        logModuleCall($gatewayModuleName, 'ipn:validation_failed', $payload, $e->getMessage());
        http_response_code(400);
        die($e->getMessage());
    }

    // ─── Handle transaction events ───────────────────────────────────────────

    if ($ipn->isTransactionEvent()) {
        if (!$ipn->isPaid()) {
            // Non-paid status (expired, canceled, etc.) — acknowledge receipt
            logTransaction($gatewayModuleName, $payload, 'Status: ' . $ipn->getStatus());
            http_response_code(200);
            echo 'OK';
            return;
        }

        simpaypayment_process_paid_ipn($ipn, $payload, $gatewayParams, $gatewayModuleName);
        return;
    }

    // ─── Handle refund events ────────────────────────────────────────────────

    if ($ipn->isRefundEvent()) {
        logTransaction($gatewayModuleName, $payload, 'Refund: ' . $ipn->getStatus());
        http_response_code(200);
        echo 'OK';
        return;
    }

    // ─── Handle BLIK alias events ────────────────────────────────────────────

    if ($ipn->isBlikAliasEvent()) {
        logTransaction($gatewayModuleName, $payload, 'BLIK Alias: ' . ($ipn->getAliasStatus() ?? 'unknown'));
        http_response_code(200);
        echo 'OK';
        return;
    }

    // ─── Unknown event type — log and acknowledge ────────────────────────────

    logTransaction($gatewayModuleName, $payload, 'Unknown event: ' . $ipn->type);
    http_response_code(200);
    echo 'OK';
}

/**
 * Records a paid transaction on the invoice.
 *
 * Every exit answers "OK" unless the notification must be retried or is invalid — SimPay
 * keeps re-sending notifications that were not acknowledged.
 */
function simpaypayment_process_paid_ipn(IpnPayload $ipn, array $payload, array $gatewayParams, string $gatewayModuleName): void
{
    $transactionId = $ipn->getTransactionId();
    $paidAmount = $ipn->getAmount();
    $paidCurrency = strtoupper($ipn->getCurrency());

    // checkCbInvoiceID() dies when the invoice does not exist
    $invoiceId = (int) checkCbInvoiceID((int) $ipn->getControl(), $gatewayParams['name']);

    // Repeated notification for an already recorded payment — acknowledge, otherwise SimPay keeps retrying
    // (checkCbTransID() would just exit without a response body).
    $alreadyRecorded = Capsule::table('tblaccounts')
        ->where('gateway', $gatewayModuleName)
        ->where('transid', $transactionId)
        ->exists();

    if ($alreadyRecorded) {
        logTransaction($gatewayModuleName, $payload, 'Duplicate (already recorded)');
        http_response_code(200);
        echo 'OK';
        return;
    }

    $invoice = Capsule::table('tblinvoices as i')
        ->join('tblclients as c', 'c.id', '=', 'i.userid')
        ->join('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
        ->where('i.id', $invoiceId)
        ->select('i.total', 'i.credit', 'cur.code as currency')
        ->first();

    $invoiceCurrency = strtoupper((string) ($invoice->currency ?? ''));

    if ($paidAmount <= 0 || $paidCurrency === '' || $paidCurrency !== $invoiceCurrency) {
        logTransaction($gatewayModuleName, $payload, sprintf(
            'Amount/currency mismatch: paid %.2f %s, invoice currency %s',
            $paidAmount,
            $paidCurrency ?: '-',
            $invoiceCurrency ?: '-'
        ));
        http_response_code(400);
        echo 'Amount mismatch';
        return;
    }

    // WHMCS supports partial payments: the money was taken, so it is recorded even if it does not cover
    // the balance (e.g. the invoice was changed after the transaction was created) — the invoice stays Unpaid.
    $paidSoFar = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin')
        - (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountout');
    $balance = round((float) $invoice->total - (float) $invoice->credit - $paidSoFar, 2);

    $status = $paidAmount + 0.0001 < $balance
        ? sprintf('Partial payment: paid %.2f, balance %.2f', $paidAmount, $balance)
        : 'Success';

    checkCbTransID($transactionId);
    logTransaction($gatewayModuleName, $payload, $status);

    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paidAmount,
        0, // fee — SimPay handles commission separately
        $gatewayModuleName
    );

    http_response_code(200);
    echo 'OK';
}
