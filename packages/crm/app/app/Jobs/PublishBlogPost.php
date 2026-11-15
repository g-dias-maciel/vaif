<?php

namespace App\Jobs;

use App\Models\BlogPost;
use App\Services\Content\BlogPublisher;
use App\Services\Content\ContentException;
use App\Services\Content\ContentMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Commits an approved article to the LP repository, waits for the public URL
 * and pings IndexNow. One try: the admin can retry from the CRM.
 */
class PublishBlogPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $postId,
        public readonly ?int $approverId = null,
    ) {
        $this->onQueue((string) config('content.queue', 'content'));
    }

    public function handle(BlogPublisher $publisher, ContentMailer $mailer): void
    {
        $post = BlogPost::find($this->postId);
        if ($post === null) {
            throw new ContentException('Artigo não encontrado.');
        }

        if ($this->approverId !== null) {
            $post->forceFill([
                'approved_by' => $this->approverId,
                'approved_at' => $post->approved_at ?? now(),
            ])->save();
        }

        try {
            $post = $publisher->publish($post);
            $mailer->published($post);
        } catch (\Throwable $e) {
            $post->forceFill([
                'status' => BlogPost::STATUS_FAILED,
                'feedback' => 'Falha ao publicar: '.$e->getMessage(),
            ])->save();

            Log::error('Blog publish failed', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
