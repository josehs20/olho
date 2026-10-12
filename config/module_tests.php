<?php

/**
 * Painel admin de testes individuais de módulo (prompt 47).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Módulos testáveis isoladamente
    |--------------------------------------------------------------------------
    |
    | Whitelist em ordem de exibição. Funciona como allow-list: o slug enviado
    | pelo formulário precisa estar aqui **e** existir como fonte ativa em
    | `source_definitions`. Nenhum valor informado vira comando — o adapter
    | monta o argv a partir da própria configuração (`ToolCommand`).
    |
    */
    'modules' => ['maigret', 'sherlock', 'whatsmyname', 'holehe', 'instagram'],

    /*
    |--------------------------------------------------------------------------
    | Saída resumida
    |--------------------------------------------------------------------------
    |
    | Limite de caracteres de stdout/stderr persistidos por teste (§6: "stdout
    | resumido"). Evita armazenar logs ilimitados.
    |
    */
    'output_limit' => (int) env('MODULE_TEST_OUTPUT_LIMIT', 4000),

];
