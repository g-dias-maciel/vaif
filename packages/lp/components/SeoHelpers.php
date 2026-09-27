<?php

declare(strict_types=1);

/**
 * Encode JSON-LD safely.
 *
 * JSON_HEX_TAG escapes `<` and `>` so a config value containing `</script>`
 * can never terminate the surrounding <script> block.
 */
function encodeJsonLd(array $data): string
{
    try {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR
        );
        return jsonLdScript($json);
    } catch (JsonException $e) {
        return '';
    }
}

function jsonLdScript(string $json): string
{
    return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
}

function generateOrganizationJsonLd(): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'VAIF',
        'url' => 'https://vaif.com.br',
        'logo' => 'https://vaif.com.br/img/vaif_logo.png',
        'description' => 'Agência de Escala para Estúdios de Tatuagem',
        'sameAs' => [
            'https://instagram.com/vaifmarketing',
        ],
        'contactPoint' => [
            '@type' => 'ContactPoint',
            'email' => 'contato@vaif.com.br',
            'contactType' => 'sales',
        ],
    ];

    return encodeJsonLd($data);
}

function generateBlogPostingJsonLd(array $post): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post['title'] ?? '',
        'description' => $post['description'] ?? '',
        'datePublished' => $post['datePublished'] ?? '',
        'dateModified' => $post['dateModified'] ?? ($post['datePublished'] ?? ''),
        'url' => $post['url'] ?? '',
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'VAIF',
        ],
    ];

    if (!empty($post['author'])) {
        $data['author'] = [
            '@type' => 'Person',
            'name' => $post['author'],
        ];
    }

    if (!empty($post['image'])) {
        $data['image'] = $post['image'];
    }

    if (!empty($post['mainEntityOfPage'])) {
        $data['mainEntityOfPage'] = $post['mainEntityOfPage'];
    }

    return encodeJsonLd($data);
}

/**
 * Tattoo studio / local business schema.
 *
 * Accepts the plain keys used by the artist template (name, url, image,
 * address, telephone, priceRange, sameAs) plus the richer keys that drive
 * local + AI search: description, type, geo, openingHours, areaServed,
 * knowsAbout, aggregateRating and review.
 */
function generateLocalBusinessJsonLd(array $artist): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => $artist['type'] ?? 'LocalBusiness',
        'name' => $artist['name'] ?? '',
        'url' => $artist['url'] ?? '',
        'image' => $artist['image'] ?? '',
    ];

    if (!empty($artist['description'])) {
        $data['description'] = $artist['description'];
    }

    if (!empty($artist['address'])) {
        $data['address'] = [
            '@type' => 'PostalAddress',
            'streetAddress' => $artist['address']['street'] ?? '',
            'addressLocality' => $artist['address']['city'] ?? '',
            'addressRegion' => $artist['address']['state'] ?? '',
            'postalCode' => $artist['address']['zip'] ?? '',
            'addressCountry' => $artist['address']['country'] ?? 'BR',
        ];
    }

    if (!empty($artist['geo']['latitude']) && !empty($artist['geo']['longitude'])) {
        $data['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => $artist['geo']['latitude'],
            'longitude' => $artist['geo']['longitude'],
        ];
    }

    if (!empty($artist['telephone'])) {
        $data['telephone'] = $artist['telephone'];
    }

    if (!empty($artist['priceRange'])) {
        $data['priceRange'] = $artist['priceRange'];
    }

    if (!empty($artist['sameAs'])) {
        $data['sameAs'] = $artist['sameAs'];
    }

    if (!empty($artist['areaServed'])) {
        $data['areaServed'] = $artist['areaServed'];
    }

    if (!empty($artist['knowsAbout'])) {
        $data['knowsAbout'] = $artist['knowsAbout'];
    }

    $hours = $artist['openingHours'] ?? null;
    if (is_array($hours) && !empty($hours['days']) && !empty($hours['opens']) && !empty($hours['closes'])) {
        $data['openingHoursSpecification'] = [
            [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => array_values($hours['days']),
                'opens' => $hours['opens'],
                'closes' => $hours['closes'],
            ],
        ];
    } elseif (!empty($artist['openingHoursSpecification'])) {
        $data['openingHoursSpecification'] = $artist['openingHoursSpecification'];
    }

    if (!empty($artist['aggregateRating']['ratingValue'])) {
        $rating = [
            '@type' => 'AggregateRating',
            'ratingValue' => (string) $artist['aggregateRating']['ratingValue'],
            'bestRating' => '5',
        ];
        if (!empty($artist['aggregateRating']['reviewCount'])) {
            $rating['reviewCount'] = (string) $artist['aggregateRating']['reviewCount'];
        }
        $data['aggregateRating'] = $rating;
    }

    if (!empty($artist['review']) && is_array($artist['review'])) {
        $data['review'] = $artist['review'];
    }

    return encodeJsonLd($data);
}

function generatePersonJsonLd(array $artist): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $artist['name'] ?? '',
        'jobTitle' => $artist['jobTitle'] ?? 'Tatuador',
        'image' => $artist['image'] ?? '',
    ];

    if (!empty($artist['description'])) {
        $data['description'] = $artist['description'];
    }

    if (!empty($artist['url'])) {
        $data['url'] = $artist['url'];
    }

    if (!empty($artist['sameAs'])) {
        $data['sameAs'] = $artist['sameAs'];
    }

    if (!empty($artist['knowsAbout'])) {
        $data['knowsAbout'] = $artist['knowsAbout'];
    }

    if (!empty($artist['address'])) {
        $data['address'] = [
            '@type' => 'PostalAddress',
            'addressLocality' => $artist['address']['city'] ?? '',
            'addressRegion' => $artist['address']['state'] ?? '',
            'addressCountry' => $artist['address']['country'] ?? 'BR',
        ];
    }

    if (!empty($artist['worksFor'])) {
        $data['worksFor'] = [
            '@type' => 'TattooParlor',
            'name' => $artist['worksFor'],
        ];
    }

    return encodeJsonLd($data);
}

function generateFaqPageJsonLd(array $faqItems): string
{
    $questions = [];
    foreach ($faqItems as $item) {
        $questions[] = [
            '@type' => 'Question',
            'name' => $item['question'] ?? '',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $item['answer'] ?? '',
            ],
        ];
    }

    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $questions,
    ];

    return encodeJsonLd($data);
}

function generateBreadcrumbListJsonLd(array $crumbs): string
{
    $items = [];
    $position = 1;
    foreach ($crumbs as $crumb) {
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $crumb['name'] ?? '',
            'item' => $crumb['url'] ?? '',
        ];
        $position++;
    }

    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $items,
    ];

    return encodeJsonLd($data);
}
