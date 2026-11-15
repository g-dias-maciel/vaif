<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\BlogTopic;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ContentSeedTopicsCommand extends Command
{
    protected $signature = 'content:seed-topics
        {--artist=adriano-santos : Landing-page slug of the artist}
        {--artist-id= : CRM artist id (overrides --artist)}
        {--start= : First publish date (YYYY-MM-DD); defaults to next Monday}';

    protected $description = 'Seed the first weekly blog calendar for an artist';

    public function handle(): int
    {
        $artist = $this->resolveArtist();

        if ($artist === null) {
            $this->error('Artista não encontrado. Informe --artist-id ou configure landing_page_slug.');

            return self::FAILURE;
        }

        $slug = (string) ($artist->settings?->landing_page_slug ?? '');
        if ($slug === '') {
            $this->error('O artista não tem landing_page_slug configurado.');

            return self::FAILURE;
        }

        $start = $this->option('start')
            ? Carbon::parse((string) $this->option('start'))->startOfDay()
            : now()->next('Monday')->startOfDay();

        $topics = $this->topics();

        $created = 0;
        foreach ($topics as $index => $topic) {
            $exists = BlogTopic::query()
                ->where('artist_id', $artist->id)
                ->where('target_keyword', $topic['target_keyword'])
                ->exists();

            if ($exists) {
                continue;
            }

            BlogTopic::create([
                'publish_date' => $start->copy()->addWeeks($index)->toDateString(),
                'artist_id' => $artist->id,
                'artist_slug' => $slug,
                'track' => $topic['track'],
                'target_keyword' => $topic['target_keyword'],
                'search_intent' => $topic['search_intent'],
                'angle' => $topic['angle'],
                'status' => BlogTopic::STATUS_PLANNED,
            ]);
            $created++;
        }

        $this->info("{$created} tópico(s) criado(s) para {$artist->displayName()} a partir de {$start->toDateString()}.");

        return self::SUCCESS;
    }

    private function resolveArtist(): ?Artist
    {
        if ($id = $this->option('artist-id')) {
            return Artist::with('settings')->find((string) $id);
        }

        $slug = (string) $this->option('artist');

        return Artist::with('settings')
            ->whereHas('settings', fn ($query) => $query->where('landing_page_slug', $slug))
            ->first();
    }

    /**
     * @return array<int, array{track: string, target_keyword: string, search_intent: string, angle: string}>
     */
    private function topics(): array
    {
        return [
            [
                'track' => BlogTopic::TRACK_LOCAL_GUIDE,
                'target_keyword' => 'cobertura de tatuagem Poços de Caldas',
                'search_intent' => 'local',
                'angle' => 'Guia completo para quem quer cobrir uma tatuagem antiga na cidade; apresentar Adriano como especialista e mostrar o estúdio no Jardim Santa Rita.',
            ],
            [
                'track' => BlogTopic::TRACK_SPOTLIGHT,
                'target_keyword' => 'Adriano Santos tatuador',
                'search_intent' => 'navegacional',
                'angle' => 'Perfil editorial: trajetória, especialidade em coberturas, realismo preto e cinza e mais de 20 anos de experiência.',
            ],
            [
                'track' => BlogTopic::TRACK_SPECIALTY_GUIDE,
                'target_keyword' => 'realismo preto e cinza Poços de Caldas',
                'search_intent' => 'comercial',
                'angle' => 'O que esperar de uma sessão de realismo preto e cinza: planejamento, contraste, cicatrização e agenda.',
            ],
            [
                'track' => BlogTopic::TRACK_CASE_STUDY,
                'target_keyword' => 'cobertura de tatuagem antes e depois',
                'search_intent' => 'informacional',
                'angle' => 'Usar um antes/depois real do portfólio para explicar decisões de composição, contraste e viabilidade da cobertura.',
            ],
            [
                'track' => BlogTopic::TRACK_EDUCATIONAL,
                'target_keyword' => 'quanto custa cobertura de tatuagem',
                'search_intent' => 'comercial',
                'angle' => 'Explicar os fatores de preço sem inventar valores: tamanho, complexidade, região do corpo e número de sessões.',
            ],
            [
                'track' => BlogTopic::TRACK_EDUCATIONAL,
                'target_keyword' => 'como escolher tatuador de cobertura',
                'search_intent' => 'informacional',
                'angle' => 'Critérios práticos: portfólio de coberturas, avaliação presencial, planejamento e comunicação.',
            ],
            [
                'track' => BlogTopic::TRACK_SPECIALTY_GUIDE,
                'target_keyword' => 'tatuagem tribal coberta',
                'search_intent' => 'informacional',
                'angle' => 'Por que tatuagens tribais antigas são candidatas comuns a cobertura e o que avaliar antes.',
            ],
            [
                'track' => BlogTopic::TRACK_LOCAL_GUIDE,
                'target_keyword' => 'fine line em Poços de Caldas',
                'search_intent' => 'local',
                'angle' => 'Fine line na região: indicações, limites técnicos e quando a cobertura é uma opção melhor.',
            ],
            [
                'track' => BlogTopic::TRACK_LOCAL_GUIDE,
                'target_keyword' => 'tatuador em Poços de Caldas',
                'search_intent' => 'local',
                'angle' => 'Como escolher um tatuador na cidade, com Adriano Santos como referência em coberturas e realismo.',
            ],
            [
                'track' => BlogTopic::TRACK_EDUCATIONAL,
                'target_keyword' => 'cuidados pós-tatuagem',
                'search_intent' => 'informacional',
                'angle' => 'Orientação geral de cuidados, com fontes, sem promessas médicas.',
            ],
            [
                'track' => BlogTopic::TRACK_EDUCATIONAL,
                'target_keyword' => 'cobertura de tatuagem dói',
                'search_intent' => 'informacional',
                'angle' => 'Como é a experiência da sessão, regiões mais sensíveis e pausas; sem prometer ausência de dor.',
            ],
            [
                'track' => BlogTopic::TRACK_SPECIALTY_GUIDE,
                'target_keyword' => 'tatuagem realista no braço',
                'search_intent' => 'comercial',
                'angle' => 'Planejamento de uma peça realista no braço, do fechamento da composição à agenda de sessões.',
            ],
        ];
    }
}
