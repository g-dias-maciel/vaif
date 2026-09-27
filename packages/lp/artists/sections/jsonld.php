<?php

declare(strict_types=1);

/**
 * JSON-LD structured data (Person + TattooParlor + FAQPage + BreadcrumbList).
 * Reads the computed artist values; sets $jsonld_html.
 * Requires components/SeoHelpers.php to be loaded first.
 */
$person_seo = [
    'name' => $display_name,
    'image' => $og_image,
    'url' => 'https://vaif.com.br/artists/' . $slug,
    'jobTitle' => "Tatuador {$primary_style}",
    'description' => $hero_subheadline,
    'knowsAbout' => $style_list,
];
if ($instagram_url) {
    $person_seo['sameAs'] = [$instagram_url];
}

$jsonld_html = generatePersonJsonLd($person_seo);

if ($show_location) {
    $loc = $artist['location'];

    $reviews = [];
    foreach ($artist['testimonials'] ?? [] as $t) {
        if (empty($t['name']) || empty($t['text'])) {
            continue;
        }
        $reviews[] = [
            '@type' => 'Review',
            'author' => ['@type' => 'Person', 'name' => $t['name']],
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => (string) ($t['rating'] ?? 5),
                'bestRating' => '5',
            ],
            'reviewBody' => $t['text'],
        ];
    }

    $biz_seo = [
        'type' => 'TattooParlor',
        'name' => $loc['studio_name'] ?? "{$display_name} Tattoo",
        'url' => 'https://vaif.com.br/artists/' . $slug,
        'image' => $og_image,
        'description' => "{$display_name} — tatuador em {$city}. Especialista em {$style_summary}.",
        'address' => [
            'street' => $loc['street'] ?? '',
            'city' => $city,
            'state' => $loc['state'] ?? '',
            'zip' => $loc['zip'] ?? '',
            'country' => 'BR',
        ],
        'telephone' => '+' . $whatsapp_number,
        'priceRange' => $artist['price_range'] ?? 'R$ 1.200+',
        'areaServed' => $city,
        'knowsAbout' => $style_list,
    ];
    if ($instagram_url) {
        $biz_seo['sameAs'] = [$instagram_url];
    }
    if (!empty($loc['lat']) && !empty($loc['lng'])) {
        $biz_seo['geo'] = ['latitude' => $loc['lat'], 'longitude' => $loc['lng']];
    }
    if (!empty($artist['opening_hours'])) {
        $biz_seo['openingHours'] = $artist['opening_hours'];
    }
    if ($rating_value !== null) {
        $aggregate_rating = ['ratingValue' => $rating_value];
        if ($testimonial_ratings !== []) {
            $aggregate_rating['reviewCount'] = count($testimonial_ratings);
        }
        $biz_seo['aggregateRating'] = $aggregate_rating;
    }
    if ($reviews !== []) {
        $biz_seo['review'] = $reviews;
    }

    $jsonld_html .= "\n" . generateLocalBusinessJsonLd($biz_seo);
}

$jsonld_html .= "\n" . generateFaqPageJsonLd($merged_faqs);

$jsonld_html .= "\n" . generateBreadcrumbListJsonLd([
    ['name' => 'Home', 'url' => 'https://vaif.com.br/'],
    ['name' => 'Artistas', 'url' => 'https://vaif.com.br/artists/'],
    ['name' => $display_name, 'url' => 'https://vaif.com.br/artists/' . $slug],
]);
