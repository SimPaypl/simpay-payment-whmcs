<?php
/**
 * SimPay.pl Payment Gateway
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

use WHMCS\Config\Setting;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

const SIMPAY_PAYMENT_VERSION = '1.0.0';

// https://developers.whmcs.com/payment-gateways/meta-data-params/
function simpaypayment_MetaData()
{
    return array(
        'DisplayName' => 'SimPay.pl Przelewy i BLIK',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @return array
 */
function simpaypayment_config()
{
    $systemUrl = Setting::getValue('SystemURL');
    $simpayVersion = simpay_payment_internal_get_version();

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'SimPay.pl Przelewy i BLIK',
        ),
        'simpay_payment_bearer' => array(
            'FriendlyName' => 'Hasło / Bearer Token',
            'Type' => 'password',
            'Size' => '128',
            'Default' => '',
            'Description' => 'Zakładka Konto Klienta > API > {WYBRANY KLUCZ} > "Szczegóły"',
        ),
        'simpay_payment_service_id' => array(
            'FriendlyName' => 'ID usługi',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '',
            'Description' => 'Zakładka Płatności online > Usługi > {WYBIERZ USŁUGĘ} > Szczegóły',
        ),
        'simpay_payment_service_hash' => array(
            'FriendlyName' => 'Klucz do sygnatury IPN usługi',
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'Zakładka Płatności online > Usługi > {WYBIERZ USŁUGĘ} > Szczegóły > Ustawienia usługi',
        ),
        'simpay_payment_ipn_check_ip' => array(
            'FriendlyName' => 'Sprawdzaj adres IP w IPN',
            'Type' => 'yesno',
            'Size' => '64',
            'Default' => 'yes',
            'Description' => 'Włącz walidację adresu IP przy przychodzącym IPN. Jeśli korzystasz z proxy - radzimy wyłączyć.',
        ),
        'simpay_payment_ipn_url' => array(
            'FriendlyName' => 'URL IPN',
            'Type' => 'info',
            'Size' => '255',
            'Default' => '',
            'Description' => '<div class="successbox">W panelu SimPay ustaw adres adres URL IPN na: <b>' . $systemUrl . '/modules/gateways/callback/simpaypayment.php</b></div>',
        ),
        'simpay_version_info' => array(
            'FriendlyName' => 'Wersja wtyczki',
            'Type' => 'info',
            'Size' => '255',
            'Default' => '',
            'Description' => $simpayVersion,
        ),
    );
}

/**
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return string
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 */
function simpaypayment_link($params)
{
    if($params['currency'] !== 'PLN') {
        return '<h3 style="color:red;">Wystąpił błąd podczas generowania płatności [SIMPAY003].</h3><h4>Płatności SimPay.pl są dostępne tylko dla waluty PLN.</p>';
    }

    $payload = array(
        'amount' => (float)$params['amount'],
        'currency' => $params['currency'],
        'description' => $params['description'],
        'control' => (string)$params['invoiceid'],
        'customer' => array(
            'name' => substr($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'], 0, 64),
            'email' => $params['clientdetails']['email'],
        ),
        'antifraud' => array(
            'systemId' => !empty($params['clientdetails']['client_id']) ? $params['clientdetails']['client_id'] : null,
        ),
        'billing' => array(
            'name' => $params['clientdetails']['firstname'],
            'surname' => $params['clientdetails']['lastname'],
            'street' => $params['clientdetails']['address1'],
            'building' => $params['clientdetails']['address2'],
            'city' => $params['clientdetails']['city'],
            'region' => $params['clientdetails']['state'],
            'postalCode' => $params['clientdetails']['postcode'],
            'country' => $params['clientdetails']['countrycode'],
            'company' => $params['clientdetails']['companyname'],
        ),
        'returns' => array(
            'success' => $params['returnurl'],
            'failure' => $params['returnurl'],
        ),
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, sprintf('https://api.simpay.pl/payment/%s/transactions', $params['simpay_payment_service_id']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $params['simpay_payment_bearer'],
        'Content-Type: application/json',
        'Accept: application/json',
        'X-SIM-PLATFORM: WHMCS',
        'X-SIM-PLATFORM-VERSION: ' . $params['whmcsVersion'],
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        logModuleCall('simpaypayment', 'link:001', $payload, curl_error($ch));

        return '<h3 style="color:red;">Wystąpił błąd podczas generowania płatności [SIMPAY001].</h3><h4>Za utrudnienia przepraszamy!</h4><p>Informacje dla administratora zostały zapisane w logach WHMCS.</p>';
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ((int)$httpCode < 200 || $httpCode >= 300) {
        logModuleCall('simpaypayment', 'link:002', $payload, $response);

        return '<h3 style="color:red;">Wystąpił błąd podczas generowania płatności [SIMPAY002].</h3><h4>Za utrudnienia przepraszamy!</h4><p>Informacje dla administratora zostały zapisane w logach WHMCS.</p>';
    }

    $json = json_decode($response, true);
    curl_close($ch);

    return sprintf('<a href="%s">%s</a>', $json['data']['redirectUrl'], $params['langpaynow']);
}

function simpay_payment_internal_get_version()
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.simpay.pl/ecommerce/plugin/whmcs/version');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($response, true);

    if (version_compare($response['data']['version'], SIMPAY_PAYMENT_VERSION, '>')) {
        return sprintf(
            '<div class="errorbox">Dostępna jest nowa wersja wtyczki WHMCS SimPay. Twoja wersja: %s, nowa wersja: %s. Możesz pobrać wtyczkę bezpośrednio <a href="%s" target="_blank">tutaj</a>.</div>',
            SIMPAY_PAYMENT_VERSION,
            $response['data']['version'],
            $response['data']['zip_url']
        );
    }

    return sprintf(
        '<div class="successbox">Twoja wtyczka jest aktualna. Wersja: %s</div>',
        SIMPAY_PAYMENT_VERSION
    );
}