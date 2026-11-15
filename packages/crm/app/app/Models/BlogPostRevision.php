<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of a draft before an admin-requested AI regeneration. Keeps the
 * previous body, the previous copilot review and the comment that triggered
 * the new version.
 */
class BlogPostRevision extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'blog_post_id',
        'version',
        'body_md',
        'ai_review',
        'feedback',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'ai_review' => 'array',
        'created_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
