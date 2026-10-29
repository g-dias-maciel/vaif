@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
<h1 class="page-title">Clientes — {{ $artist->displayName() }}</h1>

<div class="panel">
    <div class="clients-toolbar">
        <input type="search" id="clients-search" placeholder="Buscar por nome ou telefone" autocomplete="off">
        <select id="clients-status" aria-label="Filtrar por status">
            <option value="">Todos os status</option>
            @foreach ($pipeline as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
            <option value="none">Sem projeto</option>
        </select>
        <span class="clients-count" id="clients-count">
            {{ $leads->count() }} {{ $leads->count() === 1 ? 'cliente' : 'clientes' }}
        </span>
    </div>

    @if ($leads->isEmpty())
        <p class="clients-empty">Nenhum cliente cadastrado ainda.</p>
    @else
        <div class="clients-list">
            @foreach ($leads as $lead)
                @php($latestProject = $lead->projects->first())
                <div class="client-item"
                    data-client-row
                    data-client-search="{{ mb_strtolower($lead->displayName().' '.$lead->phone_e164) }}"
                    data-client-status="{{ $latestProject?->stage_key ?? 'none' }}">
                    <div class="client-main">
                        <div class="client-name">
                            <a href="{{ route('leads.show', $lead) }}">{{ $lead->displayName() }}</a>
                            @if (in_array($lead->phone_e164, $returningPhones, true))
                                <span class="tag">Recorrente</span>
                            @endif
                            @if ($lead->agent_blocked)
                                <span class="tag tag-muted">Agente silenciado</span>
                            @endif
                        </div>
                        <div class="client-meta">
                            <span>{{ $lead->phone_e164 }}</span>
                            <span>Último contato: {{ $lead->last_message_at?->format('d/m/Y H:i') ?? '—' }}</span>
                            @if ($latestProject)
                                <span class="tag {{ $latestProject->stage_key === 'fechado' ? 'tag-success' : ($latestProject->stage_key === 'perdido' ? 'tag-muted' : '') }}">
                                    {{ $latestProject->stage_label }}
                                </span>
                            @else
                                <span>Sem projeto</span>
                            @endif
                        </div>
                    </div>
                    <div class="client-actions">
                        <a href="{{ route('leads.show', $lead) }}" class="btn btn--ghost btn--small">Abrir</a>
                        <button type="button" class="btn btn--danger btn--small js-delete-client"
                            data-url="{{ route('clients.destroy', $lead) }}"
                            data-name="{{ $lead->displayName() }}">
                            Excluir
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="clients-empty" id="clients-empty" hidden>Nenhum cliente encontrado.</p>
    @endif
</div>

<div class="lightbox" id="delete-client-lightbox" hidden>
    <div class="lightbox-card" role="dialog" aria-modal="true" aria-labelledby="delete-client-title">
        <h2 id="delete-client-title">Excluir cliente?</h2>
        <p>
            Você vai excluir <strong id="delete-client-name"></strong> e todo o histórico dele:
            projetos, agendamentos, pagamentos e conversas. Essa ação não pode ser desfeita.
        </p>
        <form method="post" id="delete-client-form">
            @csrf
            @method('DELETE')
            <div class="lightbox-actions">
                <button type="button" class="btn btn--ghost btn--small" id="delete-client-cancel">Cancelar</button>
                <button type="submit" class="btn btn--danger btn--small">Excluir cliente</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const search = document.getElementById('clients-search');
    const status = document.getElementById('clients-status');
    const count = document.getElementById('clients-count');
    const empty = document.getElementById('clients-empty');
    const rows = Array.from(document.querySelectorAll('[data-client-row]'));

    const normalize = (value) => (value || '').toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

    const update = () => {
        const query = normalize(search?.value).trim();
        const chosen = status?.value || '';
        let visible = 0;

        rows.forEach((row) => {
            const matchesSearch = !query || normalize(row.dataset.clientSearch).includes(query);
            const matchesStatus = !chosen || row.dataset.clientStatus === chosen;
            const show = matchesSearch && matchesStatus;
            row.hidden = !show;
            if (show) visible++;
        });

        if (count) {
            count.textContent = visible + (visible === 1 ? ' cliente' : ' clientes');
        }
        if (empty) {
            empty.hidden = visible > 0 || rows.length === 0;
        }
    };

    search?.addEventListener('input', update);
    status?.addEventListener('change', update);
    update();

    const modal = document.getElementById('delete-client-lightbox');
    const form = document.getElementById('delete-client-form');
    const name = document.getElementById('delete-client-name');
    const cancel = document.getElementById('delete-client-cancel');
    const close = () => { if (modal) modal.hidden = true; };

    document.querySelectorAll('.js-delete-client').forEach((button) => {
        button.addEventListener('click', () => {
            if (!modal || !form) return;
            form.action = button.dataset.url;
            if (name) name.textContent = button.dataset.name || 'este cliente';
            modal.hidden = false;
            cancel?.focus();
        });
    });

    cancel?.addEventListener('click', close);
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) close();
    });
})();
</script>
@endpush
