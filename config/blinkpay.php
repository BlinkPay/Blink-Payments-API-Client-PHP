<?php

declare(strict_types=1);

/*
 * Laravel configuration for the Blink Debit client. Publish with:
 *   php artisan vendor:publish --tag=blinkpay-config
 *
 * Credentials belong in the environment (or a secrets manager feeding it),
 * never in this file or version control.
 */
return [
    'client_id' => env('BLINKPAY_CLIENT_ID', ''),
    'client_secret' => env('BLINKPAY_CLIENT_SECRET', ''),

    // Sandbox is the safe default: production must be opted into explicitly.
    // Left uncast on purpose: the service provider parses it so that an unset
    // or blank variable means sandbox, which a (bool) cast would turn into
    // production.
    'sandbox' => env('BLINKPAY_SANDBOX', true),

    // Cache store name from config/cache.php used to persist access tokens
    // between requests; null uses the default store.
    'cache_store' => env('BLINKPAY_CACHE_STORE'),

    // Request timeout in seconds, covering the token fetch.
    'timeout' => (int) env('BLINKPAY_TIMEOUT', 30),
];
