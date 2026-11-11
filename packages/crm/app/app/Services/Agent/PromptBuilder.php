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
            'nome='.($this->cleanName($turn['name'] ?? null) ?? 'desconhecido'),
            'nome_confiavel='.($this->looksLikeName($turn['name'] ?? null) ? 'sim' : 'nao'),
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
     * What the conversation already established, and the single next question
     * the agent is allowed to ask. Prevents re-asking known fields.
     *
     * @return array<int, string>
     */
    private function briefingBlock(array $facts): array
    {
        $project = is_array($facts['project'] ?? null) ? $facts['project'] : [];
        $studio = is_array($facts['studio'] ?? null) ? $facts['studio'] : [];
        $type = (string) ($project['tattoo_type'] ?? '');
        $typeLabel = ['new' => 'nova', 'cover_up' => 'cobertura', 'rework' => 'reforma'][$type] ?? null;

        $lines = ['', '## BRIEFING DO LEAD (o que já sabemos)'];
        $known = [];

        if ($typeLabel !== null) {
            $known[] = 'tipo='.$typeLabel;
        }
        if (! empty($project['placement'])) {
            $known[] = 'local='.$project['placement'];
        }
        if (! empty($project['body_zone'])) {
            $known[] = 'tamanho='.$project['body_zone'];
        }
        if (! empty($project['style'])) {
            $known[] = 'estilo='.$project['style'];
        }
        if (array_key_exists('first_tattoo', $project) && $project['first_tattoo'] !== null) {
            $known[] = 'primeira tatuagem='.($project['first_tattoo'] ? 'sim' : 'não');
        }
        if (! empty($project['meaning'])) {
            $known[] = 'significado='.$project['meaning'];
        }

        $lines[] = '- '.($known === [] ? 'nada ainda; comece pelo nome e pelo tipo.' : implode(' · ', $known));

        $flags = is_array($facts['turn']['flags'] ?? null) ? $facts['turn']['flags'] : [];
        if (! empty($flags['mentions_coverup']) && ! in_array($type, ['cover_up', 'rework'], true)) {
            $lines[] = '- O lead falou em cobrir/reformar. Trate como cobertura: NÃO dê preço nem horário, peça a foto da tatuagem atual e faça handoff.';
        }

        if (in_array($type, ['cover_up', 'rework'], true)) {
            $lines[] = '- Cobertura/reforma: NÃO pergunte tipo, estilo, primeira tatuagem nem significado. Peça a foto da tatuagem atual; se já veio, pergunte o local e o tamanho aproximado da tatuagem ATUAL. Depois peça a referência do desenho novo e faça handoff.';
        } else {
            $assumptions = is_array($facts['assumptions'] ?? null) ? $facts['assumptions'] : [];
            $next = null;
            $key = null;

            if ($typeLabel === null) {
                $key = 'tattoo_type';
                $next = ['tipo', 'A tatuagem que você quer é nova, uma cobertura ou uma reforma?'];
            } elseif (empty($project['placement'])) {
                $key = 'placement';
                $next = ['local do corpo', 'Onde no corpo você quer fazer?'];
            } elseif (empty($project['body_zone'])) {
                $key = 'body_zone';
                $next = ['tamanho', 'Nesse local, você quer uma tattoo pequena, uma área média ou o local inteiro?'];
            } elseif (empty($project['style'])) {
                $key = 'style';
                $next = ['estilo', 'Qual estilo você tem em mente?'];
            } elseif (! array_key_exists('first_tattoo', $project) || $project['first_tattoo'] === null) {
                $key = 'first_tattoo';
                $next = ['primeira tatuagem', 'É a sua primeira tatuagem?'];
            } elseif (empty($project['meaning'])) {
                $key = 'meaning';
                $next = ['significado', 'Tem algum significado ou referência para essa tattoo?'];
            }

            if ($assumptions !== []) {
                $bits = [];
                foreach ($assumptions as $field => $entry) {
                    $bits[] = $field.'='.$this->assumptionLabel($field, $entry['value'] ?? null)
                        .' ('.(int) round((float) ($entry['confidence'] ?? 0) * 100).'%)';
                }
                $lines[] = '- Suposições do contexto (confirme, NÃO trate como fato): '.implode(' · ', $bits).'.';
            }

            if ($next === null) {
                $lines[] = '- Qualificação completa. O próximo passo é o orçamento (se o gate estiver aberto) ou o fechamento.';
            } else {
                $minConfidence = (float) config('agent.assumptions.min_confidence', 0.65);
                $assumption = $key !== null ? ($assumptions[$key] ?? null) : null;
                $confirmed = is_array($assumption)
                    && (float) ($assumption['confidence'] ?? 0) >= $minConfidence
                    && isset($assumption['value'])
                        ? $this->confirmationQuestion($key, $assumption['value'])
                        : null;

                $lines[] = '- Ainda falta: '.$next[0].'. Próxima pergunta permitida (UMA só): "'.($confirmed ?? $next[1]).'"';

                if ($confirmed !== null) {
                    $lines[] = '- Essa pergunta é uma CONFIRMAÇÃO de uma suposição do contexto. Use a forma fechada ("certo?"/"é isso?"), não a pergunta aberta.';
                }
            }

            $lines[] = '- NUNCA pergunte de novo um campo que já aparece preenchido acima, mesmo que o lead tenha mudado de assunto.';
        }

        $hours = is_array($studio['working_hours'] ?? null) ? $studio['working_hours'] : [];
        if ($hours !== []) {
            $order = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
            $labels = ['seg' => 'seg', 'ter' => 'ter', 'qua' => 'qua', 'qui' => 'qui', 'sex' => 'sex', 'sab' => 'sáb', 'dom' => 'dom'];
            $parts = [];

            foreach ($order as $key) {
                if (empty($hours[$key]) || ! is_array($hours[$key])) {
                    continue;
                }
                $parts[] = $labels[$key].' '.implode(' / ', $hours[$key]);
            }

            if ($parts !== []) {
                $lines[] = '- Funcionamento: '.implode(' · ', $parts).'. NUNCA confirme um horário fora dessas janelas; se o lead pedir, ofereça uma opção real da agenda.';
            }
        }

        $lines[] = '- Responda PRIMEIRO ao que o lead acabou de dizer ou perguntar. Só depois faça a próxima pergunta permitida.';

        return $lines;
    }

    /**
     * A WhatsApp profile name is only trusted when it looks like a person:
     * no digits, no emoji, at most three words.
     */
    private function looksLikeName(mixed $name): bool
    {
        $name = $this->cleanName($name);

        if ($name === null || mb_strlen($name) > 60) {
            return false;
        }

        if (preg_match('/\d/', $name) === 1) {
            return false;
        }

        if (preg_match('/[^\p{L}\s\.\'\-]/u', $name) === 1) {
            return false;
        }

        $lower = \Illuminate\Support\Str::ascii(mb_strtolower($name));

        if (preg_match('/\b(studio|estudio|tattoo|tatuagem|ink|piercing|arte)\b/', $lower) === 1) {
            return false;
        }

        $words = preg_split('/\s+/u', $name) ?: [];

        return count($words) >= 1 && count($words) <= 3;
    }

    private function cleanName(mixed $name): ?string
    {
        $name = trim((string) $name);
        $name = preg_replace('/[\p{So}\p{Sk}\x{1F000}-\x{1FAFF}]+/u', ' ', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return $name === '' ? null : $name;
    }

    /**
     * A closed question that confirms what the AI inferred from the context.
     * The lead can answer "sim" and the field is captured from the history.
     */
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

        if ($intent === 'spam') {
            $lines[] = '- Intenção: spam, número errado ou assunto que não é tatuagem. Encerre em uma frase e NÃO faça pergunta de qualificação.';
        }

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

        if (empty($pricing['promotion']['price_cents'])) {
            $lines[] = '- Promoção: nenhuma ativa nos FATOS. Se o lead citar uma oferta ou preço de anúncio, diga que vai confirmar com o artista; NÃO confirme desconto nem condição.';
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

        if (empty($conditions['allow_hold_slot'])) {
            $lines[] = '- Proibido escrever "deixei salvo", "deixo salvo", "reservei", "está reservado", "fechado" ou "garantido" quando não há reserva criada nos FATOS. Diga que o horário só fica reservado depois do sinal.';
        }

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

        $depositStatus = (string) ($booking['deposit_status'] ?? '');
        $depositConfirmed = $depositStatus === 'confirmado';

        if (! empty($booking['created_this_turn']) && ! empty($booking['start_at'])) {
            $lines[] = $depositConfirmed
                ? '- O horário '.$booking['start_at'].' foi reservado e o sinal está confirmado. Confirme o agendamento.'
                : '- O horário '.$booking['start_at'].' foi PRÉ-RESERVADO agora. NÃO diga que está confirmado: peça o sinal/PIX e diga que só confirma depois do pagamento.';
        }

        if (! empty($booking['active']) && ! empty($booking['start_at'])) {
            $lines[] = '- Agendamento atual: '.$booking['start_at'].'.';

            if (! $depositConfirmed) {
                $lines[] = '- O horário está pré-reservado aguardando sinal. Peça o PIX agora; a confirmação só acontece depois do pagamento.';
            }
        }

        $turnFlags = is_array($facts['turn']['flags'] ?? null) ? $facts['turn']['flags'] : [];
        if (! empty($turnFlags['booking_in_chat']) && empty($booking['active']) && empty($booking['created_this_turn'])) {
            $lines[] = '- O artista já combinou um horário no chat, mas não há reserva no CRM. NÃO diga que não há horário nem negue a reserva; diga que vai confirmar com o artista e faça handoff.';
        }

        $lines = array_merge($lines, $this->briefingBlock($facts));

        $lines[] = 'REGRA: não invente preço, desconto, parcela, condição ou prazo. Se o dado não estiver nos FATOS, não afirme.';

        return implode("\n", $lines);
    }
}
