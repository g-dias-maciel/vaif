<?php

declare(strict_types=1);

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

    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return jsonLdScript($json);
    } catch (JsonException $e) {
        return '';
    }
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

    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return jsonLdScript($json);
    } catch (JsonException $e) {
        return '';
    }
}

function generateLocalBusinessJsonLd(array $artist): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => $artist['name'] ?? '',
        'url' => $artist['url'] ?? '',
        'image' => $artist['image'] ?? '',
    ];

    if (!empty($artist['address'])) {
        $data['address'] = [
            '@type' => 'PostalAddress',
            'streetAddress' => $artist['address']['street'] ?? '',
            'addressLocality' => $artist['address']['city'] ?? '',
            'addressRegion' => $artist['address']['state'] ?? '',
            'postalCode' => $artist['address']['zip'] ?? '',
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

    if (!empty($artist['openingHoursSpecification'])) {
        $data['openingHoursSpecification'] = $artist['openingHoursSpecification'];
    }

    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return jsonLdScript($json);
    } catch (JsonException $e) {
        return '';
    }
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

    if (!empty($artist['sameAs'])) {
        $data['sameAs'] = $artist['sameAs'];
    }

    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return jsonLdScript($json);
    } catch (JsonException $e) {
        return '';
    }
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

    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return jsonLdScript($json);
    } catch (JsonException $e) {
        return '';
    }
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

    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return jsonLdScript($json);
    } catch (JsonException $e) {
        return '';
    }
}
