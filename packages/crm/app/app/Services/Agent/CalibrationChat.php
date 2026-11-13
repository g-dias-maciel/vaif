<?php

namespace App\Services\Agent;

use App\Models\AgentDraft;
use App\Models\Lead;

/**
 * The real conversation in send order:
 *
 *   customer messages -> artist messages -> SDR suggestion for that turn
 *
 * The SDR suggestion is rendered below the artist's reply (or below the
 * customer messages when the artist did not answer).
 */
class CalibrationChat
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forLead(Lead $lead, int $limit = 80, bool $preferSimulated = false): array
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

        $turns = [];
        $current = null;

        foreach ($messages as $message) {
            if (! $message->is_displayable) {
                continue;
            }

            $content = trim($message->content);
            if ($content === '') {
                continue;
            }

            $isClient = $message->message_type === 'human';
            $item = [
                'id' => $message->id,
                'at' => $message->created_at,
                'content' => $content,
                'sender' => $isClient
                    ? 'Cliente'
                    : ($message->is_artist ? $artistName : 'Beatriz (enviado)'),
                'is_artist' => $message->is_artist,
            ];

            if ($isClient) {
                $lastClientAt = $current !== null && $turns[$current]['clients'] !== []
                    ? end($turns[$current]['clients'])['at']
                    : null;

                $newTurn = $current === null
                    || $turns[$current]['artists'] !== []
                    || ($lastClientAt !== null && $lastClientAt->diffInSeconds($item['at']) > 300);

                if ($newTurn) {
                    $turns[] = ['clients' => [], 'artists' => [], 'draft' => null, 'alternates' => []];
                    $current = count($turns) - 1;
                }

                $turns[$current]['clients'][] = $item;

                continue;
            }

            if ($current === null) {
                $turns[] = ['clients' => [], 'artists' => [], 'draft' => null, 'alternates' => []];
                $current = count($turns) - 1;
            }

            $turns[$current]['artists'][] = $item;
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
            $index = $this->turnForDraft($turns, $draft);

            if ($index === null) {
                continue;
            }

            $turns[$index]['drafts'][] = $draft;

            // Fallback when the artist reply was paired but is not present in
            // chat_memory (older captures/backfills).
            if ($draft->artist_reply && ! $draft->isSimulated() && $turns[$index]['artists'] === []) {
                $turns[$index]['artists'][] = [
                    'id' => null,
                    'at' => $draft->artist_replied_at ?? $draft->created_at,
                    'content' => trim((string) $draft->artist_reply),
                    'sender' => $artistName,
                    'is_artist' => true,
                ];
            }
        }

        foreach ($turns as $index => $turn) {
            $turns[$index] = $this->pickDraft($turn, $preferSimulated);
        }

        if (count($turns) > $limit) {
            $turns = array_slice($turns, -$limit);
        }

        return array_values($turns);
    }

    /**
     * @param  array<int, array<string, mixed>>  $turns
     */
    private function turnForDraft(array $turns, AgentDraft $draft): ?int
    {
        // Simulated mocks know exactly which customer message they answer.
        if ($draft->lead_message_id !== null) {
            foreach ($turns as $index => $turn) {
                foreach ($turn['clients'] as $client) {
                    if ((int) ($client['id'] ?? 0) === (int) $draft->lead_message_id) {
                        return $index;
                    }
                }
            }
        }

        $needle = $this->norm((string) $draft->lead_text);
        $fallback = null;

        foreach ($turns as $index => $turn) {
            $lastClient = $turn['clients'] !== [] ? end($turn['clients'])['at'] : null;

            if ($lastClient === null || $lastClient->gt($draft->created_at)) {
                continue;
            }

            $fallback = $index;

            foreach ($turn['clients'] as $client) {
                if ($needle !== '' && str_contains($needle, $this->norm((string) $client['content']))) {
                    $fallback = $index;
                }
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $turn
     * @return array<string, mixed>
     */
    private function pickDraft(array $turn, bool $preferSimulated): array
    {
        $drafts = $turn['drafts'] ?? [];
        unset($turn['drafts']);

        if ($drafts === []) {
            return $turn;
        }

        usort($drafts, function (AgentDraft $a, AgentDraft $b) {
            $aPriority = $this->priority($a, $preferSimulated);
            $bPriority = $this->priority($b, $preferSimulated);

            return $aPriority <=> $bPriority ?: $b->created_at <=> $a->created_at;
        });

        $main = array_shift($drafts);
        $turn['draft'] = $this->event($main);
        $turn['media_note'] = MediaNote::dedupe((string) $main->artist_media_note);

        if ($turn['media_note'] !== '') {
            $artistText = implode("\n", array_map(
                fn ($item) => (string) ($item['content'] ?? ''),
                $turn['artists'] ?? [],
            ));
            $turn['media_note'] = MediaNote::subtract($turn['media_note'], $artistText);
        }
        $turn['alternates'] = array_map(fn (AgentDraft $draft) => $this->event($draft), $drafts);

        return $turn;
    }

    /**
     * Lower is better: active live, active simulated, superseded live,
     * superseded simulated, other.
     */
    private function priority(AgentDraft $draft, bool $preferSimulated): int
    {
        $active = ! in_array($draft->status, [
            AgentDraft::STATUS_SUPERSEDED,
            AgentDraft::STATUS_EXPIRED,
        ], true);

        if ($preferSimulated) {
            return $draft->isSimulated() ? ($active ? 0 : 2) : ($active ? 1 : 3);
        }

        if ($draft->isSimulated()) {
            return $active ? 1 : 3;
        }

        return $active ? 0 : 2;
    }

    /**
     * @return array<string, mixed>
     */
    private function event(AgentDraft $draft): array
    {
        $judge = $draft->comparison['judge'] ?? null;

        return [
            'draft' => $draft,
            'status' => $draft->status,
            'is_simulated' => $draft->isSimulated(),
            'is_superseded' => $draft->status === AgentDraft::STATUS_SUPERSEDED,
            'media_only' => MessageText::isMediaOnly((string) $draft->artist_reply),
            'media_note' => MediaNote::dedupe((string) $draft->artist_media_note),
            'verdict' => is_array($judge) ? ($judge['verdict'] ?? null) : null,
            'acceptable' => is_array($judge) ? ($judge['acceptable'] ?? null) : null,
            'rationale' => is_array($judge) ? ($judge['rationale'] ?? null) : null,
        ];
    }

    private function norm(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
