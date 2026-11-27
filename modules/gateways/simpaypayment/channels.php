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
 * Falls back to a plain "Pay now" form when channels cannot be loaded.
 */
function simpaypayment_render_channel_selection(array $params): string
{
    try {
        $simpay = simpaypayment_get_sdk($params);
        $channels = $simpay->client()->getChannels();
    } catch (\Throwable $e) {
        logModuleCall('simpaypayment', 'channels:error', [], $e->getMessage());
        return simpaypayment_render_payment_form($params);
    }

    if (empty($channels)) {
        return simpaypayment_render_payment_form($params);
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

    $html .= simpaypayment_render_payment_form($params, true);

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
