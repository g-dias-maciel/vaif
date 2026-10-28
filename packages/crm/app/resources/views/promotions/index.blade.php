@extends('layouts.app')

@section('title', 'Promoções')

@section('content')
<div style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
    <h1 class="page-title" style="margin:0;">Promoções</h1>
    <a href="{{ route('promotions.create') }}" class="btn">Nova promoção</a>
</div>

<div class="panel" style="margin-bottom:20px;">
    <form method="get" action="{{ route('promotions.index') }}" class="pricing-add">
        <select name="artist_id">
            <option value="">Todos os artistas</option>
            @foreach ($artists as $artist)
                <option value="{{ $artist->id }}" @selected(request('artist_id') === $artist->id)>
                    {{ $artist->displayName() }}
                </option>
            @endforeach
        </select>

        <select name="status">
            <option value="">Todos os status</option>
            @foreach (['draft' => 'Rascunho', 'active' => 'Ativa', 'paused' => 'Pausada', 'ended' => 'Encerrada'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn--ghost">Filtrar</button>
    </form>
</div>

@if ($promotions->isEmpty())
    <div class="panel"><p class="meta">Nenhuma promoção encontrada.</p></div>
@endif

@foreach ($promotions as $promo)
    <div class="panel" style="margin-bottom:12px;">
        <div class="promo-row {{ $promo->isActive() ? 'is-active' : '' }}" style="margin:0; background:transparent; border:0; padding:0;">
            <div class="promo-main">
                <strong>{{ $promo->name }} <span class="meta">· {{ $promo->artist?->displayName() }}</span></strong>
                <span class="meta">
                    {{ $promo->type?->name ?? $promo->type_key }}
                    · {{ $promo->multiplierLabel() }}
                    · {{ $promo->audienceLabel() }}
                    · validade {{ $promo->quote_valid_days }} dia(s)
                    @if ($promo->activated_at) · ativada {{ $promo->activated_at->format('d/m/Y H:i') }} @endif
                </span>
            </div>
            <span class="tag {{ $promo->isActive() ? 'tag-success' : 'tag-muted' }}">{{ $promo->statusLabel() }}</span>
        </div>

        <div class="actions-row">
            @if ($promo->status !== 'ended')
                <a href="{{ route('promotions.edit', $promo) }}" class="btn btn--ghost btn--small">Editar</a>
            @endif

            @if (in_array($promo->status, ['draft', 'paused'], true))
                <form method="post" action="{{ route('promotions.activate', $promo) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn--small">Ativar</button>
                </form>
            @endif

            @if ($promo->status === 'active')
                <form method="post" action="{{ route('promotions.pause', $promo) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn--small">Pausar</button>
                </form>
            @endif

            @if (in_array($promo->status, ['active', 'paused'], true))
                <form method="post" action="{{ route('promotions.end', $promo) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn--danger btn--small" onclick="return confirm('Encerrar esta promoção? Cotações abertas serão anuladas.');">Encerrar</button>
                </form>
            @endif

            <a href="{{ route('promotions.create', ['from' => $promo->id]) }}" class="btn btn--ghost btn--small">Atribuir a outro artista</a>
        </div>
    </div>
@endforeach

@if ($promotions->hasPages())
    <div style="margin-top:18px;">{{ $promotions->links() }}</div>
@endif
@endsection
