<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Investigação avulsa (sem cadastro) — prompt 41.
    |--------------------------------------------------------------------------
    |
    | O preço fixo NÃO fica aqui: é o pacote ativo `guest_investigation` em
    | `credit_packages`, editável pelo admin em /admin/packages sem deploy.
    |
    | `unpaid_expiration_minutes`: prazo para o visitante pagar. Depois disso a
    | investigação avulsa e o pedido são marcados como `expired` e nada é
    | executado (o run nunca é liberado sem pagamento confirmado).
    |
    | `access_token_ttl_days`: validade do link/token de acesso. Acompanha a
    | política de retenção de investigações (config/privacy.php).
    */
    'unpaid_expiration_minutes' => (int) env('GUEST_UNPAID_EXPIRATION_MINUTES', 1440),

    'access_token_ttl_days' => (int) env('GUEST_ACCESS_TOKEN_TTL_DAYS', 365),
];
