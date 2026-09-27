<?php

declare(strict_types=1);

/**
 * FAQ items. Reads $merged_faqs; sets $faq_items_html.
 * Collapsed answers get aria-hidden so screen readers skip them.
 */
$faq_items_html = '';
$first = true;
$faq_index = 0;
foreach ($merged_faqs as $faq_item) {
    $faq_index++;
    $activeClass = $first ? ' active' : '';
    $expanded = $first ? 'true' : 'false';
    $hidden = $first ? 'false' : 'true';
    $icon = $first ? '−' : '+';
    $answer_id = "faq-answer-{$faq_index}";
    $question_h = h((string) ($faq_item['question'] ?? ''));
    $answer_h = h((string) ($faq_item['answer'] ?? ''));
    $faq_items_html .= <<<HTML
            <div class="faq-item{$activeClass}">
                <button type="button" class="faq-question" aria-expanded="{$expanded}" aria-controls="{$answer_id}">
                    <span>{$question_h}</span>
                    <span class="faq-icon" aria-hidden="true">{$icon}</span>
                </button>
                <div class="faq-answer" id="{$answer_id}" aria-hidden="{$hidden}">
                    <div class="faq-answer-inner">
                        <p>{$answer_h}</p>
                    </div>
                </div>
            </div>

HTML;
    $first = false;
}
