<?php

declare(strict_types=1);

/**
 * Testimonials cards + section. Sets $testimonials_section_html.
 * Requires render_section_header().
 */
$testimonials_html = '';
foreach ($artist['testimonials'] ?? [] as $t) {
    $stars = '';
    $rating = (int) ($t['rating'] ?? 5);
    for ($i = 0; $i < $rating; $i++) {
        $stars .= '★';
    }

    $name = h($t['name'] ?? 'Cliente');
    $initials = '';
    $words = explode(' ', $name);
    foreach ($words as $w) {
        $initials .= mb_substr($w, 0, 1);
    }
    $initials = mb_strtoupper(mb_substr($initials, 0, 2));
    $meta = h($t['meta'] ?? 'Cliente');
    $text = h($t['text'] ?? '');

    $avatar_html = '';
    if (!empty($t['photo'])) {
        $photo_url = h(image_url($t['photo'], $slug));
        $avatar_html = "<img src=\"{$photo_url}\" alt=\"{$name}\" class=\"testimonial-avatar-img\" style=\"width:36px;height:36px;border-radius:50%;object-fit:cover;\">";
    } else {
        $avatar_html = "<div class=\"testimonial-avatar\" aria-hidden=\"true\">{$initials}</div>";
    }

    $testimonials_html .= <<<HTML
            <div class="testimonial-card">
                <div class="testimonial-stars" aria-label="{$rating} de 5 estrelas">{$stars}</div>
                <p class="testimonial-text">"{$text}"</p>
                <div class="testimonial-author">
                    {$avatar_html}
                    <div>
                        <div class="testimonial-name">{$name}</div>
                        <div class="testimonial-meta">{$meta}</div>
                    </div>
                </div>
            </div>

HTML;
}

$testimonials_section_html = '';
if ($show_testimonials) {
    $header = render_section_header('Depoimentos', 'O que meus', 'clientes dizem');
    $testimonials_section_html = <<<HTML
    <!-- ═══ SECTION 5: TESTIMONIALS ═══ -->
    <section id="testimonials" class="artist-section">
        <div class="container">
{$header}
        </div>
        <div class="testimonials-grid">
{$testimonials_html}        </div>
    </section>

HTML;
}
