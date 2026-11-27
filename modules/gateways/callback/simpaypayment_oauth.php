<?php
/**
 * SimPay.pl Payment Gateway — "Połącz z SimPay" (OAuth) endpoint.
 *
 * Admin-only. Also serves as the OAuth redirect_uri (SimPay returns here with ?code=&state=).
 *
 *   GET  ?action=connect&csrf=     start the flow, redirect to panel.simpay.pl
 *   GET  ?code=&state=             OAuth callback, then redirect to the service picker
 *   GET  ?action=select            service picker
 *   POST ?action=provision         provision selected service (API key, IPN URL, IPN key)
 *   GET  ?action=cancel            abort the flow
 *   GET  ?action=disconnect&csrf=  remove credentials from WHMCS
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

const SIMPAYPAYMENT_ADMIN_CONTEXT = true;

// Resolve WHMCS root — works both with symlinks and direct installs
$whmcsRoot = dirname($_SERVER['SCRIPT_FILENAME'], 4);
if (!file_exists($whmcsRoot . '/init.php')) {
    $whmcsRoot = __DIR__ . '/../../..';
}

require_once $whmcsRoot . '/init.php';
require_once $whmcsRoot . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/../simpaypayment/vendor/autoload.php';
require_once __DIR__ . '/../simpaypayment/helpers.php';
require_once __DIR__ . '/../simpaypayment/oauth.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

simpaypayment_oauth_require_admin($whmcsRoot);

$gatewayParams = getGatewayVariables(SIMPAY_GATEWAY);
if (empty($gatewayParams['type'])) {
    simpaypayment_oauth_fail_page(simpaypayment_admin_t('oauth_error_module_inactive'));
}

if (!simpaypayment_oauth_enabled()) {
    simpaypayment_oauth_fail_page(simpaypayment_admin_t('oauth_error_not_available'));
}

$action = (string) ($_GET['action'] ?? '');

// SimPay redirects back to the bare endpoint URL with ?code=&state= (or ?error=&state=)
if ($action === '' && isset($_GET['state'])) {
    $action = 'callback';
}

$connector = simpaypayment_oauth_connector();

try {
    switch ($action) {
        case 'connect':
            simpaypayment_oauth_assert_csrf($_GET['csrf'] ?? null);
            simpaypayment_redirect($connector->start(simpaypayment_oauth_endpoint_url()));
            // no break — redirect exits

        case 'callback':
            $connector->handleCallback($_GET);
            // PRG: the authorization code must not stay in the address bar / history
            simpaypayment_redirect(simpaypayment_oauth_endpoint_url(['action' => 'select']));
            // no break

        case 'select':
            $pending = $connector->getPendingConnection();
            if ($pending === null) {
                simpaypayment_oauth_flash('error', simpaypayment_admin_t('oauth_error_expired'));
                simpaypayment_redirect(simpaypayment_admin_gateways_url());
            }
            simpaypayment_oauth_render_picker($pending);
            exit;

        case 'provision':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                exit('Method not allowed');
            }
            simpaypayment_oauth_assert_csrf($_POST['csrf'] ?? null);

            $host = (string) parse_url(simpaypayment_system_url(), PHP_URL_HOST);
            $result = $connector->connect(
                (string) ($_POST['service_id'] ?? ''),
                simpaypayment_ipn_url(),
                'WHMCS ' . $host
            );

            simpaypayment_save_connection($result);
            logModuleCall(SIMPAY_GATEWAY, 'oauth:connected', ['service_id' => $result->serviceId], 'OK');

            simpaypayment_oauth_flash('success', sprintf(
                simpaypayment_admin_t('oauth_success'),
                simpaypayment_e($result->serviceName)
            ));
            simpaypayment_redirect(simpaypayment_admin_gateways_url());
            // no break

        case 'disconnect':
            simpaypayment_oauth_assert_csrf($_GET['csrf'] ?? null);
            $connector->cancel();
            simpaypayment_clear_connection();
            logModuleCall(SIMPAY_GATEWAY, 'oauth:disconnected', [], 'OK');

            simpaypayment_oauth_flash('success', simpaypayment_admin_t('oauth_disconnected'));
            simpaypayment_redirect(simpaypayment_admin_gateways_url());
            // no break

        case 'cancel':
            $connector->cancel();
            simpaypayment_redirect(simpaypayment_admin_gateways_url());
            // no break

        default:
            simpaypayment_redirect(simpaypayment_admin_gateways_url());
    }
} catch (\Throwable $e) {
    logModuleCall(SIMPAY_GATEWAY, 'oauth:error:' . $action, $action === 'callback' ? array_keys($_GET) : [], $e->getMessage());

    simpaypayment_oauth_flash('error', simpaypayment_oauth_error_message($e));

    // wrong / unsupported service picked — the pending connection survives, let the admin pick again
    if ($action === 'provision' && $connector->getPendingConnection() !== null) {
        simpaypayment_redirect(simpaypayment_oauth_endpoint_url(['action' => 'select']));
    }

    simpaypayment_redirect(simpaypayment_admin_gateways_url());
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Only a logged-in admin allowed to configure payment gateways may use this endpoint.
 */
function simpaypayment_oauth_require_admin(string $whmcsRoot): void
{
    $isAdmin = false;

    if (class_exists('\WHMCS\Authentication\CurrentUser')) {
        $isAdmin = (new \WHMCS\Authentication\CurrentUser())->isAuthenticatedAdmin();
    } else {
        $isAdmin = !empty($_SESSION['adminid']);
    }

    if ($isAdmin && file_exists($whmcsRoot . '/includes/adminfunctions.php')) {
        require_once $whmcsRoot . '/includes/adminfunctions.php';
        if (function_exists('checkPermission')) {
            $isAdmin = (bool) checkPermission('Configure Payment Gateways', true);
        }
    }

    if (!$isAdmin) {
        http_response_code(403);
        simpaypayment_oauth_page(
            simpaypayment_admin_t('oauth_title'),
            '<div class="alert error">' . simpaypayment_admin_t('oauth_error_forbidden') . '</div>'
            . sprintf('<p><a class="btn" href="%s">%s</a></p>', simpaypayment_e(simpaypayment_admin_gateways_url()), simpaypayment_admin_t('oauth_back'))
        );
        exit;
    }
}

function simpaypayment_oauth_assert_csrf(mixed $token): void
{
    if (!simpaypayment_oauth_check_csrf(is_string($token) ? $token : null)) {
        simpaypayment_oauth_flash('error', simpaypayment_admin_t('oauth_error_csrf'));
        simpaypayment_redirect(simpaypayment_admin_gateways_url());
    }
}

function simpaypayment_redirect(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function simpaypayment_oauth_fail_page(string $message): never
{
    simpaypayment_oauth_page(
        simpaypayment_admin_t('oauth_title'),
        '<div class="alert error">' . $message . '</div>'
        . sprintf('<p><a class="btn" href="%s">%s</a></p>', simpaypayment_e(simpaypayment_admin_gateways_url()), simpaypayment_admin_t('oauth_back'))
    );
    exit;
}

function simpaypayment_oauth_render_picker(\SimPay\SDK\OAuth\PendingConnection $pending): void
{
    $suggested = $pending->suggestServiceId(simpaypayment_system_url());
    $body = '';

    if ($flash = simpaypayment_oauth_pull_flash()) {
        $body .= sprintf('<div class="alert %s">%s</div>', $flash['type'] === 'success' ? 'success' : 'error', $flash['message']);
    }

    if ($pending->accountEmail !== '') {
        $body .= '<p>' . sprintf(simpaypayment_admin_t('oauth_picker_account'), '<strong>' . simpaypayment_e($pending->accountEmail) . '</strong>') . '</p>';
    }

    $body .= '<p>' . simpaypayment_admin_t('oauth_picker_desc') . '</p>';
    $body .= '<form method="post" action="' . simpaypayment_e(simpaypayment_oauth_endpoint_url(['action' => 'provision'])) . '">';
    $body .= '<input type="hidden" name="csrf" value="' . simpaypayment_e(simpaypayment_oauth_csrf_token()) . '">';
    $body .= '<div class="services">';

    foreach ($pending->services as $service) {
        $meta = array_filter([$service->url, $service->status]);
        $body .= sprintf(
            '<label class="service"><input type="radio" name="service_id" value="%s"%s required>'
            . '<span><strong>%s</strong><small>ID: %s%s</small></span></label>',
            simpaypayment_e($service->id),
            $service->id === $suggested ? ' checked' : '',
            simpaypayment_e($service->getDisplayName()),
            simpaypayment_e($service->id),
            $meta ? ' · ' . simpaypayment_e(implode(' · ', $meta)) : ''
        );
    }

    $body .= '</div>';
    $body .= '<div class="alert warning">' . sprintf(
        simpaypayment_admin_t('oauth_picker_warning'),
        '<code>' . simpaypayment_e(simpaypayment_ipn_url()) . '</code>'
    ) . '</div>';
    $body .= '<p class="actions">'
        . '<button type="submit" class="btn primary">' . simpaypayment_admin_t('oauth_picker_submit') . '</button> '
        . sprintf('<a class="btn" href="%s">%s</a>', simpaypayment_e(simpaypayment_oauth_endpoint_url(['action' => 'cancel'])), simpaypayment_admin_t('oauth_cancel'))
        . '</p>';
    $body .= '</form>';

    simpaypayment_oauth_page(simpaypayment_admin_t('oauth_picker_title'), $body);
}

function simpaypayment_oauth_page(string $title, string $body): void
{
    $lang = simpaypayment_admin_t('oauth_html_lang');
    echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>SimPay — {$title}</title>
<style>
body{margin:0;background:#f3f4f6;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2937}
.wrap{max-width:640px;margin:48px auto;padding:0 16px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
h1{font-size:20px;margin:0 0 16px}
.services{display:flex;flex-direction:column;gap:8px;margin:16px 0}
.service{display:flex;gap:12px;align-items:flex-start;border:1px solid #d1d5db;border-radius:8px;padding:12px 14px;cursor:pointer}
.service:has(input:checked){border-color:#2563eb;background:#eff6ff}
.service input{margin-top:4px}
.service small{display:block;color:#6b7280;word-break:break-all}
.alert{border-radius:8px;padding:12px 14px;margin:16px 0}
.alert.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.alert.warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.alert code{word-break:break-all}
.actions{margin:20px 0 0}
.btn{display:inline-block;border:1px solid #d1d5db;background:#fff;color:#1f2937;border-radius:6px;padding:9px 16px;font-size:14px;text-decoration:none;cursor:pointer}
.btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}
</style>
</head>
<body><div class="wrap"><div class="card"><h1>{$title}</h1>{$body}</div></div></body>
</html>
HTML;
}
