<?php

declare(strict_types=1);

return [
    'runtime' => [
        // Resolve the application-specific bootstrap path in the ServiceProvider;
        // package config may be loaded before Laravel's global path helpers.
        'cache_path' => null,
        'cache_enabled' => env('PTADMIN_RUNTIME_CACHE', true),
    ],
    'sources' => [
        'application_path' => base_path('database/schemas'),
        'core_path' => __DIR__.'/../database/schemas',
        'application_owner' => env('SCHEMA_APPLICATION_OWNER', 'host'),
    ],
    'routes' => [
        'prefix' => 'ptadmin/schema',
        // Core 统一提供后台认证与审计；避免继承宿主公共 API 的共享限流。
        'middleware' => [\Illuminate\Routing\Middleware\SubstituteBindings::class],
        'name_prefix' => 'ptadmin.schema.',
    ],
];
