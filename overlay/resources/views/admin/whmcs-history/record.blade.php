@extends('admin.layouts.app')
@section('title', __('whmcs_history.title').' — '.$record->source_table.' #'.$record->source_id)
@section('content')
<div class="page-header"><h1>{{ $record->source_table }} #{{ $record->source_id }}</h1></div>
<p><a class="btn btn-default" href="{{ route('admin.clients.whmcs-history', $client) }}">{{ __('whmcs_history.title') }}</a></p>
<p><strong>{{ __('whmcs_history.read_only') }}</strong> — {{ $record->source_key }}</p>
<p>{{ __('whmcs_history.notice') }}</p>
<p>{{ __('whmcs_history.dates') }}</p>
@if($attachments)
<h2>{{ __('whmcs_history.attachments') }}</h2>
<ul>@foreach($attachments as $attachment)
<li><a href="{{ route('admin.clients.whmcs-history.attachment', [$client, $record->id, $attachment->id]) }}">{{ $attachment->download_name }}</a> — {{ $attachment->bytes }} bytes</li>
@endforeach</ul>
@endif
@if($finance)
<h2>{{ __('whmcs_history.finance') }}</h2>
<p>{{ __('whmcs_history.finance_notice') }}</p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ json_encode(array_diff_key($finance, ['related_records' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</pre>
<ul>@foreach($finance['related_records'] as $related)
<li><a href="{{ route('admin.clients.whmcs-history.record', [$client, $related['archive_id']]) }}">{{ $related['table'] }} #{{ $related['source_id'] }}</a> {{ $related['date'] }} {{ $related['transaction_reference'] }}</li>
@endforeach</ul>
@endif
<h2>{{ __('whmcs_history.payload') }}</h2>
<dl class="card"><div class="card-body">
@foreach(json_decode($payload, true, flags: JSON_THROW_ON_ERROR) as $field => $value)
    <dt><code>{{ $field }}</code></dt>
    <dd style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ is_scalar($value) || $value === null ? ($value === null ? 'null' : (string) $value) : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</dd>
@endforeach
</div></dl>
@endsection
