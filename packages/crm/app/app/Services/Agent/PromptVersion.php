<?php

namespace App\Services\Agent;

/**
 * Fingerprint of the prompt files, stored on every draft so a quality change
 * can be attributed to a prompt change.
 */
class PromptVersion
{
    public static function current(): string
    {
        $files = [
            resource_path('prompts/beatriz-system.md'),
            resource_path('prompts/preflight-classify.md'),
            resource_path('prompts/extract-claims.md'),
            resource_path('prompts/repair-reply.md'),
        ];

        $data = '';

        foreach ($files as $file) {
            $data .= is_file($file) ? (string) file_get_contents($file) : '';
        }

        return substr(sha1($data), 0, 12);
    }
}
