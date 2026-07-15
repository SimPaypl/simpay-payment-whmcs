<?php
/**
 * SimPay payment channels — rendering the channel selection grid.
 *
 * When a channel is selected, the form creates the transaction via a hidden
 * endpoint and redirects to the payment page. The transaction is created
 * at submit time with the selected channel passed as directChannel.
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

/**
 * Render channel selection form with available payment methods.
 *
 * Instead of POSTing to callback, we create the transaction immediately
 * when the user clicks "Pay" using a JS redirect approach. The transaction
 * is pre-created with a specific channel at click time.
 */
function simpaypayment_render_channel_selection(array $params): string
{
    try {
        $simpay = simpaypayment_get_sdk($params);
        $channels = $simpay->client()->getChannels();
    } catch (\Throwable $e) {
        logModuleCall('simpaypayment', 'channels:error', [], $e->getMessage());
        return simpaypayment_create_and_redirect($params);
    }

    if (empty($channels)) {
        return simpaypayment_create_and_redirect($params);
    }

    $html = '<div id="simpay-channels-wrapper">';
    $html .= '<style>' . simpaypayment_channels_css() . '</style>';

    // Section header
    $html .= '<p class="simpay-channels-header"><strong>' . simpaypayment_t('select_payment_method', $params) . '</strong></p>';

    $html .= '<div class="simpay-channels-grid">';

    foreach ($channels as $channel) {
        $id = htmlspecialchars((string) ($channel['id'] ?? ''), ENT_QUOTES);
        $name = htmlspecialchars((string) ($channel['name'] ?? ''), ENT_QUOTES);
        $img = htmlspecialchars((string) ($channel['img'] ?? ''), ENT_QUOTES);

        $html .= sprintf(
            '<div class="simpay-channel" data-channel-id="%s" onclick="simpaySelectChannel(this, \'%s\')" title="%s">'
            . '<img src="%s" alt="%s" loading="lazy">'
            . '<span class="simpay-channel-name">%s</span>'
            . '</div>',
            $id, $id, $name, $img, $name, $name
        );
    }

    $html .= '</div>';

    // Encode transaction params securely for the callback
    $callbackData = base64_encode(json_encode([
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

    // HMAC to prevent tampering
    $signature = hash_hmac('sha256', $callbackData, $params['simpay_payment_service_hash']);

    $systemUrl = rtrim($params['systemurl'], '/');

    $html .= sprintf(
        '<form id="simpay-payment-form" method="POST" action="%s/modules/gateways/callback/simpaypayment.php">'
        . '<input type="hidden" name="action" value="create">'
        . '<input type="hidden" name="payload" value="%s">'
        . '<input type="hidden" name="sig" value="%s">'
        . '<input type="hidden" name="channel" id="simpay-channel-input" value="">'
        . '<button type="submit" id="simpay-pay-btn" class="btn btn-success btn-lg simpay-pay-btn" disabled>'
        . '<i class="fas fa-lock"></i> %s'
        . '</button>'
        . '</form>',
        $systemUrl,
        htmlspecialchars($callbackData, ENT_QUOTES),
        $signature,
        $params['langpaynow']
    );

    $html .= '<script>' . simpaypayment_channels_js() . '</script>';
    $html .= '</div>';

    return $html;
}

/**
 * CSS for the channel selection grid.
 */
function simpaypayment_channels_css(): string
{
    return <<<'CSS'
.simpay-channels-header {
    margin: 10px 0 5px;
    font-size: 14px;
}
.simpay-channels-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
    margin: 15px 0;
    max-width: 700px;
}
.simpay-channel {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 12px 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #fff;
}
.simpay-channel:hover {
    border-color: #4CAF50;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.2);
}
.simpay-channel.selected {
    border-color: #4CAF50;
    background: #f1f8f1;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
}
.simpay-channel img {
    max-width: 80px;
    max-height: 40px;
    object-fit: contain;
    display: block;
    margin: 0 auto 6px;
}
.simpay-channel-name {
    font-size: 11px;
    color: #555;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.simpay-pay-btn {
    margin-top: 15px;
    min-width: 200px;
}
.simpay-pay-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
CSS;
}

/**
 * JavaScript for channel selection interaction.
 */
function simpaypayment_channels_js(): string
{
    return <<<'JS'
function simpaySelectChannel(el, channelId) {
    document.querySelectorAll('.simpay-channel.selected').forEach(function(item) {
        item.classList.remove('selected');
    });
    el.classList.add('selected');
    document.getElementById('simpay-channel-input').value = channelId;
    document.getElementById('simpay-pay-btn').disabled = false;
}
JS;
}
