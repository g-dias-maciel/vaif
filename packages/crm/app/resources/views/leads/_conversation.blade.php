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

@include('leads._timeline', ['timeline' => $timeline ?? [], 'scroll' => true])

<p class="meta" style="margin-top:10px;">
    {{ count($timeline ?? []) }} item(ns) · atualiza automaticamente.
</p>
