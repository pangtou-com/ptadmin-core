<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Illuminate\Contracts\Foundation\Application;

final class InstallRequirementService
{
    public function __construct(
        private Application $app,
        private readonly InstallDatabaseDrivers $databaseDrivers,
    ) {
    }

    /** @return list<array{title:string, results:list<array{title:string, config:string, state:bool, description?:string}>}> */
    public function results(): array
    {
        return [
            [
                'title' => __('ptadmin::install.sections.php_version'),
                'results' => [[
                    'title' => 'PHP',
                    'config' => sprintf('>= 8.1，%s %s', __('ptadmin::install.current_version'), PHP_VERSION),
                    'state' => version_compare(PHP_VERSION, '8.1.0', '>='),
                ]],
            ],
            [
                'title' => __('ptadmin::install.sections.extensions'),
                'results' => $this->extensions(),
            ],
            [
                'title' => __('ptadmin::install.sections.folders'),
                'results' => $this->folders(),
            ],
            [
                'title' => __('ptadmin::install.sections.files'),
                'results' => $this->files(),
            ],
        ];
    }

    public function hasFailures(): bool
    {
        foreach ($this->results() as $group) {
            foreach ($group['results'] as $requirement) {
                if (!$requirement['state']) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<array{group:string,title:string,config:string}> */
    public function failures(): array
    {
        $failures = [];
        foreach ($this->results() as $group) {
            foreach ($group['results'] as $requirement) {
                if ($requirement['state']) {
                    continue;
                }
                $failures[] = [
                    'group' => $group['title'],
                    'title' => $requirement['title'],
                    'config' => $requirement['config'],
                ];
            }
        }

        return $failures;
    }

    /** @return list<array{title:string,config:string,state:bool}> */
    private function extensions(): array
    {
        $extensions = (array) config('ptadmin.install.extensions', ['json', 'mbstring', 'openssl', 'pdo']);

        $results = array_map(static fn (string $extension): array => [
            'title' => $extension,
            'config' => '',
            'state' => extension_loaded($extension),
        ], $extensions);
        $results[] = [
            'title' => __('ptadmin::install.database_extensions'),
            'config' => __('ptadmin::install.database_extensions_required'),
            'state' => $this->databaseDrivers->defaultDriver() !== null,
        ];

        return $results;
    }

    /** @return list<array{title:string,config:string,state:bool,description:string}> */
    private function folders(): array
    {
        $results = [];
        foreach ((array) config('ptadmin.install.folders', []) as $relativePath => $description) {
            $path = $this->app->basePath((string) $relativePath);
            if (!file_exists($path)) {
                @mkdir($path, 0755, true);
            }
            $results[] = [
                'title' => (string) $relativePath,
                'config' => __('ptadmin::install.writable'),
                'state' => is_dir($path) && is_writable($path),
                'description' => __((string) $description),
            ];
        }

        return $results;
    }

    /** @return list<array{title:string,config:string,state:bool}> */
    private function files(): array
    {
        $results = [];
        foreach ((array) config('ptadmin.install.files', []) as $relativePath => $mode) {
            $path = $this->app->basePath((string) $relativePath);
            $exists = file_exists($path);
            $readOnly = $mode === 'read';
            $results[] = [
                'title' => (string) $relativePath,
                'config' => $readOnly ? __('ptadmin::install.readable') : __('ptadmin::install.writable'),
                'state' => $readOnly
                    ? $exists && is_readable($path)
                    : ($exists ? is_writable($path) : is_writable(dirname($path))),
            ];
        }

        return $results;
    }
}
