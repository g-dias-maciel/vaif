<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weekly article: draft, AI copilot review, admin approval and publish
 * state. The LP is the public renderer; the CRM is the workflow owner.
 */
class BlogPost extends Model
{
    use HasUuids;

    public const STATUS_GENERATING = 'generating';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'blog_topic_id',
        'artist_id',
        'artist_slug',
        'track',
        'title',
        'slug',
        'meta_description',
        'excerpt',
        'body_md',
        'featured_image',
        'featured_image_alt',
        'faq',
        'tags',
        'sources',
        'category',
        'target_keyword',
        'search_intent',
        'facts',
        'ai_review',
        'ai_score',
        'admin_overview',
        'status',
        'feedback',
        'model',
        'github_commit_sha',
        'published_url',
        'approved_by',
        'approved_at',
        'published_at',
        'verified_at',
    ];

    protected $casts = [
        'faq' => 'array',
        'tags' => 'array',
        'sources' => 'array',
        'facts' => 'array',
        'ai_review' => 'array',
        'ai_score' => 'integer',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(BlogTopic::class, 'blog_topic_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BlogPostRevision::class, 'blog_post_id')->orderByDesc('version');
    }

    public function isReviewable(): bool
    {
        return $this->status === self::STATUS_NEEDS_REVIEW;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_GENERATING => 'Gerando',
            self::STATUS_NEEDS_REVIEW => 'Aguardando revisão',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_PUBLISHING => 'Publicando',
            self::STATUS_PUBLISHED => 'Publicado',
            self::STATUS_FAILED => 'Falhou',
            self::STATUS_REJECTED => 'Rejeitado',
            default => ucfirst((string) $this->status),
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_NEEDS_REVIEW, self::STATUS_APPROVED => 'ready',
            self::STATUS_FAILED, self::STATUS_REJECTED => 'failed',
            default => 'queued',
        };
    }

    /**
     * Next revision number for a regeneration snapshot.
     */
    public function nextRevisionNumber(): int
    {
        return (int) ($this->revisions()->max('version') ?? 0) + 1;
    }
}
