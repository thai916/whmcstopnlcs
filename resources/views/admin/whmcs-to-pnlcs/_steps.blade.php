@php
    $labels = [
        1 => __('whmcs_to_pnlcs.steps.connect'),
        2 => __('whmcs_to_pnlcs.steps.source'),
        3 => __('whmcs_to_pnlcs.steps.destination'),
        4 => __('whmcs_to_pnlcs.steps.scope'),
        5 => __('whmcs_to_pnlcs.steps.mapping'),
        6 => __('whmcs_to_pnlcs.steps.dry_run'),
        7 => __('whmcs_to_pnlcs.steps.migrate'),
        8 => __('whmcs_to_pnlcs.steps.verify'),
        9 => __('whmcs_to_pnlcs.steps.report'),
    ];
@endphp
<div class="wtp-steps" aria-label="{{ __('whmcs_to_pnlcs.title') }}">
    @foreach($labels as $number => $label)
        <span class="wtp-step @if($number == ($current ?? 1)) wtp-step--on @elseif($number < ($current ?? 1)) wtp-step--done @endif">
            @if($number < ($current ?? 1)) <i class="fas fa-check"></i> @else <i class="fas fa-circle" style="font-size:7px"></i> @endif
            {{ $label }}
        </span>
    @endforeach
</div>
