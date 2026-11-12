<?php

namespace App\Services\Agent;

use Illuminate\Support\Str;

class PreflightClassifier
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly string $model,
    ) {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{intent: string, entities: array, flags: array, confidence: float}
     */
    public function classify(string $context, string $leadText, array $history = []): array
    {
        $system = (string) file_get_contents(resource_path('prompts/preflight-classify.md'));

        $lines = [];
        foreach ($history as $message) {
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $lines[] = (($message['role'] ?? 'user') === 'assistant' ? 'Estúdio' : 'Lead').': '.$content;
        }

        $content = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "CONTEXTO:\n".$context
                ."\n\nHISTÓRICO RECENTE (mais antigo primeiro):\n".($lines === [] ? '_(sem histórico)_' : implode("\n", $lines))
                ."\n\nMENSAGEM DO LEAD:\n".$leadText],
        ], $this->model);

        $parsed = Json::parse($content);
        $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;

        $assumptions = $this->assumptions($parsed['assumptions'] ?? null);
        $entities = is_array($parsed['entities'] ?? null) ? $parsed['entities'] : [];
        $evidenceText = $this->leadEvidenceText($leadText, $history);

        [$entities, $assumptions] = $this->guardEntities($entities, $assumptions, $evidenceText);

        $flags = is_array($parsed['flags'] ?? null) ? $parsed['flags'] : [];

        // The model tends to read "não quero cobrir" as a cover-up. The
        // deterministic check is negation-aware, so it wins.
        $flags['mentions_coverup'] = (new EntityEvidence($evidenceText))
            ->supports('tattoo_type', 'cobertura');

        // The artist often closes a slot in the chat before (or instead of)
        // creating the CRM booking. The agent must not deny a time the lead
        // has already been told.
        $flags['booking_in_chat'] = $this->bookingInChat($history);

        // Context flags used by the briefing. They are deterministic because
        // they change the question the agent is allowed to ask.
        $flags['lead_photos'] = $this->leadPhotos($leadText, $history);
        $flags['lead_busy'] = $this->leadBusy($leadText, $history);
        $flags['coverup_refused'] = $this->coverupRefused($evidenceText);

        return [
            'intent' => is_string($parsed['intent'] ?? null) ? $parsed['intent'] : 'other',
            'entities' => $entities,
            'assumptions' => $assumptions,
            'flags' => $flags,
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    /**
     * The lead's own words, plus the confirmation question they just said
     * "sim" to. Assistant questions are otherwise excluded.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function leadEvidenceText(string $leadText, array $history): string
    {
        $text = $leadText;

        foreach ($history as $message) {
            if (($message['role'] ?? 'user') === 'assistant') {
                continue;
            }
            $text .= "\n".(string) ($message['content'] ?? '');
        }

        $lastAssistant = null;
        foreach (array_reverse($history) as $message) {
            if (($message['role'] ?? 'user') === 'assistant') {
                $lastAssistant = (string) ($message['content'] ?? '');
                break;
            }
        }

        if ($lastAssistant !== null
            && preg_match('/^\s*(sim|isso|certo|correto|exato|pode ser|beleza|ok|blz|ta|tá|e isso|é isso)\b/iu', trim($leadText)) === 1
            && preg_match('/(certo|correto|seria|é isso|e isso)\s*\?/iu', $lastAssistant) === 1) {
            $text .= "\n".$lastAssistant;
        }

        return $text;
    }

    /**
     * The lead already sent a photo/video in this conversation.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function leadPhotos(string $leadText, array $history): bool
    {
        $text = $leadText;

        foreach ($history as $message) {
            if (($message['role'] ?? 'user') === 'assistant') {
                continue;
            }
            $text .= "\n".(string) ($message['content'] ?? '');
        }

        return preg_match('/\[(FOTO|MÍDIA|VÍDEO|STICKER|ÁUDIO) RECEBID[OA]\]/u', $text) === 1;
    }

    /**
     * The lead said they are driving, busy or will come back later.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function leadBusy(string $leadText, array $history): bool
    {
        $text = '';

        foreach (array_slice($history, -6) as $message) {
            if (($message['role'] ?? 'user') === 'assistant') {
                continue;
            }
            $text .= ' '.(string) ($message['content'] ?? '');
        }

        $text .= ' '.$leadText;
        $text = Str::ascii(mb_strtolower($text));

        return preg_match('/\b(dirigindo|correria|ocupad[oa]|sem tempo|depois eu vejo|daqui a pouco|no trabalho)\b/u', $text) === 1;
    }

    /**
     * The lead explicitly refused a cover-up. Re-offering it is a repeated
     * question, not a new option.
     */
    private function coverupRefused(string $evidenceText): bool
    {
        $text = Str::ascii(mb_strtolower($evidenceText));

        return preg_match('/(nao (quero|vou|da pra|da para)|nao da certo|nem) [a-z ]{0,24}cobrir/u', $text) === 1
            || preg_match('/cobrir [a-z ]{0,24}(nao da|nao funciona|nao rola)/u', $text) === 1
            || preg_match('/nao quero (cobertura|cover)/u', $text) === 1;
    }

    /**
     * The artist confirmed a slot in the conversation. Only artist messages
     * count; the copilot's own drafts never enter memory.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function bookingInChat(array $history): bool
    {
        $text = '';

        foreach ($history as $message) {
            if (($message['role'] ?? '') !== 'assistant') {
                continue;
            }
            $text .= "\n".(string) ($message['content'] ?? '');
        }

        if (trim($text) === '') {
            return false;
        }

        $text = Str::ascii(mb_strtolower($text));

        foreach ([
            '/fica agendado/',
            '/esta agendado/',
            '/agendamos/',
            '/fechado para/',
            '/confirmado para/',
            '/horario reservado/',
            '/reservei/',
        ] as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Anything the lead did not actually say is demoted to an assumption and
     * must be confirmed before the project uses it. Assistant questions are
     * excluded from the evidence on purpose: "é nova, cobertura ou reforma?"
     * must not make "nova" look explicit.
     *
     * @param  array<string, mixed>  $entities
     * @param  array<string, array{value: mixed, confidence: float, reason: string}>  $assumptions
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{0: array<string, mixed>, 1: array<string, array{value: mixed, confidence: float, reason: string}>}
     */
    private function guardEntities(array $entities, array $assumptions, string $evidenceText): array
    {
        $evidence = new EntityEvidence($evidenceText);
        $keep = [];

        foreach ($entities as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                $keep[$field] = $value;

                continue;
            }

            // Context-only fields are resolved by the system, not by words.
            if (in_array($field, ['requested_day', 'requested_window', 'selected_slot_start', 'meaning'], true)) {
                $keep[$field] = $value;

                continue;
            }

            if ($field === 'name') {
                if ($evidence->supports('name', (string) $value)) {
                    $keep[$field] = $value;
                }

                continue;
            }

            if ($evidence->supports($field, $value)) {
                $keep[$field] = $value;
                unset($assumptions[$field]);

                continue;
            }

            if (! isset($assumptions[$field])) {
                $assumptions[$field] = [
                    'value' => $value,
                    'confidence' => 0.7,
                    'reason' => 'inferido pelo contexto',
                ];
            }
        }

        return [$keep, $assumptions];
    }

    /**
     * @return array<string, array{value: mixed, confidence: float, reason: string}>
     */
    private function assumptions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $allowed = ['tattoo_type', 'placement', 'body_zone', 'style', 'first_tattoo', 'meaning'];
        $out = [];

        foreach ($allowed as $field) {
            $entry = $raw[$field] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $value = $entry['value'] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $confidence = max(0.0, min(1.0, (float) ($entry['confidence'] ?? 0)));

            if ($confidence < 0.3) {
                continue;
            }

            if ($field === 'first_tattoo') {
                if (! in_array($value, [true, false, 'true', 'false', 1, 0, '1', '0'], true)) {
                    continue;
                }
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } else {
                $value = trim((string) $value);

                if ($value === '') {
                    continue;
                }
            }

            $out[$field] = [
                'value' => $value,
                'confidence' => $confidence,
                'reason' => trim((string) ($entry['reason'] ?? '')),
            ];
        }

        return $out;
    }
}
