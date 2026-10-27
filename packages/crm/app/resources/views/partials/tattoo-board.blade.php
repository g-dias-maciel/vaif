@php
    $grouped = $tattoos->groupBy('pipeline_status');
@endphp

<div class="board">
    @foreach ($pipeline as $key => $label)
        <section class="column">
            <h2 class="column-title">
                {{ $label }}
                <span class="count">{{ $grouped->get($key)?->count() ?? 0 }}</span>
            </h2>
            <div class="cards">
                @forelse ($grouped->get($key) ?? collect() as $tattoo)
                    <div class="card">
                        <div class="card-head">
                            <strong>
                                @if (! $readonly)
                                    <a href="{{ route('tattoos.show', $tattoo) }}">{{ $tattoo->lead?->nome ?: 'Sem nome' }}</a>
                                @else
                                    {{ $tattoo->lead?->nome ?: 'Sem nome' }}
                                @endif
                                @if ($tattoo->lead?->is_returning)
                                    <span class="tag">Recorrente</span>
                                @endif
                            </strong>
                            <span class="phone">{{ $tattoo->lead?->telefone }}</span>
                        </div>

                        @if ($tattoo->placement || $tattoo->body_zone || $tattoo->style)
                            <div class="meta">
                                {{ collect([$tattoo->placement, $tattoo->body_zone, $tattoo->style])->filter()->implode(' · ') }}
                            </div>
                        @endif

                        @if ($tattoo->charged_price_cents !== null)
                            <div class="meta price">R$ {{ number_format($tattoo->charged_price_cents / 100, 2, ',', '.') }}</div>
                        @endif

                        @if ($tattoo->deposit_amount)
                            <div class="meta">Sinal: R$ {{ number_format($tattoo->deposit_amount / 100, 2, ',', '.') }}</div>
                        @endif

                        @if ($tattoo->booked_date)
                            <div class="meta gold">{{ $tattoo->booked_date->format('d/m/Y H:i') }}</div>
                        @endif

                        @unless ($readonly)
                            <div class="actions">
                                @if ($tattoo->lead?->whatsapp_url)
                                    <a href="{{ $tattoo->lead->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp btn--small">WhatsApp</a>
                                @endif
                                @if ($tattoo->pipeline_status !== 'perdido')
                                    <form method="post" action="{{ route('tattoos.mark-lost', $tattoo) }}" class="inline">
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
