<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Provedor de pagamento ativo (fallback de ambiente)
    |--------------------------------------------------------------------------
    | O valor efetivo é lido das configurações administrativas (prompt 45) e cai
    | para este default quando nada foi salvo no painel. `fake` mantém o fluxo
    | de desenvolvimento/testes; `pagbank` usa a integração real.
    */
    'provider' => env('PAYMENT_PROVIDER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Intervalo mínimo entre consultas de status (segundos)
    |--------------------------------------------------------------------------
    | O polling da página do pedido reconsulta o provedor no máximo uma vez a
    | cada janela, para não multiplicar chamadas da API por comprador. O botão
    | "Já paguei", o retorno do checkout e a confirmação pelo webhook não
    | respeitam a janela: consultam/liberam na hora.
    */
    'reconcile_cooldown_seconds' => (int) env('PAYMENT_RECONCILE_COOLDOWN_SECONDS', 20),

    /*
    |--------------------------------------------------------------------------
    | Defaults das configurações administrativas
    |--------------------------------------------------------------------------
    | Usados quando a respectiva chave ainda não foi salva em `settings`.
    */
    'defaults' => [
        'enabled' => (bool) env('PAYMENT_ENABLED', true),
        'environment' => env('PAGBANK_ENVIRONMENT', 'sandbox'),
        'method_pix' => (bool) env('PAYMENT_METHOD_PIX', true),
        'method_debit' => (bool) env('PAYMENT_METHOD_DEBIT', true),
        'method_credit' => (bool) env('PAYMENT_METHOD_CREDIT', true),
        'pix_expiration_minutes' => (int) env('PIX_EXPIRATION_MINUTES', 30),
        'credit_max_installments' => (int) env('PAYMENT_MAX_INSTALLMENTS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Integração PagBank (Checkout / Link de Pagamento)
    |--------------------------------------------------------------------------
    | Hosts oficiais (doc "Ambientes disponíveis"): Sandbox
    | `https://sandbox.api.pagseguro.com` e Produção `https://api.pagseguro.com`.
    | Nenhuma credencial vive aqui — o token fica cifrado em `settings`.
    */
    'pagbank' => [
        'environments' => [
            'sandbox' => [
                'api' => env('PAGBANK_SANDBOX_API', 'https://sandbox.api.pagseguro.com'),
            ],
            'production' => [
                'api' => env('PAGBANK_PRODUCTION_API', 'https://api.pagseguro.com'),
            ],
        ],
        'timeout' => (int) env('PAGBANK_TIMEOUT', 15),
        // Faixa aceita para a quantidade máxima de parcelas no crédito. A doc do
        // PagBank limita por conta; a UI nunca aceita valor fora desta faixa.
        'installments' => [
            'min' => 1,
            'max' => 12,
            'allowed' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dados do recebedor PIX (somente placeholders no repositório).
    |--------------------------------------------------------------------------
    | Usados apenas pelo FakePixProvider (desenvolvimento/testes).
    */
    'pix' => [
        'key_type' => env('PIX_KEY_TYPE', 'cnpj'),
        'key' => env('PIX_KEY', 'CONFIGURE_ME'),
        'receiver_name' => env('PIX_RECEIVER_NAME', 'CONFIGURE_ME'),
        'receiver_city' => env('PIX_RECEIVER_CITY', 'CONFIGURE_ME'),
        'expiration_minutes' => (int) env('PIX_EXPIRATION_MINUTES', 30),
    ],
];
