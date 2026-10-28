<ul class="slot-list">
    @forelse ($bookings as $b)
        <li>
            <div class="slot-main">
                <strong>
                    @if (! $readonly && $b->lead)
                        <a href="{{ route('leads.show', $b->lead) }}">{{ $b->lead->displayName() }}</a>
                    @else
                        {{ $b->lead?->displayName() ?? 'Cliente' }}
                    @endif
                </strong>
                <span>{{ $b->start_at->format('d/m/Y H:i') }} – {{ $b->end_at->format('H:i') }}</span>
                @if ($b->project?->placement)
                    <span class="gold">{{ $b->project->placement }}</span>
                @endif
                @if ($b->project)
                    @if ($b->project->deposit_status === 'confirmado')
                        <span class="tag tag-success">Sinal pago</span>
                    @elseif ($b->project->deposit_status === 'aguardando_confirmacao')
                        <span class="tag">Aguardando sinal</span>
                    @else
                        <span class="tag tag-muted">Sem sinal</span>
                    @endif
                @endif
            </div>
            @unless ($readonly)
                <div class="actions-row">
                    @if ($b->project && $b->project->deposit_status !== 'confirmado')
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
