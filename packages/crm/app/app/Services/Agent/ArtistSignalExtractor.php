<?php

namespace App\Services\Agent;

use App\Models\Artist;
use Illuminate\Support\Carbon;

/**
 * Extracts the observable signals from an artist's WhatsApp message: offered
 * slots, explicit booking/reschedule confirmations, price, deposit request and
 * promises. The artist is the authority; nothing here validates against the
 * price book.
 */
class ArtistSignalExtractor
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly string $model,
    ) {}

    /**
     * @param  array<string, mixed>  $turn
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    public function extract(Artist $artist, string $text, array $turn, array $history = [], bool $allowPast = false): array
    {
        $text = trim($text);

        if ($text === '') {
            return $this->empty();
        }

        $system = (string) file_get_contents(resource_path('prompts/observe-artist.md'));
        $tz = $artist->timezone ?: 'America/Sao_Paulo';
        $today = Carbon::now($tz)->toDateString();

        $lines = [];
        foreach ($history as $message) {
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $lines[] = (($message['role'] ?? 'user') === 'assistant' ? 'Estúdio' : 'Lead').': '.$content;
        }

        $state = is_array($turn['agent_state'] ?? null) ? $turn['agent_state'] : [];

        $context = [
            'data_hoje='.$today,
            'fuso='.$tz,
            'pipeline='.($turn['stage_key'] ?? '?'),
            'local='.($turn['placement'] ?? '?'),
            'zona='.($turn['body_zone'] ?? '?'),
            'tipo='.($turn['tattoo_type'] ?? '?'),
            'agendamento_atual='.($turn['active_booking_start'] ?? $turn['active_booking_id'] ?? '?'),
            'opcoes_oferecidas='.Json::string(is_array($state['offered_slots'] ?? null) ? $state['offered_slots'] : []),
        ];

        $content = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => 'CONTEXTO: '.implode(' ', $context)
                ."\n\nHISTÓRICO RECENTE (mais antigo primeiro):\n".($lines === [] ? '_(sem histórico)_' : implode("\n", $lines))
                ."\n\nMENSAGEM DO ARTISTA:\n".$text],
        ], $this->model);

        $parsed = Json::parse($content);

        return $this->normalize($parsed, $tz, $allowPast);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw, string $tz, bool $allowPast = false): array
    {
        $out = $this->empty();

        $slots = [];
        $rawSlots = is_array($raw['offered_slots'] ?? null) ? $raw['offered_slots'] : [];

        foreach (array_slice($rawSlots, 0, 6) as $slot) {
            $local = is_array($slot)
                ? ($slot['local'] ?? $slot['start_at'] ?? null)
                : $slot;
            $iso = $this->toIso($local, $tz, $allowPast);

            if ($iso === null) {
                continue;
            }

            $slots[] = [
                'start_at' => $iso,
                'label' => is_array($slot) ? trim((string) ($slot['label'] ?? '')) : '',
            ];
        }

        // Assign stable ids in the shape `agent_state.offered_slots` already uses.
        foreach ($slots as $index => $slot) {
            $slots[$index]['id'] = 'O'.($index + 1);
        }

        $out['offered_slots'] = $slots;
        $out['booking_confirmed_at'] = $this->toIso($raw['booking_confirmed_local'] ?? null, $tz, $allowPast);
        $out['reschedule_to'] = $this->toIso($raw['reschedule_local'] ?? null, $tz, $allowPast);

        $price = $this->positiveInt($raw['price_cents'] ?? null);
        $out['price_cents'] = $price;
        $out['deposit_cents'] = $this->positiveInt($raw['deposit_cents'] ?? null);
        $out['asks_for_deposit'] = (bool) ($raw['asks_for_deposit'] ?? false);

        $promises = is_array($raw['promises'] ?? null) ? $raw['promises'] : [];
        $out['promises'] = array_values(array_filter(array_map(
            fn ($promise) => is_string($promise) ? trim($promise) : null,
            $promises,
        )));

        $out['handoff'] = (bool) ($raw['handoff'] ?? false);
        $out['confidence'] = max(0.0, min(1.0, (float) ($raw['confidence'] ?? 0.0)));

        return $out;
    }

    private function toIso(mixed $value, string $tz, bool $allowPast = false): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // A date without a time is not a bookable slot.
        if (! str_contains($value, ':') && ! str_contains($value, 'T')) {
            return null;
        }

        // A bare date/time is in the studio timezone. An ISO string with an
        // offset is respected by the parser.
        try {
            $parsed = Carbon::parse($value, $tz);

            // Do not create a booking in the past from a stale live message.
            // Backfill explicitly opts into historical dates.
            if (! $allowPast && $parsed->lt(now()->subMinutes(5))) {
                return null;
            }

            return $parsed->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'offered_slots' => [],
            'booking_confirmed_at' => null,
            'reschedule_to' => null,
            'price_cents' => null,
            'deposit_cents' => null,
            'asks_for_deposit' => false,
            'promises' => [],
            'handoff' => false,
            'confidence' => 0.0,
        ];
    }
}
