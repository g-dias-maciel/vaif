<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lead pipeline statuses and their Portuguese display labels.
    |--------------------------------------------------------------------------
    |
    | The keys are the canonical `stage_key` values written by the SDR agent
    | (Beatriz) to `projects`; the values are the labels the Artist sees in the
    | CRM. Keep this in sync with the agent's prompt and `pipeline_stages`.
    |
    */

    'pipeline_statuses' => [
        'novo'                => 'Novo',
        'qualificando'        => 'Qualificando',
        'orcamento_enviado'   => 'Orçamento enviado',
        'aguardando_deposito' => 'Aguardando sinal',
        'agendado'            => 'Agendado',
        'aguardando_artista'  => 'Aguardando artista',
        'fechado'             => 'Fechado',
        'perdido'             => 'Perdido',
        'bloqueado'           => 'Bloqueado',
    ],

    /*
    |--------------------------------------------------------------------------
    | Closed pipeline stages
    |--------------------------------------------------------------------------
    |
    | Finished projects. The dashboard pipeline shows these only inside the
    | selected period; every other (open) stage is always visible so a live
    | deal is never hidden by the time filter.
    |
    */

    'closed_pipeline_statuses' => ['fechado', 'perdido'],

    /*
    |--------------------------------------------------------------------------
    | Pricing table — canonical sizes and regions (the price-table tabs)
    |--------------------------------------------------------------------------
    |
    | Each region is a tab; inside it are sub-locations. The artist sets a
    | price for each sub-location × size. Custom sizes/locations the artist
    | adds are stored on the artist (pricing_extras) and merged at render time.
    |
    */

    'pricing_sizes' => [
        'pequeno' => 'Pequeno',
        'medio' => 'Médio',
        'grande' => 'Grande',
        'fechamento' => 'Fechamento',
    ],

    'pricing_regions' => [
        'perna' => [
            'label' => 'Perna',
            'locations' => [
                'panturrilha' => 'Panturrilha',
                'coxa' => 'Coxa',
                'canela' => 'Canela',
            ],
        ],
        'braco' => [
            'label' => 'Braço',
            'locations' => [
                'antebraco' => 'Antebraço',
                'braco_externo' => 'Braço externo',
                'braco_interno' => 'Braço interno',
            ],
        ],
        'tronco' => [
            'label' => 'Tronco',
            'locations' => [
                'costas' => 'Costas',
                'peito' => 'Peito',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Member area link
    |--------------------------------------------------------------------------
    |
    | The VAIF member area where Artists watch the training videos.
    |
    */

    'member_area_url' => env('MEMBER_AREA_URL', 'https://membros.vaif.com.br/'),

    /*
    |--------------------------------------------------------------------------
    | Onboarding webhook
    |--------------------------------------------------------------------------
    |
    | Shared secret the n8n onboarding flow sends to provision a CRM login for
    | a newly created Artist.
    |
    */

    'onboard_webhook_token' => env('ONBOARD_WEBHOOK_TOKEN'),

];
