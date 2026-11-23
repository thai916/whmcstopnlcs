@extends('admin.layouts.app')
@section('title', __('whmcs_to_pnlcs.title'))
@section('content')
@include('admin.whmcs-to-pnlcs._styles')

<div class="wtp">
    <div class="wtp-hero">
        <h1><i class="fas fa-scale-balanced"></i> {{ __('whmcs_to_pnlcs.reconcile.title', ['id' => $run->id]) }}</h1>
        <p>{{ $report['financial_note'] ?? '' }}</p>
        <div class="wtp-chips">
            <a href="{{ route('admin.whmcs-to-pnlcs.run', $run) }}" class="btn btn-sm btn-secondary">← {{ __('whmcs_to_pnlcs.reconcile.back') }}</a>
        </div>
    </div>

    @include('admin.whmcs-to-pnlcs._steps', ['current' => 8])

    @if(!empty($report['error']))
        <div class="wtp-note wtp-note--warn" role="alert"><i class="fas fa-triangle-exclamation"></i> {{ $report['error'] }}</div>
    @else
        <div class="wtp-card">
            <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-table-list"></i></span><h2>{{ __('whmcs_to_pnlcs.reconcile.title', ['id' => $run->id]) }}</h2></div>
            <div class="wtp-card__body" style="overflow:auto">
                <table class="wtp-table">
                    <thead>
                        <tr>
                            <th>{{ __('whmcs_to_pnlcs.reconcile.entity') }}</th>
                            <th style="text-align:right">{{ __('whmcs_to_pnlcs.reconcile.source_count') }}</th>
                            <th style="text-align:right">{{ __('whmcs_to_pnlcs.reconcile.target_count') }}</th>
                            <th style="text-align:right">{{ __('whmcs_to_pnlcs.reconcile.delta') }}</th>
                            <th style="text-align:right">{{ __('whmcs_to_pnlcs.reconcile.source_total') }}</th>
                            <th style="text-align:right">{{ __('whmcs_to_pnlcs.reconcile.target_total') }}</th>
                            <th style="text-align:right">{{ __('whmcs_to_pnlcs.reconcile.total_difference') }}</th>
                            <th>{{ __('whmcs_to_pnlcs.reconcile.note') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report['rows'] as $row)
                            <tr>
                                <td><strong>{{ $row['entity'] }}</strong></td>
                                <td style="text-align:right">{{ number_format($row['source_count']) }}</td>
                                <td style="text-align:right">{{ number_format($row['target_count']) }}</td>
                                <td style="text-align:right"><span class="wtp-pill wtp-pill--{{ $row['count_difference'] === 0 ? 'green' : 'amber' }}">{{ $row['count_difference'] > 0 ? '+' : '' }}{{ $row['count_difference'] }}</span></td>
                                <td style="text-align:right">{{ $row['source_total'] ?? '—' }}</td>
                                <td style="text-align:right">{{ $row['target_total'] ?? '—' }}</td>
                                <td style="text-align:right">{{ $row['total_difference'] ?? '—' }}</td>
                                <td style="color:#6b7d92">{{ $row['note'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
