<?php

declare(strict_types=1);

return [
    'api_prefix' => env('PTADMIN_API_PREFIX', 'ptadmin'),
    'api_url' => env('PTADMIN_API_URL', ''),
    'route_prefix' => env('PTADMIN_ROUTE_PREFIX', 'ptadmin'),
    'web_prefix' => env('PTADMIN_WEB_PREFIX', 'admin'),
    'backend_version' => env('PTADMIN_CORE_VERSION', '0.1.0'),
    'api_protocol' => (int) env('PTADMIN_API_PROTOCOL', 1),
    'schema_protocol' => (int) env('PTADMIN_SCHEMA_PROTOCOL', 1),
    'config_groups' => [
        'system' => '系统配置',
        'security' => '安全',
        'auth' => '认证',
        'addon' => '插件配置',
    ],
    'frontend' => [
        'bundled_path' => __DIR__.'/../resources/admin-frontend',
        'host_apis' => ['v1'],
        'runtime_profiles' => ['admin-v1'],
        'development' => [
            'allowed_origins' => [],
        ],
        'capabilities' => [
            'context',
            'surface.lifecycle',
            'http.request',
            'feedback.notify',
            'feedback.confirm',
            'navigation.openResource',
            'schema.runtime',
            'schema.openPresentation',
            'surface.setTitle',
            'surface.openPopup',
            'surface.closePopup',
            'assets.pick',
            'assets.upload',
        ],
    ],
    'theme' => [
        'preset' => env('PTADMIN_THEME_PRESET', 'classic-blue'),
        'mode' => env('PTADMIN_THEME_MODE', 'light'),
        'primary_color' => env('PTADMIN_THEME_PRIMARY_COLOR', '#2563eb'),
    ],
    'site_theme' => [
        'directory' => env('PTADMIN_SITE_THEME_DIRECTORY', base_path('templates')),
        'state_file' => env('PTADMIN_SITE_THEME_STATE_FILE', storage_path('theme.json')),
        'public_directory' => env('PTADMIN_SITE_THEME_PUBLIC_DIRECTORY', public_path('themes')),
        'staging_directory' => env('PTADMIN_SITE_THEME_STAGING_DIRECTORY', storage_path('app/ptadmin/themes/staging')),
        'default' => [
            'code' => env('PTADMIN_SITE_THEME_DEFAULT_CODE', 'default'),
            'version' => env('PTADMIN_SITE_THEME_DEFAULT_VERSION', '1.0.0'),
        ],
    ],
    'layout' => [
        'navigation' => env('PTADMIN_LAYOUT_NAVIGATION', 'sidebar'),
        'density' => env('PTADMIN_LAYOUT_DENSITY', 'comfortable'),
    ],
    'auth' => [
        'login_path' => env('PTADMIN_LOGIN_PATH', '/login'),
        'session_path' => env('PTADMIN_SESSION_PATH', '/session'),
        'renew_path' => env('PTADMIN_RENEW_PATH', '/session/renew'),
    ],
    'session' => [
        'table' => env('PTADMIN_SESSION_TABLE', 'ptadmin_sessions'),
        'idle_minutes' => (int) env('PTADMIN_SESSION_IDLE_MINUTES', 30),
        'absolute_hours' => (int) env('PTADMIN_SESSION_ABSOLUTE_HOURS', 8),
    ],
    'storage' => [
        'public_disk' => env('PTADMIN_PUBLIC_UPLOAD_DISK', 'public'),
        'private_disk' => env('PTADMIN_PRIVATE_UPLOAD_DISK', 'local'),
        'public_directory' => env('PTADMIN_PUBLIC_UPLOAD_DIRECTORY', 'assets'),
        'private_directory' => env('PTADMIN_PRIVATE_UPLOAD_DIRECTORY', 'private'),
        'max_upload_kilobytes' => (int) env('PTADMIN_MAX_UPLOAD_KILOBYTES', 10240),
    ],
    'install' => [
        'database_default' => 'mysql',
        'database_drivers' => ['sqlite', 'mysql'],
        'extensions' => ['json', 'mbstring', 'openssl', 'pdo'],
        'folders' => [
            'addons' => 'ptadmin::install.requirement_descriptions.folders.addons',
            'public' => 'ptadmin::install.requirement_descriptions.folders.public',
            'storage' => 'ptadmin::install.requirement_descriptions.folders.storage',
            'bootstrap/cache' => 'ptadmin::install.requirement_descriptions.folders.bootstrap_cache',
        ],
        'files' => [
            '.env' => 'write',
        ],
    ],
    'tasks' => [
        'path' => env('PTADMIN_TASK_PATH', storage_path('app/ptadmin/tasks')),
        'heartbeat_timeout_seconds' => (int) env('PTADMIN_TASK_HEARTBEAT_TIMEOUT', 900),
        'event_limit' => (int) env('PTADMIN_TASK_EVENT_LIMIT', 100),
    ],
    'cloud' => [
        'client_id' => env('PTADMIN_CLOUD_CLIENT_ID', ''),
        'redirect_uri' => env('PTADMIN_CLOUD_REDIRECT_URI', ''),
        'callback_redirect' => env('PTADMIN_CLOUD_CALLBACK_REDIRECT', ''),
        'session_path' => env('PTADMIN_CLOUD_SESSION_PATH', storage_path('app/ptadmin/cloud/session.json')),
    ],
    'addon' => [
        'path' => env('PTADMIN_ADDON_PATH', base_path('addons')),
        'download_path' => env('PTADMIN_ADDON_DOWNLOAD_PATH', storage_path('app/ptadmin/addons/downloads')),
        'require_signature' => (bool) env('PTADMIN_ADDON_REQUIRE_SIGNATURE', true),
        'runtime' => [
            'enabled' => (bool) env('PTADMIN_ADDON_RUNTIME_ENABLED', true),
        ],
        'trust' => [
            'keys' => [],
            'publishers' => [],
            'allow_local' => (bool) env('PTADMIN_ADDON_ALLOW_LOCAL_TRUST', false),
        ],
    ],
];
