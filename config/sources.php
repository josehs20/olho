<?php

// Escopo padrão do Sherlock: não há "top N" na ferramenta, então o limite vem de
// uma lista curada de sites (configurável por `SHERLOCK_SITES`). Varredura
// limitada respeita as fontes e cabe no timeout do job.
$sherlockSites = env('SHERLOCK_SITES', implode(',', [
    'GitHub', 'GitLab', 'BitBucket', 'Telegram', 'Twitter', 'Instagram',
    'Reddit', 'Pinterest', 'TikTok', 'YouTube', 'Twitch', 'Vimeo',
    'Medium', 'Spotify', 'SoundCloud', 'Docker Hub', 'Keybase', 'Gravatar',
    'HackerNews',
]));

// Escopo padrão do WhatsMyName: a ordem do dataset é alfabética e não há
// ranking, então o padrão é uma lista curada de sites de alto sinal (nomes
// exatos do dataset). `WHATSMYNAME_MAX_SITES` permanece como teto de segurança.
$whatsMyNameSites = env('WHATSMYNAME_SITES', implode(',', [
    'GitHub (User)', 'GitLab', 'Bitbucket', 'Codeberg', 'Codeforces', 'Kaggle',
    'Telegram', 'Reddit', 'Pinterest', 'TikTok', 'Vimeo', 'Medium', 'Spotify',
    'SoundCloud', 'Twitch', 'Instagram', 'Keybase', 'Gravatar', 'Behance',
    'ArtStation', 'Flickr', 'Last.fm', 'Hacker News', 'Bluesky Username',
    'Docker Hub (User)', 'DeviantArt',
]));

return [

    /*
    |--------------------------------------------------------------------------
    | Integração real das ferramentas OSINT
    |--------------------------------------------------------------------------
    |
    | Cada fonte aceita `mock` (determinístico, sem rede) ou `process` (executa
    | a ferramenta real em container). O padrão do ambiente é `process`; a suíte
    | de testes força `mock` (phpunit.xml) para permanecer hermetica.
    |
    | O caminho do binário e os parâmetros vêm SEMPRE daqui/da `source_definitions`
    | — nunca da entrada do usuário (docs/09, docs/21).
    |
    */

    'maigret' => [
        'driver' => env('MAIGRET_DRIVER', 'process'),
        'binary' => env('MAIGRET_BINARY', 'maigret'),

        // Timeout por requisição HTTP (`--timeout`) e teto do processo inteiro.
        'request_timeout' => (int) env('MAIGRET_REQUEST_TIMEOUT', 8),
        'process_timeout' => (int) env('MAIGRET_PROCESS_TIMEOUT', 45),

        // Escopo da varredura (`--top-sites`) e concorrência (`--max-connections`).
        'top_sites' => (int) env('MAIGRET_TOP_SITES', 30),
        'max_connections' => (int) env('MAIGRET_MAX_CONNECTIONS', 30),

        // Extração de dados adicionais das páginas (mais lento e mais sujeito a
        // bloqueio; desligado por padrão para respeitar as fontes).
        'extract' => (bool) env('MAIGRET_EXTRACT', false),

        // Banco de dados do próprio Maigret (opcional; evita auto-update).
        'database' => env('MAIGRET_DATABASE'),

        // Limite do relatório JSON aceito do Maigret.
        'max_report_bytes' => (int) env('MAIGRET_MAX_REPORT_BYTES', 5_000_000),

        // Diretório base para execuções isoladas (limpo ao final).
        'workdir' => env('MAIGRET_WORKDIR', storage_path('app/maigret')),
    ],

    'sherlock' => [
        'driver' => env('SHERLOCK_DRIVER', 'process'),
        'binary' => env('SHERLOCK_BINARY', 'sherlock'),

        // Timeout por requisição HTTP (`--timeout`) e teto do processo inteiro.
        'request_timeout' => (int) env('SHERLOCK_REQUEST_TIMEOUT', 6),
        'process_timeout' => (int) env('SHERLOCK_PROCESS_TIMEOUT', 45),

        // Sites verificados (`--site` repetido). Lista vazia = todos os sites do
        // banco local (varredura longa; use com cautela).
        'sites' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $sherlockSites),
        ))),

        'workdir' => env('SHERLOCK_WORKDIR', storage_path('app/sherlock')),
    ],

    'whatsmyname' => [
        // WhatsMyName é um **dataset** consumido por checkers externos (o
        // projeto upstream não tem CLI oficial de busca desde 2023). `http`
        // executa as verificações conforme o schema publicado; `mock` usa o
        // adapter determinístico da suíte de testes.
        'driver' => env('WHATSMYNAME_DRIVER', 'http'),

        // Dataset fixado por commit na imagem (docker/php/Dockerfile). Quando
        // `revision` está vazio, o adapter lê o arquivo REVISION ao lado.
        'database' => env('WHATSMYNAME_DATABASE', '/opt/whatsmyname/wmn-data.json'),
        'revision' => env('WHATSMYNAME_REVISION'),

        'request_timeout' => (int) env('WHATSMYNAME_REQUEST_TIMEOUT', 8),
        'execution_timeout' => (int) env('WHATSMYNAME_EXECUTION_TIMEOUT', 45),
        'concurrency' => (int) env('WHATSMYNAME_CONCURRENCY', 10),
        'max_sites' => (int) env('WHATSMYNAME_MAX_SITES', 40),

        // Categorias verificadas (vazio = todas, exceto as excluídas).
        'categories' => array_values(array_filter(array_map('trim', explode(',', (string) env('WHATSMYNAME_CATEGORIES', ''))))),
        // Nunca consultar conteúdo adulto/NSFW.
        'excluded_categories' => array_values(array_filter(array_map('trim', explode(',', (string) env('WHATSMYNAME_EXCLUDED_CATEGORIES', 'xx NSFW xx'))))),
        // Lista explícita de sites (opcional; vazio = categorias + limite).
        'sites' => array_values(array_filter(array_map('trim', explode(',', (string) $whatsMyNameSites)))),

        'user_agent' => env('WHATSMYNAME_USER_AGENT', 'InvestigacaoPublica/1.0 (OSINT; verificacao de perfil publico)'),
    ],

    'holehe' => [
        // Verificação pública de cadastro por e-mail (fluxos de registro/login).
        // Executa a ferramenta real como **processo separado** (mesmo padrão do
        // Maigret/Sherlock) e sempre com `--no-password-recovery`: nunca dispara
        // recuperação de senha. A licença GPL-3.0 do binário não alcança o código
        // da aplicação (não há linkagem e o serviço é acessado pela rede, sem
        // distribuição do binário) — ver docs/30-holehe-integracao.md.
        'driver' => env('HOLEHE_DRIVER', 'process'),
        'binary' => env('HOLEHE_BINARY', 'holehe'),

        // Timeout por requisição interna (`--timeout`) e teto do processo.
        'request_timeout' => (int) env('HOLEHE_REQUEST_TIMEOUT', 10),
        'process_timeout' => (int) env('HOLEHE_PROCESS_TIMEOUT', 45),
        'max_report_bytes' => (int) env('HOLEHE_MAX_REPORT_BYTES', 2_000_000),

        'workdir' => env('HOLEHE_WORKDIR', storage_path('app/holehe')),
    ],

];
