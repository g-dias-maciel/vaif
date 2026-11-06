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
