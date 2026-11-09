<?php

namespace App\Services\Agent;

use App\Models\AgentDraft;
use App\Models\Lead;

/**
 * Builds calibration turns for one lead:
 *
 *   client message(s)  ->  Beatriz draft (not sent)  ->  artist reply(ies)
 *
 * This is the chat-shaped view used on the Calibração page. The generic
 * timeline is still used on the lead page.
 */
class CalibrationConversation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forLead(Lead $lead, int $limit = 60): array
    {
        $artistName = $lead->artist?->displayName() ?: 'Artista';
        $events = [];

        try {
            $messages = $lead->chatMessages()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
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

            $isHuman = $message->message_type === 'human';
            $isArtist = $message->is_artist;

            $events[] = [
                'at' => $message->created_at,
                'kind' => $isHuman ? 'client' : 'right',
                'content' => $content,
                'sender' => $isHuman
                    ? 'Cliente'
                    : ($isArtist ? $artistName : 'Beatriz (enviado)'),
                'is_artist' => $isArtist,
                'order' => $isHuman ? 0 : 2,
            ];
        }

        try {
            $drafts = AgentDraft::query()
                ->where('lead_id', $lead->id)
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

            $events[] = [
                'at' => $draft->created_at,
                'kind' => 'draft',
                'draft' => $draft,
                'lead_text' => $draft->lead_text,
                'order' => 1,
                'status' => $draft->status,
                'verdict' => is_array($judge) ? ($judge['verdict'] ?? null) : null,
                'acceptable' => is_array($judge) ? ($judge['acceptable'] ?? null) : null,
                'rationale' => is_array($judge) ? ($judge['rationale'] ?? null) : null,
            ];
        }

        usort($events, function (array $a, array $b) {
            $left = $a['at']?->getTimestamp() ?? 0;
            $right = $b['at']?->getTimestamp() ?? 0;

            return $left <=> $right ?: (($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        });

        $turns = [];
        $index = -1;

        foreach ($events as $event) {
            if ($event['kind'] === 'client') {
                $current = $turns[$index] ?? null;
                $clients = $current['clients'] ?? [];
                $lastClientAt = $clients !== [] ? end($clients)['at'] : null;

                $sameBurst = $current !== null
                    && $current['draft'] === null
                    && $current['right'] === []
                    && $lastClientAt !== null
                    && $lastClientAt->diffInSeconds($event['at']) <= 300;

                if ($sameBurst) {
                    $turns[$index]['clients'][] = $event;

                    continue;
                }

                $turns[] = ['clients' => [$event], 'draft' => null, 'right' => []];
                $index = count($turns) - 1;

                continue;
            }

            if ($event['kind'] === 'draft') {
                $current = $turns[$index] ?? null;

                if ($current !== null && $current['draft'] === null) {
                    if ($current['clients'] === [] && trim((string) $event['lead_text']) !== '') {
                        $turns[$index]['clients'][] = [
                            'at' => $event['at'],
                            'kind' => 'client',
                            'content' => $event['lead_text'],
                            'sender' => 'Cliente',
                            'is_artist' => false,
                            'order' => 0,
                        ];
                    }

                    $turns[$index]['draft'] = $event;

                    continue;
                }

                $turns[] = [
                    'clients' => trim((string) $event['lead_text']) !== '' ? [[
                        'at' => $event['at'],
                        'kind' => 'client',
                        'content' => $event['lead_text'],
                        'sender' => 'Cliente',
                        'is_artist' => false,
                        'order' => 0,
                    ]] : [],
                    'draft' => $event,
                    'right' => [],
                ];
                $index = count($turns) - 1;

                continue;
            }

            // Artist/Betriz sent message.
            $current = $turns[$index] ?? null;

            if ($current !== null) {
                $turns[$index]['right'][] = $event;

                continue;
            }

            $turns[] = ['clients' => [], 'draft' => null, 'right' => [$event]];
            $index = count($turns) - 1;
        }

        if (count($turns) > $limit) {
            $turns = array_slice($turns, -$limit);
        }

        return $turns;
    }
}
