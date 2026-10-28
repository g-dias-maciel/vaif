@if ($chatMessages->isEmpty())
    <p class="meta">Nenhuma conversa registrada ainda.</p>
@else
    <div class="chat-thread">
        @foreach ($chatMessages as $message)
            <div class="chat-bubble {{ $message->direction }}">
                <div class="chat-who">{{ $message->direction === 'inbound' ? 'Cliente' : 'Beatriz' }}</div>
                <div class="chat-text">{{ $message->content !== '' ? $message->content : '—' }}</div>
                <div class="chat-time">{{ $message->created_at?->format('d/m H:i') }}</div>
            </div>
        @endforeach
    </div>
    <p class="meta" style="margin-top:10px;">Últimas {{ $chatMessages->count() }} mensagens · atualiza automaticamente.</p>
@endif
