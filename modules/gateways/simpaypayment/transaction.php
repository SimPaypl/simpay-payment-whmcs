<?php
/**
 * SimPay transaction creation logic.
 *
 * The invoice page never creates a transaction by itself — it renders a signed form, and the
 * transaction is created only when the customer clicks "Pay" (callback, action=create).
 * Otherwise every invoice view (refresh, bots, link previews) would create a new SimPay transaction.
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

use SimPay\SDK\SimPay;
use SimPay\SDK\TransactionBuilder;

/**
 * Invoice data passed from the invoice page to the callback, signed with HMAC.
 */
function simpaypayment_build_payment_payload(array $params): string
{
    return base64_encode(json_encode([
        'amount' => (float) $params['amount'],
        'currency' => $params['currency'],
        'description' => $params['description'],
        'invoiceid' => (string) $params['invoiceid'],
        'firstname' => $params['clientdetails']['firstname'],
        'lastname' => $params['clientdetails']['lastname'],
        'email' => $params['clientdetails']['email'],
        'client_id' => $params['clientdetails']['client_id'] ?? '',
        'address1' => $params['clientdetails']['address1'],
        'address2' => $params['clientdetails']['address2'] ?? '',
        'city' => $params['clientdetails']['city'],
        'postcode' => $params['clientdetails']['postcode'],
        'countrycode' => $params['clientdetails']['countrycode'],
        'companyname' => $params['clientdetails']['companyname'] ?? '',
        'returnurl' => $params['returnurl'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'useragent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]));
}

function simpaypayment_sign_payload(string $payload, string $signatureKey): string
{
    return hash_hmac('sha256', $payload, $signatureKey);
}

/**
 * Form posting the signed payload to the callback. With $withChannel the submit button stays
 * disabled until a channel is picked (channel grid); otherwise it is a plain "Pay now" button.
 */
function simpaypayment_render_payment_form(array $params, bool $withChannel = false): string
{
    $payload = simpaypayment_build_payment_payload($params);

    return sprintf(
        '<form id="simpay-payment-form" method="POST" action="%s/modules/gateways/callback/simpaypayment.php">'
        . '<input type="hidden" name="action" value="create">'
        . '<input type="hidden" name="payload" value="%s">'
        . '<input type="hidden" name="sig" value="%s">'
        . '<input type="hidden" name="channel" id="simpay-channel-input" value="">'
        . '<button type="submit" id="simpay-pay-btn" class="btn btn-success btn-lg simpay-pay-btn"%s>'
        . '<i class="fas fa-lock"></i> %s'
        . '</button>'
        . '</form>',
        htmlspecialchars(rtrim($params['systemurl'], '/'), ENT_QUOTES),
        htmlspecialchars($payload, ENT_QUOTES),
        simpaypayment_sign_payload($payload, (string) $params['simpay_payment_service_hash']),
        $withChannel ? ' disabled' : '',
        $params['langpaynow']
    );
}

/**
 * Builds the SimPay transaction from the (verified) payload created by simpaypayment_build_payment_payload().
 */
function simpaypayment_build_transaction(SimPay $simpay, array $data, ?string $channelId = null): TransactionBuilder
{
    $returnUrl = (string) $data['returnurl'];

    $builder = $simpay->transaction()
        ->setAmount((float) $data['amount'], (string) $data['currency'])
        ->setDescription((string) $data['description'])
        ->setControl((string) $data['invoiceid'])
        ->setCustomer(
            trim($data['firstname'] . ' ' . $data['lastname']),
            (string) $data['email'],
            ($data['ip'] ?? '') ?: ($_SERVER['REMOTE_ADDR'] ?? null)
        )
        ->setAntifraud(
            !empty($data['client_id']) ? (string) $data['client_id'] : null,
            ($data['useragent'] ?? '') ?: ($_SERVER['HTTP_USER_AGENT'] ?? null)
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

    if ($channelId !== null && $channelId !== '') {
        $builder->setDirectChannel($channelId);
    }

    return $builder;
}
