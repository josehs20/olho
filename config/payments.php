<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Provedor de pagamento ativo.
    |--------------------------------------------------------------------------
    | MVP: 'fake'. Gateways reais serão adicionados depois sem mudar o domínio.
    */
    'provider' => env('PAYMENT_PROVIDER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Dados do recebedor PIX (somente placeholders no repositório).
    |--------------------------------------------------------------------------
    */
    'pix' => [
        'key_type' => env('PIX_KEY_TYPE', 'cnpj'),
        'key' => env('PIX_KEY', 'CONFIGURE_ME'),
        'receiver_name' => env('PIX_RECEIVER_NAME', 'CONFIGURE_ME'),
        'receiver_city' => env('PIX_RECEIVER_CITY', 'CONFIGURE_ME'),
        'expiration_minutes' => (int) env('PIX_EXPIRATION_MINUTES', 30),
    ],
];
