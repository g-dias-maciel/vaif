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
                    $content = trim((string) $draft->artist_reply);
                    $note = trim((string) $draft->artist_media_note);

                    if ($note !== '' && MessageText::isMediaOnly($content)) {
                        $content .= "\n".$note;
                    }

                    $items[] = [
                        'at' => $repliedAt,
                        'kind' => 'artist',
                        'sender' => $lead->artist?->displayName() ?: 'Artista',
                        'content' => $content,
                        'order' => 2,
                        'media_note' => $note !== '' && MessageText::isMediaOnly((string) $draft->artist_reply),
                    ];
                }
            }
        }

        // Media that lives in chat_memory is only a placeholder. Attach the
        // staff description to the artist bubble so the lead page is readable.
        $notes = $drafts->filter(
            fn (AgentDraft $draft) => trim((string) $draft->artist_media_note) !== ''
                && $draft->artist_replied_at !== null
        );

        if ($notes->isNotEmpty()) {
            foreach ($items as $index => $item) {
                if (($item['kind'] ?? null) !== 'artist'
                    || ! MessageText::hasMediaMarker((string) ($item['content'] ?? ''))
                    || $item['at'] === null) {
                    continue;
                }

                $match = $notes->first(
                    fn (AgentDraft $draft) => abs($draft->artist_replied_at->getTimestamp() - $item['at']->getTimestamp()) <= 600
                );

                if ($match !== null) {
                    $items[$index]['content'] = trim((string) $item['content'])."\n".trim((string) $match->artist_media_note);
                    $items[$index]['media_note'] = true;
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
