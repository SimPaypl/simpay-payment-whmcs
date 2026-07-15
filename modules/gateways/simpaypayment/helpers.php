<?php
/**
 * SimPay helpers — SDK factory, version check, translations.
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

use SimPay\SDK\SimPay;

/**
 * Create SimPay SDK instance from WHMCS gateway params.
 */
function simpaypayment_get_sdk(array $params): SimPay
{
    return new SimPay(
        bearerToken: $params['simpay_payment_bearer'],
        serviceId: $params['simpay_payment_service_id'],
        signatureKey: $params['simpay_payment_service_hash'],
        platform: 'WHMCS',
        platformVersion: $params['whmcsVersion'] ?? '8.0'
    );
}

/**
 * Get translated string for the current client language.
 *
 * Falls back to Polish if language not found.
 */
function simpaypayment_t(string $key, array $params = []): string
{
    static $translations = null;

    if ($translations === null) {
        $translations = require __DIR__ . '/lang.php';
    }

    $lang = strtolower($params['language'] ?? 'polish');

    // Map WHMCS language names to our keys
    $langMap = [
        'polish' => 'pl',
        'english' => 'en',
    ];

    $langKey = $langMap[$lang] ?? 'pl';

    return $translations[$langKey][$key] ?? $translations['pl'][$key] ?? $key;
}

/**
 * Get admin translation — uses WHMCS admin language setting.
 */
function simpaypayment_admin_t(string $key): string
{
    static $translations = null;

    if ($translations === null) {
        $translations = require __DIR__ . '/lang.php';
    }

    // Detect admin language from WHMCS session/config
    $adminLang = 'pl';

    if (defined('ADMINAREA') || isset($GLOBALS['aInt'])) {
        $lang = strtolower($_SESSION['adminlang'] ?? \WHMCS\Config\Setting::getValue('Language') ?? 'polish');
        $langMap = ['polish' => 'pl', 'english' => 'en'];
        $adminLang = $langMap[$lang] ?? 'pl';
    }

    return $translations[$adminLang][$key] ?? $translations['pl'][$key] ?? $key;
}

/**
 * Check for plugin updates via SimPay API.
 */
function simpaypayment_check_version(): string
{
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.simpay.pl/ecommerce/plugin/whmcs/version');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($response, true);

        if (!empty($response['data']['version']) && version_compare($response['data']['version'], SIMPAY_PAYMENT_VERSION, '>')) {
            return sprintf(
                '<div class="errorbox">' . simpaypayment_admin_t('admin_version_update') . '</div>',
                SIMPAY_PAYMENT_VERSION,
                $response['data']['version'],
                $response['data']['zip_url'] ?? '#'
            );
        }
    } catch (\Throwable $e) {
        // Silently fail — version check is not critical
    }

    return sprintf(
        '<div class="successbox">' . simpaypayment_admin_t('admin_version_current') . '</div>',
        SIMPAY_PAYMENT_VERSION
    );
}
