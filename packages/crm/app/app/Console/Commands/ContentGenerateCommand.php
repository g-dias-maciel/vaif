<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBlogPost;
use App\Models\BlogPost;
use App\Models\BlogTopic;
use Illuminate\Console\Command;

class ContentGenerateCommand extends Command
{
    protected $signature = 'content:generate
        {--topic= : Generate a specific blog topic id}
        {--post= : Regenerate a specific blog post id}
        {--feedback= : Admin comments for a regeneration}';

    protected $description = 'Queue the weekly blog draft (or a regeneration)';

    public function handle(): int
    {
        if (! (bool) config('content.enabled', true)) {
            $this->warn('Content workflow is disabled (CONTENT_ENABLED).');

            return self::SUCCESS;
        }

        if ($postId = $this->option('post')) {
            $post = BlogPost::find($postId);
            if ($post === null) {
                $this->error('Post não encontrado.');

                return self::FAILURE;
            }
            if (! $post->blog_topic_id) {
                $this->error('O post não tem tópico associado.');

                return self::FAILURE;
            }

            GenerateBlogPost::dispatch(
                $post->blog_topic_id,
                $post->id,
                (string) ($this->option('feedback') ?: ''),
                null,
            );

            $this->info("Regeneração enfileirada para {$post->id}.");

            return self::SUCCESS;
        }

        $topic = $this->option('topic')
            ? BlogTopic::find((string) $this->option('topic'))
            : BlogTopic::query()
                ->where('status', BlogTopic::STATUS_PLANNED)
                ->whereDate('publish_date', '<=', now()->toDateString())
                ->orderBy('publish_date')
                ->first();

        if ($topic === null) {
            $this->warn('Nenhum tópico planejado para hoje.');

            return self::SUCCESS;
        }

        if (empty($topic->artist_slug)) {
            $this->error('O tópico não tem artist_slug. Configure a landing page do artista.');

            return self::FAILURE;
        }

        $pending = BlogPost::query()
            ->where('blog_topic_id', $topic->id)
            ->whereIn('status', [
                BlogPost::STATUS_GENERATING,
                BlogPost::STATUS_NEEDS_REVIEW,
                BlogPost::STATUS_APPROVED,
                BlogPost::STATUS_PUBLISHING,
                BlogPost::STATUS_PUBLISHED,
            ])
            ->exists();

        if ($pending) {
            $this->warn('Este tópico já tem um artigo em revisão, aprovado ou publicado.');

            return self::SUCCESS;
        }

        if ($topic->status !== BlogTopic::STATUS_PLANNED) {
            $this->warn('Este tópico já foi gerado ou está na fila.');

            return self::SUCCESS;
        }

        $topic->forceFill(['status' => BlogTopic::STATUS_GENERATED])->save();

        try {
            GenerateBlogPost::dispatch($topic->id, null, null, null);
        } catch (\Throwable $e) {
            $topic->forceFill(['status' => BlogTopic::STATUS_PLANNED])->save();
            $this->error('Não foi possível enfileirar a geração: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Geração enfileirada para “{$topic->target_keyword}” ({$topic->id}).");

        return self::SUCCESS;
    }
}
