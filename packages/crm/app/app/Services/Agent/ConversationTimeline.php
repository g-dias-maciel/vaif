<?php

namespace App\Services\Agent;

use App\Models\AgentDraft;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * One chronological thread per lead: lead messages, copilot drafts (not sent)
 * and the artist's real replies.
 *
 * Drafts are not written to `agent_memory`, so the conversation has to merge
 * the transcript with `agent_drafts`. Superseded drafts are omitted: they were
 * replaced by a newer suggestion for the same burst and only add noise.
 */
class ConversationTimeline
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forLead(Lead $lead, int $limit = 120): array
    {
        $items = [];

        try {
            $messages = $lead->chatMessages()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            // The SQLite test schema has no agent_memory table.
            $messages = collect();
        }

        foreach ($messages as $message) {
            if (! $message->is_displayable) {
                continue;
            }

            $content = trim($message->content);

            if ($content === '') {
                continue;
            }

            $kind = $message->message_type === 'human'
                ? 'lead'
                : ($message->is_artist ? 'artist' : 'ai');

            $items[] = [
                'at' => $message->created_at,
                'kind' => $kind,
                'sender' => $kind === 'artist'
                    ? ($lead->artist?->displayName() ?: 'Artista')
                    : $message->sender_label,
                'content' => $content,
                'order' => $kind === 'lead' ? 0 : 2,
            ];
        }

        try {
            $drafts = AgentDraft::query()
                ->where('lead_id', $lead->id)
                ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
                ->whereIn('status', [
                    AgentDraft::STATUS_PENDING,
                    AgentDraft::STATUS_REPLIED,
                    AgentDraft::STATUS_COMPARED,
                ])
                ->orderBy('created_at')
                ->get();
        } catch (\Throwable $e) {
            $drafts = collect();
        }

        foreach ($drafts as $draft) {
            $judge = $draft->comparison['judge'] ?? null;

            $items[] = [
                'at' => $draft->created_at,
                'kind' => 'draft',
                'sender' => 'Beatriz · sugestão não enviada',
                'content' => $draft->draft_reply,
                'order' => 1,
                'draft' => $draft,
                'status' => $draft->status,
                'verdict' => is_array($judge) ? ($judge['verdict'] ?? null) : null,
                'acceptable' => is_array($judge) ? ($judge['acceptable'] ?? null) : null,
                'rationale' => is_array($judge) ? ($judge['rationale'] ?? null) : null,
            ];

            // If the artist reply was paired but not written to chat_memory
            // (older captures/backfills), still show it in the real chat.
            if ($draft->artist_reply && ! $draft->isSimulated()) {
                $repliedAt = $draft->artist_replied_at ?? $draft->created_at;
                $windowEnd = $repliedAt->copy()->addMinutes(10);
                $alreadyThere = collect($items)->contains(
                    fn ($item) => ($item['kind'] ?? null) === 'artist'
                        && $item['at'] !== null
                        && $item['at']->gte($draft->created_at)
                        && $item['at']->lte($windowEnd)
                );

                if (! $alreadyThere) {
                    $items[] = [
                        'at' => $repliedAt,
                        'kind' => 'artist',
                        'sender' => $lead->artist?->displayName() ?: 'Artista',
                        'content' => trim((string) $draft->artist_reply),
                        'order' => 2,
                    ];
                }
            }
        }

        usort($items, function (array $a, array $b) {
            $left = $a['at']?->getTimestamp() ?? 0;
            $right = $b['at']?->getTimestamp() ?? 0;

            return $left <=> $right ?: (($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        });

        if (count($items) > $limit) {
            $items = array_slice($items, -$limit);
        }

        return $items;
    }
}
