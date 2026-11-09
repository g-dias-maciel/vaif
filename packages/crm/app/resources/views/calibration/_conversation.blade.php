@php($turns = $turns ?? [])
@php($artistName = $artistName ?? 'Artista')

<div class="chat-thread" style="max-height:60vh; overflow-y:auto; padding-right:6px;">
    @forelse ($turns as $turn)
        <div style="margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid var(--border);">
            @foreach ($turn['clients'] as $client)
                <div class="chat-bubble inbound">
                    <div class="chat-who">
                        {{ $client['sender'] ?? 'Cliente' }}
                        <span class="meta">· {{ $client['at']?->format('d/m H:i') }}</span>
                    </div>
                    <div class="chat-text">{{ $client['content'] ?? '' }}</div>
                </div>
            @endforeach

            @if ($turn['draft'])
                @php($draft = $turn['draft'])
                <div style="margin:10px 0; padding:11px 13px; border:1px dashed var(--gold, #D4B04C); border-radius:10px; background:rgba(212,176,76,.05);">
                    <div class="meta" style="text-transform:uppercase; letter-spacing:.5px;">
                        <strong style="color:var(--gold, #D4B04C);">Beatriz teria respondido</strong>
                        · {{ $draft['draft']->created_at?->format('d/m H:i') }}

                        @if (($draft['status'] ?? null) === 'pending')
                            <span class="tag tag-muted">aguardando o artista</span>
                        @elseif (($draft['status'] ?? null) === 'replied')
                            <span class="tag tag-muted">comparando</span>
                        @elseif (($draft['status'] ?? null) === 'compared')
                            <span class="tag {{ ($draft['acceptable'] ?? false) ? 'tag-success' : 'tag-muted' }}">
                                {{ ($draft['acceptable'] ?? false) ? 'aceitável' : 'revisar' }}
                            </span>
                        @endif
                    </div>

                    <div class="chat-text" style="margin-top:6px;">{{ $draft['draft']->draft_reply }}</div>

                    @if (! empty($draft['rationale']))
                        <div class="meta" style="margin-top:6px;">
                            @if (($draft['verdict'] ?? null) === 'draft_better')
                                Rascunho melhor ·
                            @elseif (($draft['verdict'] ?? null) === 'artist_better')
                                Artista melhor ·
                            @elseif (($draft['verdict'] ?? null) === 'tie')
                                Equivalente ·
                            @endif
                            {{ $draft['rationale'] }}
                        </div>
                    @endif
                </div>
            @elseif (! empty($turn['clients']))
                <div class="meta" style="margin:8px 0;">Sem sugestão da Beatriz registrada para esta mensagem.</div>
            @endif

            @foreach ($turn['right'] as $right)
                <div class="chat-bubble outbound {{ ($right['is_artist'] ?? false) ? 'artist' : '' }}">
                    <div class="chat-who">
                        {{ $right['sender'] ?? $artistName }}
                        <span class="meta">· {{ $right['at']?->format('d/m H:i') }}</span>
                    </div>
                    <div class="chat-text">{{ $right['content'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    @empty
        <p class="meta">Nenhuma mensagem nesta conversa.</p>
    @endforelse
</div>
