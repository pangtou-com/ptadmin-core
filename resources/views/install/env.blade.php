@extends("ptadmin-install::layouts.base")

@section('content')
    @php
        $databaseConfig = (array) config('database.connections.'.$databaseDriver, []);
        $databaseValue = $databaseDriver === 'sqlite' ? 'ptadmin.db' : 'ptadmin';
    @endphp
    <form id="install-form">
        <div id="install-form-alert" class="install-alert install-alert-warning" style="display: none;"></div>
        <div class="install-section">
            <h2 class="install-section-title">{{ __('ptadmin::install.sections.basic') }}</h2>
            <div class="install-form-grid">
                <label class="install-field">
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.app_url') }}</span>
                    <input type="url" name="app_url" value="{{ $appUrl }}" placeholder="{{ __('ptadmin::install.placeholders.app_url') }}" autocomplete="url" class="install-input" required>
                </label>
                <label class="install-field">
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.app_name') }}</span>
                    <input type="text" name="app_name" value="{{ config('app.name', 'PTAdmin') }}" placeholder="{{ __('ptadmin::install.placeholders.app_name') }}" autocomplete="organization" class="install-input" maxlength="100" required>
                </label>
                <label class="install-field">
                    <span class="install-field-label">{{ __('ptadmin::install.fields.web_prefix') }}</span>
                    <input type="text" name="ptadmin_web_prefix" placeholder="{{ __('ptadmin::install.placeholders.web_prefix') }}" autocomplete="off" class="install-input" pattern="[a-z][a-z0-9_-]{2,63}(\/[a-z][a-z0-9_-]{2,63})*" maxlength="128">
                    <small class="install-field-help">{{ __('ptadmin::install.help.web_prefix') }}</small>
                </label>
            </div>
        </div>

        <div class="install-section">
            <h2 class="install-section-title">{{ __('ptadmin::install.sections.database') }}</h2>
            <div class="install-form-grid">
                <label class="install-field">
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.db_connection') }}</span>
                    <select name="db_connection" class="install-select" required>
                        @foreach ($databaseOptions as $option)
                            <option value="{{ $option['driver'] }}" @selected($databaseDriver === $option['driver']) @disabled(!$option['available']) data-port="{{ $option['port'] }}">{{ $option['available'] ? $option['label'] : __('ptadmin::install.database_driver_missing_extension', ['database' => $option['label'], 'extension' => $option['extension']]) }}</option>
                        @endforeach
                    </select>
                    <small class="install-field-help">{{ __('ptadmin::install.help.database_driver') }}</small>
                </label>
                <label class="install-field" data-server-database-only>
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.db_host') }}</span>
                    <input type="text" name="db_host" value="{{ $databaseConfig['host'] ?? '127.0.0.1' }}" placeholder="{{ __('ptadmin::install.placeholders.db_host') }}" autocomplete="off" class="install-input">
                </label>
                <label class="install-field" data-server-database-only>
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.db_port') }}</span>
                    <input type="number" name="db_port" value="{{ ($databaseConfig['port'] ?? '') ?: ($databaseDriver === 'pgsql' ? '5432' : '3306') }}" min="1" max="65535" placeholder="{{ __('ptadmin::install.placeholders.db_port') }}" autocomplete="off" class="install-input">
                </label>
                <label class="install-field">
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.db_database') }}</span>
                    <input type="text" name="db_database" value="{{ $databaseValue }}" placeholder="{{ __('ptadmin::install.placeholders.db_database') }}" autocomplete="off" class="install-input" required>
                </label>
                <label class="install-field" data-server-database-only>
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.db_username') }}</span>
                    <input type="text" name="db_username" value="{{ $databaseConfig['username'] ?? '' }}" placeholder="{{ __('ptadmin::install.placeholders.db_username') }}" autocomplete="username" class="install-input">
                </label>
                <label class="install-field" data-server-database-only>
                    <span class="install-field-label">{{ __('ptadmin::install.fields.db_password') }}</span>
                    <input type="password" name="db_password" value="" placeholder="{{ __('ptadmin::install.placeholders.db_password') }}" autocomplete="current-password" class="install-input">
                </label>
                <label class="install-field">
                    <span class="install-field-label">{{ __('ptadmin::install.fields.db_prefix') }}</span>
                    <input type="text" name="db_prefix" value="pt_" placeholder="{{ __('ptadmin::install.placeholders.db_prefix') }}" autocomplete="off" class="install-input" pattern="[A-Za-z0-9_]{0,32}" maxlength="32">
                </label>
            </div>
        </div>

        <div class="install-section">
            <h2 class="install-section-title">{{ __('ptadmin::install.sections.founder') }}</h2>
            <div class="install-form-grid">
                <label class="install-field">
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.username') }}</span>
                    <input type="text" name="username" placeholder="{{ __('ptadmin::install.placeholders.username') }}" autocomplete="username" class="install-input" minlength="4" maxlength="36" pattern="[A-Za-z0-9_.-]+" required>
                </label>
                <label class="install-field">
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.nickname') }}</span>
                    <input type="text" name="nickname" value="超级管理员" placeholder="{{ __('ptadmin::install.placeholders.nickname') }}" autocomplete="name" class="install-input" maxlength="100" required>
                </label>
                <label class="install-field">
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.password') }}</span>
                    <input type="password" name="password" placeholder="{{ __('ptadmin::install.placeholders.password') }}" autocomplete="new-password" class="install-input" required minlength="6" maxlength="36">
                </label>
                <label class="install-field">
                    <span class="install-field-label"><span class="required">*</span> {{ __('ptadmin::install.fields.password_confirmation') }}</span>
                    <input type="password" name="password_confirmation" placeholder="{{ __('ptadmin::install.placeholders.password_confirmation') }}" autocomplete="new-password" class="install-input" required minlength="6" maxlength="36">
                </label>
                <label class="install-field">
                    <span class="install-field-label">{{ __('ptadmin::install.fields.email') }}</span>
                    <input type="email" name="email" placeholder="{{ __('ptadmin::install.placeholders.email') }}" autocomplete="email" class="install-input" maxlength="255">
                </label>
                <label class="install-field">
                    <span class="install-field-label">{{ __('ptadmin::install.fields.mobile') }}</span>
                    <input type="text" name="mobile" placeholder="{{ __('ptadmin::install.placeholders.mobile') }}" autocomplete="tel" inputmode="tel" class="install-input" maxlength="30">
                </label>
            </div>
        </div>
    </form>
@endsection

@section('button')
    <div class="button-row">
        <a href="{{ route('ptadmin.install.requirements') }}" class="install-button install-button-secondary">{{ __('ptadmin::install.prev') }}</a>
        <button type="button" id="submit" class="install-button install-button-primary">{{ __('ptadmin::install.submit') }}</button>
    </div>
@endsection

@section('script')
    @include("ptadmin-install::_js")
@endsection
