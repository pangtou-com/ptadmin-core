<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PTAdmin\Core\Install\InstallStateStore;

final class InstallStatusController extends Controller
{
    public function __construct(private InstallStateStore $state)
    {
    }

    public function __invoke(): JsonResponse
    {
        $installed = $this->state->readInstalled();
        $current = $this->state->readState();

        return response()->json([
            'installed' => $this->state->isInstalled(),
            'stage' => $installed['status'] ?? ($current['stage'] ?? 'not_started'),
            'core_version' => $installed['core_version'] ?? ($current['core_version'] ?? config('ptadmin.backend_version')),
            'web_prefix' => $installed['web_prefix'] ?? ($current['web_prefix'] ?? null),
        ]);
    }
}
