<div class="chat-status" style="margin-bottom:10px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    @if (($chatOwner ?? 'agent') === 'artist')
        <span class="tag tag-success">Artista no atendimento</span>
        <span class="meta">A Beatriz está silenciada nesta conversa.</span>
        @isset($lead)
            <form method="post" action="{{ route('leads.return-chat', $lead) }}" class="inline" style="margin-left:auto;">
                @csrf
                <button type="submit" class="btn btn--ghost btn--small">Devolver para a Beatriz</button>
            </form>
        @endisset
    @else
        <span class="tag tag-muted">Beatriz no atendimento</span>
    @endif
</div>

@php($draftLatest = $draftSummary['latest'] ?? null)
@php($draftHistory = $draftSummary['history'] ?? collect())
@php($draftCount = $draftSummary['count'] ?? 0)

@if ($draftLatest)
    <div class="draft-panel" style="margin:12px 0; padding:10px; border:1px dashed var(--border, #d6d6d6); border-radius:10px;">
        <div class="meta" style="margin-bottom:8px;">
            <strong>Sugestão da Beatriz</strong> · rascunho não enviado
            @if ($draftCount > 1)
                · {{ $draftCount }} nesta conversa
            @endif
        </div>

        @php($judge = $draftLatest->comparison['judge'] ?? null)
        <div class="chat-bubble outbound" style="opacity:.92;">
            <div class="chat-who">
                {{ $draftLatest->created_at?->format('d/m H:i') }}
                @if ($draftLatest->status === 'pending')
                    <span class="tag tag-muted">aguardando o artista</span>
                @elseif ($draftLatest->status === 'replied')
                    <span class="tag tag-muted">comparando com a resposta</span>
                @elseif ($draftLatest->status === 'compared')
                    <span class="tag {{ ($judge['acceptable'] ?? false) ? 'tag-success' : 'tag-muted' }}">
                        {{ ($judge['acceptable'] ?? false) ? 'aceitável' : 'revisar' }}
                    </span>
                @endif
            </div>
            <div class="chat-text">{{ $draftLatest->draft_reply }}</div>

            @if ($draftLatest->artist_reply)
                <div class="chat-text" style="margin-top:6px; opacity:.85;">
                    <strong>Artista respondeu:</strong> {{ $draftLatest->artist_reply }}
                </div>
            @endif

            @if ($judge)
                <div class="meta" style="margin-top:6px;">
                    @if (($judge['verdict'] ?? null) === 'draft_better')
                        Rascunho melhor
                    @elseif (($judge['verdict'] ?? null) === 'artist_better')
                        Artista melhor
                    @elseif (($judge['verdict'] ?? null) === 'tie')
                        Equivalente
                    @endif
                    @if (! empty($judge['rationale']))
                        · {{ $judge['rationale'] }}
                    @endif
                </div>
            @endif
        </div>

        @if ($draftHistory->isNotEmpty())
            <details style="margin-top:10px;">
                <summary class="meta" style="cursor:pointer;">Ver sugestões anteriores ({{ $draftHistory->count() }})</summary>

                <div style="margin-top:8px;">
                    @foreach ($draftHistory as $draft)
                        @php($oldJudge = $draft->comparison['judge'] ?? null)
                        <div style="border-top:1px solid var(--border); padding:8px 0;">
                            <div class="meta">
                                {{ $draft->created_at?->format('d/m H:i') }}
                                · {{ $draft->status }}
                                @if ($oldJudge)
                                    · {{ $oldJudge['verdict'] ?? '' }}
                                @endif
                            </div>
                            <div class="chat-text" style="opacity:.8;">{{ \Illuminate\Support\Str::limit($draft->draft_reply, 180) }}</div>
                            @if ($draft->artist_reply)
                                <div class="meta">Artista: {{ \Illuminate\Support\Str::limit($draft->artist_reply, 160) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endif

@if ($chatMessages->isEmpty())
    <p class="meta">Nenhuma conversa registrada ainda.</p>
@else
    <div class="chat-thread">
        @foreach ($chatMessages as $message)
            <div class="chat-bubble {{ $message->direction }} {{ $message->is_artist ? 'artist' : '' }}">
                <div class="chat-who">{{ $message->sender_label }}</div>
                <div class="chat-text">{{ $message->content !== '' ? $message->content : '—' }}</div>
                <div class="chat-time">{{ $message->created_at?->format('d/m H:i') }}</div>
            </div>
        @endforeach
    </div>
    <p class="meta" style="margin-top:10px;">Últimas {{ $chatMessages->count() }} mensagens · atualiza automaticamente.</p>
@endif
