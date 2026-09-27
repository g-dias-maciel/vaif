<?php

declare(strict_types=1);

/**
 * Specialty tags derived from the artist's style list; sets $specialty_tags_html.
 */
$specialty_tags_html = '';
foreach ($style_list as $style) {
    $hstyle = h(trim($style));
    $specialty_tags_html .= <<<HTML
                    <span class="specialty-tag">{$hstyle}</span>

HTML;
}
