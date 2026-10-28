@extends('layouts.app')

@section('title', $promotion ? 'Editar promoção' : 'Nova promoção')

@section('content')
<a href="{{ route('promotions.index') }}" class="back">&larr; Voltar para promoções</a>
<h1 class="page-title">{{ $promotion ? 'Editar promoção' : 'Nova promoção' }}</h1>

@if ($errors->any())
    <div class="flash error">{{ $errors->first() }}</div>
@endif

@php
    $locked = $promotion?->isActive() ?? false;
    $value = function (string $key, $default = null) use ($promotion, $source) {
        return old($key, $promotion?->{$key} ?? $source?->{$key} ?? $default);
    };
@endphp

@if ($source)
    <div class="panel" style="margin-bottom:16px;">
        <p class="meta" style="margin:0;">
            Atribuindo uma cópia de <strong>{{ $source->name }}</strong> a outro artista.
            A nova promoção será criada como rascunho.
        </p>
    </div>
@endif

@if ($locked)
    <div class="panel" style="margin-bottom:16px;">
        <p class="meta" style="margin:0;">
            Esta promoção está ativa. Nome, público e validade podem ser ajustados;
            para mudar o tipo ou o multiplicador, pause a promoção primeiro.
        </p>
    </div>
@endif

<div class="panel">
    <form method="post"
          action="{{ $promotion ? route('promotions.update', $promotion) : route('promotions.store') }}"
          class="form-grid">
        @csrf
        @if ($promotion)
            @method('PUT')
        @endif
        @if ($source)
            <input type="hidden" name="source_id" value="{{ $source->id }}">
        @endif

        @if ($promotion)
            <div class="form-field">
                <label>Artista</label>
                <div>{{ $promotion->artist?->displayName() ?? '—' }}</div>
            </div>
            <div class="form-field">
                <label>Status</label>
                <div><span class="tag {{ $promotion->isActive() ? 'tag-success' : 'tag-muted' }}">{{ $promotion->statusLabel() }}</span></div>
            </div>
        @else
            <div class="form-field">
                <label for="artist_id">Artista *</label>
                <select name="artist_id" id="artist_id" required>
                    <option value="">Selecione o artista</option>
                    @foreach ($artists as $artist)
                        <option value="{{ $artist->id }}" @selected(old('artist_id') === $artist->id)>
                            {{ $artist->displayName() }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="form-field">
            <label for="name">Nome da promoção *</label>
            <input type="text" name="name" id="name" value="{{ $value('name') }}" required maxlength="255">
        </div>

        <div class="form-field">
            <label for="type_key">Mecânica *</label>
            @if ($locked)
                <input type="hidden" name="type_key" value="{{ $promotion->type_key }}">
                <select id="type_key" disabled>
                    @foreach ($types as $type)
                        <option value="{{ $type->key }}" @selected($promotion->type_key === $type->key)>{{ $type->name }}</option>
                    @endforeach
                </select>
            @else
                <select name="type_key" id="type_key" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->key }}" @selected($value('type_key') === $type->key)>{{ $type->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="form-field">
            <label for="multiplier">Multiplicador *</label>
            @if ($locked)
                <input type="hidden" name="multiplier" value="{{ $promotion->multiplier }}">
                <input type="number" id="multiplier" value="{{ $promotion->multiplier }}" step="0.01" disabled>
            @else
                <input type="number" name="multiplier" id="multiplier" value="{{ $value('multiplier', 2) }}" min="1" max="10" step="0.01" required>
            @endif
        </div>

        <div class="form-field">
            <label for="audience">Público *</label>
            <select name="audience" id="audience" required>
                @foreach (['all' => 'Todos os clientes', 'new' => 'Clientes novos', 'returning' => 'Recorrentes'] as $key => $label)
                    <option value="{{ $key }}" @selected($value('audience', 'all') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-field">
            <label for="quote_valid_days">Validade da cotação (dias) *</label>
            <input type="number" name="quote_valid_days" id="quote_valid_days" value="{{ $value('quote_valid_days', 7) }}" min="1" max="365" required>
        </div>

        <div style="grid-column: 1 / -1; margin-top: 8px;">
            <button type="submit" class="btn">{{ $promotion ? 'Salvar alterações' : 'Criar promoção' }}</button>
        </div>
    </form>
</div>
@endsection
