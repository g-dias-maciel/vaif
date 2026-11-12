@php($pending = $write->isPending())

<div class="panel" style="margin-bottom:12px; padding:14px;">
    <div style="display:flex; align-items:baseline; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <strong>{{ $write->kindLabel() }}</strong>
        <span class="meta">{{ $write->created_at?->diffForHumans() }}</span>
    </div>

    <div style="margin-top:6px;">{{ $write->summary() }}</div>

    @if (! empty($write->evidence['message']))
        <p class="meta" style="margin-top:8px; white-space:pre-line;">“{{ \Illuminate\Support\Str::limit($write->evidence['message'], 240) }}”</p>
    @endif

    @if ($pending)
        <form method="post" action="{{ route('observed-writes.decide', $write) }}" style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
            @csrf
            <button type="submit" name="decision" value="approve" class="btn btn--small">Aprovar</button>
            <button type="submit" name="decision" value="reject" class="btn btn--ghost btn--small">Recusar</button>
        </form>
    @else
        <span class="tag tag-muted" style="margin-top:8px; display:inline-block;">{{ $write->status }}</span>
    @endif
</div>
