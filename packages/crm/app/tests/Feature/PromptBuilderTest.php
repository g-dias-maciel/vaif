<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Services\Agent\PromptBuilder;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    private function artist(): Artist
    {
        $artist = new Artist([
            'id' => (string) Str::uuid(),
            'name' => 'Adriano',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'prompt-artist',
        ]);

        $artist->setRelation('settings', new ArtistSetting([
            'artist_id' => $artist->id,
            'display_name' => 'Adriano',
            'city' => 'Poços de Caldas',
            'address' => 'Rua Oswaldo Cruz 135',
            'working_hours' => ['seg' => ['09:00-12:00', '14:00-18:00']],
        ]));

        return $artist;
    }

    private function facts(array $project = [], array $assumptions = []): array
    {
        return [
            'assumptions' => $assumptions,
            'pricing' => ['status' => 'no_price_row'],
            'availability' => ['status' => 'no_slots'],
            'project' => array_merge([
                'tattoo_type' => 'new',
                'placement' => null,
                'body_zone' => null,
                'style' => null,
                'first_tattoo' => null,
                'meaning' => null,
            ], $project),
            'booking' => ['active' => false],
            'conditions' => ['allow_hold_slot' => false, 'guarantee_price' => false],
            'portfolio' => [],
            'client' => [],
            'studio' => [
                'timezone' => 'America/Sao_Paulo',
                'working_hours' => ['seg' => ['09:00-12:00', '14:00-18:00']],
            ],
            'turn' => ['intent' => 'qualify'],
        ];
    }

    public function test_the_prompt_lists_known_fields_and_the_single_next_question(): void
    {
        $prompt = (new PromptBuilder())->system($this->artist(), [], $this->facts());

        $this->assertStringContainsString('BRIEFING DO LEAD', $prompt);
        $this->assertStringContainsString('Ainda falta: local do corpo', $prompt);
        $this->assertStringContainsString('NUNCA pergunte de novo', $prompt);
        $this->assertStringContainsString('Responda PRIMEIRO', $prompt);
        $this->assertStringContainsString('Funcionamento: seg 09:00-12:00 / 14:00-18:00', $prompt);
        $this->assertStringContainsString('deixei salvo', $prompt);
    }

    public function test_the_next_question_skips_fields_already_known(): void
    {
        $prompt = (new PromptBuilder())->system($this->artist(), [], $this->facts([
            'placement' => 'braco',
        ]));

        $this->assertStringContainsString('local=braco', $prompt);
        $this->assertStringContainsString('Ainda falta: tamanho', $prompt);
        $this->assertStringNotContainsString('Onde no corpo você quer fazer?', $prompt);
    }

    public function test_a_confident_assumption_becomes_a_confirmation_question(): void
    {
        $prompt = (new PromptBuilder())->system($this->artist(), [], $this->facts([
            'tattoo_type' => null,
        ], [
            'tattoo_type' => ['value' => 'nova', 'confidence' => 0.86, 'reason' => 'pediu na mão'],
        ]));

        $this->assertStringContainsString('Essa tatuagem seria uma tatuagem nova, certo?', $prompt);
        $this->assertStringContainsString('CONFIRMAÇÃO', $prompt);
        $this->assertStringNotContainsString('A tatuagem que você quer é nova, uma cobertura ou uma reforma?', $prompt);
    }

    public function test_a_placement_assumption_becomes_a_confirmation_question(): void
    {
        $prompt = (new PromptBuilder())->system($this->artist(), [], $this->facts([
            'tattoo_type' => 'new',
            'placement' => null,
        ], [
            'placement' => ['value' => 'mao', 'confidence' => 0.95, 'reason' => 'disse na mão'],
        ]));

        $this->assertStringContainsString('Você quer fazer na mão, certo?', $prompt);
        $this->assertStringNotContainsString('Onde no corpo você quer fazer?', $prompt);
    }

    public function test_a_low_confidence_assumption_keeps_the_open_question(): void
    {
        $prompt = (new PromptBuilder())->system($this->artist(), [], $this->facts([
            'tattoo_type' => null,
        ], [
            'tattoo_type' => ['value' => 'nova', 'confidence' => 0.4, 'reason' => 'palpite'],
        ]));

        $this->assertStringContainsString('A tatuagem que você quer é nova, uma cobertura ou uma reforma?', $prompt);
        $this->assertStringNotContainsString('CONFIRMAÇÃO de uma suposição', $prompt);
    }
}
