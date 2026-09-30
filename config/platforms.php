<?php

/**
 * Plataformas exibidas na landing (prompt 42).
 *
 * Honestidade: uma plataforma só é marcada como coberta quando aparece no catálogo
 * das fontes ativas — WhatsMyName (/opt/whatsmyname/wmn-data.json, 717 sites),
 * Maigret (resources/data.json) e Sherlock (data.json). A execução real de
 * referência (investigação 26 / run 27, docs/36) observou, entre outros:
 * github.com, pinterest.com, vimeo.com, flickr.com, t.me, medium.com, tiktok.com,
 * open.spotify.com, facebook.com, reddit.com, twitch.tv, behance.net, kaggle.com.
 *
 * `scope` documenta o recorte configurado por execução (MAIGRET_TOP_SITES=30,
 * WHATSMYNAME_MAX_SITES=40): não é promessa de achado, é o alcance real das fontes.
 *
 * WhatsApp é marcado como **fora do escopo** de propósito: nenhuma fonte acessa
 * mensagens privadas (AGENTS.md e docs/35).
 */
return [
    'scope' => [
        'maigret_top_sites' => (int) env('MAIGRET_TOP_SITES', 30),
        'whatsmyname_max_sites' => (int) env('WHATSMYNAME_MAX_SITES', 40),
    ],

    'platforms' => [
        ['name' => 'Instagram', 'covered' => true],
        ['name' => 'Facebook', 'covered' => true],
        ['name' => 'TikTok', 'covered' => true],
        ['name' => 'X / Twitter', 'covered' => true],
        ['name' => 'YouTube', 'covered' => true],
        ['name' => 'LinkedIn', 'covered' => true],
        ['name' => 'GitHub', 'covered' => true],
        ['name' => 'Telegram', 'covered' => true],
        ['name' => 'Pinterest', 'covered' => true],
        ['name' => 'Reddit', 'covered' => true],
        ['name' => 'Spotify', 'covered' => true],
        ['name' => 'Twitch', 'covered' => true],
        ['name' => 'Medium', 'covered' => true],
        ['name' => 'Websites e blogs', 'covered' => true],
        ['name' => 'Usernames', 'covered' => true],
        ['name' => 'E-mails', 'covered' => true],
        ['name' => 'Menções públicas', 'covered' => true],
        [
            'name' => 'WhatsApp',
            'covered' => false,
            'note' => 'Mensagens privadas nunca são acessadas: as fontes ativas não verificam WhatsApp.',
        ],
    ],
];
