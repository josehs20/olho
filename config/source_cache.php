<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache de resultados de fonte (apenas dados públicos por entrada).
    |--------------------------------------------------------------------------
    */
    'enabled' => env('SOURCE_CACHE_ENABLED', true),
    'ttl' => (int) env('SOURCE_CACHE_TTL', 3600),
];
