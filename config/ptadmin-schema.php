<?php

declare(strict_types=1);

return [
    'runtime' => [
        'cache_path' => bootstrap_path('cache/ptadmin-runtime.php'),
        'cache_enabled' => env('PTADMIN_RUNTIME_CACHE', true),
    ],
    'sources' => [
        'application_path' => base_path('database/schemas'),
        'core_path' => __DIR__.'/../database/schemas',
        'application_owner' => env('SCHEMA_APPLICATION_OWNER', 'host'),
    ],
    'routes' => [
        'prefix' => 'ptadmin/schema',
        'middleware' => ['api'],
        'name_prefix' => 'ptadmin.schema.',
    ],
];
