<?php

declare(strict_types=1);

/**
 * Location + map section. Sets $location_html. Requires render_section_header().
 */
$location_html = '';
if ($show_location) {
    $loc = $artist['location'];
    $studio_name = h($loc['studio_name'] ?? "{$display_name} Tattoo");
    $street = h($loc['street'] ?? '');
    $neighborhood = h($loc['neighborhood'] ?? '');
    $city_state = h(($loc['city'] ?? '') . ' — ' . ($loc['state'] ?? ''));
    $zip = h($loc['zip'] ?? '');
    $maps_url = h($loc['maps_embed_url'] ?? '');

    $address_html = "{$street}<br>\n                    {$neighborhood}, {$city_state}<br>\n                    CEP: {$zip}";

    $header = render_section_header('Localização', 'Onde', 'me encontrar');
    $location_html = <<<HTML
    <section id="location" class="artist-section">
        <div class="container">
{$header}
        </div>
        <div class="location-grid">
            <div class="location-details">
                <h3>{$studio_name}</h3>
                <address>
                    {$address_html}
                </address>
                <div class="diamond-divider">
                    <span class="line"></span><span class="diamond"></span><span class="line"></span>
                </div>
                <div class="location-info">
                    <div class="location-info-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Seg a Sáb: 10h às 19h (com hora marcada)</span>
                    </div>
                    <div class="location-info-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span>Atendimento exclusivo com horário agendado</span>
                    </div>
                </div>
            </div>
            <div class="map-frame">
                <iframe
                    title="Mapa do estúdio {$studio_name}"
                    src="{$maps_url}"
                    allowfullscreen=""
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade">
                </iframe>
            </div>
        </div>
    </section>

HTML;
}
