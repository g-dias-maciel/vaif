<?php

namespace App\Services\Scoring;

use App\Models\Lead;
use App\Models\Project;

/**
 * One Lead with the score of its best open Project, plus a revival score when
 * every Project is closed. The queue chooses the segment; this object only
 * carries the facts.
 */
final class LeadProfile
{
    public function __construct(
        public readonly Lead $lead,
        public readonly ?Project $openProject,
        public readonly ?LeadScore $openScore,
        public readonly ?Project $lastProject,
        public readonly ?LeadScore $revivalScore,
        public readonly bool $returning,
        public readonly ?\Illuminate\Support\Carbon $lastActivity,
    ) {
    }

    public function hasOpenProject(): bool
    {
        return $this->openProject !== null;
    }

    public function score(): ?LeadScore
    {
        return $this->openScore ?? $this->revivalScore;
    }

    /**
     * The Project shown on a queue row: the best open one, else the last one.
     */
    public function project(): ?Project
    {
        return $this->openProject ?? $this->lastProject;
    }

    public function isArchived(): bool
    {
        return $this->lead->archived_at !== null;
    }


}
