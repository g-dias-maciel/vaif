<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Http;

/**
 * Fetches the public artist profile from the LP. The LP is the source of truth
 * for bio, portfolio, before/after, testimonials and local facts; the CRM only
 * stores the landing-page slug.
 */
class ArtistFactSheet
{
    /**
     * @return array<string, mixed>
     */
    public function fetch(string $slug): array
    {
        if ($slug === '') {
            throw new ContentException('O artista não tem landing page configurada.');
        }

        $url = (string) config('content.lp.artist_api_url');
        if ($url === '') {
            throw new ContentException('LP_ARTIST_API_URL não está configurada.');
        }

        $request = Http::acceptJson()->timeout((int) config('content.lp.timeout', 20));

        $key = (string) config('content.lp.artist_api_key');
        if ($key !== '') {
            $request = $request->withHeaders(['X-Api-Key' => $key]);
        }

        try {
            $response = $request->get($url, ['slug' => $slug]);
        } catch (\Throwable $e) {
            throw new ContentException('Não foi possível consultar a landing page: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 404) {
            throw new ContentException("A landing page do artista '{$slug}' não está publicada.");
        }

        if (! $response->successful()) {
            throw new ContentException('A API do artista retornou HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload) || empty($payload['display_name'])) {
            throw new ContentException('A API do artista retornou um perfil inválido.');
        }

        return $payload;
    }
}
