<?php

declare(strict_types=1);

/**
 * About section: bio, specialty tags, config-driven stats and photo.
 * Sets $about_stats_html and $about_html. Requires render_section_header().
 */
$about_stats_html = '';
foreach ($stats_items as $stat) {
    $stat_value = h($stat['value']);
    $stat_label = h($stat['label']);
    $about_stats_html .= <<<HTML
                    <div class="about-stat">
                        <strong>{$stat_value}</strong>
                        <span>{$stat_label}</span>
                    </div>

HTML;
}

$about_html = '';
if ($show_about) {
    $bio_content = $artist['bio'];
    $about_name = h($display_name);
    $header = render_section_header('Sobre o Artista', 'Conheça', $display_name);
    $about_html = <<<HTML
    <!-- ═══ SECTION 3: ABOUT ═══ -->
    <section id="about" class="artist-section">
        <div class="container">
{$header}
        </div>
        <div class="about-grid">
            <div class="about-photo-wrap">
                <img
                    src="{$about_img_url}"
                    alt="{$about_name} no estúdio"
                    class="about-photo"
                    loading="lazy"
                >
            </div>
            <div class="about-text">
                {$bio_content}
                <div class="about-specialties">
{$specialty_tags_html}                </div>
                <div class="about-stats">
{$about_stats_html}                </div>
            </div>
        </div>
    </section>

HTML;
}
