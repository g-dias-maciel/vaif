<?php

declare(strict_types=1);

function parse_frontmatter(string $raw): array
{
    $raw = ltrim($raw);

    if (!str_starts_with($raw, "---\n") && $raw !== '---') {
        return [[], $raw];
    }

    $content = substr($raw, 3);
    if (strlen($content) === 0) {
        return [[], ''];
    }

    $pos = strpos($content, "\n---\n");
    $terminator = "\n---\n";

    if ($pos === false) {
        $pos = strpos($content, "\n---");
        $terminator = "\n---";
    }

    $frontmatter = [];

    if ($pos === false) {
        return [[], $raw];
    }

    $yamlBlock = substr($content, 0, $pos);
    $body = substr($content, $pos + strlen($terminator));

    foreach (explode("\n", $yamlBlock) as $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }

        $colonPos = strpos($line, ':');
        if ($colonPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $colonPos));
        $value = trim(substr($line, $colonPos + 1));

        if ($key === '') {
            continue;
        }

        $frontmatter[$key] = $value;
    }

    return [$frontmatter, trim($body)];
}
