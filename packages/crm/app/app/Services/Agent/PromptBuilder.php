<?php

namespace App\Services\Agent;

use App\Models\Artist;
use Carbon\CarbonImmutable;

class PromptBuilder
{
    public function system(Artist $artist, array $turn, array $facts): string
    {
        $settings = $artist->settings;
        $template = (string) file_get_contents(resource_path('prompts/beatriz-system.md'));

        $floor = $settings?->floor_pct !== null ? (float) $settings->floor_pct : 80.0;
        $depositType = $settings?->deposit_type ?: 'percent';
        $depositValue = $settings?->deposit_value !== null ? (float) $settings->deposit_value : 30.0;
        $deposit = $facts['pricing']['deposit_cents'] ?? null;

        $pricing = $facts['pricing'] ?? [];
        $base = $pricing['base_cents'] ?? null;
        $offered = $pricing['offered_cents'] ?? $base;
        $table = $base !== null
            ? "| Local | Tamanho | À vista |\n|---|---|---|\n| ".($facts['project']['placement'] ?? '?').' | '.($facts['project']['body_zone'] ?? '?').' | '.Json::money($base).' |'
            : '_Nenhuma tabela de preços cadastrada para este projeto._';

        $sinal = $depositType === 'fixed'
            ? ($depositValue > 0 ? 'R$ '.number_format($depositValue, 2, ',', '.') : '(não informado)')
            : ($depositValue > 0 ? rtrim(rtrim(number_format($depositValue, 2, ',', '.')), '0') . '%' : '(não informado)');

        $replacements = [
            '{{NOME}}' => $settings?->display_name ?: $artist->name,
            '{{INSTAGRAM}}' => $settings?->instagram_handle ?: '(não informado)',
            '{{PIX}}' => $settings?->pix_key ?: '(informe a chave PIX)',
            '{{SINAL}}' => $deposit !== null ? Json::money((int) $deposit) : $sinal,
            '{{PISO}}' => $floor.'%',
            '{{DESCONTO_MAX}}' => (100 - $floor).'%',
            '{{TABELA_PRECOS}}' => $table,
            '{{OFFER}}' => $this->offerBlock($pricing),
            '{{ESTILOS}}' => ! empty($settings?->specialties) ? implode(', ', $settings->specialties) : '(não informado)',
            '{{NAO_FAZ}}' => ! empty($settings?->excluded_styles) ? implode(', ', $settings->excluded_styles) : '(não informado)',
            '{{MODO_VENDA}}' => ($settings?->full_selling ?? true)
                ? 'Venda completa: qualifique, apresente o orçamento e feche o agendamento.'
                : 'Triagem: qualifique e passe para o artista; não apresente preço nem horário.',
            '{{CIDADE}}' => $settings?->city ?: '(não informado)',
            '{{ENDERECO}}' => $settings?->address ?: '(não informado)',
        ];

        $prompt = strtr($template, $replacements);

        return $prompt."\n\n".$this->factsBlock($facts)."\n".$this->referenceBlock($turn['reference_analysis'] ?? [])."\n";
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    public function messages(Artist $artist, array $turn, array $facts, string $leadText, array $history = []): array
    {
        $messages = [['role' => 'system', 'content' => $this->system($artist, $turn, $facts)]];

        foreach ($history as $message) {
            if (! empty($message['content'])) {
                $messages[] = $message;
            }
        }

        $messages[] = ['role' => 'user', 'content' => $this->context($artist, $turn)."\n".$leadText];

        return $messages;
    }

    public function context(Artist $artist, array $turn): string
    {
        $state = $turn['agent_state'] ?? [];
        $tz = $artist->timezone ?: 'America/Sao_Paulo';
        $today = CarbonImmutable::now($tz)->toDateString();
        $type = ['new' => 'nova', 'cover_up' => 'cobertura', 'rework' => 'reforma'][$turn['tattoo_type'] ?? ''] ?? '?';

        $parts = [
            'pipeline='.($turn['stage_key'] ?? '?'),
            'nome='.($turn['name'] ?? 'desconhecido'),
            'deposit='.($turn['deposit_status'] ?? 'nao_solicitado'),
            'tipo='.$type,
            'lead_id='.($turn['lead_id'] ?? ''),
            'project_id='.($turn['project_id'] ?? ''),
            'placement='.($turn['placement'] ?? '?'),
            'zona='.($turn['body_zone'] ?? '?'),
            'estilo='.($turn['style'] ?? '?'),
            'primeira_tatuagem='.(($turn['first_tattoo'] ?? null) === true ? 'sim' : (($turn['first_tattoo'] ?? null) === false ? 'nao' : '?')),
            'significado='.($turn['meaning'] ?? '?'),
            'processo_explicado='.(! empty($state['processo_explicado']) ? 'sim' : 'nao'),
            'preco_liberado='.(! empty($state['preco_liberado']) ? 'sim' : 'nao'),
            'duvidas_eliminadas='.(! empty($state['duvidas_eliminadas']) ? 'sim' : 'nao'),
            'aguardando_confirmacao_duvidas='.(! empty($state['aguardando_confirmacao_duvidas']) ? 'sim' : 'nao'),
            'primeira_mensagem='.(! empty($turn['is_new']) ? 'sim' : 'nao'),
            'preco_tabela='.($turn['table_price_cents'] ?? '?'),
            'preco_negociado='.($turn['quoted_price_cents'] ?? '?'),
            'agendamento_atual='.($turn['active_booking_start'] ?? '?'),
            'pergunta_pendente='.($state['pending_question'] ?? '?'),
            'opcoes_oferecidas='.Json::string(is_array($state['offered_slots'] ?? null) ? $state['offered_slots'] : []),
            'data_hoje='.$today,
        ];

        return '[Contexto: '.implode(' ', $parts).'] [artist_id='.$artist->id.']';
    }

    /**
     * Active promotion / open quote. The deterministic price engine already
     * chose the value; the prompt must not fall back to the table price.
     */
    private function offerBlock(array $pricing): string
    {
        $blocks = [];
        $promo = is_array($pricing['promotion'] ?? null) ? $pricing['promotion'] : [];

        if (! empty($promo['price_cents'])) {
            $multiplier = (float) ($promo['multiplier'] ?? 1);
            $blocks[] = 'PROMOÇÃO ATIVA: "'.($promo['name'] ?? 'promoção').'". '
                .'O preço a apresentar JÁ é o preço da promoção (multiplicador '.$multiplier.'x sobre a tabela). '
                .'NUNCA apresente o preço da tabela quando a promoção está ativa. '
                .'Apresente o valor da promoção como o preço normal e, em seguida, mencione a promoção: fazendo uma tatuagem, você ganha outra do mesmo tamanho, grátis. '
                .'NUNCA fale do VALOR da segunda tatuagem: ela é combinada diretamente com o artista e não é agendada por você.';
        }

        if (! empty($pricing['open_quote'])) {
            $blocks[] = 'Já existe uma COTAÇÃO aberta para este lead (proteção de preço): '
                .Json::money((int) ($pricing['offered_cents'] ?? 0))
                .'. HONRE essa cotação mesmo que a promoção tenha sido pausada ou que o preço da promoção seja diferente.';
        }

        return $blocks === []
            ? '_Nenhuma promoção ativa no momento. Apresente apenas o preço normal da tabela._'
            : implode("\n\n", $blocks);
    }

    /**
     * The automatic vision analysis of the reference image. It is supporting
     * context, never a source of price.
     */
    private function referenceBlock(array $analysis): string
    {
        if ($analysis === [] || empty($analysis['assunto'])) {
            return '';
        }

        $flag = fn ($value) => in_array($value, [false, 'nao', 'não'], true) ? 'não' : 'sim';
        $summary = trim((string) ($analysis['assunto'] ?? $analysis['resumo'] ?? 'imagem recebida'));

        $lines = [
            '## Referência visual (análise automática)',
            'A imagem recebida foi analisada automaticamente. Use como apoio, não como verdade absoluta.',
            '- É referência de tatuagem: '.$flag($analysis['referencia_de_tatuagem'] ?? null),
            '- Referência: '.$summary,
        ];

        foreach ([
            'estilo' => 'Estilo provável',
            'cores' => 'Cores',
            'complexidade' => 'Complexidade',
            'tipo' => 'Tipo de imagem',
            'cobertura' => 'Cobertura/reforma',
            'confianca' => 'Confiança da análise',
            'observacoes' => 'Observações',
        ] as $key => $label) {
            if (! empty($analysis[$key])) {
                $lines[] = '- '.$label.': '.$analysis[$key];
            }
        }

        $lines[] = 'Regras: na PRIMEIRA resposta após esta análise, reconheça a referência em uma frase curta usando o assunto; não descreva detalhes minuciosos; não aja como se não tivesse visto a imagem. Se a confiança for baixa ou não for referência, peça outra foto. NUNCA use esta análise para calcular preço — o preço vem da tabela.';

        return implode("\n", $lines);
    }

    private function factsBlock(array $facts): string
    {
        $pricing = $facts['pricing'] ?? [];
        $conditions = $facts['conditions'] ?? [];
        $availability = $facts['availability'] ?? [];
        $booking = $facts['booking'] ?? [];
        $portfolio = $facts['portfolio'] ?? [];
        $client = $facts['client'] ?? [];
        $intent = $facts['turn']['intent'] ?? 'other';
        $wantsSlots = in_array($intent, ['ask_availability', 'choose_slot', 'close', 'reschedule'], true);

        $lines = ['## FATOS DESTE TURNO (fonte: CRM)'];

        if (($pricing['status'] ?? null) === 'ready') {
            $base = $pricing['base_cents'] ?? null;
            $promo = $pricing['promotion']['price_cents'] ?? null;
            $present = ! empty($pricing['open_quote'])
                ? ($pricing['offered_cents'] ?? $base)
                : ($promo ?? $base ?? $pricing['offered_cents'] ?? null);

            $line = '- Preço a apresentar: '.Json::money($present).' à vista. Este é o ÚNICO preço que você pode afirmar neste turno.';

            if ($base !== null && (int) $base !== (int) $present) {
                $line .= ' (tabela: '.Json::money($base).'; promoção/cotação já aplicada).';
            }

            $lines[] = $line;
        } else {
            $lines[] = '- Preço: não disponível para este local/tamanho. NÃO informe valor; pergunte o que falta ou faça handoff.';
        }

        if (! empty($pricing['deposit_cents'])) {
            $lines[] = '- Sinal: '.Json::money((int) $pricing['deposit_cents']).'.';
        }

        $lines[] = '- PIX: '.(! empty($conditions['pix_available']) ? 'disponível' : 'não configurado').'.';

        if (($conditions['reschedule_hours'] ?? null) !== null) {
            $lines[] = '- Remarcação: até '.$conditions['reschedule_hours'].' horas antes.';
        } else {
            $lines[] = '- Remarcação: política não configurada. NÃO afirme prazo; diga que vai confirmar com o artista.';
        }

        if (($conditions['cancellation_hours'] ?? null) !== null) {
            $lines[] = '- Cancelamento: até '.$conditions['cancellation_hours'].' horas antes.';
        }

        $methods = $conditions['payment_methods'] ?? [];
        if (is_array($methods) && $methods !== []) {
            $lines[] = '- Pagamento: '.implode(', ', $methods).(! empty($conditions['pix_available']) ? ' e PIX' : '').'.';
        } elseif (! empty($conditions['pix_available'])) {
            $lines[] = '- Pagamento: PIX.';
        }

        $lines[] = '- Segurar horário: '.(! empty($conditions['allow_hold_slot']) ? 'permitido' : 'NÃO permitido sem sinal');
        $lines[] = '- Garantir preço: '.(! empty($conditions['guarantee_price']) ? 'permitido conforme o orçamento' : 'NÃO permitido');

        if (! empty($portfolio['url'])) {
            $lines[] = '- Portfólio: '.$portfolio['url'].' (envie este link quando pedirem fotos; não invente outro).';
        } else {
            $lines[] = '- Portfólio: link não configurado. Diga que vai pedir ao artista; NÃO prometa fotos.';
        }

        if (! empty($client['returning'])) {
            $last = ! empty($client['last_booking_at']) ? ' (último atendimento: '.substr((string) $client['last_booking_at'], 0, 10).')' : '';
            $lines[] = '- Cliente: já tem histórico com o estúdio'.$last.'. Não trate como lead novo.';
        }

        if ($wantsSlots) {
            if (($availability['status'] ?? null) === 'ready' && ! empty($availability['slots'])) {
                $slots = array_map(fn ($s) => ($s['id'] ?? '?').' '.($s['label'] ?? ''), $availability['slots']);
                $lines[] = '- Horários disponíveis (ofereça SOMENTE estes, no máximo 2): '.implode(' | ', $slots).'.';
            } elseif (($availability['status'] ?? null) === 'no_slots') {
                $lines[] = '- Horários: nenhum para o período pedido. Diga que esse dia está cheio e pergunte outro dia.';
            } else {
                $lines[] = '- Horários: ainda não é possível oferecer. Falta: '.($availability['missing'] ?? 'qualificação').'. NÃO diga que a agenda está cheia; pergunte o que falta.';
            }
        }

        if (! empty($booking['created_this_turn']) && ! empty($booking['start_at'])) {
            $lines[] = '- O horário '.$booking['start_at'].' foi reservado agora. Confirme para o lead.';
        }

        if (! empty($booking['active']) && ! empty($booking['start_at'])) {
            $lines[] = '- Agendamento atual: '.$booking['start_at'].'.';
        }

        $lines[] = 'REGRA: não invente preço, desconto, parcela, condição ou prazo. Se o dado não estiver nos FATOS, não afirme.';

        return implode("\n", $lines);
    }
}
