<?php
/**
 * SimPay.pl Payment Gateway
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'simpaypayment';
$gatewayParams = getGatewayVariables($gatewayModuleName);

function simpay_payment_ipn_error($message)
{
    if (!headers_sent()) {
        http_response_code(400);
    }

    echo $message;
    die();
}

function simpay_payment_ipn_get_ip()
{
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // If the client is behind a proxy, get the first IP in the forwarded chain
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        // Otherwise, just use the remote address
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    return $ip;
}

function simpay_payment_ipn_calculate_signature($payload, $serviceHash)
{
    unset($payload['signature']);

    $data = simpay_payment_ipn_flatten_array($payload);
    $data[] = $serviceHash;

    return hash('sha256', implode('|', $data));
}

function simpay_payment_ipn_flatten_array(array $array)
{
    $return = array();

    array_walk_recursive($array, function ($a) use (&$return) {
        $return[] = $a;
    });

    return $return;
}

if (!$gatewayParams['type']) {
    simpay_payment_ipn_error('Module Not Activated');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    simpay_payment_ipn_error('Method not allowed');
}

if ($gatewayParams['simpay_payment_ipn_check_ip'] === 'on') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.simpay.pl/ip');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($response, true);

    if (!in_array(simpay_payment_ipn_get_ip(), $response['data'])) {
        logModuleCall($gatewayModuleName, 'ipn:ip', simpay_payment_ipn_get_ip(), $response['data']);
        simpay_payment_ipn_error('invalid ip');
    }
}

$payload = json_decode(@file_get_contents('php://input'), true);
if (empty($payload)) {
    logModuleCall($gatewayModuleName, 'ipn:payload_unreadable', $payload, '');
    simpay_payment_ipn_error('cannot read payload');
}

if (empty($payload['id']) ||
    empty($payload['service_id']) ||
    empty($payload['status']) ||
    empty($payload['amount']['value']) ||
    empty($payload['amount']['currency']) ||
    empty($payload['control']) ||
    empty($payload['channel']) ||
    empty($payload['environment']) ||
    empty($payload['signature'])
) {
    logModuleCall($gatewayModuleName, 'ipn:payload_invalid', $payload, '');
    simpay_payment_ipn_error('invalid payload');
}

$signature = simpay_payment_ipn_calculate_signature($payload, $gatewayParams['simpay_payment_service_hash']);
if (!hash_equals($signature, $payload['signature'])) {
    logModuleCall($gatewayModuleName, 'ipn:payload_signature', $payload['signature'], $signature);
    simpay_payment_ipn_error('invalid signature');
}

if ($payload['service_id'] !== $gatewayParams['simpay_payment_service_id']) {
    logModuleCall($gatewayModuleName, 'ipn:invalid_service_id', $payload['service_id'], $gatewayParams['simpay_payment_service_id']);
    simpay_payment_ipn_error('invalid service_id');
}

if ($payload['status'] !== 'transaction_paid') {
    header('Content-Type: text/plain', true, 200);
    echo 'OK';
    die();
}

$invoiceId = checkCbInvoiceID((int)$payload['control'], $gatewayParams['name']);

checkCbTransID($payload['id']);

logTransaction($gatewayModuleName, $payload, 'Success');

addInvoicePayment(
    $invoiceId,
    $payload['id'],
    $payload['amount']['value'],
    $payload['amount']['commission'],
    $gatewayModuleName
);

header('Content-Type: text/plain', true, 200);
echo 'OK';
die();