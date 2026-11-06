@php
    $grouped = $projects->groupBy('stage_key');
    // The dashboard offers the agent silence toggle; staff/admin boards keep
    // the mark-lost action.
    $agentAction = $agentAction ?? false;
    // Triage-only artists schedule and close leads manually.
    $triageOnly = isset($artist) && ! ($artist->settings?->full_selling ?? true);
    $lastMessageAt = $lastMessageAt ?? [];
    // Optional cap per column: the rest goes behind a "Ver todos" expander so
    // a busy stage does not turn the dashboard into an endless list.
    $cardLimit = $cardLimit ?? null;
@endphp

<div class="board">
    @foreach ($pipeline as $key => $label)
        @php
            $items = $grouped->get($key) ?? collect();
            $visible = $cardLimit ? $items->take($cardLimit) : $items;
            $hidden = $cardLimit ? $items->slice($cardLimit) : collect();
        @endphp
        <section class="column" data-stage="{{ $key }}">
            <h2 class="column-title">
                {{ $label }}
                <span class="count">{{ $items->count() }}</span>
            </h2>
            <div class="cards">
                @foreach ($visible as $project)
                    @include('partials.project-card')
                @endforeach

                @if ($hidden->isNotEmpty())
                    <details class="cards-more" data-persist="stage-{{ $key }}">
                        <summary>Ver todos ({{ $items->count() }})</summary>
                        <div class="cards">
                            @foreach ($hidden as $project)
                                @include('partials.project-card')
                            @endforeach
                        </div>
                    </details>
                @endif

                @if ($items->isEmpty())
                    <div class="empty">—</div>
                @endif
            </div>
        </section>
    @endforeach
</div>
