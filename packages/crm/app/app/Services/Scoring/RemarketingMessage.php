<?php

namespace App\Services\Scoring;

use App\Models\Artist;
use App\Models\Lead;
use App\Models\Project;

/**
 * Deterministic WhatsApp message suggestions for the manual queue.
 *
 * The Artist can edit the text in WhatsApp before sending. Nothing here quotes
 * a price that is not already on the Project, and nothing promises a discount.
 * The AI phase replaces this with a composed message but keeps the same facts.
 */
class RemarketingMessage
{
    public function for(Artist $artist, Lead $lead, ?Project $project, string $segment): string
    {
        $name = $lead->displayName();
        $greeting = $name === 'Sem nome' ? 'Oi! Tudo bem?' : 'Oi '.$this->firstName($name).'! Tudo bem?';

        if ($segment === 'reviver' || $project?->stage_key === 'perdido') {
            $local = $this->local($project);

            return $greeting.' Faz um tempo que a gente conversou sobre a tatuagem '.$local
                .'. Abri novos horários e lembrei de você — ainda tem interesse?';
        }

        return match ($project?->stage_key) {
            'aguardando_deposito' => $greeting
                .' Seu horário ainda está reservado. Quer que eu te mande o PIX de novo para garantir?',

            'orcamento_enviado' => $this->quoted($greeting, $project),

            'aguardando_artista' => $greeting
                .' A '.$artist->displayName().' já vai te responder sobre a tatuagem '
                .$this->local($project).'. Enquanto isso, posso te ajudar em algo?',

            default => $greeting.' Voltando aqui sobre a tatuagem '.$this->local($project)
                .'. Quer continuar de onde paramos?',
        };
    }

    private function quoted(string $greeting, ?Project $project): string
    {
        $local = $this->local($project);
        $quote = $project?->quotes->sortByDesc('created_at')->first();

        if ($quote === null) {
            return $greeting.' Passando para saber se ficou alguma dúvida sobre a tatuagem '
                .$local.'. Consigo te ajudar no que precisar.';
        }

        $price = 'R$ '.number_format($quote->offered_price_cents / 100, 2, ',', '.');

        return $greeting.' Passando para saber se ficou alguma dúvida sobre o orçamento de '
            .$price.' para a tatuagem '.$local.'. Qualquer coisa, estou por aqui.';
    }

    private function local(?Project $project): string
    {
        $placement = $project?->placement_label;
        $zone = $project?->body_zone_label;

        if ($placement !== null && $zone !== null) {
            return 'no '.mb_strtolower($placement).' ('.$zone.')';
        }

        if ($placement !== null) {
            return 'no '.mb_strtolower($placement);
        }

        if ($zone !== null) {
            return 'na região '.mb_strtolower($zone);
        }

        return 'que você queria fazer';
    }

    private function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return $parts[0] ?? $name;
    }
}
