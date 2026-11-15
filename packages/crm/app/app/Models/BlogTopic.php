<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A planned weekly article. The content:generate command picks the oldest due
 * topic and hands it to the draft generator.
 */
class BlogTopic extends Model
{
    use HasUuids;

    public const TRACK_LOCAL_GUIDE = 'local_guide';

    public const TRACK_SPOTLIGHT = 'spotlight';

    public const TRACK_SPECIALTY_GUIDE = 'specialty_guide';

    public const TRACK_CASE_STUDY = 'case_study';

    public const TRACK_EDUCATIONAL = 'educational';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SKIPPED = 'skipped';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'publish_date',
        'artist_id',
        'artist_slug',
        'track',
        'target_keyword',
        'search_intent',
        'angle',
        'status',
        'notes',
    ];

    protected $casts = [
        'publish_date' => 'date',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'blog_topic_id');
    }

    public function latestPost(): ?BlogPost
    {
        return $this->posts()->latest('created_at')->first();
    }

    public function label(): string
    {
        return match ($this->track) {
            self::TRACK_LOCAL_GUIDE => 'Guia local',
            self::TRACK_SPOTLIGHT => 'Perfil do artista',
            self::TRACK_SPECIALTY_GUIDE => 'Guia de especialidade',
            self::TRACK_CASE_STUDY => 'Antes e depois',
            self::TRACK_EDUCATIONAL => 'Educacional',
            default => 'Conteúdo',
        };
    }
}
