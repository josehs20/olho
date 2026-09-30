<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Versão da regra de correlação (persistida junto do score).
    |--------------------------------------------------------------------------
    |
    | v2 (prompt 37): cada sinal virou um **fator explicável** com peso,
    | explicação, limitação, evidências usadas e fontes observadoras. O score
    | continua sendo indicador interno — nunca probabilidade de identidade.
    */
    'rule_version' => env('CORRELATION_RULE_VERSION', 'v2'),

    /*
    |--------------------------------------------------------------------------
    | Fatores (parâmetros de produto, não verdade universal)
    |--------------------------------------------------------------------------
    |
    | `weight`: contribuição no indicador interno.
    | `corroborating`: false = coincidência isolada (não corrobora identidade).
    | `evaluated`: false = ainda não avaliado no MVP (vai para `not_evaluated`).
    | `evidence_types`: tipos de evidência que registram o fator.
    */
    'signals' => [
        'username_exact_match' => [
            'type' => 'same_username',
            'weight' => 40,
            'corroborating' => false,
            'evaluated' => true,
            'evidence_types' => ['username_match', 'url_match'],
            'explanation' => 'O username pesquisado é exatamente o mesmo identificador observado no perfil público.',
            'limitation' => 'Username é escolhido pelo titular e pode ser reutilizado por pessoas diferentes; isoladamente não indica identidade.',
        ],
        'name_compatible' => [
            'type' => 'matching_public_name',
            'weight' => 20,
            'corroborating' => true,
            'evaluated' => true,
            'evidence_types' => ['name_match'],
            'explanation' => 'O nome informado aparece no título ou na descrição pública da descoberta.',
            'limitation' => 'A comparação é textual e não distingue homônimos; nomes iguais podem pertencer a pessoas distintas.',
        ],
        'email_exact_match' => [
            'type' => 'same_email',
            'weight' => 20,
            'corroborating' => true,
            'evaluated' => true,
            'evidence_types' => ['email_match'],
            'explanation' => 'O e-mail pesquisado aparece associado ao serviço na resposta pública da fonte.',
            'limitation' => 'Indicação de cadastro não prova que a pessoa controla aquela conta (e-mail pode ser compartilhado, corporativo ou descartável).',
        ],
        'location_compatible' => [
            'type' => 'matching_location',
            'weight' => 10,
            'corroborating' => true,
            'evaluated' => true,
            'evidence_types' => ['public_page_reference'],
            'explanation' => 'A cidade/estado informado aparece nos metadados públicos observados.',
            'limitation' => 'Localização declarada pode estar desatualizada; não é inferência de endereço nem de localização privada.',
        ],
        'image_reference_compatible' => [
            'type' => 'matching_image_reference',
            'weight' => 20,
            'corroborating' => true,
            'evaluated' => false,
            'evidence_types' => ['image_reference'],
            'explanation' => 'Referência de imagem pública compatível entre perfis.',
            'limitation' => 'Não avaliado no MVP: comparação de imagens e reconhecimento facial estão fora do escopo.',
        ],
        'bio_similar' => [
            'type' => 'similar_bio',
            'weight' => 10,
            'corroborating' => true,
            'evaluated' => false,
            'evidence_types' => ['public_page_reference'],
            'explanation' => 'Descrição/biografia pública semelhante entre perfis.',
            'limitation' => 'Não avaliado no MVP: exige comparação semântica e traria risco de falso positivo.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limiares de exibição (termos neutros, sem afirmar identidade)
    |--------------------------------------------------------------------------
    */
    'strong_threshold' => 60,
    'possible_threshold' => 30,

    /*
    |--------------------------------------------------------------------------
    | Avisos fixos do indicador
    |--------------------------------------------------------------------------
    */
    'score_kind' => 'internal_correlation_indicator',

    'global_limitations' => [
        'O valor exibido é um indicador interno de correlação entre sinais públicos observados; não é probabilidade de identidade nem confirmação de que a mesma pessoa controla as contas.',
        'A correlação usa apenas os dados observados pelas fontes deste run e as regras desta versão; nada é inferido a partir de dados privados.',
        'Uma correspondência possível não é identidade confirmada.',
    ],
];
