@extends('admin.layouts.app')
@section('title', __('whmcs_to_pnlcs.title'))
@section('content')
@include('admin.whmcs-to-pnlcs._styles')

@php
    $statusClass = match($run->status) {
        'completed' => 'green',
        'failed', 'cancelled' => 'red',
        'running' => 'blue',
        default => 'gray',
    };
    $lastError = $progress['last_error'] ?? null;
@endphp
<div class="wtp" id="wtp-run-root" data-run="{{ $run->id }}">
    <div class="wtp-hero">
        <h1><i class="fas fa-rotate"></i> {{ __('whmcs_to_pnlcs.run.title', ['id' => $run->id]) }}</h1>
        <p>{{ $run->name ?: implode(', ', $run->scope ?? []) }}</p>
        <div class="wtp-chips">
            <span class="wtp-chip"><i class="fas fa-tag"></i> {{ $run->dry_run ? __('whmcs_to_pnlcs.steps.dry_run') : __('whmcs_to_pnlcs.steps.migrate') }}</span>
            <span class="wtp-chip"><i class="fas fa-layer-group"></i> {{ count($run->entities ?? []) }} {{ __('whmcs_to_pnlcs.source.entity') }}</span>
            <span class="wtp-chip"><i class="fas fa-boxes-stacked"></i> {{ $run->batch_size }} / {{ __('whmcs_to_pnlcs.scope.batch_size') }}</span>
        </div>
    </div>

    @include('admin.whmcs-to-pnlcs._steps', ['current' => $run->status === 'completed' ? 9 : 7])

    <div class="wtp-grid">
        <div class="wtp-card">
            <div class="wtp-card__head">
                <span class="wtp-card__icon"><i class="fas fa-gauge-high"></i></span>
                <h2>{{ __('whmcs_to_pnlcs.run.progress') }}</h2>
                <span class="wtp-card__aside"><span class="wtp-pill wtp-pill--{{ $statusClass }}" id="wtp-status">{{ __('whmcs_to_pnlcs.statuses.'.$run->status) ?? $run->status }}</span></span>
            </div>
            <div class="wtp-card__body">
                <div class="wtp-mono" style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span id="wtp-current">{{ $progress['current_entity'] ?? '—' }}</span>
                    <span id="wtp-percent-text">{{ $progress['percent'] }}%</span>
                </div>
                <div class="wtp-bar"><span id="wtp-percent-bar" style="width:{{ $progress['percent'] }}%"></span></div>
                <div class="wtp-mono" style="color:#6b7d92">
                    <span id="wtp-done">{{ $progress['done'] }}</span> / <span id="wtp-expected">{{ $progress['expected'] }}</span>
                    · {{ __('whmcs_to_pnlcs.run.entity') }} <span id="wtp-entity-index">{{ $progress['entity_index'] + 1 }}</span>/{{ $progress['entity_total'] }}
                    · offset <span id="wtp-offset">{{ $progress['offset'] }}</span>
                </div>
                <div class="wtp-actions" style="margin-top:16px">
                    <button type="button" class="btn btn-primary" onclick="wtpRunBatch()" id="wtp-start"><i class="fas fa-forward"></i> {{ __('whmcs_to_pnlcs.run.start') }}</button>
                    <form method="post" action="{{ route('admin.whmcs-to-pnlcs.pause', $run) }}" onsubmit="wtpStopAuto()">@csrf<button type="submit" class="btn btn-secondary"><i class="fas fa-pause"></i> {{ __('whmcs_to_pnlcs.run.pause') }}</button></form>
                    <form method="post" action="{{ route('admin.whmcs-to-pnlcs.resume', $run) }}">@csrf<button type="submit" class="btn btn-secondary"><i class="fas fa-play"></i> {{ __('whmcs_to_pnlcs.run.resume') }}</button></form>
                    <form method="post" action="{{ route('admin.whmcs-to-pnlcs.retry', $run) }}" onsubmit="wtpStopAuto()">@csrf<button type="submit" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> {{ __('whmcs_to_pnlcs.run.retry') }}</button></form>
                    <form method="post" action="{{ route('admin.whmcs-to-pnlcs.cancel', $run) }}" onsubmit="wtpStopAuto(); return pnConfirm(event, @js(__('Are you sure?')))">@csrf<button type="submit" class="btn btn-danger"><i class="fas fa-xmark"></i> {{ __('whmcs_to_pnlcs.run.cancel') }}</button></form>
                </div>
                <div id="wtp-live-note" class="wtp-note wtp-note--{{ $lastError ? 'warn' : 'info' }}" style="display:{{ $lastError ? 'block' : 'none' }};margin-top:14px">{{ $lastError ? __('whmcs_to_pnlcs.batch_interrupted').' '.$lastError : '' }}</div>
            </div>
        </div>

        <div>
            <div class="wtp-card">
                <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-circle-info"></i></span><h2>{{ __('whmcs_to_pnlcs.run.source_info') }}</h2></div>
                <div class="wtp-card__body">
                    <dl class="wtp-kv">
                        <dt>WHMCS</dt><dd>{{ $run->source_info['version'] ?? '—' }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.diagnostics.mysql') }}</dt><dd>{{ $run->source_info['server']['mysql_version'] ?? '—' }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.diagnostics.mode') }}</dt><dd>{{ strtoupper($run->source_info['server']['security_mode'] ?? '') }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.diagnostics.grants') }}</dt><dd>{{ $run->source_info['grant_coverage']['label'] ?? '—' }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.run.timezone') }}</dt>
                        <dd>{{ $run->timezone['source_iana'] ?? $run->timezone['source_setting'] ?? '—' }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.options') ?? 'Options' }}</dt>
                        <dd class="wtp-mono">{{ json_encode($run->options ?? []) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="wtp-card">
                <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-triangle-exclamation"></i></span><h2>{{ __('whmcs_to_pnlcs.run.warnings') }}</h2></div>
                <div class="wtp-card__body">
                    @forelse($run->warnings ?? [] as $warning)
                        <div class="wtp-note wtp-note--warn"><i class="fas fa-exclamation"></i> {{ $warning }}</div>
                    @empty
                        <div class="wtp-empty">{{ __('whmcs_to_pnlcs.no_runs') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @if($reconciliation && empty($reconciliation['error']))
        <div class="wtp-card">
            <div class="wtp-card__head">
                <span class="wtp-card__icon"><i class="fas fa-scale-balanced"></i></span>
                <h2>{{ __('whmcs_to_pnlcs.reconcile.title', ['id' => $run->id]) }}</h2>
                <span class="wtp-card__aside"><a href="{{ route('admin.whmcs-to-pnlcs.reconcile', $run) }}">{{ __('whmcs_to_pnlcs.run.reconcile') }} →</a></span>
            </div>
            <div class="wtp-card__body" style="max-height:360px;overflow:auto">
                <table class="wtp-table">
                    <thead><tr><th>{{ __('whmcs_to_pnlcs.reconcile.entity') }}</th><th>{{ __('whmcs_to_pnlcs.reconcile.source_count') }}</th><th>{{ __('whmcs_to_pnlcs.reconcile.target_count') }}</th><th>{{ __('whmcs_to_pnlcs.reconcile.delta') }}</th><th>{{ __('whmcs_to_pnlcs.reconcile.total_difference') }}</th></tr></thead>
                    <tbody>
                        @foreach($reconciliation['rows'] as $row)
                            <tr>
                                <td><strong>{{ $row['entity'] }}</strong></td>
                                <td>{{ $row['source_count'] }}</td>
                                <td>{{ $row['target_count'] }}</td>
                                <td><span class="wtp-pill wtp-pill--{{ $row['count_difference'] === 0 ? 'green' : 'amber' }}">{{ $row['count_difference'] > 0 ? '+' : '' }}{{ $row['count_difference'] }}</span></td>
                                <td>{{ $row['total_difference'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="wtp-grid">
        <div class="wtp-card">
            <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-list-check"></i></span><h2>{{ __('whmcs_to_pnlcs.run.checklist') }}</h2></div>
            <div class="wtp-card__body">
                @foreach($checklist as $item)
                    <div class="wtp-note wtp-note--info"><i class="fas fa-check"></i> {{ $item }}</div>
                @endforeach
            </div>
        </div>

        <div class="wtp-card">
            <div class="wtp-card__head">
                <span class="wtp-card__icon"><i class="fas fa-file-arrow-down"></i></span>
                <h2>{{ __('whmcs_to_pnlcs.run.reports') }}</h2>
                <span class="wtp-card__aside"><a href="{{ route('admin.whmcs-to-pnlcs.records', $run) }}">{{ __('whmcs_to_pnlcs.run.records') }} →</a></span>
            </div>
            <div class="wtp-card__body">
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
                    @foreach(['summary', 'records', 'mappings', 'financial', 'warnings'] as $type)
                        <form method="post" action="{{ route('admin.whmcs-to-pnlcs.reports.store', $run) }}">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <input type="hidden" name="format" value="csv">
                            <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-file-csv"></i> {{ ucfirst($type) }} (CSV)</button>
                        </form>
                        <form method="post" action="{{ route('admin.whmcs-to-pnlcs.reports.store', $run) }}">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <input type="hidden" name="format" value="json">
                            <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-file-code"></i> {{ ucfirst($type) }} (JSON)</button>
                        </form>
                    @endforeach
                </div>
                @forelse($reports as $report)
                    <div class="wtp-conn">
                        <span class="wtp-card__icon"><i class="fas fa-file"></i></span>
                        <div class="wtp-conn__body">
                            <div class="wtp-conn__name">{{ $report->type }} · {{ strtoupper($report->format) }}</div>
                            <div class="wtp-conn__meta">{{ $report->created_at?->format('Y-m-d H:i:s') }}</div>
                        </div>
                        <a href="{{ route('admin.whmcs-to-pnlcs.reports.download', $report) }}" class="btn btn-sm btn-primary"><i class="fas fa-download"></i></a>
                    </div>
                @empty
                    <div class="wtp-empty">{{ __('whmcs_to_pnlcs.no_runs') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const batchUrl = @json(route('admin.whmcs-to-pnlcs.batch', $run));
    const csrf = @json(csrf_token());
    const labels = @json(__('whmcs_to_pnlcs.statuses'));
    const note = document.getElementById('wtp-live-note');
    const startHtml = document.getElementById('wtp-start')?.innerHTML || '';
    const batchSize = Math.max(1, {{ (int) $run->batch_size }});
    const expected = Math.max(0, {{ (int) ($progress['expected'] ?? 0) }});
    const maxBatches = Math.max(50, Math.ceil(expected / batchSize) + 10);

    const text = {
        processing: @js(__('whmcs_to_pnlcs.processing_batch')),
        batch: @js(__('whmcs_to_pnlcs.run.auto_batch')),
        completed: @js(__('whmcs_to_pnlcs.completed_note')),
        stopped: @js(__('whmcs_to_pnlcs.run.auto_stopped')),
        noProgress: @js(__('whmcs_to_pnlcs.run.auto_no_progress')),
        noProgressError: @js(__('whmcs_to_pnlcs.run.auto_no_progress_error')),
        limit: @js(__('whmcs_to_pnlcs.run.auto_limit')).replace(':count', maxBatches),
        failed: @js(__('whmcs_to_pnlcs.batch_failed')),
        interrupted: @js(__('whmcs_to_pnlcs.batch_interrupted')),
        autoRunning: @js(__('whmcs_to_pnlcs.run.auto_running')),
    };
    const terminal = ['completed', 'cancelled', 'failed', 'paused'];

    let running = false;
    let autoRun = false;
    let stopAuto = false;
    let timer = null;
    let batches = 0;
    let warningCount = {{ (int) count($run->warnings ?? []) }};
    let lastError = @json($lastError);
    let lastSignature = null;
    let stalled = 0;

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function showNote(message, warn = false) {
        if (!note) return;
        note.style.display = 'block';
        note.className = 'wtp-note wtp-note--' + (warn ? 'warn' : 'info');
        note.textContent = message;
    }

    function setBusy(busy) {
        const btn = document.getElementById('wtp-start');
        if (!btn) return;
        btn.disabled = busy;
        btn.innerHTML = busy
            ? '<i class="fas fa-spinner fa-spin"></i> ' + text.autoRunning
            : startHtml;
    }

    function clearTimer() {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    }

    function signature(data) {
        return [data.status, data.entity_index, data.offset, data.done].join(':');
    }

    function terminalMessage(data) {
        if (data.last_error) return text.interrupted + ' ' + data.last_error;
        if (data.status === 'completed') return text.completed;
        const warnings = Array.isArray(data.warnings) ? data.warnings : [];
        if (warnings.length) return warnings[warnings.length - 1];
        return text.stopped;
    }

    function stopLocal(message, warn = false) {
        clearTimer();
        autoRun = false;
        stopAuto = false;
        setBusy(false);
        if (message) showNote(message, warn);
    }

    function scheduleNext() {
        if (!autoRun || stopAuto) {
            stopLocal(text.stopped);
            return;
        }
        if (batches >= maxBatches) {
            stopLocal(text.limit, true);
            return;
        }
        timer = window.setTimeout(sendBatch, 150);
    }

    function sendBatch() {
        clearTimer();
        if (running || !autoRun) return;
        if (stopAuto) {
            stopLocal(text.stopped);
            return;
        }

        running = true;
        setBusy(true);
        showNote(text.processing);

        fetch(batchUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json'
            },
            body: '{}'
        })
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(data => {
            batches++;

            setText('wtp-status', labels[data.status] || data.status);
            setText('wtp-current', data.current_entity || '—');
            setText('wtp-percent-text', data.percent + '%');
            const bar = document.getElementById('wtp-percent-bar');
            if (bar) bar.style.width = data.percent + '%';
            setText('wtp-done', data.done);
            setText('wtp-expected', data.expected);
            setText('wtp-offset', data.offset);
            setText('wtp-entity-index', data.entity_index + 1);

            const warnings = Array.isArray(data.warnings) ? data.warnings : [];
            lastError = data.last_error || null;
            if (data.last_error || warnings.length > warningCount) {
                showNote(data.last_error
                    ? text.interrupted + ' ' + data.last_error
                    : warnings[warnings.length - 1], true);
                warningCount = Math.max(warningCount, warnings.length);
            } else {
                showNote(data.status === 'completed' ? text.completed : text.batch);
            }

            if (terminal.includes(data.status)) {
                stopLocal(terminalMessage(data), data.status === 'failed' || Boolean(data.last_error));
                if (data.status === 'completed') {
                    window.setTimeout(() => window.location.reload(), 1200);
                }
                return;
            }

            const sig = signature(data);
            if (sig === lastSignature) {
                stalled++;
            } else {
                stalled = 0;
                lastSignature = sig;
            }
            if (stalled >= 1) {
                const message = lastError
                    ? text.noProgressError.replace(':error', text.interrupted + ' ' + lastError)
                    : text.noProgress;
                stopLocal(message, true);
                return;
            }

            scheduleNext();
        })
        .catch(error => {
            stopLocal(text.failed + ' ' + error.message, true);
        })
        .finally(() => {
            running = false;
        });
    }

    function wtpRunBatch() {
        if (running) return;
        clearTimer();
        autoRun = true;
        stopAuto = false;
        batches = 0;
        lastSignature = null;
        stalled = 0;
        sendBatch();
    }

    function wtpStopAuto() {
        if (autoRun) {
            stopAuto = true;
            showNote(text.stopped);
        }
    }

    window.wtpRunBatch = wtpRunBatch;
    window.wtpStopAuto = wtpStopAuto;
})();
</script>
@endsection
