@extends('layouts.app')

@section('title', 'Calibração — ' . $artist->displayName())

@section('content')
<style>
    .calib-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }
    .calib-card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
    .calib-value { font-size: 1.55rem; font-weight: 600; color: var(--gold); line-height: 1.1; }
    .calib-label { color: var(--muted); font-size: .7rem; text-transform: uppercase; letter-spacing: .8px; margin-top: 5px; }
    .calib-table { width: 100%; border-collapse: collapse; }
    .calib-table th, .calib-table td { padding: 9px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .calib-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }
    .calib-mode { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: .68rem; letter-spacing: .4px; text-transform: uppercase; border: 1px solid var(--border); }
    .calib-mode--auto { color: #fff; background: var(--gold); border-color: var(--gold); }
    .calib-mode--draft { color: var(--gold); border-color: var(--gold); }
    .calib-mode--off { color: var(--muted); }
    .calib-pass { color: var(--gold); font-weight: 600; }
    .calib-fail { color: var(--danger, #d05a5a); font-weight: 600; }
    .calib-draft { border: 1px solid var(--border); border-radius: 10px; padding: 14px; margin-bottom: 12px; }
    .calib-draft h3 { margin: 0 0 6px; font-size: .9rem; }
    .calib-message { white-space: pre-line; line-height: 1.55; }
    .calib-chip { display: inline-block; font-size: .66rem; letter-spacing: .4px; text-transform: uppercase; padding: 2px 7px; border-radius: 999px; border: 1px solid var(--border); color: var(--muted); }
    .calib-chip--ok { color: var(--gold); border-color: var(--gold); }
    .calib-chip--bad { color: var(--danger, #d05a5a); border-color: var(--danger, #d05a5a); }
</style>

<a href="{{ route('calibration.index') }}" class="back">&larr; Calibração</a>

<div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
    <div>
        <h1 class="page-title" style="margin-bottom:4px;">{{ $artist->displayName() }}</h1>
        <div class="meta">
            Sessão <code>{{ $artist->wa_session_slug ?: 'sem sessão' }}</code>
            · status do artista: <strong>{{ $artist->status }}</strong>
            · últimos 30 dias
        </div>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="{{ route('artists.show', $artist) }}" class="btn btn--ghost">Ver artista</a>
        <a href="{{ route('calibration.show', $artist) }}" class="btn btn--ghost">Atualizar</a>
    </div>
</div>

<div class="calib-grid" style="margin-bottom:20px;">
    <div class="calib-card">
        <div class="calib-value">{{ $report['totals']['compared'] }}</div>
        <div class="calib-label">Pares comparados</div>
    </div>
    <div class="calib-card">
        <div class="calib-value">{{ (int) round($report['rates']['acceptable'] * 100) }}%</div>
        <div class="calib-label">Aceitáveis</div>
    </div>
    <div class="calib-card">
        <div class="calib-value">{{ (int) round($report['rates']['response'] * 100) }}%</div>
        <div class="calib-label">Respondidos pelo artista</div>
    </div>
    <div class="calib-card">
        <div class="calib-value">{{ $report['totals']['median_response_seconds'] !== null ? $report['totals']['median_response_seconds'] . 's' : '—' }}</div>
        <div class="calib-label">Resposta mediana</div>
    </div>
    <div class="calib-card">
        <div class="calib-value {{ $report['passed'] ? 'calib-pass' : 'calib-fail' }}">{{ $report['passed'] ? 'PASS' : 'FAIL' }}</div>
        <div class="calib-label">Gates de calibração</div>
    </div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <h2 class="section-title" style="margin-top:0;">Modo do agente</h2>
    <p class="meta" style="max-width:820px;">
        <strong>off</strong> não rascunha nem responde. <strong>draft</strong> rascunha, mostra no lead e compara com o artista — nada é enviado, e funciona mesmo com o artista suspenso.
        <strong>auto</strong> envia a resposta validada ao lead. Promover para auto exige os gates abaixo.
    </p>

    @if (! ($schemaReady ?? true))
        <div class="flash error" style="margin-bottom:14px;">
            A migração de calibração ainda não foi aplicada. Rode
            <code>packages/crm/db/migrations/0033_agent_calibration.sql</code> para liberar a troca de modo e os rascunhos.
        </div>
    @endif

    <form method="post" action="{{ route('calibration.mode', $artist) }}" style="display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; margin-top:14px; @if(! ($schemaReady ?? true)) opacity:.5; pointer-events:none; @endif">
        @csrf
        <div class="form-field" style="min-width:260px;">
            <label for="reply-mode">Modo</label>
            <select name="reply_mode" id="reply-mode">
                <option value="off" @selected($report['reply_mode'] === 'off')>off — desligado</option>
                <option value="draft" @selected($report['reply_mode'] === 'draft')>draft — rascunho e compara (não envia)</option>
                <option value="auto" @selected($report['reply_mode'] === 'auto')>auto — envia ao lead</option>
            </select>
        </div>
        <label class="meta" style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
            <input type="checkbox" name="force" value="1">
            Forçar auto mesmo sem os gates
        </label>
        <button type="submit" class="btn">Salvar modo</button>
    </form>

    @if (! $report['passed'])
        <div class="flash error" style="margin-top:14px;">
            Auto está bloqueado até os gates passarem. Use <strong>draft</strong> para continuar coletando pares.
        </div>
    @endif
</div>

<div class="panel" style="margin-bottom:20px;">
    <h2 class="section-title" style="margin-top:0;">Webhook do WAHA</h2>
    <p class="meta">
        A troca é feita com <code>PUT /api/sessions/{session}</code>: a autenticação do WhatsApp é preservada e o artista não lê QR de novo.
    </p>

    @if (! $artist->wa_session_slug)
        <div class="flash error">Este artista não tem sessão WhatsApp configurada.</div>
    @elseif ($webhook === null)
        <div class="flash error">Não foi possível consultar o WAHA agora. Verifique a conexão e a API key.</div>
    @else
        @php($current = rtrim((string) ($webhook['url'] ?? ''), '/'))
        @php($isCrm = $current !== '' && $current === rtrim($crmWebhookUrl, '/'))
        @php($isN8n = $current !== '' && $current === rtrim($n8nWebhookUrl, '/'))
        <table class="calib-table" style="margin-top:12px;">
            <tr>
                <th style="width:170px;">Status WAHA</th>
                <td>{{ $webhook['status'] }}{{ $webhook['me'] ? ' · ' . $webhook['me'] : '' }}</td>
            </tr>
            <tr>
                <th>Webhook atual</th>
                <td>
                    @if ($current === '')
                        <span class="meta">nenhum webhook configurado</span>
                    @else
                        <code style="word-break:break-all;">{{ $current }}</code>
                        @if ($isCrm)
                            <span class="calib-chip calib-chip--ok">CRM</span>
                        @elseif ($isN8n)
                            <span class="calib-chip">n8n</span>
                        @else
                            <span class="calib-chip calib-chip--bad">outro destino</span>
                        @endif
                    @endif
                </td>
            </tr>
            <tr>
                <th>Eventos</th>
                <td>{{ ! empty($webhook['events']) ? implode(', ', $webhook['events']) : '—' }}</td>
            </tr>
            <tr>
                <th>Token do webhook</th>
                <td>{{ $webhook['has_token'] ? 'configurado' : 'sem header customizado' }}</td>
            </tr>
        </table>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px;">
            @if (! $isCrm)
                <form method="post" action="{{ route('calibration.webhook', $artist) }}" class="inline">
                    @csrf
                    <input type="hidden" name="target" value="crm">
                    <button type="submit" class="btn"
                        onclick="return confirm('Apontar esta sessão para o CRM? Em draft nada é enviado; em auto a Beatriz envia ao lead. Continuar?');">
                        Apontar para o CRM
                    </button>
                </form>
            @endif
            @if (! $isN8n)
                <form method="post" action="{{ route('calibration.webhook', $artist) }}" class="inline">
                    @csrf
                    <input type="hidden" name="target" value="n8n">
                    <button type="submit" class="btn btn--ghost"
                        onclick="return confirm('Voltar esta sessão para o n8n? O agente do CRM deixa de receber estas mensagens. Continuar?');">
                        Voltar para o n8n
                    </button>
                </form>
            @endif
        </div>
        @if (config('transport.n8n_webhook_token') === null)
            <p class="meta" style="margin-top:10px;">Para o rollback ao n8n, configure <code>N8N_WAHA_WEBHOOK_TOKEN</code> se o token do n8n for diferente do token do CRM.</p>
        @endif
    @endif
</div>

<div class="panel" style="margin-bottom:20px;">
    <h2 class="section-title" style="margin-top:0;">Gates</h2>
    <table class="calib-table">
        <thead>
            <tr><th>Gate</th><th>Atual</th><th>Alvo</th><th>Resultado</th><th>Tipo</th></tr>
        </thead>
        <tbody>
            @foreach ($report['gates'] as $gate)
                <tr>
                    <td>{{ $gate['label'] }}</td>
                    <td>{{ $gate['actual'] }}</td>
                    <td>{{ $gate['target'] }}</td>
                    <td class="{{ $gate['pass'] ? 'calib-pass' : 'calib-fail' }}">{{ $gate['pass'] ? 'PASS' : 'FAIL' }}</td>
                    <td class="meta">{{ $gate['hard'] ? 'obrigatório' : 'aviso' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (! empty($report['warnings']))
        <div style="margin-top:12px;">
            @foreach ($report['warnings'] as $warning)
                <div class="meta">Cobertura: {{ $warning }}</div>
            @endforeach
        </div>
    @endif

    <div class="meta" style="margin-top:12px;">
        Drafts: {{ $report['totals']['drafts'] }}
        · respondidos: {{ $report['totals']['answered'] }}
        · pendentes: {{ $report['totals']['pending'] }}
        · expirados: {{ $report['totals']['expired'] }}
        · substituídos: {{ $report['totals']['superseded'] }}
        · fatos inventados: {{ $report['totals']['unsafe_drafts'] }}
        · pergunta coberta: {{ (int) round($report['rates']['covered_question'] * 100) }}%
        · tom: {{ $report['rates']['tone_avg'] !== null ? $report['rates']['tone_avg'] . '/5' : '—' }}
    </div>
</div>

<div class="panel">
    <h2 class="section-title" style="margin-top:0;">Conversas com sugestões</h2>
    <p class="meta">{{ $draftTotal ?? 0 }} rascunho(s) em {{ $draftGroups->count() ?? 0 }} conversa(s), agrupados por lead.</p>

    @forelse ($draftGroups as $group)
        @php($draft = $group['latest'])
        @php($judge = $draft->comparison['judge'] ?? null)
        @php($det = $draft->comparison['deterministic'] ?? null)
        <div class="calib-draft">
            <h3>
                {{ $group['lead_name'] ?: 'Conversa sem nome' }}
                <span class="calib-chip">{{ $group['count'] }} sugestõe(s)</span>
                <span class="calib-chip">{{ $draft->status }}</span>
                @if ($judge)
                    <span class="calib-chip {{ ($judge['acceptable'] ?? false) ? 'calib-chip--ok' : 'calib-chip--bad' }}">
                        {{ ($judge['acceptable'] ?? false) ? 'aceitável' : 'revisar' }}
                    </span>
                @endif
                <span class="meta" style="float:right;">{{ $draft->created_at?->format('d/m H:i') }} · {{ $draft->model }}</span>
            </h3>

            <div class="meta" style="margin-bottom:8px;">Chat: <code>{{ $group['chat_id'] }}</code></div>

            <div class="meta" style="margin:2px 0 8px; font-weight:600;">
                Conversa · cliente à esquerda, {{ $artist->displayName() }} à direita, Beatriz abaixo do cliente
            </div>

            @if (! empty($group['lead']) && ($group['missing'] ?? 0) > 0)
                <form method="post" action="{{ route('calibration.mock', [$artist, $group['lead']]) }}" style="margin-bottom:10px;">
                    @csrf
                    <button type="submit" class="btn btn--small"
                        onclick="return confirm('Gerar respostas simuladas da Beatriz para as mensagens sem sugestão? Nada será enviado ao lead.');">
                        Gerar {{ min(10, $group['missing']) }} resposta(s) da Beatriz
                        @if ($group['missing'] > 10)
                            (de {{ $group['missing'] }})
                        @endif
                    </button>
                    <span class="meta">· simulação, nada é enviado.</span>
                </form>
            @endif

            @include('calibration._conversation', [
                'blocks' => $group['blocks'] ?? [],
                'artistName' => $artist->displayName(),
            ])

            <details style="margin-top:14px;">
                <summary class="meta" style="cursor:pointer;">Diagnóstico da última sugestão</summary>

                <div style="margin-top:10px;">
                    <div class="meta" style="margin-bottom:4px;">Última sugestão</div>
                    <div class="calib-message" style="margin-bottom:8px;">{{ $draft->draft_reply }}</div>

                    @if ($draft->artist_reply)
                        <div class="meta" style="margin-bottom:4px;">Resposta do artista</div>
                        <div class="calib-message" style="margin-bottom:10px;">{{ $draft->artist_reply }}</div>
                    @endif

                    @if ($judge)
                        <div class="meta" style="margin-bottom:6px;">
                            Veredito: <strong>{{ $judge['verdict'] ?? '—' }}</strong>
                            · pergunta coberta: {{ ($judge['covered_lead_question'] ?? false) ? 'sim' : 'não' }}
                            · handoff perdido: {{ ($judge['missing_handoff'] ?? false) ? 'sim' : 'não' }}
                            · tom: {{ $judge['tone'] ?? '—' }}/5
                        </div>
                        @if (! empty($judge['rationale']))
                            <div class="calib-message" style="margin-bottom:8px;">{{ $judge['rationale'] }}</div>
                        @endif
                        @if (! empty($judge['missing']))
                            <div class="meta">Faltou: {{ implode(' · ', $judge['missing']) }}</div>
                        @endif
                        @if (! empty($judge['factual_conflicts']))
                            <div class="meta" style="color: var(--danger, #d05a5a);">Conflitos: {{ implode(' · ', $judge['factual_conflicts']) }}</div>
                        @endif
                    @elseif ($draft->status === 'pending')
                        <div class="meta">Aguardando a resposta do artista no WhatsApp.</div>
                    @endif

                    @if ($det && ! empty($det['violations']))
                        <div class="meta" style="margin-top:6px;">Validador na resposta do artista: {{ implode(', ', $det['violations']) }}</div>
                    @endif

                    @if (! empty($draft->simulated_actions))
                        <div class="meta" style="margin-top:6px;">
                            Simulado (não gravado): {{ json_encode($draft->simulated_actions, JSON_UNESCAPED_UNICODE) }}
                        </div>
                    @endif

                    <div class="meta" style="margin-top:6px;">Prompt {{ $draft->prompt_version ?: '—' }} · {{ $draft->latency_ms }}ms</div>

                    @if ($group['history']->isNotEmpty())
                        <div class="meta" style="margin-top:12px; font-weight:600;">Sugestões anteriores</div>
                        <div style="margin-top:4px;">
                            @foreach ($group['history'] as $old)
                                @php($oldJudge = $old->comparison['judge'] ?? null)
                                <div style="border-top:1px solid var(--border); padding:8px 0;">
                                    <div class="meta">
                                        {{ $old->created_at?->format('d/m H:i') }}
                                        · {{ $old->intent ?: '—' }}
                                        · {{ $old->status }}
                                        @if ($oldJudge)
                                            · {{ $oldJudge['verdict'] ?? '' }}
                                        @endif
                                    </div>
                                    <div class="calib-message" style="opacity:.8;">{{ \Illuminate\Support\Str::limit($old->draft_reply, 180) }}</div>
                                    @if ($old->artist_reply)
                                        <div class="meta">Artista: {{ \Illuminate\Support\Str::limit($old->artist_reply, 160) }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </details>

            @if (! empty($group['lead']))
                <div style="margin-top:10px;">
                    <a href="{{ route('leads.show', $group['lead']) }}" class="btn btn--ghost btn--small">Abrir lead</a>
                </div>
            @endif
        </div>
    @empty
        <p class="meta">Nenhum rascunho ainda. Quando o artista estiver em draft e a sessão apontar para o CRM, as sugestões aparecem aqui e na página do lead.</p>
    @endforelse
</div>
@endsection
