<?php
/**
 * SimPay "Połącz z SimPay" (OAuth) — WHMCS glue for SimPay\SDK\OAuth\OAuthConnector.
 *
 * The flow itself lives in simpay/ecommerce. This file only wires it to WHMCS:
 * PHP session storage, admin URLs, gateway settings, flash messages and translations.
 * The HTTP endpoint is modules/gateways/callback/simpaypayment_oauth.php.
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

use SimPay\SDK\Exception\ApiException;
use SimPay\SDK\OAuth\ConnectionResult;
use SimPay\SDK\OAuth\OAuthClient;
use SimPay\SDK\OAuth\OAuthConnector;
use SimPay\SDK\OAuth\OAuthException;
use SimPay\SDK\OAuth\OAuthStorageInterface;
use WHMCS\Config\Setting;

/**
 * OAuth client registered by SimPay for the WHMCS plugin (public client, PKCE, skips redirect_uri validation).
 * When empty, the "Connect with SimPay" button is hidden and only manual credentials are available.
 */
const SIMPAY_OAUTH_CLIENT_ID = '01a1e47c-5986-7362-bccd-61a9ce0a6068';

const SIMPAY_GATEWAY = 'simpaypayment';

/** Non-secret OAuth metadata, stored in tblconfiguration via WHMCS\Config\Setting. */
const SIMPAY_SETTING_OAUTH_SERVICE_ID = 'SimPayOAuthServiceId';
const SIMPAY_SETTING_OAUTH_SERVICE_NAME = 'SimPayOAuthServiceName';
const SIMPAY_SETTING_OAUTH_ACCOUNT_EMAIL = 'SimPayOAuthAccountEmail';

const SIMPAY_SESSION_FLASH = 'simpay_oauth_flash';
const SIMPAY_SESSION_CSRF = 'simpay_oauth_csrf';

/**
 * Keeps the SimPay OAuth flow in the admin's PHP session (WHMCS starts it in init.php).
 */
final class SimpayWhmcsSessionOAuthStorage implements OAuthStorageInterface
{
    public function get(string $key): ?array
    {
        $value = $_SESSION[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }
}

function simpaypayment_oauth_enabled(): bool
{
    return SIMPAY_OAUTH_CLIENT_ID !== '';
}

function simpaypayment_oauth_connector(): OAuthConnector
{
    return OAuthConnector::create(SIMPAY_OAUTH_CLIENT_ID, new SimpayWhmcsSessionOAuthStorage());
}

// ─────────────────────────────────────────────────────────────────────────────
// URLs
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Base URL from the WHMCS "System URL" setting, not from the current request — behind a
 * TLS-terminating proxy PHP sees plain HTTP, which SimPay rejects as redirect_uri.
 */
function simpaypayment_system_url(): string
{
    return rtrim((string) Setting::getValue('SystemURL'), '/');
}

function simpaypayment_ipn_url(): string
{
    return simpaypayment_system_url() . '/modules/gateways/callback/simpaypayment.php';
}

/**
 * OAuth redirect_uri and endpoint for all OAuth actions.
 */
function simpaypayment_oauth_endpoint_url(array $query = []): string
{
    $url = simpaypayment_system_url() . '/modules/gateways/callback/simpaypayment_oauth.php';

    return $query ? $url . '?' . http_build_query($query) : $url;
}

function simpaypayment_oauth_is_redirect_allowed(): bool
{
    return OAuthClient::isRedirectUriAllowed(simpaypayment_oauth_endpoint_url());
}

/**
 * Admin page with the gateway configuration (Setup → Payments → Payment Gateways).
 */
function simpaypayment_admin_gateways_url(): string
{
    $adminDir = 'admin';

    if (class_exists('\App') && is_callable(['\App', 'get_admin_folder_name'])) {
        $adminDir = (string) \App::get_admin_folder_name();
    } elseif (!empty($GLOBALS['customadminpath'])) {
        $adminDir = (string) $GLOBALS['customadminpath'];
    }

    return simpaypayment_system_url() . '/' . trim($adminDir, '/') . '/configgateways.php#m_' . SIMPAY_GATEWAY;
}

// ─────────────────────────────────────────────────────────────────────────────
// Session helpers: CSRF + flash
// ─────────────────────────────────────────────────────────────────────────────

function simpaypayment_oauth_csrf_token(): string
{
    if (empty($_SESSION[SIMPAY_SESSION_CSRF]) || !is_string($_SESSION[SIMPAY_SESSION_CSRF])) {
        $_SESSION[SIMPAY_SESSION_CSRF] = bin2hex(random_bytes(16));
    }

    return $_SESSION[SIMPAY_SESSION_CSRF];
}

function simpaypayment_oauth_check_csrf(?string $token): bool
{
    $expected = $_SESSION[SIMPAY_SESSION_CSRF] ?? '';

    return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
}

function simpaypayment_oauth_flash(string $type, string $message): void
{
    $_SESSION[SIMPAY_SESSION_FLASH] = ['type' => $type, 'message' => $message];
}

/**
 * @return array{type: string, message: string}|null
 */
function simpaypayment_oauth_pull_flash(): ?array
{
    $flash = $_SESSION[SIMPAY_SESSION_FLASH] ?? null;
    unset($_SESSION[SIMPAY_SESSION_FLASH]);

    return is_array($flash) && isset($flash['type'], $flash['message']) ? $flash : null;
}

function simpaypayment_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Gateway settings
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Writes a gateway setting (tblpaymentgateways).
 *
 * Since WHMCS 8.0 these values are encrypted at rest, so they must never be written or read
 * with raw queries — GatewaySetting handles encryption (WHMCS's own Stripe module uses it the same way).
 */
function simpaypayment_set_gateway_setting(string $setting, string $value): void
{
    if (!class_exists('\WHMCS\Module\GatewaySetting') || !method_exists('\WHMCS\Module\GatewaySetting', 'setValue')) {
        throw new \RuntimeException('WHMCS\Module\GatewaySetting::setValue() is not available (WHMCS 8.0+ required).');
    }

    \WHMCS\Module\GatewaySetting::setValue(SIMPAY_GATEWAY, $setting, $value);
}

/**
 * Decrypted gateway settings, read the supported way (getGatewayVariables).
 *
 * @return array<string, mixed>
 */
function simpaypayment_get_gateway_settings(): array
{
    static $loading = false;

    // getGatewayVariables() may load the module file and call simpaypayment_config() — avoid recursion
    if ($loading) {
        return [];
    }

    if (!function_exists('getGatewayVariables') && defined('ROOTDIR')) {
        require_once ROOTDIR . '/includes/gatewayfunctions.php';
    }

    if (!function_exists('getGatewayVariables')) {
        return [];
    }

    $loading = true;
    try {
        $params = getGatewayVariables(SIMPAY_GATEWAY);
    } catch (\Throwable $e) {
        $params = [];
    } finally {
        $loading = false;
    }

    return is_array($params) ? $params : [];
}

function simpaypayment_save_connection(ConnectionResult $result): void
{
    simpaypayment_set_gateway_setting('simpay_payment_bearer', $result->apiKey);
    simpaypayment_set_gateway_setting('simpay_payment_service_id', $result->serviceId);
    simpaypayment_set_gateway_setting('simpay_payment_service_hash', $result->ipnSignatureKey);

    // non-secret metadata, kept in tblconfiguration (plain WHMCS settings API)
    Setting::setValue(SIMPAY_SETTING_OAUTH_SERVICE_ID, $result->serviceId);
    Setting::setValue(SIMPAY_SETTING_OAUTH_SERVICE_NAME, $result->serviceName);
    Setting::setValue(SIMPAY_SETTING_OAUTH_ACCOUNT_EMAIL, $result->accountEmail);

    $saved = simpaypayment_get_gateway_settings();
    if ($saved !== [] && (string) ($saved['simpay_payment_service_id'] ?? '') !== $result->serviceId) {
        throw new \RuntimeException('Credentials were provisioned in SimPay but could not be saved in WHMCS gateway settings.');
    }
}

/**
 * Removes credentials from WHMCS. The API has no endpoint for revoking API keys,
 * so the key stays in the SimPay panel.
 */
function simpaypayment_clear_connection(): void
{
    foreach (['simpay_payment_bearer', 'simpay_payment_service_id', 'simpay_payment_service_hash'] as $setting) {
        simpaypayment_set_gateway_setting($setting, '');
    }

    foreach ([SIMPAY_SETTING_OAUTH_SERVICE_ID, SIMPAY_SETTING_OAUTH_SERVICE_NAME, SIMPAY_SETTING_OAUTH_ACCOUNT_EMAIL] as $setting) {
        Setting::setValue($setting, '');
    }
}

/**
 * OAuth connection details, or null when the current credentials were not provisioned via OAuth
 * (never connected, disconnected, or the service ID was later changed manually).
 *
 * @param array<string, mixed>|null $gatewayParams Decrypted gateway params, when already at hand
 *
 * @return array{service_id: string, service_name: string, account_email: string}|null
 */
function simpaypayment_get_oauth_connection(?array $gatewayParams = null): ?array
{
    $oauthServiceId = (string) Setting::getValue(SIMPAY_SETTING_OAUTH_SERVICE_ID);

    if ($oauthServiceId === '') {
        return null;
    }

    $params = $gatewayParams ?? simpaypayment_get_gateway_settings();

    if ($oauthServiceId !== (string) ($params['simpay_payment_service_id'] ?? '')
        || (string) ($params['simpay_payment_bearer'] ?? '') === ''
    ) {
        return null;
    }

    return [
        'service_id' => $oauthServiceId,
        'service_name' => (string) Setting::getValue(SIMPAY_SETTING_OAUTH_SERVICE_NAME),
        'account_email' => (string) Setting::getValue(SIMPAY_SETTING_OAUTH_ACCOUNT_EMAIL),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Messages
// ─────────────────────────────────────────────────────────────────────────────

function simpaypayment_oauth_error_message(\Throwable $e): string
{
    if ($e instanceof OAuthException) {
        return match ($e->getReason()) {
            OAuthException::REDIRECT_URI_NOT_ALLOWED => simpaypayment_admin_t('oauth_error_https'),
            OAuthException::ACCESS_DENIED => simpaypayment_admin_t('oauth_error_access_denied'),
            OAuthException::AUTHORIZATION_FAILED => sprintf(simpaypayment_admin_t('oauth_error_authorization_failed'), simpaypayment_e($e->getDetails())),
            OAuthException::INVALID_STATE => simpaypayment_admin_t('oauth_error_invalid_state'),
            OAuthException::NO_PAYMENT_SERVICES => simpaypayment_admin_t('oauth_error_no_services'),
            OAuthException::EXPIRED => simpaypayment_admin_t('oauth_error_expired'),
            OAuthException::UNKNOWN_SERVICE => simpaypayment_admin_t('oauth_error_unknown_service'),
            OAuthException::UNSUPPORTED_IPN_VERSION => sprintf(simpaypayment_admin_t('oauth_error_ipn_version'), simpaypayment_e($e->getDetails())),
            default => simpaypayment_e($e->getMessage()),
        };
    }

    $details = $e instanceof ApiException && $e->getApiMessage() ? $e->getApiMessage() : $e->getMessage();

    return sprintf(simpaypayment_admin_t('oauth_error_generic'), simpaypayment_e($details));
}

// ─────────────────────────────────────────────────────────────────────────────
// Gateway config block
// ─────────────────────────────────────────────────────────────────────────────

/**
 * HTML for the "Connection" section of the gateway configuration:
 * flash message, OAuth status and Connect / Disconnect buttons.
 */
function simpaypayment_render_oauth_block(): string
{
    $html = '';

    static $flash = false;
    if ($flash === false) {
        $flash = defined('ADMINAREA') ? simpaypayment_oauth_pull_flash() : null;
    }

    if ($flash) {
        $html .= sprintf(
            '<div class="%s" style="margin:0 0 10px;">%s</div>',
            $flash['type'] === 'success' ? 'successbox' : 'errorbox',
            $flash['message']
        );
    }

    if (!simpaypayment_oauth_enabled()) {
        return $html . simpaypayment_admin_t('admin_section_connection_desc')
            . '<br><small><a href="https://panel.simpay.pl" target="_blank">panel.simpay.pl</a></small>';
    }

    if (!simpaypayment_oauth_is_redirect_allowed()) {
        return $html . '<div class="infobox" style="margin:0;">' . simpaypayment_admin_t('oauth_requires_https') . '</div>'
            . '<small>' . simpaypayment_admin_t('oauth_manual_hint') . '</small>';
    }

    $csrf = simpaypayment_oauth_csrf_token();
    $connectUrl = simpaypayment_oauth_endpoint_url(['action' => 'connect', 'csrf' => $csrf]);
    $connection = simpaypayment_get_oauth_connection();

    if ($connection !== null) {
        $disconnectUrl = simpaypayment_oauth_endpoint_url(['action' => 'disconnect', 'csrf' => $csrf]);
        $confirm = json_encode(simpaypayment_admin_t('oauth_disconnect_confirm'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        return $html
            . '<div class="successbox" style="margin:0 0 8px;">'
            . sprintf(
                simpaypayment_admin_t('oauth_connected'),
                simpaypayment_e($connection['service_name'] ?: $connection['service_id']),
                simpaypayment_e($connection['account_email'] ?: '-')
            )
            . '</div>'
            . sprintf('<a href="%s" class="btn btn-default btn-sm">%s</a> ', simpaypayment_e($connectUrl), simpaypayment_admin_t('oauth_reconnect'))
            . sprintf(
                '<a href="%s" class="btn btn-danger btn-sm" onclick="return confirm(%s);">%s</a>',
                simpaypayment_e($disconnectUrl),
                simpaypayment_e($confirm),
                simpaypayment_admin_t('oauth_disconnect')
            );
    }

    return $html
        . simpaypayment_admin_t('oauth_connect_desc')
        . sprintf(
            '<br><a href="%s" class="btn btn-primary" style="margin:8px 0;">%s</a>',
            simpaypayment_e($connectUrl),
            simpaypayment_admin_t('oauth_connect')
        )
        . '<br><small>' . simpaypayment_admin_t('oauth_manual_hint') . '</small>';
}
