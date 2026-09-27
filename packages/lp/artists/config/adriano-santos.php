<?php

declare(strict_types=1);

return [
    'slug'             => 'adriano-santos',
    'display_name'     => 'Adriano Santos',
    'instagram_handle' => 'studiomadri_tattoo',
    'style'            => 'Realismo Preto & Cinza, Coberturas, Tribal, Fine Line, Oriental',
    // Especialidade destacada — habilita blocos exclusivos (ex.: antes/depois de coberturas).
    'specialty'        => 'coberturas',
    'profile_photo'    => 'https://images.unsplash.com/photo-1598371839696-5c5bb00bdc28?w=1200&q=85',
    // Fotos locais em WebP (artists/adriano-santos/media/). Enquanto o arquivo não existir,
    // a página usa profile_photo como fallback.
    'hero_photo'       => 'adriano-hero.webp',
    'about_photo'      => 'adriano-bio.webp',
    'whatsapp_number'  => '553599968249',
    'whatsapp_message' => 'Olá, Adriano! Vim pelo seu site. Quero tatuar: (descreva a ideia) | Região do corpo: | Tenho referências:',
    'price_range'      => 'R$ 1.200+',

    'hero_headline'    => 'Adriano Santos | Especialista em Coberturas | Poços de Caldas',
    'hero_subheadline' => 'Transformo memórias em arte eterna na pele. Especialista em coberturas impossíveis há mais de 20 anos, com centenas de clientes satisfeitos em Poços de Caldas e região.',

    'portfolio' => [
        ['src' => 'portfolio-01.webp', 'alt' => 'Tatuagem em preto e cinza no antebraço'],
        ['src' => 'portfolio-02.webp', 'alt' => 'Tatuagem blackwork no peito com letras ornamentadas'],
        ['src' => 'portfolio-03.webp', 'alt' => 'Tatuagem de tigre nas costas em preto e cinza'],
        ['src' => 'portfolio-04.webp', 'alt' => 'Tatuagem de guerreiro viking no braço'],
        ['src' => 'portfolio-05.webp', 'alt' => 'Tatuagem de guerreiro espartano no braço com moldura grega'],
        ['src' => 'portfolio-06.webp', 'alt' => 'Tatuagem em preto e cinza de mulher alada com adorno no braço'],
    ],

    // Bloco "Antes e Depois" — opcional, disponível para qualquer artista.
    // Aponta para arquivos em artists/adriano-santos/media/ (ou URLs absolutas).
    'before_after' => [
        'tag'               => 'Especialidade',
        'heading'           => 'Coberturas',
        'heading_highlight' => 'antes e depois',
        'nav_label'         => 'Coberturas',
        'description'       => 'Uma seleção de coberturas feitas no Studio Madri Tattoo. Arraste a barra dourada para revelar como cada tatuagem antiga ganhou uma nova história.',

        'items' => [
            [
                'before'  => 'cobertura-01-antes.webp',
                'after'   => 'cobertura-01-depois.webp',
                'title'   => 'Leão realista sobre tatuagem antiga',
                'caption' => 'Traço tribal e chamas já desbotados no braço, cobertos por um leão em realismo preto e cinza que devolve definição e contraste à região.',
            ],
            [
                'before'  => 'cobertura-02-antes.webp',
                'after'   => 'cobertura-02-depois.webp',
                'title'   => 'Guerreiro espartano com moldura grega',
                'caption' => 'Blackwork tribal antigo no ombro transformado em um guerreiro espartano em preto e cinza, com elmo de pluma vermelha e moldura em chave grega.',
            ],
            [
                'before'  => 'cobertura-03-antes.webp',
                'after'   => 'cobertura-03-depois.webp',
                'title'   => 'Rosa e mandala em preto e cinza',
                'caption' => 'Rosa antiga com a cor já apagada, coberta por uma composição de rosa e mandala em preto e cinza, finalizada com pontilhismo.',
            ],
        ],
    ],

    'bio' => "<p><strong style=\"color:#D4B04C;\">Adriano Silva dos Santos</strong>, aos 40 anos, é tatuador e fundador do <strong style=\"color:#D4B04C;\">Studio Madri Tattoo</strong>, localizado em Poços de Caldas, Minas Gerais.</p>\n<p>Seu primeiro contato com a tatuagem aconteceu em <strong style=\"color:#D4B04C;\">2005, na cidade de São Paulo</strong>, onde residiu por 14 anos e construiu sua trajetória profissional. Durante esse período, aprimorou suas habilidades e trabalhou com diversos estilos de tatuagem, desenvolvendo uma experiência ampla e uma técnica cada vez mais precisa.</p>\n<p>Há cerca de seis anos, Adriano mudou-se para Minas Gerais, onde deu continuidade à sua carreira e fundou o Studio Madri Tattoo. Atualmente, seu principal foco está nas tatuagens de <strong style=\"color:#D4B04C;\">cobertura</strong> e no <strong style=\"color:#D4B04C;\">realismo preto e cinza</strong>, embora continue trabalhando com diferentes estilos de tatuagem.</p>\n<p>Ao longo dos anos, desenvolveu uma <strong style=\"color:#D4B04C;\">técnica própria</strong> para realizar coberturas de maneira mais rápida, eficiente e com resultados cuidadosamente planejados. Esse trabalho fez com que clientes de Poços de Caldas e de outras localidades procurassem seu estúdio em busca de sua experiência, especialmente para transformar ou corrigir tatuagens antigas.</p>\n<p>Casado com <strong style=\"color:#D4B04C;\">Michelle</strong> e pai da <strong style=\"color:#D4B04C;\">Lis</strong>, Adriano concilia sua vida familiar com a dedicação à arte da tatuagem. Hoje, segue construindo sua história em Minas Gerais, oferecendo aos seus clientes um trabalho personalizado, responsável e focado na qualidade de cada resultado.</p>",

    'cta_text' => 'Agende sua sessão pelo WhatsApp',
    'anos_de_experiencia' => '20',

    'stats' => [
        ['value' => '20+',  'label' => 'Anos de Experiência'],
        ['value' => '600+', 'label' => 'Tatuagens Realizadas'],
        ['value' => '4.95', 'label' => 'Avaliação Média'],
    ],

    'opening_hours' => [
        'days'   => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'opens'  => '10:00',
        'closes' => '19:00',
    ],

    'testimonials' => [
        [
            'name'   => 'Fabio Junior',
            'meta'   => 'Cliente desde 2026',
            'text'   => 'Profissional de alto nível e muito caprichoso! Excelente profissional. Recomendo',
            'rating' => 5,
        ],
        [
            'name'   => 'Cidineia Donizete',
            'meta'   => 'Cliente desde 2025',
            'text'   => 'Adorei! nunca tinha feito mas gostei muito.',
            'rating' => 5,
        ],
        [
            'name'   => 'Heiko Kloss',
            'meta'   => 'Cliente desde 2025',
            'text'   => 'Olha fiz uma cobertura na perna que ficou perfeita,aí fiz uma tatuagem normal no ante braço que ficou maravilhosa, os desenhos ficam muito realistas',
            'rating' => 5,
        ],
    ],

    'instagram_feed' => true,

    'instagram_posts' => [
        'https://www.instagram.com/studiomadri_tattoo/reel/Db3P0YTx7YF/',
        'https://www.instagram.com/studiomadri_tattoo/reel/DbquXOvR_hQ/',
        'https://www.instagram.com/studiomadri_tattoo/reel/DbYOSgaxo8a/',
        'https://www.instagram.com/studiomadri_tattoo/reel/Da77T20Rsgh/',
        'https://www.instagram.com/studiomadri_tattoo/reel/DayUPFlBTQk/',
    ],

    // FAQ do artista — mescla com os padrões do template (sobrescreve por pergunta).
    'faq' => [],

    'location' => [
        'street'          => 'Rua Osvaldo Cruz, 135',
        'neighborhood'    => 'Jardim Santa Rita',
        'city'            => 'Poços de Caldas',
        'state'           => 'MG',
        'zip'             => '37701-161',
        'lat'             => -21.7912641,
        'lng'             => -46.5590475,
        'maps_embed_url'  => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3704.715632835156!2d-46.5590475!3d-21.7912641!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x94b6657448c605d3%3A0x9257fadbc973e643!2sStudio%20Madri%20Tattoo%20e%20Piercing!5e0!3m2!1sen!2sde!4v1786638069915!5m2!1sen!2sde',
        'studio_name'     => 'Studio Madri Tattoo e Piercing — Jardim Santa Rita',
    ],
];
