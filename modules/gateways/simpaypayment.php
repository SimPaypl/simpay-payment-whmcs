<?php
/**
 * SimPay.pl Payment Gateway for WHMCS
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/simpaypayment/vendor/autoload.php';
require_once __DIR__ . '/simpaypayment/helpers.php';
require_once __DIR__ . '/simpaypayment/transaction.php';
require_once __DIR__ . '/simpaypayment/channels.php';
require_once __DIR__ . '/simpaypayment/oauth.php';

const SIMPAY_PAYMENT_VERSION = '2.1.0';

// ─────────────────────────────────────────────────────────────────────────────
// WHMCS Gateway API
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 */
function simpaypayment_MetaData()
{
    return [
        'DisplayName' => 'SimPay.pl Przelewy i BLIK',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Gateway configuration options.
 */
function simpaypayment_config()
{
    $ipnUrl = simpaypayment_ipn_url();

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SimPay.pl Przelewy i BLIK',
        ],

        // ── Connection settings ──────────────────────────────────────────────

        'simpay_section_connection' => [
            'FriendlyName' => '<strong>' . simpaypayment_admin_t('admin_section_connection') . '</strong>',
            'Type' => 'info',
            'Description' => simpaypayment_render_oauth_block(),
        ],
        'simpay_payment_service_id' => [
            'FriendlyName' => simpaypayment_admin_t('admin_field_service_id'),
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => '<br><small>' . simpaypayment_admin_t('admin_field_service_id_desc') . '</small>',
        ],
        'simpay_payment_bearer' => [
            'FriendlyName' => simpaypayment_admin_t('admin_field_bearer'),
            'Type' => 'password',
            'Size' => '128',
            'Default' => '',
            'Description' => '<br><small>' . simpaypayment_admin_t('admin_field_bearer_desc') . '</small>',
        ],
        'simpay_payment_service_hash' => [
            'FriendlyName' => simpaypayment_admin_t('admin_field_signature_key'),
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => '<br><small>' . simpaypayment_admin_t('admin_field_signature_key_desc') . '</small>',
        ],

        // ── Webhook ──────────────────────────────────────────────────────────

        'simpay_section_webhook' => [
            'FriendlyName' => '<strong>' . simpaypayment_admin_t('admin_section_webhook') . '</strong>',
            'Type' => 'info',
            'Description' => simpaypayment_admin_t('admin_webhook_desc')
                . '<br><code style="display:inline-block;margin-top:5px;padding:6px 12px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;font-size:13px;">' . $ipnUrl . '</code>',
        ],
        'simpay_payment_ipn_check_ip' => [
            'FriendlyName' => simpaypayment_admin_t('admin_field_check_ip'),
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => simpaypayment_admin_t('admin_field_check_ip_desc')
                . '<br><small>' . simpaypayment_admin_t('admin_field_check_ip_hint') . '</small>',
        ],

        // ── Payment display ──────────────────────────────────────────────────

        'simpay_section_display' => [
            'FriendlyName' => '<strong>' . simpaypayment_admin_t('admin_section_display') . '</strong>',
            'Type' => 'info',
            'Description' => simpaypayment_admin_t('admin_section_display_desc'),
        ],
        'simpay_payment_show_channels' => [
            'FriendlyName' => simpaypayment_admin_t('admin_field_show_channels'),
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => simpaypayment_admin_t('admin_field_show_channels_desc')
                . '<br><small>' . simpaypayment_admin_t('admin_field_show_channels_hint') . '</small>',
        ],

        // ── Plugin info ──────────────────────────────────────────────────────

        'simpay_section_info' => [
            'FriendlyName' => '<strong>' . simpaypayment_admin_t('admin_section_info') . '</strong>',
            'Type' => 'info',
            'Description' => simpaypayment_check_version(),
        ],
    ];
}

/**
 * Payment link — HTML displayed on the invoice page.
 */
function simpaypayment_link($params)
{
    if ($params['currency'] !== 'PLN') {
        return '<div class="alert alert-danger">' . simpaypayment_t('currency_error', $params) . '</div>';
    }

    if ($params['simpay_payment_show_channels'] === 'on') {
        return simpaypayment_render_channel_selection($params);
    }

    return simpaypayment_render_payment_form($params);
}

/**
 * Admin status check — transaction details in the admin panel.
 */
function simpaypayment_AdminStatusCheck($params)
{
    try {
        $simpay = simpaypayment_get_sdk($params);
        $transactionId = $params['transid'];

        if (empty($transactionId)) {
            return ['status' => 'Brak ID transakcji'];
        }

        $transaction = $simpay->client()->getTransaction($transactionId);
        $data = $transaction['data'] ?? $transaction;

        return [
            'Transaction ID' => $data['id'] ?? $transactionId,
            'Status' => $data['status'] ?? 'Nieznany',
            'Amount' => isset($data['amount']['value']) ? $data['amount']['value'] . ' ' . ($data['amount']['currency'] ?? 'PLN') : '-',
            'Channel' => $data['channel'] ?? '-',
            'Paid At' => $data['paidAt'] ?? '-',
            'Created At' => $data['createdAt'] ?? '-',
        ];
    } catch (\Throwable $e) {
        return ['Error' => $e->getMessage()];
    }
}

