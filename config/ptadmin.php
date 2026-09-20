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
        'general' => '常规',
        'security' => '安全',
        'auth' => '认证',
        'appearance' => '外观',
        'frontend' => '前端',
        'addon' => '插件',
    ],
    'frontend' => [
        'bundled_path' => __DIR__.'/../resources/admin-frontend',
        'host_apis' => ['admin-v1'],
        'runtime_profiles' => ['admin-v1'],
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
