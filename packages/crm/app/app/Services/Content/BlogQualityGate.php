<?php

namespace App\Services\Content;

use Illuminate\Support\Str;

/**
 * Deterministic checks that run before the AI copilot review. They catch the
 * cheap failures (wrong length, missing FAQs, duplicate slug, obvious hype)
 * without paying for another model call.
 */
class BlogQualityGate
{
    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $facts
     * @param  array<int, string>  $existingSlugs
     * @param  array<int, string>  $existingTitles
     * @return array{pass: bool, errors: array<int, string>, warnings: array<int, string>, metrics: array<string, mixed>}
     */
    public function check(array $draft, array $facts, array $existingSlugs = [], array $existingTitles = []): array
    {
        $errors = [];
        $warnings = [];

        $title = trim((string) ($draft['title'] ?? ''));
        $slug = trim((string) ($draft['slug'] ?? ''));
        $meta = trim((string) ($draft['meta_description'] ?? ''));
        $body = trim((string) ($draft['body_md'] ?? ''));
        $faq = (array) ($draft['faq'] ?? []);

        $plain = trim(strip_tags($body));
        $wordCount = count(preg_split('/\s+/u', $plain) ?: []);
        $h2Count = preg_match_all('/^##\s+/mu', $body) ?: 0;
        $internalLinks = [];
        if (preg_match_all('/\[[^\]]+\]\((\/[^)\s]+)\)/u', $body, $matches)) {
            $internalLinks = array_values(array_unique($matches[1]));
        }

        if ($title === '') {
            $errors[] = 'Título ausente.';
        } elseif (mb_strlen($title) < 30 || mb_strlen($title) > 80) {
            $warnings[] = 'O título tem '.mb_strlen($title).' caracteres; o ideal é 45–65.';
        }

        if ($slug === '' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $errors[] = 'Slug inválido. Use letras minúsculas, números e hífens.';
        } elseif (in_array($slug, $existingSlugs, true)) {
            $errors[] = "O slug '{$slug}' já está publicado.";
        }

        if ($meta === '') {
            $errors[] = 'Meta description ausente.';
        } elseif (mb_strlen($meta) < 100 || mb_strlen($meta) > 180) {
            $warnings[] = 'A meta description tem '.mb_strlen($meta).' caracteres; o ideal é 120–160.';
        }

        if ($wordCount < (int) config('content.min_words', 900)) {
            $errors[] = "O artigo tem {$wordCount} palavras; o mínimo é ".(int) config('content.min_words', 900).'.';
        } elseif ($wordCount > (int) config('content.max_words', 1800)) {
            $warnings[] = "O artigo tem {$wordCount} palavras; o máximo recomendado é ".(int) config('content.max_words', 1800).'.';
        }

        if ($h2Count < 3) {
            $errors[] = "O artigo tem {$h2Count} seções H2; o mínimo é 3.";
        }

        if (count($faq) < (int) config('content.min_faqs', 4)) {
            $errors[] = 'O artigo tem '.count($faq).' FAQs; o mínimo é '.(int) config('content.min_faqs', 4).'.';
        }

        if (count($internalLinks) < (int) config('content.min_internal_links', 2)) {
            $errors[] = 'O artigo tem '.count($internalLinks).' links internos; o mínimo é '.(int) config('content.min_internal_links', 2).'.';
        }

        $artistSlug = (string) ($facts['slug'] ?? '');
        if ($artistSlug !== '' && ! in_array('/artists/'.$artistSlug, $internalLinks, true)) {
            $errors[] = 'O artigo não linka para a página do artista (/artists/'.$artistSlug.').';
        }

        if (trim((string) ($draft['featured_image'] ?? '')) === '') {
            $warnings[] = 'Nenhuma imagem de destaque foi escolhida.';
        }

        if (preg_match('/^\s*\|.+\|\s*$/m', $body) === 1) {
            $errors[] = 'O artigo usa tabela markdown; o renderizador do blog não suporta tabelas.';
        }

        if (in_array($title, $existingTitles, true)) {
            $errors[] = 'Já existe um artigo publicado com este título.';
        }

        $keyword = trim((string) ($draft['target_keyword'] ?? ''));
        if ($keyword !== '') {
            $haystack = $this->fold($title.' '.mb_substr($plain, 0, 500).' '.$body);
            if (! str_contains($haystack, $this->fold($keyword))) {
                $warnings[] = "A palavra-chave '{$keyword}' não aparece no título, na abertura ou no corpo.";
            }
        }

        $hypeHaystack = $title.' '.$meta.' '.$body;
        foreach ($this->forbiddenPatterns() as $pattern => $message) {
            if (preg_match($pattern, $hypeHaystack) === 1) {
                $errors[] = $message;
            }
        }

        return [
            'pass' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'metrics' => [
                'words' => $wordCount,
                'h2' => $h2Count,
                'faqs' => count($faq),
                'internal_links' => count($internalLinks),
                'meta_length' => mb_strlen($meta),
            ],
        ];
    }

    private function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    /**
     * @return array<string, string>
     */
    private function forbiddenPatterns(): array
    {
        return [
            '/\bo\s+melhor\s+tatuador\b/iu' => 'Evite a afirmação absoluta "o melhor tatuador"; use "um dos principais".',
            '/\bmelhor\s+do\s+(brasil|mundo|pa[ií]s)\b/iu' => 'Evite "melhor do Brasil/mundo"; é uma afirmação absoluta.',
            '/\b(n[ºo]|n[uú]mero)\s*1\b/iu' => 'Evite "nº 1"; não há fonte para essa posição.',
            '/\bgarantimos\b|\b100%\s+(livre|sem|garantido)\b/iu' => 'Evite garantias absolutas de resultado.',
            '/\bn[aã]o\s+d[oó]i\b/iu' => 'Não prometa ausência de dor; descreva a experiência com honestidade.',
            '/\bcura\s+garantida\b|\bsem\s+cicatriz\b/iu' => 'Evite promessas médicas de cicatrização.',
        ];
    }
}
