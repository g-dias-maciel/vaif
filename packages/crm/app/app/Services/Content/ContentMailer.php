<?php

namespace App\Services\Content;

use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Small staff notification: a draft is ready for review, or an article is live.
 * Uses the existing Laravel mailer; failures never block the workflow.
 */
class ContentMailer
{
    /**
     * @return array<int, string>
     */
    public function recipients(): array
    {
        $configured = (array) config('content.recipients', []);

        $emails = $configured !== []
            ? $configured
            : User::query()->where('role', User::ROLE_STAFF)->pluck('email')->all();

        return array_values(array_unique(array_filter(
            array_map('trim', $emails),
            fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    public function draftReady(BlogPost $post): int
    {
        $url = route('content.show', $post);
        $overview = trim((string) $post->admin_overview);

        return $this->send(
            'Artigo aguardando revisão: '.$post->title,
            "Um novo artigo está pronto para revisão no CRM.\n\n".
            "Título: {$post->title}\n".
            "Artista: {$post->artist_slug}\n".
            "Palavra-chave: {$post->target_keyword}\n".
            'Score do Copiloto: '.($post->ai_score ?? '—')."/100\n\n".
            ($overview !== '' ? "Resumo:\n{$overview}\n\n" : '').
            "Revisar e aprovar: {$url}\n"
        );
    }

    public function published(BlogPost $post): int
    {
        return $this->send(
            'Artigo publicado: '.$post->title,
            "O artigo foi publicado no blog.\n\n".
            "URL: {$post->published_url}\n".
            "Artista: {$post->artist_slug}\n"
        );
    }

    private function send(string $subject, string $body): int
    {
        if (! (bool) config('content.mail', true)) {
            return 0;
        }

        $emails = $this->recipients();
        if ($emails === []) {
            return 0;
        }

        try {
            Mail::raw($body, function ($message) use ($emails, $subject): void {
                $message->to($emails)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('Content notification e-mail failed', ['error' => $e->getMessage()]);

            return 0;
        }

        return count($emails);
    }
}
