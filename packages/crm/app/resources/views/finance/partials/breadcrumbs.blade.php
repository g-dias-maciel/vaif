@php($items = $items ?? [])

<nav class="fin-breadcrumbs" aria-label="Trilha de navegação">
    <a href="{{ route('finance.dashboard') }}">Financeiro</a>
    @foreach ($items as $item)
        <span class="fin-breadcrumbs__sep">/</span>
        @if (! empty($item['url']))
            <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
        @else
            <span class="fin-breadcrumbs__current">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
