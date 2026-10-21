<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Core\Tasks\TaskException;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\PendingTaskDispatcher;
use PTAdmin\Core\Theme\ActiveThemeResolver;
use PTAdmin\Core\Theme\ThemeCatalog;
use PTAdmin\Core\Theme\ThemeDescriptor;
use PTAdmin\Core\Theme\ThemeDataPackImporter;
use PTAdmin\Core\Theme\ThemeDataPackValidator;
use PTAdmin\Core\Theme\ThemeDataPackDescriptor;
use PTAdmin\Core\Theme\ThemeException;
use PTAdmin\Core\Theme\ThemeLifecycle;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ThemeController extends Controller
{
    public function __construct(private readonly PendingTaskDispatcher $dispatcher)
    {
    }

    public function index(ThemeCatalog $catalog, ActiveThemeResolver $active, ThemeDataPackValidator $dataPacks, ThemeDataPackImporter $importer): JsonResponse
    {
        $scan = $catalog->scan();
        $current = $active->active();

        return AdminResponse::success('', [
            'total' => count($scan['themes']),
            'results' => array_map(fn (ThemeDescriptor $theme): array => $this->summary($theme, $current, false, $dataPacks, $importer), $scan['themes']),
            'errors' => $scan['errors'],
        ]);
    }

    public function show(string $code, string $version, ThemeCatalog $catalog, ActiveThemeResolver $active, ThemeDataPackValidator $dataPacks, ThemeDataPackImporter $importer): JsonResponse
    {
        $theme = $catalog->find($code, $version);
        if ($theme === null) {
            return AdminResponse::error(40400, 'Theme not found.', ['error_code' => 'theme_not_found'], 404);
        }

        return AdminResponse::success('', $this->summary($theme, $active->active(), true, $dataPacks, $importer));
    }

    public function cover(string $code, string $version, ThemeCatalog $catalog): BinaryFileResponse
    {
        $theme = $catalog->find($code, $version);
        if ($theme === null) {
            abort(404);
        }

        $cover = $this->coverPath($theme);
        if ($cover === null) {
            abort(404);
        }

        return response()->file($cover, [
            'Content-Type' => $this->coverMimeType($cover),
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    public function install(Request $request, TaskManager $tasks): JsonResponse
    {
        $source = (string) $request->input('source', '');
        if ($source === '') {
            return AdminResponse::error(42200, 'Theme package source is required.', ['error_code' => 'theme_source_required'], 422);
        }
        try {
            $idempotencyKey = $request->input('idempotency_key', $request->header('Idempotency-Key'));
            $task = $tasks->create(
                TaskManager::SITE_THEME_INSTALL,
                [
                    'source' => $source,
                    'code' => $request->input('code'),
                    'version' => $request->input('version'),
                    'data_packs' => is_array($request->input('data_packs')) ? array_values($request->input('data_packs')) : [],
                ],
                $this->requestId($request),
                (int) $request->input('max_attempts', 1),
                is_string($idempotencyKey) ? $idempotencyKey : null,
            );

            $this->dispatcher->dispatch($task);
            return AdminResponse::success('', $task, 202);
        } catch (TaskException $exception) {
            $status = in_array($exception->errorCode, ['idempotency_key_conflict', 'task_in_progress'], true) ? 409 : 422;
            return AdminResponse::error($status === 409 ? 40900 : 42200, $exception->getMessage(), ['error_code' => $exception->errorCode, 'details' => $exception->details], $status);
        }
    }

    public function activate(string $code, string $version, ThemeLifecycle $lifecycle): JsonResponse
    {
        try {
            $result = $lifecycle->activate($code, $version);

            return AdminResponse::success('', [
                'code' => $result['code'],
                'version' => $result['version'],
                'active' => $result['active'],
            ]);
        } catch (ThemeException $exception) {
            $status = $exception->errorCode === 'theme_not_found' ? 404 : 422;

            return AdminResponse::error(
                $status === 404 ? 40400 : 42200,
                $exception->getMessage(),
                ['error_code' => $exception->errorCode, 'details' => $exception->details],
                $status,
            );
        }
    }

    public function remove(string $code, string $version, Request $request, TaskManager $tasks): JsonResponse
    {
        return $this->lifecycleTask($request, $tasks, TaskManager::SITE_THEME_REMOVE, $code, $version, 'remove');
    }

    /** @return array<string,mixed> */
    private function summary(ThemeDescriptor $theme, ?ThemeDescriptor $active, bool $details, ThemeDataPackValidator $dataPackValidator, ThemeDataPackImporter $importer): array
    {
        $manifest = $theme->manifest;
        $cover = $this->coverPath($theme);
        $result = [
            'code' => $theme->code,
            'version' => $theme->version,
            'name' => (string) ($manifest['name'] ?? ''),
            'author' => (string) ($manifest['author'] ?? ''),
            'state' => 'installed',
            'cover_url' => $cover === null ? null : $this->coverUrl($theme),
            'template_count' => $this->templateCount($theme),
            'data_packs' => array_map(
                fn (ThemeDataPackDescriptor $pack): array => $importer->inspect($pack),
                $dataPackValidator->validate($manifest['data_packs'] ?? null, $theme->directory),
            ),
            'active' => $active?->identifier() === $theme->identifier(),
            'updated_at' => date(DATE_ATOM, (int) (filemtime($theme->directory) ?: time())),
        ];
        if ($details) {
            $result['manifest'] = $manifest;
            $result['modules'] = is_array($manifest['modules'] ?? null) ? $manifest['modules'] : [];
            $result['directory'] = $theme->directory;
        }

        return $result;
    }

    private function coverPath(ThemeDescriptor $theme): ?string
    {
        foreach (['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'] as $extension) {
            if (is_file($theme->directory.'/cover.'.$extension)) {
                return $theme->directory.'/cover.'.$extension;
            }
        }

        return null;
    }

    private function coverUrl(ThemeDescriptor $theme): string
    {
        $prefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');

        return '/'.$prefix.'/public/site/themes/'.rawurlencode($theme->code).'/'.rawurlencode($theme->version).'/cover';
    }

    private function coverMimeType(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function templateCount(ThemeDescriptor $theme): int
    {
        $count = 0;
        foreach (glob($theme->directory.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (in_array(basename($directory), ['assets', 'data'], true)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function requestId(Request $request): ?string
    {
        $value = $request->attributes->get('ptadmin.request_id');
        return is_string($value) ? $value : null;
    }

    private function lifecycleTask(Request $request, TaskManager $tasks, string $type, string $code, string $version, string $operation): JsonResponse
    {
        try {
            $idempotencyKey = $request->input('idempotency_key', $request->header('Idempotency-Key'));
            $task = $tasks->create($type, ['code' => $code, 'version' => $version, 'operation' => $operation], $this->requestId($request), (int) $request->input('max_attempts', 1), is_string($idempotencyKey) ? $idempotencyKey : null);

            $this->dispatcher->dispatch($task);
            return AdminResponse::success('', $task, 202);
        } catch (TaskException $exception) {
            $status = in_array($exception->errorCode, ['idempotency_key_conflict', 'task_in_progress'], true) ? 409 : 422;
            return AdminResponse::error($status === 409 ? 40900 : 42200, $exception->getMessage(), ['error_code' => $exception->errorCode, 'details' => $exception->details], $status);
        }
    }
}
