<?php

namespace Tests\Unit;

use App\Services\Content\BlogQualityGate;
use Tests\TestCase;

class BlogQualityGateTest extends TestCase
{
    private function draft(array $overrides = []): array
    {
        $body = "## Resumo\n\n".str_repeat('Conteúdo útil sobre cobertura de tatuagem. ', 200).
            "\n\n## Como funciona\n\n[Adriano](/artists/adriano-santos) explica o processo.\n\n".
            "## Como agendar\n\n[Outro artigo](/blog/outro-artigo) e [o perfil](/artists/adriano-santos).\n";

        return array_merge([
            'title' => 'Cobertura de Tatuagem em Poços de Caldas: Guia Completo',
            'slug' => 'cobertura-de-tatuagem-em-pocos-de-caldas',
            'meta_description' => 'Guia completo sobre cobertura de tatuagem em Poços de Caldas com Adriano Santos, especialista em realismo preto e cinza e coberturas.',
            'body_md' => $body,
            'faq' => [
                ['question' => 'Quem faz?', 'answer' => 'Adriano.'],
                ['question' => 'Como agendar?', 'answer' => 'WhatsApp.'],
                ['question' => 'Dói?', 'answer' => 'Varia.'],
                ['question' => 'Quanto custa?', 'answer' => 'Depende.'],
            ],
            'featured_image' => '/artists/adriano-santos/media/portfolio-01.webp',
            'target_keyword' => 'cobertura de tatuagem Poços de Caldas',
        ], $overrides);
    }

    public function test_a_complete_draft_passes(): void
    {
        $result = (new BlogQualityGate)->check(
            $this->draft(),
            ['slug' => 'adriano-santos'],
            [],
            [],
        );

        $this->assertTrue($result['pass'], implode(' | ', $result['errors']));
        $this->assertSame(4, $result['metrics']['faqs']);
    }

    public function test_absolute_hype_is_rejected(): void
    {
        $draft = $this->draft();
        $draft['body_md'] .= "\n\nEle é o melhor tatuador do Brasil.";

        $result = (new BlogQualityGate)->check($draft, ['slug' => 'adriano-santos']);

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('melhor', implode(' ', $result['errors']));
    }

    public function test_missing_artist_link_and_short_faq_list_are_rejected(): void
    {
        $draft = $this->draft([
            'faq' => [['question' => 'Quem faz?', 'answer' => 'Adriano.']],
            'body_md' => "## Resumo\n\nTexto sem link.\n\n## Como funciona\n\nTexto.\n\n## Como agendar\n\nTexto.\n",
        ]);

        $result = (new BlogQualityGate)->check($draft, ['slug' => 'adriano-santos']);

        $this->assertFalse($result['pass']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $result = (new BlogQualityGate)->check(
            $this->draft(),
            ['slug' => 'adriano-santos'],
            ['cobertura-de-tatuagem-em-pocos-de-caldas'],
            [],
        );

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('já está publicado', implode(' ', $result['errors']));
    }
}
