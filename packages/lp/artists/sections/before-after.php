<?php

declare(strict_types=1);

/**
 * Cover-up "antes e depois" comparison block.
 * Sets $before_after_section_html. Requires render_section_header().
 */
$before_after_section_html = '';
if ($show_before_after) {
    $ba = $artist['before_after'];
    $ba_heading = $ba['heading'] ?? 'Antes e';
    $ba_highlight = $ba['heading_highlight'] ?? 'depois';

    $ba_description_html = '';
    if (!empty($ba['description'])) {
        $ba_description_html = '<p class="before-after-description">' . h($ba['description']) . '</p>';
    }

    $ba_items_html = '';
    $ba_index = 0;
    foreach ($ba['items'] as $item) {
        if (empty($item['before']) || empty($item['after'])) {
            continue;
        }
        $ba_index++;
        $before_src = h(image_url($item['before'], $slug));
        $after_src = h(image_url($item['after'], $slug));

        // Frame proportions — bare `cover` crops mismatched photos, so default
        // to `contain` (whole image visible) with a configurable aspect ratio.
        $item_aspect = normalize_ba_aspect($item['aspect'] ?? ($ba['aspect'] ?? ''));
        $item_fit = normalize_ba_fit($item['fit'] ?? ($ba['fit'] ?? ''));
        $fit_class = $item_fit === 'cover' ? ' ba-viewport--cover' : '';

        $ba_title = h($item['title'] ?? "Cobertura {$ba_index}");
        $alt_before = h($item['alt_before'] ?? "Antes: {$ba_title}");
        $alt_after = h($item['alt_after'] ?? "Depois: {$ba_title}");
        $slider_label = h("Arrastar para comparar antes e depois: {$ba_title}");

        $ba_caption_html = '';
        if (!empty($item['caption'])) {
            $ba_caption_html = '<p class="ba-caption">' . h($item['caption']) . '</p>';
        }

        $ba_items_html .= <<<HTML
            <figure class="ba-item">
                <div class="ba-viewport{$fit_class}" style="aspect-ratio: {$item_aspect};">
                    <img class="ba-img ba-img--before" src="{$before_src}" alt="{$alt_before}" loading="lazy" draggable="false">
                    <img class="ba-img ba-img--after" src="{$after_src}" alt="{$alt_after}" loading="lazy" draggable="false">
                    <span class="ba-badge ba-badge--before" aria-hidden="true">Antes</span>
                    <span class="ba-badge ba-badge--after" aria-hidden="true">Depois</span>
                    <span class="ba-handle" aria-hidden="true">
                        <span class="ba-handle-line"></span>
                        <span class="ba-handle-grip">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 6 4 12 9 18"/><polyline points="15 6 20 12 15 18"/></svg>
                        </span>
                    </span>
                    <input type="range" class="ba-range" min="0" max="100" value="50" step="0.1" aria-label="{$slider_label}">
                </div>
                <figcaption class="ba-caption-wrap">
                    <h3 class="ba-title">{$ba_title}</h3>
                    {$ba_caption_html}
                </figcaption>
            </figure>

HTML;
    }

    if ($ba_items_html !== '') {
        $header = render_section_header(
            $ba['tag'] ?? 'Destaque',
            $ba_heading,
            $ba_highlight,
            'diamond',
            $ba_description_html
        );
        $before_after_section_html = <<<HTML
    <!-- ═══ SECTION: BEFORE / AFTER ═══ -->
    <section id="before_after" class="artist-section">
        <div class="container">
{$header}
        </div>
        <div class="before-after-grid">
{$ba_items_html}        </div>
    </section>

HTML;
    }
}
