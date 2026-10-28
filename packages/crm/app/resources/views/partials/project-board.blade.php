@php
    $grouped = $projects->groupBy('stage_key');
@endphp

<div class="board">
    @foreach ($pipeline as $key => $label)
        <section class="column">
            <h2 class="column-title">
                {{ $label }}
                <span class="count">{{ $grouped->get($key)?->count() ?? 0 }}</span>
            </h2>
            <div class="cards">
                @forelse ($grouped->get($key) ?? collect() as $project)
                    <div class="card">
                        <div class="card-head">
                            <strong>
                                @if (! $readonly && $project->lead)
                                    <a href="{{ route('leads.show', $project->lead) }}">{{ $project->lead->displayName() }}</a>
                                @else
                                    {{ $project->lead?->displayName() ?? 'Sem nome' }}
                                @endif
                                @if ($project->is_returning)
                                    <span class="tag">Recorrente</span>
                                @endif
                            </strong>
                            <span class="phone">{{ $project->lead?->phone_e164 }}</span>
                        </div>

                        @if ($project->placement || $project->body_zone || $project->style)
                            <div class="meta">
                                {{ collect([$project->placement, $project->body_zone, $project->style])->filter()->implode(' · ') }}
                            </div>
                        @endif

                        @if ($project->charged_price_cents !== null)
                            <div class="meta price">R$ {{ number_format($project->charged_price_cents / 100, 2, ',', '.') }}</div>
                        @endif

                        @if ($project->deposit)
                            <div class="meta">Sinal: R$ {{ number_format($project->deposit->amount_cents / 100, 2, ',', '.') }}</div>
                        @endif

                        @if ($project->booked_at)
                            <div class="meta gold">{{ $project->booked_at->format('d/m/Y H:i') }}</div>
                        @endif

                        @unless ($readonly)
                            <div class="actions">
                                @if ($project->whatsapp_url)
                                    <a href="{{ $project->whatsapp_url }}" target="_blank" rel="noopener" class="btn btn--whatsapp btn--small">WhatsApp</a>
                                @endif
                                @if ($project->stage_key !== 'perdido')
                                    <form method="post" action="{{ route('projects.mark-lost', $project) }}" class="inline">
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
