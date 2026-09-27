<?php

declare(strict_types=1);

/**
 * Shared section header: eyebrow tag, heading with a highlighted word, and a
 * divider. `$divider` is 'diamond' (default) or 'tattoo' (the needle motif).
 * `$descriptionHtml` is inserted untrusted-free: callers must pass escaped HTML.
 */
function render_section_header(
    string $tag,
    string $heading,
    string $highlight,
    string $divider = 'diamond',
    string $descriptionHtml = ''
): string {
    $t = h($tag);
    $head = h($heading);
    $hl = h($highlight);

    if ($divider === 'tattoo') {
        $dividerHtml = <<<HTML
                <div class="tattoo-divider">
                    <span class="dot"></span>
                    <span class="needle"></span>
                    <span class="dot"></span>
                    <span class="line"></span>
                    <span class="diamond" style="width:6px;height:6px;background:var(--gold);transform:rotate(45deg);flex-shrink:0;"></span>
                    <span class="line"></span>
                    <span class="dot"></span>
                    <span class="needle"></span>
                    <span class="dot"></span>
                </div>
HTML;
    } else {
        $dividerHtml = <<<HTML
                <div class="diamond-divider">
                    <span class="line"></span><span class="diamond"></span><span class="line"></span>
                </div>
HTML;
    }

    $description = $descriptionHtml !== '' ? "\n                {$descriptionHtml}" : '';

    return <<<HTML
            <div class="section-header">
                <span class="section-tag">{$t}</span>
                <h2 class="section-heading">{$head} <span>{$hl}</span></h2>
{$dividerHtml}{$description}
            </div>
HTML;
}
