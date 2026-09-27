<?php

declare(strict_types=1);

/**
 * Hero background media and the two-tone name. Sets $hero_bg_html and
 * $hero_name_html. Uses $hero_img_url / $artist['hero_video'] / $display_name.
 */
$hero_bg_html = '';
if (!empty($artist['hero_video'])) {
    $hero_video_url_h = h($artist['hero_video']);
    $hero_img_url_h = h($hero_img_url);
    $hero_bg_html = <<<HTML
        <video autoplay muted loop playsinline poster="{$hero_img_url_h}" aria-hidden="true" tabindex="-1">
            <source src="{$hero_video_url_h}" type="video/mp4">
        </video>
HTML;
} else {
    $hero_img_url_h = h($hero_img_url);
    $hero_bg_html = "<img src=\"{$hero_img_url_h}\" alt=\"\" aria-hidden=\"true\" loading=\"eager\">";
}

$name_parts = explode(' ', trim($display_name));
if (count($name_parts) > 1) {
    $last = array_pop($name_parts);
    $hero_name_html = h(implode(' ', $name_parts)) . ' <span>' . h($last) . '</span>';
} else {
    $hero_name_html = h($display_name);
}
