@extends('admin.layouts.app')
@section('title', __('whmcs_history.title'))
@section('content')
<div class="page-header"><h1>{{ __('whmcs_history.title') }}</h1></div>
<p>{{ __('whmcs_history.notice') }}</p>
<p><strong>{{ __('whmcs_history.restricted') }}</strong></p>
@if(!$installed)<div class="alert alert-warning" role="status">{{ __('whmcs_history.missing_schema') }}</div>@endif
@if($errors->any())
<div class="alert alert-danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="card"><div class="card-body">
<form method="POST" action="{{ route('admin.whmcs-import.history.run') }}">
    @csrf
    <div class="form-group">
        <label for="history-connection">{{ __('whmcs_history.connection') }}</label>
        <select id="history-connection" class="form-control" name="connection_id" required>
            @foreach($connections as $connection)
            <option value="{{ $connection->id }}" @selected((string) old('connection_id') === (string) $connection->id)>#{{ $connection->id }} — {{ $connection->name ?: $connection->database }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label for="history-source">{{ __('whmcs_history.source') }}</label>
        <input id="history-source" class="form-control" name="source_key" value="{{ old('source_key') }}" pattern="[A-Za-z0-9._-]{1,64}" maxlength="64" required aria-describedby="history-source-help">
        <p id="history-source-help">{{ __('whmcs_history.source_help') }}</p>
    </div>
    <div class="form-group">
        <label for="history-snapshot">{{ __('whmcs_history.snapshot') }}</label>
        <input id="history-snapshot" class="form-control" name="snapshot_key" value="{{ old('snapshot_key') }}" pattern="[A-Za-z0-9._-]{1,64}" maxlength="64" required>
    </div>
    <fieldset class="form-group">
        <legend>{{ __('whmcs_history.categories') }}</legend>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;">
        @foreach($categories as $key => $definition)
            <label><input type="checkbox" name="categories[]" value="{{ $key }}" @checked(in_array($key, old('categories', array_keys($categories)), true))> {{ $key }} <small>({{ $definition['table'] }})</small></label>
        @endforeach
        </div>
    </fieldset>
    <p><label><input type="checkbox" name="restored_backup" value="1" required @checked(old('restored_backup'))> {{ __('whmcs_history.restored_backup') }}</label></p>
    <p><label><input type="checkbox" name="select_only" value="1" required @checked(old('select_only'))> {{ __('whmcs_history.select_only') }}</label></p>
    <div class="form-group">
        <label for="history-mode">{{ __('whmcs_history.mode') }}</label>
        <select id="history-mode" class="form-control" name="mode">
            <option value="dry_run" @selected(old('mode', 'dry_run') === 'dry_run')>{{ __('whmcs_history.dry_run') }}</option>
            @if($installed)<option value="archive" @selected(old('mode') === 'archive')>{{ __('whmcs_history.archive') }}</option>@endif
        </select>
    </div>
    <div class="form-group">
        <label for="history-resume">{{ __('whmcs_history.resume') }}</label>
        <input id="history-resume" class="form-control" name="resume" type="number" min="1" value="{{ old('resume') }}">
    </div>
    <button type="submit" class="btn btn-primary" @disabled($connections->isEmpty())>{{ __('whmcs_history.run') }}</button>
</form>
</div></div>
@if($report)
<h2>{{ __('whmcs_history.report') }}</h2>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</pre>
@endif
<h2>{{ __('whmcs_history.runs') }}</h2>
<div style="overflow-x:auto;">
<table class="table">
    <thead><tr><th>{{ __('whmcs_history.run_id') }}</th><th>{{ __('whmcs_history.source') }}</th><th>{{ __('whmcs_history.snapshot') }}</th><th>{{ __('whmcs_history.state') }}</th></tr></thead>
    <tbody>@foreach($runs as $run)<tr>
        <td>{{ $run->id }}</td><td>{{ $run->source_key }}</td><td>{{ $run->snapshot_key }}</td><td>{{ $run->state }}
        @if($run->report)<details><summary>{{ __('whmcs_history.report') }}</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ json_encode(json_decode($run->report, true, flags: JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</pre></details>@endif
        </td>
    </tr>@endforeach</tbody>
</table>
</div>
@endsection
