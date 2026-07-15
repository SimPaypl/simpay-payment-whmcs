<?php
/**
 * SimPay transaction creation logic.
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

use SimPay\SDK\Exception\ApiException;

/**
 * Create transaction via SDK and return a redirect link or error HTML.
 */
function simpaypayment_create_and_redirect(array $params, ?string $channelId = null): string
{
    try {
        $simpay = simpaypayment_get_sdk($params);

        $builder = $simpay->transaction()
            ->setAmount((float) $params['amount'], $params['currency'])
            ->setDescription($params['description'])
            ->setControl((string) $params['invoiceid'])
            ->setCustomer(
                $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
                $params['clientdetails']['email'],
                $_SERVER['REMOTE_ADDR'] ?? null
            )
            ->setAntifraud(
                !empty($params['clientdetails']['client_id']) ? (string) $params['clientdetails']['client_id'] : null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            )
            ->setReturnUrls($params['returnurl'], $params['returnurl']);

        // Only include billing if we have meaningful address data
        if (!empty($params['clientdetails']['address1']) && !empty($params['clientdetails']['city'])) {
            $builder->setBilling(
                $params['clientdetails']['firstname'],
                $params['clientdetails']['lastname'],
                $params['clientdetails']['address1'],
                $params['clientdetails']['address2'] ?: null,
                $params['clientdetails']['city'],
                $params['clientdetails']['postcode'],
                $params['clientdetails']['countrycode'],
                $params['clientdetails']['companyname'] ?: null
            );
        }

        if ($channelId !== null) {
            $builder->setDirectChannel($channelId);
        }

        $response = $simpay->client()->createTransaction($builder->toArray());
        $redirectUrl = $response['data']['redirectUrl'] ?? null;

        if (empty($redirectUrl)) {
            logModuleCall('simpaypayment', 'link:no_redirect', $builder->toArray(), $response);
            return '<div class="alert alert-danger">' . simpaypayment_t('payment_error_generic', $params) . '</div>';
        }

        return sprintf(
            '<a href="%s" class="btn btn-success btn-lg"><i class="fas fa-lock"></i> %s</a>',
            htmlspecialchars($redirectUrl, ENT_QUOTES),
            $params['langpaynow']
        );
    } catch (ApiException $e) {
        logModuleCall('simpaypayment', 'link:api_error', $params['invoiceid'], $e->getMessage());
        return '<div class="alert alert-danger">' . simpaypayment_t('payment_error_api', $params) . '</div>';
    } catch (\Throwable $e) {
        logModuleCall('simpaypayment', 'link:exception', $params['invoiceid'], $e->getMessage());
        return '<div class="alert alert-danger">' . simpaypayment_t('payment_error_generic', $params) . '</div>';
    }
}
