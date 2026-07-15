<?php
/**
 * SimPay translations.
 *
 * Keys are used in templates and error messages visible to the customer.
 * Admin panel labels remain in Polish (as they target Polish merchants).
 *
 * @copyright Copyright (c) 2025 Payments Solution Sp. z o.o.
 */

return [
    'pl' => [
        // Admin panel
        'admin_section_connection' => 'Konfiguracja połączenia SimPay',
        'admin_section_connection_desc' => 'Skonfiguruj dane API SimPay używane do obsługi płatności.',
        'admin_field_service_id' => 'ID usługi',
        'admin_field_service_id_desc' => 'Płatności online → Usługi → Szczegóły → <strong>ID usługi</strong>',
        'admin_field_bearer' => 'Hasło API (Bearer Token)',
        'admin_field_bearer_desc' => 'Panel klienta → API → Szczegóły → <strong>Hasło / Token Bearer</strong>',
        'admin_field_signature_key' => 'Klucz podpisu IPN',
        'admin_field_signature_key_desc' => 'Płatności online → Usługi → Szczegóły → Ustawienia → <strong>Klucz podpisu IPN</strong>',
        'admin_section_webhook' => 'Webhook (IPN)',
        'admin_webhook_desc' => 'Ustaw poniższy adres URL w panelu SimPay jako adres webhooka/powiadomień:',
        'admin_field_check_ip' => 'Weryfikuj adres IP',
        'admin_field_check_ip_desc' => 'Weryfikuj adres IP w przychodzących powiadomieniach IPN.',
        'admin_field_check_ip_hint' => 'Jeśli Twój WHMCS jest za Cloudflare lub reverse proxy — nie zalecamy włączania tej opcji.',
        'admin_section_display' => 'Wyświetlanie płatności',
        'admin_section_display_desc' => 'Ustawienia wpływające na sposób prezentacji płatności klientowi.',
        'admin_field_show_channels' => 'Wyświetlaj kanały płatności',
        'admin_field_show_channels_desc' => 'Wyświetl listę kanałów płatności (banki, BLIK, karty) na stronie faktury.',
        'admin_field_show_channels_hint' => 'Klient wybiera metodę przed przekierowaniem. Gdy wyłączone — wybór nastąpi na stronie SimPay.',
        'admin_section_info' => 'Informacje o wtyczce',
        'admin_version_current' => 'Twoja wtyczka jest aktualna. Wersja: %s',
        'admin_version_update' => 'Dostępna jest nowa wersja wtyczki WHMCS SimPay. Twoja wersja: %s, nowa wersja: %s. Możesz pobrać wtyczkę bezpośrednio <a href="%s" target="_blank">tutaj</a>.',

        // Customer-facing
        'currency_error' => 'Płatności SimPay.pl są dostępne tylko dla waluty PLN.',
        'payment_error_api' => 'Wystąpił błąd podczas generowania płatności. Za utrudnienia przepraszamy!',
        'payment_error_generic' => 'Wystąpił błąd podczas generowania płatności. Spróbuj ponownie.',
        'select_payment_method' => 'Wybierz metodę płatności',
    ],
    'en' => [
        // Admin panel
        'admin_section_connection' => 'SimPay Connection Settings',
        'admin_section_connection_desc' => 'Configure the SimPay API credentials used for payment processing.',
        'admin_field_service_id' => 'Service ID',
        'admin_field_service_id_desc' => 'Online payments → Services → Details → <strong>Service ID</strong>',
        'admin_field_bearer' => 'API Password (Bearer Token)',
        'admin_field_bearer_desc' => 'Client panel → API → Details → <strong>Password / Bearer Token</strong>',
        'admin_field_signature_key' => 'IPN Signature Key',
        'admin_field_signature_key_desc' => 'Online payments → Services → Details → Settings → <strong>IPN Signature Key</strong>',
        'admin_section_webhook' => 'Webhook (IPN)',
        'admin_webhook_desc' => 'Set the following URL in the SimPay panel as the webhook/notification address:',
        'admin_field_check_ip' => 'Verify IP Address',
        'admin_field_check_ip_desc' => 'Verify IP address in incoming IPN notifications.',
        'admin_field_check_ip_hint' => 'If your WHMCS is behind Cloudflare or a reverse proxy — we do not recommend enabling this option.',
        'admin_section_display' => 'Payment Display',
        'admin_section_display_desc' => 'Settings affecting how payments are presented to the customer.',
        'admin_field_show_channels' => 'Show Payment Channels',
        'admin_field_show_channels_desc' => 'Display payment channels (banks, BLIK, cards) on the invoice page.',
        'admin_field_show_channels_hint' => 'Customer selects a method before redirect. When disabled — selection happens on the SimPay page.',
        'admin_section_info' => 'Plugin Information',
        'admin_version_current' => 'Your plugin is up to date. Version: %s',
        'admin_version_update' => 'A new version of the SimPay WHMCS plugin is available. Your version: %s, new version: %s. You can download it directly <a href="%s" target="_blank">here</a>.',

        // Customer-facing
        'currency_error' => 'SimPay.pl payments are only available for PLN currency.',
        'payment_error_api' => 'An error occurred while generating the payment. We apologize for the inconvenience!',
        'payment_error_generic' => 'An error occurred while generating the payment. Please try again.',
        'select_payment_method' => 'Select payment method',
    ],
];

