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

use SimPay\SDK\SimPay;
use SimPay\SDK\PaymentStatus;
use SimPay\SDK\AmountVerifier;
use SimPay\SDK\Exception\IpnException;
use SimPay\SDK\Exception\IpNotAllowedException;
use SimPay\SDK\Exception\ApiException;

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

    // Verify HMAC signature to prevent tampering
    $expectedSig = hash_hmac('sha256', $payloadRaw, $gatewayParams['simpay_payment_service_hash']);
    if (!hash_equals($expectedSig, $sig)) {
        logModuleCall('simpaypayment', 'create:invalid_signature', $_POST, '');
        die('Invalid signature');
    }

    $data = json_decode(base64_decode($payloadRaw), true);
    if (empty($data) || empty($data['invoiceid'])) {
        die('Invalid payload');
    }

    $returnUrl = $data['returnurl'];

    $simpay = new SimPay(
        bearerToken: $gatewayParams['simpay_payment_bearer'],
        serviceId: $gatewayParams['simpay_payment_service_id'],
        signatureKey: $gatewayParams['simpay_payment_service_hash'],
        platform: 'WHMCS',
        platformVersion: $GLOBALS['CONFIG']['Version'] ?? '8.0'
    );

    $builder = $simpay->transaction()
        ->setAmount((float) $data['amount'], $data['currency'])
        ->setDescription($data['description'])
        ->setControl((string) $data['invoiceid'])
        ->setCustomer(
            $data['firstname'] . ' ' . $data['lastname'],
            $data['email'],
            $data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null
        )
        ->setAntifraud(
            !empty($data['client_id']) ? (string) $data['client_id'] : null,
            $data['useragent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null
        )
        ->setReturnUrls($returnUrl, $returnUrl);

    // Only include billing if meaningful address data exists
    if (!empty($data['address1']) && !empty($data['city'])) {
        $builder->setBilling(
            $data['firstname'],
            $data['lastname'],
            $data['address1'] ?: null,
            $data['address2'] ?: null,
            $data['city'] ?: null,
            $data['postcode'] ?: null,
            $data['countrycode'] ?: null,
            $data['companyname'] ?: null
        );
    }

    if (!empty($channelId)) {
        $builder->setDirectChannel($channelId);
    }

    $payload = $builder->toArray();

    try {
        $response = $simpay->client()->createTransaction($payload);
        $redirectUrl = $response['data']['redirectUrl'] ?? null;

        logModuleCall('simpaypayment', 'create:success', $payload, $response);

        if (empty($redirectUrl)) {
            header('Location: ' . $returnUrl . '&paymentfailed=true');
            exit;
        }

        header('Location: ' . $redirectUrl);
        exit;
    } catch (\Throwable $e) {
        logModuleCall('simpaypayment', 'create:error', $payload, $e->getMessage());
        header('Location: ' . $returnUrl . '&paymentfailed=true');
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Handler: IPN webhook
// ─────────────────────────────────────────────────────────────────────────────

function simpaypayment_handle_ipn(array $gatewayParams, string $gatewayModuleName): void
{
    $simpay = new SimPay(
        bearerToken: $gatewayParams['simpay_payment_bearer'],
        serviceId: $gatewayParams['simpay_payment_service_id'],
        signatureKey: $gatewayParams['simpay_payment_service_hash'],
        platform: 'WHMCS',
        platformVersion: $GLOBALS['CONFIG']['Version'] ?? '8.0'
    );

    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);

    if (empty($payload)) {
        logModuleCall($gatewayModuleName, 'ipn:empty_payload', $rawPayload, '');
        http_response_code(400);
        die('Cannot read payload');
    }

    // Determine remote IP for validation
    $remoteIp = null;
    if ($gatewayParams['simpay_payment_ipn_check_ip'] === 'on') {
        $remoteIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        // Take the first IP if X-Forwarded-For contains multiple
        if ($remoteIp && str_contains($remoteIp, ',')) {
            $remoteIp = trim(explode(',', $remoteIp)[0]);
        }
    }

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

        // Transaction is paid — process payment
        $invoiceId = $ipn->getControl();
        $transactionId = $ipn->getTransactionId();
        $paidAmount = $ipn->getAmount();

        $invoiceId = checkCbInvoiceID((int) $invoiceId, $gatewayParams['name']);
        checkCbTransID($transactionId);

        logTransaction($gatewayModuleName, $payload, 'Success');

        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $paidAmount,
            0, // fee — SimPay handles commission separately
            $gatewayModuleName
        );

        http_response_code(200);
        echo 'OK';
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

