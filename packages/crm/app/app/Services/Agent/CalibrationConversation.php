<?php

namespace App\Services\Agent;

use App\Models\AgentDraft;
use App\Models\Lead;

/**
 * Per-customer-message calibration blocks:
 *
 *   customer message (left)
 *      -> Beatriz mock/draft (below)
 *      -> artist reply(ies) (right)
 *
 * A simulated draft is matched by `lead_message_id`; a live draft is matched to
 * the closest customer message before it, preferring a message whose text is
 * inside the draft `lead_text`.
 */
class CalibrationConversation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forLead(Lead $lead, int $limit = 80): array
    {
        $artistName = $lead->artist?->displayName() ?: 'Artista';

        try {
            $messages = $lead->chatMessages()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            $messages = collect();
        }

        $blocks = [];
        $rights = [];

        foreach ($messages as $message) {
            if (! $message->is_displayable) {
                continue;
            }

            $content = trim($message->content);

            if ($content === '') {
                continue;
            }

            $item = [
                'id' => $message->id,
                'at' => $message->created_at,
                'content' => $content,
                'sender' => $message->message_type === 'human'
                    ? 'Cliente'
                    : ($message->is_artist ? $artistName : 'Beatriz (enviado)'),
                'is_artist' => $message->is_artist,
            ];

            if ($message->message_type === 'human') {
                $blocks[] = [
                    'message' => $item,
                    'draft' => null,
                    'alternates' => [],
                    'replies' => [],
                ];
            } else {
                $rights[] = $item;
            }
        }

        try {
            $drafts = AgentDraft::query()
                ->where('lead_id', $lead->id)
                ->orderBy('created_at')
                ->get();
        } catch (\Throwable $e) {
            $drafts = collect();
        }

        foreach ($drafts as $draft) {
            $event = $this->draftEvent($draft);
            $index = $this->draftBlockIndex($blocks, $draft, $event);

            if ($index === null) {
                $blocks[] = [
                    'message' => trim((string) $draft->lead_text) !== '' ? [
                        'id' => null,
                        'at' => $draft->created_at,
                        'content' => trim((string) $draft->lead_text),
                        'sender' => 'Cliente',
                        'is_artist' => false,
                    ] : null,
                    'draft' => $event,
                    'alternates' => [],
                    'replies' => [],
                ];

                continue;
            }

            $existing = $blocks[$index]['draft'];

            if ($existing === null) {
                $blocks[$index]['draft'] = $event;
            } elseif (($existing['is_superseded'] ?? false) && ! $event['is_superseded']) {
                $blocks[$index]['alternates'][] = $existing;
                $blocks[$index]['draft'] = $event;
            } else {
                $blocks[$index]['alternates'][] = $event;
            }
        }

        foreach ($rights as $right) {
            $index = $this->lastBlockBefore($blocks, $right['at']);

            if ($index === null) {
                $blocks[] = [
                    'message' => null,
                    'draft' => null,
                    'alternates' => [],
                    'replies' => [$right],
                ];

                continue;
            }

            $blocks[$index]['replies'][] = $right;
        }

        usort($blocks, function (array $a, array $b) {
            $left = $a['message']['at']?->getTimestamp() ?? 0;
            $right = $b['message']['at']?->getTimestamp() ?? 0;

            return $left <=> $right;
        });

        if (count($blocks) > $limit) {
            $blocks = array_slice($blocks, -$limit);
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function draftEvent(AgentDraft $draft): array
    {
        $judge = $draft->comparison['judge'] ?? null;

        return [
            'draft' => $draft,
            'status' => $draft->status,
            'is_superseded' => $draft->status === AgentDraft::STATUS_SUPERSEDED,
            'is_simulated' => $draft->isSimulated(),
            'verdict' => is_array($judge) ? ($judge['verdict'] ?? null) : null,
            'acceptable' => is_array($judge) ? ($judge['acceptable'] ?? null) : null,
            'rationale' => is_array($judge) ? ($judge['rationale'] ?? null) : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function draftBlockIndex(array $blocks, AgentDraft $draft, array $event): ?int
    {
        if ($draft->lead_message_id !== null) {
            foreach ($blocks as $index => $block) {
                if ((int) ($block['message']['id'] ?? 0) === (int) $draft->lead_message_id) {
                    return $index;
                }
            }
        }

        $needle = $this->norm((string) $draft->lead_text);
        $fallback = null;

        foreach ($blocks as $index => $block) {
            $at = $block['message']['at'] ?? null;

            if ($at === null || $at->gt($draft->created_at)) {
                continue;
            }

            $fallback = $index;

            if ($needle !== '' && str_contains($needle, $this->norm((string) ($block['message']['content'] ?? '')))) {
                $fallback = $index;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function lastBlockBefore(array $blocks, mixed $at): ?int
    {
        $found = null;

        foreach ($blocks as $index => $block) {
            $blockAt = $block['message']['at'] ?? null;

            if ($blockAt !== null && $at !== null && $blockAt->lte($at)) {
                $found = $index;
            }
        }

        return $found;
    }

    private function norm(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
