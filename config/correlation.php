<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Versão da regra de correlação (persistida junto do score).
    |--------------------------------------------------------------------------
    */
    'rule_version' => env('CORRELATION_RULE_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Pesos dos sinais (parâmetros de produto, não verdade universal).
    |--------------------------------------------------------------------------
    */
    'weights' => [
        'username_exact_match' => 40,
        'name_compatible' => 20,
        'email_exact_match' => 20,
        'image_reference_compatible' => 20,
        'bio_similar' => 10,
        'location_compatible' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limiares de exibição (termos neutros, sem afirmar identidade).
    |--------------------------------------------------------------------------
    */
    'strong_threshold' => 60,
    'possible_threshold' => 30,
];
