@extends('admin.layouts.app')
@section('title', __('whmcs_to_pnlcs.title'))
@section('content')
@include('admin.whmcs-to-pnlcs._styles')

@php
    $selected = old('scope', ['full']);
@endphp
<div class="wtp">
    <div class="wtp-hero">
        <h1><i class="fas fa-magnifying-glass-chart"></i> {{ __('whmcs_to_pnlcs.title') }}</h1>
        <p>{{ $connection->name ?: $connection->database }} — {{ $connection->host }}:{{ $connection->port }}</p>
        <div class="wtp-chips">
            <span class="wtp-chip"><i class="fas fa-database"></i> {{ $analysis['version'] ? 'WHMCS '.$analysis['version'] : ($analysis['server']['mysql_version'] ?? '') }}</span>
            <span class="wtp-chip"><i class="fas fa-table"></i> {{ $analysis['table_count'] }} {{ __('whmcs_to_pnlcs.diagnostics.tables') }}</span>
            <span class="wtp-chip"><i class="fas fa-lock"></i> {{ strtoupper($analysis['server']['security_mode'] ?? '') }}</span>
        </div>
    </div>

    @include('admin.whmcs-to-pnlcs._steps', ['current' => 2])

    @if(!empty($analysis['unreadable_tables']))
        <div class="wtp-note wtp-note--warn" role="alert">
            <i class="fas fa-triangle-exclamation"></i> {{ $analysis['grant_coverage']['label'] }}
            <div class="wtp-mono" style="margin-top:5px">{{ implode(', ', $analysis['unreadable_tables']) }}</div>
        </div>
    @endif

    <div class="wtp-grid">
        <div class="wtp-card">
            <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-database"></i></span><h2>{{ __('whmcs_to_pnlcs.source.title') }}</h2></div>
            <div class="wtp-card__body" style="max-height:520px;overflow:auto">
                <table class="wtp-table">
                    <thead>
                        <tr>
                            <th>{{ __('whmcs_to_pnlcs.source.entity') }}</th>
                            <th>{{ __('whmcs_to_pnlcs.source.source_table') }}</th>
                            <th style="text-align:right">{{ __('whmcs_to_pnlcs.source.records') }}</th>
                            <th>{{ __('whmcs_to_pnlcs.source.classification') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($analysis['available_entities'] as $entity)
                            @php $definition = $catalog[$entity] ?? []; @endphp
                            <tr>
                                <td><strong>{{ $definition['label'] ?? $entity }}</strong></td>
                                <td><span class="wtp-tag">{{ $definition['source'] ?? '—' }}</span></td>
                                <td style="text-align:right">{{ number_format($analysis['counts'][$entity] ?? 0) }}</td>
                                <td>
                                    <span class="wtp-pill wtp-pill--{{ ($definition['mode'] ?? 'native') === 'archive' ? 'amber' : 'blue' }}">
                                        {{ ($definition['mode'] ?? 'native') === 'archive' ? __('whmcs_to_pnlcs.archive_mode') : __('whmcs_to_pnlcs.native') }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                        @foreach($analysis['extra_archive_tables'] as $extra)
                            <tr>
                                <td>{{ __('whmcs_to_pnlcs.other_history') }}</td>
                                <td><span class="wtp-tag">{{ $extra['table'] }}</span></td>
                                <td style="text-align:right">{{ number_format($extra['count']) }}</td>
                                <td><span class="wtp-pill wtp-pill--amber">{{ __('whmcs_to_pnlcs.archive_mode') }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <div class="wtp-card">
                <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-shield-halved"></i></span><h2>{{ __('whmcs_to_pnlcs.diagnostics.title') }}</h2></div>
                <div class="wtp-card__body">
                    <dl class="wtp-kv">
                        <dt>{{ __('whmcs_to_pnlcs.diagnostics.grants') }}</dt><dd>{{ $analysis['grant_coverage']['label'] }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.diagnostics.mode') }}</dt><dd>{{ strtoupper($analysis['server']['security_mode'] ?? '') }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.diagnostics.tls') }}</dt><dd>{{ !empty($analysis['server']['tls_active']) ? __('whmcs_to_pnlcs.diagnostics.yes') : __('whmcs_to_pnlcs.diagnostics.no') }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.diagnostics.verified') }}</dt><dd>{{ !empty($analysis['server']['identity_verified']) ? __('whmcs_to_pnlcs.diagnostics.yes') : __('whmcs_to_pnlcs.diagnostics.no') }}</dd>
                        <dt>{{ __('whmcs_to_pnlcs.diagnostics.timezone') }}</dt>
                        <dd>
                            {{ $analysis['timezone']['source_iana'] ?? $analysis['timezone']['source_setting'] ?? '—' }}
                            @if(!empty($analysis['timezone']['warning']))
                                <div class="wtp-note wtp-note--warn" style="margin:6px 0 0">{{ $analysis['timezone']['warning'] }}</div>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="wtp-card">
                <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-arrow-right-arrow-left"></i></span><h2>{{ __('whmcs_to_pnlcs.destination.title') }}</h2></div>
                <div class="wtp-card__body">
                    <table class="wtp-table">
                        <thead><tr><th>{{ __('whmcs_to_pnlcs.destination.table') }}</th><th style="text-align:right">{{ __('whmcs_to_pnlcs.destination.existing') }}</th></tr></thead>
                        <tbody>
                            @foreach($destination as $table => $count)
                                <tr><td><span class="wtp-tag">{{ $table }}</span></td><td style="text-align:right">{{ number_format($count) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="wtp-card">
        <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-bolt"></i></span><h2>{{ __('whmcs_to_pnlcs.safety.title') }}</h2></div>
        <div class="wtp-card__body">
            @foreach($safety as $finding)
                <div class="wtp-note wtp-note--{{ $finding['level'] === 'warning' ? 'warn' : 'info' }}">
                    <i class="fas fa-{{ $finding['level'] === 'warning' ? 'triangle-exclamation' : 'circle-info' }}"></i>
                    <strong>{{ $finding['title'] }}</strong> — {{ $finding['detail'] }}
                </div>
            @endforeach
        </div>
    </div>

    <form method="post" action="{{ route('admin.whmcs-to-pnlcs.prepare', $connection) }}">
        @csrf
        <div class="wtp-card">
            <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-crosshairs"></i></span><h2>{{ __('whmcs_to_pnlcs.scope.title') }}</h2></div>
            <div class="wtp-card__body">
                <div class="wtp-note wtp-note--info">{{ __('whmcs_to_pnlcs.scope.help') }}</div>

                <div class="wtp-section">{{ __('whmcs_to_pnlcs.steps.scope') }}</div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px">
                    @foreach($groups as $key => $group)
                        <label style="display:flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid #d5dee9;border-radius:8px;background:#fff;font-size:12px;font-weight:600;cursor:pointer">
                            <input type="checkbox" name="scope[]" value="{{ $key }}" class="wtp-group" data-entities="{{ implode(',', $group['entities']) }}" @if(in_array($key, $selected, true)) checked @endif style="width:auto">
                            {{ $group['label'] }}
                            <span class="wtp-pill wtp-pill--gray">{{ count($group['entities']) }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="wtp-section">{{ __('whmcs_to_pnlcs.scope.options') }}</div>
                <div class="wtp-fields">
                    <div class="wtp-field">
                        <label for="wtp-batch">{{ __('whmcs_to_pnlcs.scope.batch_size') }}</label>
                        <input id="wtp-batch" name="batch_size" type="number" min="1" max="1000" value="{{ old('batch_size', 100) }}">
                    </div>
                    <div class="wtp-field">
                        <label for="wtp-duplicate">{{ __('whmcs_to_pnlcs.scope.duplicate_strategy') }}</label>
                        <select id="wtp-duplicate" name="duplicate_strategy">
                            <option value="skip" @if(old('duplicate_strategy', 'skip') === 'skip') selected @endif>{{ __('whmcs_to_pnlcs.scope.duplicate_skip') }}</option>
                            <option value="update" @if(old('duplicate_strategy') === 'update') selected @endif>{{ __('whmcs_to_pnlcs.scope.duplicate_update') }}</option>
                            <option value="flag" @if(old('duplicate_strategy') === 'flag') selected @endif>{{ __('whmcs_to_pnlcs.scope.flag') }}</option>
                        </select>
                    </div>
                    <div class="wtp-field">
                        <label for="wtp-name">{{ __('whmcs_to_pnlcs.connection.name') }}</label>
                        <input id="wtp-name" name="name" value="{{ old('name') }}" autocomplete="off">
                    </div>
                    <div class="wtp-field">
                        <label for="wtp-tz">{{ __('whmcs_to_pnlcs.run.timezone') }}</label>
                        <input id="wtp-tz" name="timezone_override" value="{{ old('timezone_override') }}" placeholder="{{ $analysis['timezone']['source_iana'] ?? 'Europe/Warsaw' }}">
                    </div>
                    <div class="wtp-field wtp-span2" style="flex-direction:row;gap:24px;flex-wrap:wrap">
                        <label style="display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;cursor:pointer">
                            <input type="checkbox" name="archive_extras" value="1" @if(old('archive_extras', true)) checked @endif style="width:auto"> {{ __('whmcs_to_pnlcs.scope.archive_extras') }}
                        </label>
                        <label style="display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;cursor:pointer;color:#1c7a45">
                            <input type="checkbox" name="dry_run" value="1" @if(old('dry_run', true)) checked @endif style="width:auto"> {{ __('whmcs_to_pnlcs.scope.dry_run') }}
                        </label>
                    </div>
                </div>

                <div class="wtp-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> {{ __('whmcs_to_pnlcs.scope.start') }}</button>
                    <a href="{{ route('admin.whmcs-to-pnlcs.index') }}" class="btn btn-secondary">{{ __('whmcs_to_pnlcs.reconcile.back') }}</a>
                </div>
            </div>
        </div>
    </form>

    <div class="wtp-card">
        <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-table-columns"></i></span><h2>{{ __('whmcs_to_pnlcs.mapping.title') }}</h2></div>
        <div class="wtp-card__body">
            <div class="wtp-note wtp-note--info">{{ __('whmcs_to_pnlcs.mapping.help') }}</div>
            <div style="max-height:420px;overflow:auto">
                <table class="wtp-table">
                    <thead><tr><th>{{ __('whmcs_to_pnlcs.mapping.target') }}</th><th>{{ __('whmcs_to_pnlcs.source.source_table') }}</th><th>{{ __('whmcs_to_pnlcs.mapping.fields') }}</th><th>{{ __('whmcs_to_pnlcs.mapping.notes') }}</th></tr></thead>
                    <tbody>
                        @foreach($catalog as $entity => $definition)
                            <tr>
                                <td><strong>{{ $definition['label'] ?? $entity }}</strong><br><span class="wtp-tag">{{ $definition['target'] ?? 'archive' }}</span></td>
                                <td><span class="wtp-tag">{{ $definition['source'] ?? '—' }}</span></td>
                                <td class="wtp-mono">{{ implode(', ', $definition['fields'] ?? []) ?: '—' }}</td>
                                <td>
                                    @if(!empty($definition['require_review'])) <span class="wtp-pill wtp-pill--amber">review required</span> @endif
                                    @if(!empty($definition['no_email'])) <span class="wtp-pill wtp-pill--gray">no email</span> @endif
                                    @if(!empty($definition['no_gateway'])) <span class="wtp-pill wtp-pill--gray">no gateway</span> @endif
                                    @if(!empty($definition['no_registrar'])) <span class="wtp-pill wtp-pill--gray">no registrar</span> @endif
                                    @if(!empty($definition['reason'])) <div style="margin-top:4px;color:#6b7d92">{{ $definition['reason'] }}</div> @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
