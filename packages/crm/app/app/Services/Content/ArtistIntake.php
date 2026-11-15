<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Http;

/**
 * Mints the single-use LP intake link the artist uses to submit their landing
 * page information. The token and form live in the LP; the CRM only asks for
 * the link.
 */
class ArtistIntake
{
    public function link(string $label): string
    {
        $url = (string) config('content.intake.url');
        $key = (string) config('content.intake.key');

        if ($url === '') {
            throw new ContentException('LP_INTAKE_TOKEN_URL não está configurada.');
        }
        if ($key === '') {
            throw new ContentException('LP_INTAKE_API_KEY não está configurada.');
        }

        try {
            $response = Http::withHeaders(['X-Api-Key' => $key])
                ->acceptJson()
                ->timeout((int) config('content.intake.timeout', 15))
                ->post($url, ['label' => $label]);
        } catch (\Throwable $e) {
            throw new ContentException('Não foi possível falar com a landing page: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new ContentException('A LP recusou a geração do link (HTTP '.$response->status().').');
        }

        $link = $response->json('url');
        if (! is_string($link) || $link === '') {
            throw new ContentException('A LP não devolveu um link de cadastro.');
        }

        return $link;
    }
}
