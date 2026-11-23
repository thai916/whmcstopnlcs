@extends('admin.layouts.app')
@section('title', __('whmcs_to_pnlcs.title'))
@section('content')
@include('admin.whmcs-to-pnlcs._styles')

<div class="wtp">
    <div class="wtp-hero">
        <h1><i class="fas fa-box-archive"></i> {{ __('whmcs_to_pnlcs.archive_view.title') }}</h1>
        <p>{{ __('whmcs_to_pnlcs.archive_view.help') }}</p>
    </div>

    @include('admin.whmcs-to-pnlcs._steps', ['current' => 9])

    <div class="wtp-card">
        <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-lock"></i></span><h2>{{ __('whmcs_to_pnlcs.archive_view.title') }}</h2></div>
        <div class="wtp-card__body" style="overflow:auto">
            <table class="wtp-table">
                <thead>
                    <tr>
                        <th>{{ __('whmcs_to_pnlcs.archive_view.entity') }}</th>
                        <th>{{ __('whmcs_to_pnlcs.archive_view.source_id') }}</th>
                        <th>{{ __('whmcs_to_pnlcs.archive_view.created') }}</th>
                        <th>{{ __('whmcs_to_pnlcs.archive_view.data') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $record)
                        <tr>
                            <td><strong>{{ $record->entity }}</strong></td>
                            <td><span class="wtp-tag">{{ $record->source_id }}</span></td>
                            <td>{{ $record->source_created_at?->format('Y-m-d') ?? '—' }}</td>
                            <td class="wtp-mono" style="max-width:640px">{{ json_encode($record->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="wtp-empty">{{ __('whmcs_to_pnlcs.no_runs') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div style="margin-top:14px">{{ $records->links() }}</div>
        </div>
    </div>
</div>
@endsection
