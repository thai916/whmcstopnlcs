@extends('admin.layouts.app')
@section('title', __('whmcs_history.title').' — #'.$client->id)
@section('content')
<div class="page-header"><h1>{{ __('whmcs_history.title') }} — #{{ $client->id }} {{ $client->full_name }}</h1></div>
<p><a class="btn btn-default" href="{{ route('admin.clients.show', $client) }}">{{ __('whmcs_history.profile') }}</a></p>
<p>{{ __('whmcs_history.notice') }}</p>
<p><strong>{{ __('whmcs_history.restricted') }}</strong></p>
@if($errors->any())<div class="alert alert-danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(!$installed)
<div class="alert alert-warning" role="status">{{ __('whmcs_history.missing_schema') }}</div>
@else
<form method="GET" class="card"><div class="card-body">
    <label for="archive-source">{{ __('whmcs_history.source') }}</label>
    <select name="source" id="archive-source">
        <option value="">{{ __('whmcs_history.all_sources') }}</option>
        @foreach($links->unique('source_key') as $link)<option value="{{ $link->source_key }}" @selected(request('source') === $link->source_key)>{{ $link->source_key }}</option>@endforeach
    </select>
    <label for="archive-category">{{ __('whmcs_history.category') }}</label>
    <select name="category" id="archive-category">
        <option value="">{{ __('whmcs_history.all_categories') }}</option>
        @foreach($categories as $key => $definition)<option value="{{ $key }}" @selected(request('category') === $key)>{{ $key }}</option>@endforeach
    </select>
    <button class="btn btn-default" type="submit">{{ __('whmcs_history.filter') }}</button>
</div></form>
<div style="overflow-x:auto;">
<table class="table"><thead><tr><th>{{ __('whmcs_history.source') }}</th><th>{{ __('whmcs_history.category') }}</th><th>{{ __('whmcs_history.source_id') }}</th></tr></thead>
<tbody>
@forelse($records as $record)
<tr><td>{{ $record->source_key }}</td><td>{{ $record->source_table }}</td><td><a href="{{ route('admin.clients.whmcs-history.record', [$client, $record->id]) }}">{{ $record->source_id }} — {{ __('whmcs_history.view_record') }}</a></td></tr>
@empty
<tr><td colspan="3">{{ __('whmcs_history.empty') }}</td></tr>
@endforelse
</tbody></table>
</div>
<nav aria-label="{{ __('whmcs_history.title') }}">
    <a class="btn btn-default" href="{{ route('admin.clients.whmcs-history', ['client' => $client] + request()->only('source', 'category')) }}">{{ __('whmcs_history.first') }}</a>
    @if($next)<a class="btn btn-default" href="{{ route('admin.clients.whmcs-history', ['client' => $client, 'after' => $next] + request()->only('source', 'category')) }}">{{ __('whmcs_history.next') }}</a>@endif
</nav>
<h2>{{ __('whmcs_history.links') }}</h2>
<ul>@foreach($links as $link)<li>{{ $link->source_key }} / {{ $link->client_source_id }}</li>@endforeach</ul>
<details class="card" style="margin-top:20px;"><summary>{{ __('whmcs_history.link_title') }}</summary><div class="card-body">
<p>{{ __('whmcs_history.link_warning') }}</p>
<form method="POST" action="{{ route('admin.clients.whmcs-history.link', $client) }}">
    @csrf
    <div class="form-group"><label for="link-source">{{ __('whmcs_history.source') }}</label><input class="form-control" id="link-source" name="source_key" value="{{ old('source_key') }}" pattern="[A-Za-z0-9._-]{1,64}" maxlength="64" required></div>
    <div class="form-group"><label for="link-client">{{ __('whmcs_history.source_id') }}</label><input class="form-control" id="link-client" name="client_source_id" value="{{ old('client_source_id') }}" pattern="[1-9][0-9]{0,18}" maxlength="19" required></div>
    <p><label><input type="checkbox" name="confirmed" value="1" required> {{ __('whmcs_history.confirmed') }}</label></p>
    <button class="btn btn-primary" type="submit">{{ __('whmcs_history.link') }}</button>
</form>
</div></details>
@endif
@endsection
