@extends('layouts.app')

@section('title', $prospect->name)

@section('content')
@include('sales.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <a href="{{ route('sales.pipeline') }}" class="meta">← Pipeline</a>
                @include('sales.partials.band', ['prospect' => $prospect])
                <span class="badge">{{ $prospect->stage?->label_pt ?? 'Sem estágio' }}</span>
            </div>
            <h1 style="margin:8px 0 4px;">{{ $prospect->name }}</h1>
            <p class="meta">
                {{ $prospect->displayPhone() }}
                @if ($prospect->instagram)
                    · &#64;{{ $prospect->instagram }}
                @endif
                · {{ $prospect->source }}
            </p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            @if ($prospect->whatsappUrl())
                <a href="{{ $prospect->whatsappUrl() }}" target="_blank" rel="noopener noreferrer" class="btn">WhatsApp</a>
            @endif
            @if ($prospect->instagramUrl())
                <a href="{{ $prospect->instagramUrl() }}" target="_blank" rel="noopener noreferrer" class="btn btn--ghost">Instagram</a>
            @endif
            <a href="{{ route('sales.prospects.edit', $prospect) }}" class="btn btn--ghost">Editar</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="sales-errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="sales-grid">
        <div>
            <div class="label">Receita mensal</div>
            <div class="value">{{ $prospect->revenueLabel() }}</div>
        </div>
        <div>
            <div class="label">Ticket médio</div>
            <div class="value">{{ $prospect->ticket_cents !== null ? 'R$ '.number_format($prospect->ticket_cents / 100, 0, ',', '.') : '—' }}</div>
        </div>
        <div>
            <div class="label">Sessões/mês</div>
            <div class="value">{{ $prospect->sessions_monthly ?? '—' }}</div>
        </div>
        <div>
            <div class="label">Horas de admin/dia</div>
            <div class="value">{{ $prospect->admin_hours_day ?? '—' }}</div>
        </div>
        <div>
            <div class="label">Dono</div>
            <div class="value">{{ $prospect->owner?->name ?? 'Não atribuído' }}</div>
        </div>
        <div>
            <div class="label">Campanha</div>
            <div class="value">{{ $prospect->campaign?->name ?? '—' }}</div>
        </div>
        <div>
            <div class="label">Próxima ação</div>
            <div class="value">{{ $prospect->next_action_at?->format('d/m/Y H:i') ?? '—' }}</div>
        </div>
        <div>
            <div class="label">Criado em</div>
            <div class="value">{{ $prospect->created_at?->format('d/m/Y') ?? '—' }}</div>
        </div>
    </div>

    @if ($prospect->next_action_note)
        <p class="meta" style="margin-top:14px;"><strong>Próxima ação:</strong> {{ $prospect->next_action_note }}</p>
    @endif

    @if ($prospect->notes)
        <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--border);">
            <div class="label">Notas</div>
            <p style="margin-top:6px;">{!! nl2br(e($prospect->notes)) !!}</p>
        </div>
    @endif
</div>

<div class="panel" style="margin-top:18px;">
    <h2 class="section-title" style="margin-top:0;">Estágio</h2>

    <form method="post" action="{{ route('sales.prospects.stage', $prospect) }}" class="sales-toolbar">
        @csrf
        <select name="stage_key">
            @foreach ($stages as $stage)
                <option value="{{ $stage->key }}" @selected($prospect->stage_key === $stage->key)>{{ $stage->label_pt }}</option>
            @endforeach
        </select>
        <input type="text" name="lost_reason" value="{{ old('lost_reason') }}" placeholder="Motivo (obrigatório para perdido)">
        <input type="text" name="note" value="{{ old('note') }}" placeholder="Nota da mudança (opcional)">
        <button type="submit" class="btn btn--small">Mover estágio</button>
    </form>

    <p class="meta" style="margin-top:8px;">Ganho será habilitado quando o handoff entrar na próxima fase. Use Proposta por enquanto.</p>
</div>

<div class="panel" style="margin-top:18px;">
    <h2 class="section-title" style="margin-top:0;">Próxima ação</h2>

    <form method="post" action="{{ route('sales.prospects.next-action', $prospect) }}" class="sales-toolbar">
        @csrf
        <input type="datetime-local" name="next_action_at" value="{{ old('next_action_at', $prospect->next_action_at?->format('Y-m-d\TH:i')) }}">
        <input type="text" name="next_action_note" value="{{ old('next_action_note', $prospect->next_action_note) }}" placeholder="O que precisa acontecer?">
        <button type="submit" class="btn btn--small">Salvar</button>
    </form>
</div>

<div class="panel" style="margin-top:18px;">
    <h2 class="section-title" style="margin-top:0;">Atividades</h2>

    <form method="post" action="{{ route('sales.prospects.activities.store', $prospect) }}" class="sales-form" style="margin-bottom:18px;">
        @csrf
        <label>
            Tipo
            <select name="type">
                <option value="call" @selected(old('type') === 'call')>Ligação</option>
                <option value="whatsapp" @selected(old('type') === 'whatsapp')>WhatsApp</option>
                <option value="note" @selected(old('type', 'note') === 'note')>Nota</option>
                <option value="task" @selected(old('type') === 'task')>Tarefa</option>
            </select>
        </label>
        <label>
            Título
            <input type="text" name="title" value="{{ old('title') }}" placeholder="Opcional">
        </label>
        <label>
            Prazo (tarefa)
            <input type="datetime-local" name="due_at" value="{{ old('due_at') }}">
        </label>
        <label class="full">
            O que aconteceu?
            <textarea name="body" placeholder="Resumo da conversa, objeção, próximo passo...">{{ old('body') }}</textarea>
        </label>
        <div class="full">
            <button type="submit" class="btn btn--small">Registrar atividade</button>
        </div>
    </form>

    @forelse ($prospect->activities as $activity)
        <div class="activity">
            <span class="activity-type">{{ $activity->typeLabel() }}</span>
            <div class="activity-body">
                <strong>{{ $activity->title ?: $activity->typeLabel() }}</strong>
                @if ($activity->body)
                    <p>{{ $activity->body }}</p>
                @endif
                <div class="activity-meta">
                    {{ $activity->user?->name ?? 'Sistema' }}
                    · {{ $activity->created_at?->format('d/m/Y H:i') }}
                    @if ($activity->due_at)
                        · Prazo {{ $activity->due_at->format('d/m/Y H:i') }}
                    @endif
                    @if ($activity->completed_at)
                        · Concluída {{ $activity->completed_at->format('d/m/Y H:i') }}
                    @endif
                </div>

                @if ($activity->isTaskOpen())
                    <form method="post" action="{{ route('sales.prospects.activities.complete', [$prospect, $activity]) }}" style="margin-top:8px;">
                        @csrf
                        <button type="submit" class="btn btn--small">Concluir tarefa</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <p class="sales-empty">Nenhuma atividade registrada ainda.</p>
    @endforelse
</div>

<div class="panel" style="margin-top:18px;">
    <h2 class="section-title" style="margin-top:0;">Contrato</h2>

    @if ($prospect->contracts->isEmpty())
        <p class="meta">Sem contrato. O handoff entra na próxima fase.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Plano</th>
                    <th>Mensal</th>
                    <th>Total</th>
                    <th>Início</th>
                    <th>Fim</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prospect->contracts as $contract)
                    <tr>
                        <td>{{ $contract->plan }}</td>
                        <td>R$ {{ number_format($contract->monthly_value_cents / 100, 0, ',', '.') }}</td>
                        <td>R$ {{ number_format($contract->total_value_cents / 100, 0, ',', '.') }}</td>
                        <td>{{ $contract->start_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $contract->end_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $contract->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
