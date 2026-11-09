@php($items = $timeline ?? [])
@php($scroll = $scroll ?? true)

<div class="chat-thread" @if ($scroll) style="max-height:60vh; overflow-y:auto; padding-right:6px;" @endif>
    @forelse ($items as $item)
        @if (($item['kind'] ?? null) === 'draft')
            @php($draft = $item['draft'])
            <div class="chat-bubble outbound"
                style="border:1px dashed var(--gold, #D4B04C); background: transparent; opacity:.95;">
                <div class="chat-who">
                    {{ $item['sender'] }}
                    @if (($item['status'] ?? null) === 'pending')
                        <span class="tag tag-muted">aguardando o artista</span>
                    @elseif (($item['status'] ?? null) === 'replied')
                        <span class="tag tag-muted">comparando</span>
                    @elseif (($item['status'] ?? null) === 'compared')
                        <span class="tag {{ ($item['acceptable'] ?? false) ? 'tag-success' : 'tag-muted' }}">
                            {{ ($item['acceptable'] ?? false) ? 'aceitável' : 'revisar' }}
                        </span>
                    @endif
                </div>

                <div class="chat-text">{{ $item['content'] }}</div>

                @if (! empty($item['rationale']))
                    <div class="meta" style="margin-top:6px;">
                        @if (($item['verdict'] ?? null) === 'draft_better')
                            Rascunho melhor ·
                        @elseif (($item['verdict'] ?? null) === 'artist_better')
                            Artista melhor ·
                        @elseif (($item['verdict'] ?? null) === 'tie')
                            Equivalente ·
                        @endif
                        {{ $item['rationale'] }}
                    </div>
                @endif

                <div class="chat-time">{{ $item['at']?->format('d/m H:i') }}</div>
            </div>
        @else
            <div class="chat-bubble {{ ($item['kind'] ?? '') === 'lead' ? 'inbound' : 'outbound' }} {{ ($item['kind'] ?? '') === 'artist' ? 'artist' : '' }}">
                <div class="chat-who">{{ $item['sender'] ?? '—' }}</div>
                <div class="chat-text">{{ $item['content'] ?? '' }}</div>
                <div class="chat-time">{{ $item['at']?->format('d/m H:i') }}</div>
            </div>
        @endif
    @empty
        <p class="meta">Nenhuma mensagem registrada ainda.</p>
    @endforelse
</div>
