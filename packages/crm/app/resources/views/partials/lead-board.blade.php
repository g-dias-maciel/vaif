@php
    $grouped = $leads->groupBy('pipeline_status');
@endphp

<div class="board">
    @foreach ($pipeline as $key => $label)
        <section class="column">
            <h2 class="column-title">
                {{ $label }}
                <span class="count">{{ $grouped->get($key)?->count() ?? 0 }}</span>
            </h2>
            <div class="cards">
                @forelse ($grouped->get($key) ?? collect() as $lead)
                    <div class="card">
                        <div class="card-head">
                            <strong>
                                @if (! $readonly)
                                    <a href="{{ route('leads.show', $lead) }}">{{ $lead->nome ?: 'Sem nome' }}</a>
                                @else
                                    {{ $lead->nome ?: 'Sem nome' }}
                                @endif
                                @if ($lead->is_returning)
                                    <span class="tag">Recorrente</span>
                                @endif
                            </strong>
                            <span class="phone">{{ $lead->telefone }}</span>
                        </div>

                        @if ($lead->placement || $lead->body_zone || $lead->style)
                            <div class="meta">
                                {{ collect([$lead->placement, $lead->body_zone, $lead->style])->filter()->implode(' · ') }}
                            </div>
                        @endif

                        @if ($lead->charged_price_cents !== null)
                            <div class="meta price">R$ {{ number_format($lead->charged_price_cents / 100, 2, ',', '.') }}</div>
                        @endif

                        @if ($lead->deposit_amount)
                            <div class="meta">Sinal: R$ {{ number_format($lead->deposit_amount / 100, 2, ',', '.') }}</div>
                        @endif

                        @if ($lead->booked_date)
                            <div class="meta gold">{{ $lead->booked_date->format('d/m/Y H:i') }}</div>
                        @endif

                        @unless ($readonly)
                            <div class="actions">
                                @if ($lead->whatsapp_url)
                                    <a href="{{ $lead->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp btn--small">WhatsApp</a>
                                @endif
                                @if ($lead->pipeline_status !== 'perdido')
                                    <form method="post" action="{{ route('leads.mark-lost', $lead) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="btn btn--danger btn--small"
                                            onclick="return confirm('Marcar este projeto como perdido?');">Marcar perdido</button>
                                    </form>
                                @endif
                            </div>
                        @endunless
                    </div>
                @empty
                    <div class="empty">—</div>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
