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

@if (isset($drafts) && $drafts->isNotEmpty())
    <div class="draft-panel" style="margin:12px 0; padding:10px; border:1px dashed var(--border, #d6d6d6); border-radius:10px;">
        <div class="meta" style="margin-bottom:8px;"><strong>Sugestões da Beatriz</strong> · rascunhos não enviados</div>

        @foreach ($drafts as $draft)
            @php($judge = $draft->comparison['judge'] ?? null)
            <div class="chat-bubble outbound" style="opacity:.92; margin-bottom:8px;">
                <div class="chat-who">
                    Sugestão · {{ $draft->created_at?->format('d/m H:i') }}
                    @if ($draft->status === 'pending')
                        <span class="tag tag-muted">aguardando o artista</span>
                    @elseif ($draft->status === 'compared')
                        <span class="tag {{ ($judge['acceptable'] ?? false) ? 'tag-success' : 'tag-muted' }}">
                            {{ ($judge['acceptable'] ?? false) ? 'aceitável' : 'revisar' }}
                        </span>
                    @endif
                </div>
                <div class="chat-text">{{ $draft->draft_reply }}</div>

                @if ($draft->artist_reply)
                    <div class="chat-text" style="margin-top:6px; opacity:.85;">
                        <strong>Artista respondeu:</strong> {{ $draft->artist_reply }}
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
        @endforeach
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
