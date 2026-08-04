<?php

declare(strict_types=1);

function render_markdown(string $text): string
{
    // 1. Extract code fences with placeholders
    $fences = [];
    $text = preg_replace_callback(
        '/```\n?(.*?)\n?```/s',
        function (array $m) use (&$fences): string {
            $key = '%%FENCE' . count($fences) . '%%';
            $fences[$key] = '<pre><code>' . htmlspecialchars($m[1]) . '</code></pre>';
            return "\n" . $key . "\n";
        },
        $text
    );

    $lines = explode("\n", $text);
    $output = [];
    $i = 0;
    $len = count($lines);

    while ($i < $len) {
        $line = $lines[$i];

        // 2. Headings
        if (preg_match('/^### (.+)$/', $line, $m)) {
            $output[] = '<h3>' . $m[1] . '</h3>';
            $i++;
            continue;
        }
        if (preg_match('/^## (.+)$/', $line, $m)) {
            $output[] = '<h2>' . $m[1] . '</h2>';
            $i++;
            continue;
        }
        if (preg_match('/^# (.+)$/', $line, $m)) {
            $output[] = '<h1>' . $m[1] . '</h1>';
            $i++;
            continue;
        }

        // 3. Horizontal rules (standalone ---, ***, ___)
        if (preg_match('/^(---|\*\*\*|___)$/', $line)) {
            $output[] = '<hr>';
            $i++;
            continue;
        }

        // 4. Blockquotes
        if (preg_match('/^> (.+)$/', $line, $m)) {
            $quoteLines = [];
            while ($i < $len && preg_match('/^> (.+)$/', $lines[$i], $m)) {
                $quoteLines[] = $m[1];
                $i++;
            }
            $output[] = '<blockquote><p>' . implode(' ', $quoteLines) . '</p></blockquote>';
            continue;
        }

        // 5. Unordered lists
        if (preg_match('/^[-*] (.+)$/', $line, $m)) {
            $listItems = [];
            while ($i < $len && preg_match('/^[-*] (.+)$/', $lines[$i], $m)) {
                $listItems[] = '<li>' . $m[1] . '</li>';
                $i++;
            }
            $output[] = '<ul>' . implode('', $listItems) . '</ul>';
            continue;
        }

        // 6. Ordered lists
        if (preg_match('/^(\d+)\. (.+)$/', $line, $m)) {
            $listItems = [];
            while ($i < $len && preg_match('/^(\d+)\. (.+)$/', $lines[$i], $m)) {
                $listItems[] = '<li>' . $m[2] . '</li>';
                $i++;
            }
            $output[] = '<ol>' . implode('', $listItems) . '</ol>';
            continue;
        }

        // Blank line -> collect paragraph
        if ($line === '') {
            $i++;
            continue;
        }

        // Collect paragraph lines
        $paraLines = [];
        while ($i < $len && $lines[$i] !== '' && !preg_match('/^(#{1,3} |>\s|[-*]\s|\d+\.\s|---|\*\*\*|___)$/', $lines[$i])) {
            $paraLines[] = $lines[$i];
            $i++;
        }

        if (count($paraLines) > 0) {
            $para = implode(' ', $paraLines);
            $para = process_inline($para);
            $output[] = '<p>' . $para . '</p>';
        }
    }

    $html = implode('', $output);

    // 13. Reinsert code fences
    foreach ($fences as $key => $value) {
        $html = str_replace($key, $value, $html);
    }

    return $html;
}

function process_inline(string $text): string
{
    // 7. Images
    $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1">', $text);

    // 8. Links
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);

    // 9. Bold
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

    // 10. Italic
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

    // 11. Inline code
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    return $text;
}
