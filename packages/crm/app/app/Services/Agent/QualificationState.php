<?php

namespace App\Services\Agent;

/**
 * The deterministic qualification order.
 *
 * The model writes the sentence and maps the lead's words to canonical
 * values; this class owns which field is missing and which question is
 * allowed next. The validator can therefore reject a reply that stops,
 * restarts or asks another field out of order.
 */
class QualificationState
{
    public const REQUIRED_FIELDS = [
        'tattoo_type',
        'placement',
        'body_zone',
        'style',
    ];

    public const FIELDS = [
        'tattoo_type',
        'placement',
        'body_zone',
        'style',
        'first_tattoo',
        'meaning',
    ];

    public const QUESTIONS = [
        'tattoo_type' => 'A tatuagem que você quer é nova, uma cobertura ou uma reforma?',
        'placement' => 'Onde no corpo você quer fazer?',
        'body_zone' => 'Nesse local, você quer uma tattoo pequena, uma área média ou o local inteiro?',
        'style' => 'Qual estilo você tem em mente?',
        'first_tattoo' => 'É a sua primeira tatuagem?',
        'meaning' => 'Tem algum significado ou referência para essa tattoo?',
    ];

    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, array{value: mixed, confidence: float}>  $assumptions
     * @param  array<int, string>  $skipped
     */
    public function __construct(
        private readonly array $project,
        private readonly array $assumptions = [],
        private readonly array $skipped = [],
        private readonly ?string $tattooType = null,
    ) {
    }

    public function nextField(): ?string
    {
        return $this->nextMissing(self::FIELDS);
    }

    /**
     * The fields that block a price/booking. First tattoo and meaning are
     * useful context, but they are optional and must not stop the quote.
     */
    public function requiredNextField(): ?string
    {
        return $this->nextMissing(self::REQUIRED_FIELDS);
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function nextMissing(array $fields): ?string
    {
        $type = $this->tattooType ?? ($this->project['tattoo_type'] ?? null);

        if (in_array($type, ['cover_up', 'rework'], true)) {
            return null;
        }

        foreach ($fields as $field) {
            if (in_array($field, $this->skipped, true)) {
                continue;
            }

            if (! $this->filled($field)) {
                return $field;
            }
        }

        return null;
    }

    public function isComplete(): bool
    {
        return $this->nextField() === null;
    }

    public function questionFor(string $field): string
    {
        $assumption = $this->confidentAssumption($field);

        if ($assumption !== null) {
            return $this->confirmationQuestion($field, $assumption['value']) ?? self::QUESTIONS[$field];
        }

        return self::QUESTIONS[$field] ?? 'Me conta mais sobre a tatuagem que você quer fazer?';
    }

    public function isConfirmation(string $field): bool
    {
        return $this->confidentAssumption($field) !== null;
    }

    public function label(string $field): string
    {
        return match ($field) {
            'tattoo_type' => 'tipo',
            'placement' => 'local do corpo',
            'body_zone' => 'tamanho',
            'style' => 'estilo',
            'first_tattoo' => 'primeira tatuagem',
            'meaning' => 'significado',
            default => $field,
        };
    }

    /**
     * Does the reply appear to ask this field, even if the claim extractor
     * labelled the question as `other`?
     */
    public function asks(string $reply, string $field): bool
    {
        $reply = $this->norm($reply);

        return match ($field) {
            'tattoo_type' => preg_match('/nova|cobertura|reforma|cobrir|reformar/u', $reply) === 1,
            'placement' => preg_match('/onde no corpo|qual (o )?local|em qual (parte|local)|braco|perna|costas|peito|ombro|antebraco|coxa|panturrilha|tornozelo|pescoco|costela|barriga|pulso|mao|dedo|pe\b/u', $reply) === 1,
            'body_zone' => preg_match('/tamanho|pequen|media|medio|local inteiro|fechamento/u', $reply) === 1,
            'style' => preg_match('/estilo|fine line|realismo|blackwork|old school|tradicional|floral|minimalista|geometric/u', $reply) === 1,
            'first_tattoo' => preg_match('/primeira tatuagem|primeira tattoo|ja tem (outra|alguma)|primeira vez/u', $reply) === 1,
            'meaning' => preg_match('/significado|referencia|historia|simbolo|homenagem/u', $reply) === 1,
            default => false,
        };
    }

    private function filled(string $field): bool
    {
        if (! array_key_exists($field, $this->project)) {
            return false;
        }

        $value = $this->project[$field];

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * @return array{value: mixed, confidence: float}|null
     */
    private function confidentAssumption(string $field): ?array
    {
        $entry = $this->assumptions[$field] ?? null;

        if (! is_array($entry) || ! isset($entry['value'])) {
            return null;
        }

        $confidence = (float) ($entry['confidence'] ?? 0);

        if ($confidence < (float) config('agent.assumptions.min_confidence', 0.65)) {
            return null;
        }

        return ['value' => $entry['value'], 'confidence' => $confidence];
    }

    private function confirmationQuestion(string $field, mixed $value): ?string
    {
        $label = $this->assumptionLabel($field, $value);

        return match ($field) {
            'tattoo_type' => $label === 'nova'
                ? 'Essa tatuagem seria uma tatuagem nova, certo?'
                : 'Essa tatuagem seria '.($label === 'cobertura' ? 'uma cobertura' : 'uma reforma').', certo?',
            'placement' => 'Você quer fazer '.$label.', certo?',
            'body_zone' => 'Pelo que você mandou, parece '
                .($label === 'média' ? 'uma área média' : ($label === 'local inteiro' ? 'o local inteiro' : 'uma tattoo '.$label))
                .'. É isso?',
            'style' => 'Pelo que você mandou, o estilo é '.$label.', certo?',
            'first_tattoo' => $value === true || $value === 'true'
                ? 'Essa seria a sua primeira tatuagem, certo?'
                : 'Você já tem outras tatuagens, certo?',
            'meaning' => 'Entendi que a ideia tem o significado "'.$label.'", certo?',
            default => null,
        };
    }

    private function assumptionLabel(string $field, mixed $value): string
    {
        return match ($field) {
            'tattoo_type' => (string) $value,
            'placement' => $this->placementPhrase($value),
            'body_zone' => [
                'pequeno' => 'pequena',
                'medio' => 'média',
                'grande' => 'grande',
                'fechamento' => 'local inteiro',
            ][(string) $value] ?? (string) $value,
            'first_tattoo' => ($value === true || $value === 'true') ? 'sim' : 'não',
            'style' => str_replace('_', ' ', (string) $value),
            'meaning' => (string) $value,
            default => (string) $value,
        };
    }

    private function placementPhrase(mixed $value): string
    {
        return match ((string) $value) {
            'mao' => 'na mão',
            'pe' => 'no pé',
            'dedo' => 'no dedo',
            'pulso' => 'no pulso',
            'braco' => 'no braço',
            'braco_externo' => 'na parte externa do braço',
            'braco_interno' => 'na parte interna do braço',
            'antebraco' => 'no antebraço',
            'costas' => 'nas costas',
            'peito' => 'no peito',
            'perna' => 'na perna',
            'coxa' => 'na coxa',
            'panturrilha' => 'na panturrilha',
            'tornozelo' => 'no tornozelo',
            'pescoco' => 'no pescoço',
            'costela' => 'na costela',
            'barriga' => 'na barriga',
            'ombro' => 'no ombro',
            default => 'no local',
        };
    }

    private function norm(string $value): string
    {
        return \Illuminate\Support\Str::ascii(mb_strtolower($value));
    }
}
