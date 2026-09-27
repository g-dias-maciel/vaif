<?php

declare(strict_types=1);

/**
 * Navigation links. Reads the section visibility flags; sets $nav_links_html.
 */
$nav_links_html = '';
$nav_sections = [
    ['id' => 'portfolio',    'label' => 'Portfólio',   'show' => $show_portfolio],
    ['id' => 'before_after', 'label' => $artist['before_after']['nav_label'] ?? $specialty_label, 'show' => $show_before_after],
    ['id' => 'about',        'label' => 'Sobre',       'show' => $show_about],
    ['id' => 'testimonials', 'label' => 'Depoimentos', 'show' => $show_testimonials],
    ['id' => 'faq',          'label' => 'FAQ',         'show' => $show_faq],
    ['id' => 'location',     'label' => 'Local',       'show' => $show_location],
];

foreach ($nav_sections as $nav) {
    if ($nav['show']) {
        $nav_links_html .= "                <li><a href=\"#{$nav['id']}\" class=\"nav-link\">" . h($nav['label']) . "</a></li>\n";
    }
}
