<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Http;

/**
 * Commits the final markdown to the LP repository. Coolify watches `main` and
 * deploys the new file; the LP container never writes content at runtime.
 */
class GitHubPublisher
{
    public function put(string $path, string $content, string $message): string
    {
        $token = (string) config('content.github.token');
        $repo = (string) config('content.github.repo');
        $branch = (string) config('content.github.branch', 'main');
        $api = rtrim((string) config('content.github.api_url', 'https://api.github.com'), '/');

        if ($token === '') {
            throw new ContentException('GITHUB_CONTENT_TOKEN não está configurado.');
        }
        if ($repo === '' || ! str_contains($repo, '/')) {
            throw new ContentException('GITHUB_CONTENT_REPO precisa estar no formato owner/repo.');
        }

        $url = $api.'/repos/'.$repo.'/contents/'.$this->encodePath($path);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $sha = $this->existingSha($url, $token, $branch);

            $payload = [
                'message' => $message,
                'content' => base64_encode($content),
                'branch' => $branch,
            ];
            if ($sha !== null) {
                $payload['sha'] = $sha;
            }

            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout((int) config('content.github.timeout', 30))
                ->put($url, $payload);

            if ($response->successful()) {
                $commit = $response->json('commit.sha');
                if (! is_string($commit) || $commit === '') {
                    throw new ContentException('O GitHub aceitou o commit, mas não devolveu o SHA.');
                }

                return $commit;
            }

            // A concurrent commit changed the file sha. Fetch again and retry.
            if ($response->status() === 409 && $attempt === 1) {
                continue;
            }

            throw new ContentException(
                'GitHub recusou o commit (HTTP '.$response->status().'): '.
                mb_substr($response->body(), 0, 500)
            );
        }

        throw new ContentException('Não foi possível commitar o artigo no GitHub.');
    }

    private function existingSha(string $url, string $token, string $branch): ?string
    {
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout((int) config('content.github.timeout', 30))
            ->get($url, ['ref' => $branch]);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new ContentException(
                'Não foi possível consultar o arquivo no GitHub (HTTP '.$response->status().').'
            );
        }

        $sha = $response->json('sha');

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }
}
