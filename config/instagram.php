<?php

/**
 * Integração do Instagram via `instagrapi` (prompt 48).
 *
 * O cliente Python roda como **processo separado** (o mesmo princípio de
 * Maigret/Sherlock/Holehe): o FPM nunca importa a biblioteca, o worker apenas
 * dispara o script com timeout e diretório isolado. O `sessionid` da conta de
 * serviço (burner) é configurado no painel admin (`/admin/settings/integrations`)
 * e chega ao script pelo ambiente do processo — as variáveis abaixo são apenas
 * os *defaults* usados quando o administrador ainda não salvou nada.
 */
return [

    // `process` executa o script Python; `mock` mantém o adapter determinístico
    // (sem rede), usado pela suíte de testes.
    'driver' => env('INSTAGRAM_DRIVER', 'process'),

    'binary' => env('INSTAGRAM_BINARY', 'python3'),

    // Timeout por requisição HTTP no cliente do Instagram (`request_timeout`).
    'request_timeout' => (int) env('INSTAGRAM_REQUEST_TIMEOUT', 15),

    // Teto do processo inteiro. Menor que o `source_definitions.timeout` (60s)
    // para o adapter encerrar antes de o worker desistir do job.
    'process_timeout' => (int) env('INSTAGRAM_PROCESS_TIMEOUT', 45),

    // Limite do JSON aceito do script (relatórios minúsculos: é um perfil).
    'max_output_bytes' => (int) env('INSTAGRAM_MAX_OUTPUT_BYTES', 5_000_000),

    // Diretório base para execuções isoladas (limpo ao final).
    // Fallback de credencial (NUNCA preencher no `.env` real: o cookie é um
    // segredo e pertence ao banco cifrado). Existe para ambientes descartáveis.
    'workdir' => env('INSTAGRAM_WORKDIR', storage_path('app/instagram')),
    'session_id' => env('INSTAGRAM_SESSION_ID'),
    'ds_user_id' => env('INSTAGRAM_DS_USER_ID'),
];
