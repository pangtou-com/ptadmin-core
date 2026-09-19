@extends("ptadmin-install::layouts.base")

@section('content')
    <div class="pre-content">
        <pre><code>{{ __('ptadmin::install.finish_message') }}</code></pre>
    </div>
    <div class="install-alert install-alert-warning">
        <strong>{{ __('ptadmin::install.application_identity.title') }}</strong>
        <p>{{ __('ptadmin::install.application_identity.description') }}</p>
        <code>{{ storage_path('ptadmin.json') }}</code>
    </div>
@endsection

@section('button')
    <div class="button-row">
        <a href="{{ $adminUrl ?? url('/') }}" class="install-button install-button-primary">{{ __('ptadmin::install.login_admin') }}</a>
        <a href="{{ url('/') }}" class="install-button install-button-secondary">{{ __('ptadmin::install.back_home') }}</a>
    </div>
@endsection

@section('script')
<script>

</script>
@endsection
