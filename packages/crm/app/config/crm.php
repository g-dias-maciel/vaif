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
        'novo' => 'Novo',
        'qualificando' => 'Qualificando',
        'orcamento_enviado' => 'Orçamento enviado',
        'aguardando_deposito' => 'Aguardando sinal',
        'agendado' => 'Agendado',
        'aguardando_artista' => 'Aguardando artista',
        'fechado' => 'Fechado',
        'perdido' => 'Perdido',
        'bloqueado' => 'Bloqueado',
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
    | Remarketing score
    |--------------------------------------------------------------------------
    |
    | A heuristic chance-to-close score for open Projects, plus a separate
    | revival score for Leads whose last Project was lost. The stage base rates
    | are calibrated against historical outcomes (`crm:score-baseline`); the
    | adjustments make the score explainable to the Artist. The score is a
    | queue order, never a gate.
    |
    */

    'lead_score' => [
        'stage_base_rates' => [
            'novo' => 15,
            'qualificando' => 35,
            'orcamento_enviado' => 55,
            'aguardando_deposito' => 80,
            'aguardando_artista' => 70,
        ],

        'tier_thresholds' => [
            'hot' => 70,
            'warm' => 40,
        ],

        'tier_labels' => [
            'hot' => 'Quente',
            'warm' => 'Morno',
            'cold' => 'Frio',
            'won' => 'Ganho',
            'closed' => 'Encerrado',
        ],

        'adjustments' => [
            'quote_accepted' => 18,
            'quote_open' => 4,
            'quote_expired' => -8,
            'quote_declined' => -10,
            'deposit_confirmed' => 20,
            'deposit_requested' => 8,
            'booking_active' => 25,
            'replied_after_quote' => 10,
            'recent_2d' => 8,
            'recent_7d' => 5,
            'stale_30d' => -6,
            'stale_60d' => -12,
            'ghosted' => -12,
            'brief_full' => 8,
            'brief_partial' => 4,
            'returning' => 10,
            'agent_blocked' => -8,
            'archived' => -20,
        ],

        'revival' => [
            'stage_points' => [
                'agendado' => 40,
                'aguardando_deposito' => 35,
                'aguardando_artista' => 35,
                'orcamento_enviado' => 25,
                'qualificando' => 15,
                'novo' => 5,
            ],
            'quote' => 10,
            'deposit' => 15,
            'booking' => 20,
            'returning' => 10,
            'brief' => 5,
            'agent_blocked' => -10,
            'recency' => [
                'under_14' => 5,
                '14_90' => 15,
                '91_180' => 8,
                'older' => 0,
            ],
            'window_days' => 365,
        ],

        'remarketing' => [
            'default_days' => 7,
            'allowed_days' => [7, 15, 30, 60, 90],
            'hide_after_opened_days' => 3,
            'hide_after_contacted_days' => 14,
            'hide_after_dismissed_days' => 90,
            'page_size' => 50,
            'attempt_window_days' => 30,
            'max_attempts' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Calendar sync window
    |--------------------------------------------------------------------------
    |
    | Only bookings inside this window are mirrored to Google. Older history
    | stays in the CRM and is not pushed to the Artist's personal calendar.
    |
    */
    'google_calendar_past_days' => 30,
    'google_calendar_future_days' => 730,

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
    | Placement and body-zone labels
    |--------------------------------------------------------------------------
    |
    | Projects store canonical keys (used by price_book); the UI shows accents
    | and the artist's language.
    |
    */

    'placement_labels' => [
        'braco' => 'Braço',
        'braco_externo' => 'Braço externo',
        'braco_interno' => 'Braço interno',
        'antebraco' => 'Antebraço',
        'costas' => 'Costas',
        'peito' => 'Peito',
        'perna' => 'Perna',
        'coxa' => 'Coxa',
        'panturrilha' => 'Panturrilha',
        'tornozelo' => 'Tornozelo',
        'pescoco' => 'Pescoço',
        'costela' => 'Costela',
        'barriga' => 'Barriga',
        'ombro' => 'Ombro',
        'pulso' => 'Pulso',
        'dedo' => 'Dedo',
        'dedos' => 'Dedos',
        'mao' => 'Mão',
        'pe' => 'Pé',
        'rosto' => 'Rosto',
        'partes_intimas' => 'Partes íntimas',
    ],

    'body_zone_labels' => [
        'pequeno' => 'Pequeno',
        'medio' => 'Médio',
        'grande' => 'Grande',
        'fechamento' => 'Fechamento',
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit event labels
    |--------------------------------------------------------------------------
    |
    | `events.type` stays machine-readable; the lead timeline shows Portuguese.
    |
    */

    'event_labels' => [
        'project_created' => 'Projeto criado',
        'project_updated' => 'Dados do projeto atualizados',
        'stage_changed' => 'Etapa alterada',
        'quote_sent' => 'Orçamento enviado',
        'promotion_quote_sent' => 'Orçamento promocional enviado',
        'quote_accepted' => 'Orçamento aceito',
        'deposit_requested' => 'Sinal solicitado',
        'deposit_confirmed' => 'Sinal confirmado',
        'booking_created' => 'Agendamento criado',
        'booking_rescheduled' => 'Agendamento remarcado',
        'booking_cancelled' => 'Agendamento cancelado',
        'time_blocked' => 'Horário bloqueado',
        'promotion_activated' => 'Promoção ativada',
        'promotion_paused' => 'Promoção pausada',
        'promotion_ended' => 'Promoção encerrada',
        'handoff_triggered' => 'Encaminhado ao artista',
        'agent_blocked' => 'Agente silenciado (handoff)',
        'agent_unblocked' => 'Agente reativado',
        'lead_anonymized' => 'Dados anonimizados (LGPD)',
        'tattoo_completed' => 'Tattoo concluída',
        'remarketing_touch' => 'Contato de remarketing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tattoo styles — suggestions for the Artist's style lists
    |--------------------------------------------------------------------------
    |
    | Only a datalist hint in the CRM UI: the Artist can type any style, and
    | the saved values are free text. `specialties` (what they do) and
    | `excluded_styles` (what they do not do) are consumed by the SDR agent.
    |
    */

    'tattoo_styles' => [
        'realismo',
        'micro realismo',
        'blackwork',
        'fine line',
        'old school',
        'neo traditional',
        'new school',
        'aquarela',
        'oriental',
        'tribal',
        'maori',
        'geometria',
        'pontilhismo',
        'lettering',
        'floral',
        'minimalista',
        'trash polka',
        'chicano',
        'cartoon',
        'anime',
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

    /*
    |--------------------------------------------------------------------------
    | Internal sales CRM
    |--------------------------------------------------------------------------
    |
    | Bearer token used by the LP calculator (and the diagnostic form) to push
    | new Artist prospects into the internal pipeline.
    |
    */
    'sales_ingest_token' => env('SALES_INGEST_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Notion pipeline sync
    |--------------------------------------------------------------------------
    |
    | The n8n workflow reuses its Notion credential, normalizes each pipeline
    | page and POSTs it to the CRM. The token falls back to the sales ingest
    | token so a single server-to-server secret can be used until Notion sync
    | gets its own rotation.
    |
    */
    'notion_sync_token' => env('NOTION_SYNC_TOKEN', env('SALES_INGEST_TOKEN')),

    /*
    |--------------------------------------------------------------------------
    | Admin alerts
    |--------------------------------------------------------------------------
    |
    | Where operational problems go: inbound messages the agent deliberately
    | did not answer (paused artist, unknown session) and any other drop that
    | must not be silent. Defaults to the transactional sender.
    |
    */

    'admin_email' => env('VAIF_ADMIN_EMAIL', env('MAIL_FROM_ADDRESS', 'contato@vaif.com.br')),

];
