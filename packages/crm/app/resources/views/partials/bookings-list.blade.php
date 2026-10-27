<ul class="slot-list">
    @forelse ($bookings as $b)
        <li>
            <div class="slot-main">
                <strong>
                    @if (! $readonly && $b->tattoo)
                        <a href="{{ route('tattoos.show', $b->tattoo) }}">{{ $b->lead?->nome ?: 'Cliente' }}</a>
                    @else
                        {{ $b->lead?->nome ?: 'Cliente' }}
                    @endif
                </strong>
                <span>{{ $b->start_at->format('d/m/Y H:i') }} – {{ $b->end_at->format('H:i') }}</span>
                @if ($b->tattoo?->placement)
                    <span class="gold">{{ $b->tattoo->placement }}</span>
                @endif
                @if ($b->tattoo)
                    @if ($b->tattoo->deposit_status === 'confirmado')
                        <span class="tag tag-success">Sinal pago</span>
                    @elseif ($b->tattoo->deposit_status === 'aguardando_confirmacao')
                        <span class="tag">Aguardando sinal</span>
                    @else
                        <span class="tag tag-muted">Sem sinal</span>
                    @endif
                @endif
            </div>
            @unless ($readonly)
                <div class="actions-row">
                    @if ($b->tattoo && $b->tattoo->deposit_status !== 'confirmado')
                        <form method="post" action="{{ route('bookings.confirm-deposit', $b) }}" class="inline">
                            @csrf
                            <button type="submit" class="btn btn--small">Confirmar sinal</button>
                        </form>
                    @endif
                    <a href="{{ route('bookings.reschedule', $b) }}" class="btn btn--ghost btn--small">Remarcar</a>
                    <form method="post" action="{{ route('bookings.cancel', $b) }}" class="inline">
                        @csrf
                        <button type="submit" class="btn btn--danger btn--small" onclick="return confirm('Cancelar este agendamento?');">Cancelar</button>
                    </form>
                </div>
            @endunless
        </li>
    @empty
        <li class="empty">Nenhum agendamento.</li>
    @endforelse
</ul>
