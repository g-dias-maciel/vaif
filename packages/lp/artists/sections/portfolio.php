<?php

declare(strict_types=1);

/**
 * Portfolio grid (clickable items open the lightbox).
 * Sets $portfolio_section_html. Requires render_section_header().
 */
$portfolio_html = '';
foreach ($artist['portfolio'] ?? [] as $item) {
    if (empty($item['src'])) {
        continue;
    }
    $src = h(image_url($item['src'], $slug));
    $alt = h($item['alt'] ?? 'Tatuagem do portfólio');
    $portfolio_html .= <<<HTML
            <button type="button" class="portfolio-item" data-full="{$src}" data-alt="{$alt}" aria-label="Ampliar imagem: {$alt}">
                <img src="{$src}" alt="{$alt}" loading="lazy">
            </button>

HTML;
}

$portfolio_section_html = '';
if ($show_portfolio) {
    $header = render_section_header('Portfólio', 'Trabalhos', 'recentes', 'tattoo');
    $portfolio_section_html = <<<HTML
    <!-- ═══ SECTION 2: PORTFOLIO ═══ -->
    <section id="portfolio" class="artist-section">
        <div class="container">
{$header}
        </div>
        <div class="portfolio-grid">
{$portfolio_html}        </div>
    </section>

HTML;
}
