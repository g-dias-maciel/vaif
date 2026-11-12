<?php

namespace App\Services\Agent;

use Illuminate\Support\Str;

/**
 * Checks whether an entity the classifier extracted is actually supported by
 * the lead's own words (never by the assistant's questions).
 *
 * The classifier tends to infer values ("pediu na mão" -> nova). When that
 * happens the value must become an assumption and be confirmed, not written to
 * the project as a fact.
 */
class EntityEvidence
{
    private string $text;

    public function __construct(string $text)
    {
        $this->text = Str::ascii(mb_strtolower($text));
    }

    public function supports(string $field, mixed $value): bool
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } else {
            $value = Str::ascii(mb_strtolower(trim((string) $value)));
        }

        if ($value === '') {
            return false;
        }

        return match ($field) {
            'tattoo_type' => $this->tattooType($value),
            'placement' => $this->placement($value),
            'body_zone' => $this->bodyZone($value),
            'style' => $this->style($value),
            'first_tattoo' => $this->firstTattoo($value),
            'name' => $this->name($value),
            default => true,
        };
    }

    private function tattooType(string $value): bool
    {
        return match ($value) {
            'nova' => $this->any(['/\bnova\b/', '/\bnovo\b/', '/tatuagem nova/', '/tattoo nova/']),
            'cobertura' => $this->any([
                '/cobertura/',
                '/cover ?up/',
                '/cobrir[^.]{0,30}(tatuagem|tattoo)/',
                '/cobrir (ela|ele|essa|esse|aquela|aquele|minha)/',
                '/\bcubro\b/',
                '/tampar/',
            ]),
            'reforma' => $this->any(['/reform/', '/retoc/', '/refaz/']),
            default => false,
        };
    }

    private function placement(string $value): bool
    {
        return match ($value) {
            'braco' => $this->any(['/braco/']),
            'braco_externo' => $this->any(['/braco/']) && $this->any(['/externo|externa|fora/']),
            'braco_interno' => $this->any(['/braco/']) && $this->any(['/interno|interna|dentro/']),
            'antebraco' => $this->any(['/antebraco/']),
            'mao' => $this->any(['/\bmao\b/']),
            'pe' => $this->any(['/\bpe\b/']),
            'dedo' => $this->any(['/dedo/']),
            'pulso' => $this->any(['/pulso/']),
            'costas' => $this->any(['/costas/']),
            'peito' => $this->any(['/peito/']),
            'perna' => $this->any(['/perna/']),
            'coxa' => $this->any(['/coxa/']),
            'panturrilha' => $this->any(['/panturrilha|batata da perna/']),
            'tornozelo' => $this->any(['/tornozelo/']),
            'pescoco' => $this->any(['/pescoco/']),
            'costela' => $this->any(['/costela/']),
            'barriga' => $this->any(['/barriga|abdome/']),
            'ombro' => $this->any(['/ombro/']),
            default => false,
        };
    }

    private function bodyZone(string $value): bool
    {
        return match ($value) {
            'pequeno' => $this->any(['/pequen|mini/']),
            'medio' => $this->any(['/medi/']),
            'grande' => $this->any(['/grande/']),
            'fechamento' => $this->any(['/fechamento|inteiro|fechad/']),
            default => false,
        };
    }

    private function style(string $value): bool
    {
        return match ($value) {
            'realismo' => $this->any(['/realismo|realista/']),
            'blackwork' => $this->any(['/black ?work/']),
            'fine_line' => $this->any(['/fine ?line|traco fino/']),
            'old_school' => $this->any(['/old ?school/']),
            'neo_tradicional' => $this->any(['/neo ?tradicional/']),
            'tradicional' => $this->any(['/tradicional/']),
            'oriental' => $this->any(['/oriental|japones/']),
            'tribal' => $this->any(['/tribal/']),
            'geometrico' => $this->any(['/geometric/']),
            'pontilhismo' => $this->any(['/pontilhismo|pontilhado/']),
            'aquarela' => $this->any(['/aquarela/']),
            'floral' => $this->any(['/flor|floral/']),
            'japones' => $this->any(['/japones|oriental/']),
            'minimalista' => $this->any(['/minimalista|minimal/']),
            default => false,
        };
    }

    private function firstTattoo(string $value): bool
    {
        return match ($value) {
            'true' => $this->any(['/primeira (tatuagem|tattoo)/', '/\bprimeira\b/', '/nunca (fiz|tive)/', '/nenhuma/']),
            'false' => $this->any(['/tenho (outra|outras)/', '/ja tenho/', '/outras (tatuagens|tattoos)/', '/algumas/', '/mais de uma/']),
            default => false,
        };
    }

    private function name(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && str_contains($this->text, $value);
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function any(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesAffirmatively($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesAffirmatively(string $pattern): bool
    {
        if (preg_match_all($pattern, $this->text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }

        foreach ($matches[0] as [$match, $offset]) {
            $before = substr($this->text, max(0, $offset - 40), min(40, $offset));

            // "não quero cobrir", "nunca fiz", "nem pensar em cobertura".
            if (preg_match('/(nao|nunca|nem|sem)\s+[a-z ]{0,24}$/', $before) === 1) {
                continue;
            }

            return true;
        }

        return false;
    }
}
