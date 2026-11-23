@extends('admin.layouts.app')
@section('title', __('whmcs_to_pnlcs.title'))
@section('content')
@include('admin.whmcs-to-pnlcs._styles')

<div class="wtp">
    <div class="wtp-hero">
        <h1><i class="fas fa-list"></i> {{ __('whmcs_to_pnlcs.records.title', ['id' => $run->id]) }}</h1>
        <p>{{ $run->name ?: implode(', ', $run->scope ?? []) }} · {{ $run->dry_run ? __('whmcs_to_pnlcs.steps.dry_run') : __('whmcs_to_pnlcs.steps.migrate') }}</p>
        <div class="wtp-chips">
            <a href="{{ route('admin.whmcs-to-pnlcs.run', $run) }}" class="btn btn-sm btn-secondary">← {{ __('whmcs_to_pnlcs.steps.migrate') }}</a>
            <a href="{{ route('admin.whmcs-to-pnlcs.reconcile', $run) }}" class="btn btn-sm btn-secondary">{{ __('whmcs_to_pnlcs.run.reconcile') }} →</a>
        </div>
    </div>

    <div class="wtp-card">
        <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-table-list"></i></span><h2>{{ __('whmcs_to_pnlcs.records.title', ['id' => $run->id]) }}</h2></div>
        <div class="wtp-card__body" style="overflow:auto">
            <table class="wtp-table">
                <thead>
                    <tr>
                        <th>{{ __('whmcs_to_pnlcs.records.entity') }}</th>
                        <th>{{ __('whmcs_to_pnlcs.records.source_id') }}</th>
                        <th>{{ __('whmcs_to_pnlcs.records.target_id') }}</th>
                        <th>{{ __('whmcs_to_pnlcs.records.status') }}</th>
                        <th>{{ __('whmcs_to_pnlcs.records.attempts') }}</th>
                        <th>{{ __('whmcs_to_pnlcs.records.error') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $record)
                        <tr>
                            <td><strong>{{ $record->entity }}</strong></td>
                            <td><span class="wtp-tag">{{ $record->source_id }}</span></td>
                            <td>{{ $record->target_id ?? '—' }}</td>
                            <td><span class="wtp-pill wtp-pill--{{ in_array($record->status, ['failed', 'error'], true) ? 'red' : (in_array($record->status, ['archived', 'updated'], true) ? 'amber' : 'green') }}">{{ $record->status }}</span></td>
                            <td>{{ $record->retry_count }}</td>
                            <td style="color:#6b7d92">{{ $record->error ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="wtp-empty">{{ __('whmcs_to_pnlcs.no_runs') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div style="margin-top:14px">{{ $records->links() }}</div>
        </div>
    </div>
</div>
@endsection
