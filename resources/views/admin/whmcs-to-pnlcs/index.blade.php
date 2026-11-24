@extends('admin.layouts.app')
@section('title', __('whmcs_to_pnlcs.title'))
@section('content')
@include('admin.whmcs-to-pnlcs._styles')

<div class="wtp">
    <div class="wtp-hero">
        <h1><i class="fas fa-right-left"></i> {{ __('whmcs_to_pnlcs.title') }}</h1>
        <p>{{ __('whmcs_to_pnlcs.description') }}</p>
        <div class="wtp-chips">
            <span class="wtp-chip"><i class="fas fa-lock"></i> {{ __('whmcs_to_pnlcs.badge_read_only') }}</span>
            <span class="wtp-chip"><i class="fas fa-layer-group"></i> {{ __('whmcs_to_pnlcs.badge_batches') }}</span>
            <span class="wtp-chip"><i class="fas fa-clipboard-check"></i> {{ __('whmcs_to_pnlcs.badge_audit') }}</span>
            <span class="wtp-chip"><i class="fas fa-ban"></i> {{ __('whmcs_to_pnlcs.badge_no_automation') }}</span>
        </div>
    </div>

    @include('admin.whmcs-to-pnlcs._steps', ['current' => 1])

    <div class="wtp-note wtp-note--warn" role="note">
        <i class="fas fa-network-wired"></i> {{ __('whmcs_to_pnlcs.connection.ip_note') }}
    </div>

    @if($errors->has('connection'))
        <div class="wtp-note wtp-note--warn" role="alert">
            <i class="fas fa-triangle-exclamation"></i> {{ $errors->first('connection') }}
        </div>
    @endif
    @if($errors->any())
        <div class="wtp-note wtp-note--warn" role="alert">
            <ul style="margin:0;padding-left:18px">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="wtp-grid">
        <div class="wtp-card">
            <div class="wtp-card__head">
                <span class="wtp-card__icon"><i class="fas fa-database"></i></span>
                <h2>{{ __('whmcs_to_pnlcs.connection.title') }}</h2>
            </div>
            <div class="wtp-card__body">
                <div class="wtp-note wtp-note--info">
                    <i class="fas fa-shield-halved"></i> {{ __('whmcs_to_pnlcs.safety.detail') }}
                </div>
                <form method="post" action="{{ route('admin.whmcs-to-pnlcs.connection.store') }}">
                    @csrf
                    <div class="wtp-fields">
                        <div class="wtp-field wtp-span2">
                            <label for="wtp-name">{{ __('whmcs_to_pnlcs.connection.name') }}</label>
                            <input id="wtp-name" name="name" value="{{ old('name') }}" autocomplete="off">
                        </div>
                        <div class="wtp-field">
                            <label for="wtp-host">{{ __('whmcs_to_pnlcs.connection.host') }}</label>
                            <input id="wtp-host" name="host" value="{{ old('host') }}" required autocomplete="off" placeholder="db.example.com">
                        </div>
                        <div class="wtp-field">
                            <label for="wtp-port">{{ __('whmcs_to_pnlcs.connection.port') }}</label>
                            <input id="wtp-port" name="port" type="number" min="1" max="65535" value="{{ old('port', 3306) }}" required>
                        </div>
                        <div class="wtp-field">
                            <label for="wtp-database">{{ __('whmcs_to_pnlcs.connection.database') }}</label>
                            <input id="wtp-database" name="database" value="{{ old('database') }}" required autocomplete="off">
                        </div>
                        <div class="wtp-field">
                            <label for="wtp-prefix">{{ __('whmcs_to_pnlcs.connection.prefix') }}</label>
                            <input id="wtp-prefix" name="prefix" value="{{ old('prefix', 'tbl') }}" autocomplete="off">
                        </div>
                        <div class="wtp-field">
                            <label for="wtp-username">{{ __('whmcs_to_pnlcs.connection.username') }}</label>
                            <input id="wtp-username" name="username" value="{{ old('username') }}" required autocomplete="off">
                        </div>
                        <div class="wtp-field">
                            <label for="wtp-password">{{ __('whmcs_to_pnlcs.connection.password') }}</label>
                            <input id="wtp-password" name="password" type="password" autocomplete="new-password">
                        </div>
                        <div class="wtp-field">
                            <label for="wtp-timeout">{{ __('whmcs_to_pnlcs.connection.timeout') }}</label>
                            <input id="wtp-timeout" name="timeout" type="number" min="1" max="120" value="{{ old('timeout', 10) }}">
                        </div>
                        <div class="wtp-field">
                            <label for="wtp-tls">{{ __('whmcs_to_pnlcs.connection.security') }}</label>
                            <select id="wtp-tls" name="tls_mode" onchange="wtpTlsHelp()">
                                <option value="verified" @if(old('tls_mode', 'verified') === 'verified') selected @endif>{{ __('whmcs_to_pnlcs.modes.verified') }}</option>
                                <option value="unverified" @if(old('tls_mode') === 'unverified') selected @endif>{{ __('whmcs_to_pnlcs.modes.unverified') }}</option>
                                <option value="none" @if(old('tls_mode') === 'none') selected @endif>{{ __('whmcs_to_pnlcs.modes.none') }}</option>
                            </select>
                        </div>
                        <div class="wtp-field wtp-span2">
                            <label for="wtp-ca">{{ __('whmcs_to_pnlcs.connection.ca') }}</label>
                            <input id="wtp-ca" name="ca_path" value="{{ old('ca_path') }}" autocomplete="off">
                        </div>
                        <div class="wtp-field wtp-span2">
                            <label for="wtp-tls-host">{{ __('whmcs_to_pnlcs.connection.tls_hostname') }}</label>
                            <input id="wtp-tls-host" name="tls_hostname" value="{{ old('tls_hostname') }}" autocomplete="off">
                        </div>
                        <div class="wtp-field wtp-span2" id="wtp-insecure-wrap" style="display:none">
                            <label style="display:flex;gap:8px;align-items:flex-start;font-weight:600;color:#b4232a">
                                <input type="checkbox" name="allow_insecure" value="1" @if(old('allow_insecure')) checked @endif style="width:auto;margin-top:2px">
                                <span>{{ __('whmcs_to_pnlcs.connection.insecure') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="wtp-note wtp-note--warn" id="wtp-tls-help" style="display:none"></div>
                    <div class="wtp-actions">
                        <button type="submit" formaction="{{ route('admin.whmcs-to-pnlcs.connection.test') }}" class="btn btn-secondary">
                            <i class="fas fa-plug"></i> {{ __('whmcs_to_pnlcs.test_connection') }}
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-arrow-right"></i> {{ __('whmcs_to_pnlcs.save_continue') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div>
            <div class="wtp-card">
                <div class="wtp-card__head">
                    <span class="wtp-card__icon"><i class="fas fa-bookmark"></i></span>
                    <h2>{{ __('whmcs_to_pnlcs.saved_connections') }}</h2>
                    <span class="wtp-card__aside">{{ count($connections) }}</span>
                </div>
                <div class="wtp-card__body">
                    @forelse($connections as $connection)
                        <div class="wtp-conn">
                            <span class="wtp-card__icon"><i class="fas fa-server"></i></span>
                            <div class="wtp-conn__body">
                                <div class="wtp-conn__name">{{ $connection->name ?: $connection->database }}</div>
                                <div class="wtp-conn__meta">{{ $connection->host }}:{{ $connection->port }} · {{ $connection->username }} · {{ strtoupper($connection->tls_mode) }}</div>
                            </div>
                            <a href="{{ route('admin.whmcs-to-pnlcs.analyze', $connection) }}" class="btn btn-sm btn-primary">{{ __('whmcs_to_pnlcs.open_connection') }}</a>
                        </div>
                    @empty
                        <div class="wtp-empty">{{ __('whmcs_to_pnlcs.no_connections') }}</div>
                    @endforelse
                </div>
            </div>

            <div class="wtp-card">
                <div class="wtp-card__head">
                    <span class="wtp-card__icon"><i class="fas fa-clock-rotate-left"></i></span>
                    <h2>{{ __('whmcs_to_pnlcs.recent_runs') }}</h2>
                </div>
                <div class="wtp-card__body">
                    @forelse($runs as $run)
                        <div class="wtp-conn">
                            <div class="wtp-conn__body">
                                <div class="wtp-conn__name">#{{ $run->id }} — {{ $run->name ?: implode(', ', $run->scope ?? []) }}</div>
                                <div class="wtp-conn__meta">
                                    {{ $run->dry_run ? __('whmcs_to_pnlcs.steps.dry_run') : __('whmcs_to_pnlcs.steps.migrate') }} ·
                                    {{ $run->created_at?->format('Y-m-d H:i') }}
                                </div>
                            </div>
                            <span class="wtp-pill wtp-pill--{{ $run->status === 'completed' ? 'green' : ($run->status === 'failed' ? 'red' : 'blue') }}">
                                {{ __('whmcs_to_pnlcs.statuses.'.$run->status) ?? $run->status }}
                            </span>
                            <a href="{{ route('admin.whmcs-to-pnlcs.run', $run) }}" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-right"></i></a>
                        </div>
                    @empty
                        <div class="wtp-empty">{{ __('whmcs_to_pnlcs.no_runs') }}</div>
                    @endforelse
                </div>
            </div>

            @if($profiles->isNotEmpty())
                <div class="wtp-card">
                    <div class="wtp-card__head">
                        <span class="wtp-card__icon"><i class="fas fa-layer-group"></i></span>
                        <h2>{{ __('whmcs_to_pnlcs.saved_profiles') }}</h2>
                    </div>
                    <div class="wtp-card__body">
                        @foreach($profiles as $profile)
                            <div class="wtp-conn">
                                <div class="wtp-conn__body">
                                    <div class="wtp-conn__name">{{ $profile->name }}</div>
                                    <div class="wtp-conn__meta">{{ $profile->connection?->database }} · {{ implode(', ', $profile->scope ?? []) }}</div>
                                </div>
                                <form method="post" action="{{ route('admin.whmcs-to-pnlcs.profile.destroy', $profile) }}" onsubmit="return pnConfirm(event, @js(__('Are you sure?')))">
                                    @csrf @method('delete')
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if($diagnostics)
        <div class="wtp-card">
            <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-stethoscope"></i></span><h2>{{ __('whmcs_to_pnlcs.diagnostics.title') }}</h2></div>
            <div class="wtp-card__body">
                <dl class="wtp-kv">
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.version') }}</dt><dd>{{ $diagnostics['version'] ?? '—' }}</dd>
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.mysql') }}</dt><dd>{{ $diagnostics['server']['mysql_version'] ?? '—' }}</dd>
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.database') }}</dt><dd>{{ $diagnostics['server']['database'] ?? '—' }}</dd>
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.tables') }}</dt><dd>{{ $diagnostics['table_count'] ?? 0 }}</dd>
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.mode') }}</dt><dd>{{ strtoupper($diagnostics['server']['security_mode'] ?? '') }}</dd>
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.tls') }}</dt><dd>{{ !empty($diagnostics['server']['tls_active']) ? __('whmcs_to_pnlcs.diagnostics.yes') : __('whmcs_to_pnlcs.diagnostics.no') }}</dd>
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.verified') }}</dt><dd>{{ !empty($diagnostics['server']['identity_verified']) ? __('whmcs_to_pnlcs.diagnostics.yes') : __('whmcs_to_pnlcs.diagnostics.no') }}</dd>
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.grants') }}</dt><dd>{{ $diagnostics['grants']['label'] ?? '—' }}</dd>
                    <dt>{{ __('whmcs_to_pnlcs.diagnostics.timezone') }}</dt>
                    <dd>
                        {{ $diagnostics['timezone']['source_iana'] ?? $diagnostics['timezone']['source_setting'] ?? '—' }}
                        @if(!empty($diagnostics['timezone']['warning']))
                            <div class="wtp-note wtp-note--warn" style="margin:6px 0 0">{{ $diagnostics['timezone']['warning'] }}</div>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    @endif

    <div class="wtp-card">
        <div class="wtp-card__head"><span class="wtp-card__icon"><i class="fas fa-list-check"></i></span><h2>{{ __('whmcs_to_pnlcs.safety.title') }}</h2></div>
        <div class="wtp-card__body">
            @foreach($checklist as $item)
                <div class="wtp-note wtp-note--info"><i class="fas fa-check"></i> {{ $item }}</div>
            @endforeach
        </div>
    </div>
</div>

<script>
function wtpTlsHelp() {
    const mode = document.getElementById('wtp-tls').value;
    const help = document.getElementById('wtp-tls-help');
    const wrap = document.getElementById('wtp-insecure-wrap');
    const texts = {
        unverified: @js(__('whmcs_to_pnlcs.connection.warning_unverified')),
        none: @js(__('whmcs_to_pnlcs.connection.warning_none'))
    };
    help.textContent = texts[mode] || '';
    help.style.display = texts[mode] ? 'block' : 'none';
    wrap.style.display = mode === 'none' ? 'block' : 'none';
}
wtpTlsHelp();
</script>
@endsection
