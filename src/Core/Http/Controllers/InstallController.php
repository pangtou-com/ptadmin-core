<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Core\Install\Exceptions\InstallAlreadyRunningException;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use PTAdmin\Core\Install\InstallService;

final class InstallController extends Controller
{
    public function __construct(private InstallService $installer)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{3,100}$/'],
            'password' => ['required', 'string', 'min:6', 'max:4096'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'app_name' => ['nullable', 'string', 'max:100'],
            'app_url' => ['nullable', 'url', 'max:2048'],
            'ptadmin_web_prefix' => ['nullable', 'string', 'max:128'],
            'web_prefix' => ['nullable', 'string', 'max:128'],
            'db_connection' => ['nullable', 'string', 'in:sqlite,mysql'],
            'db_database' => ['nullable', 'string', 'max:255'],
            'db_host' => ['nullable', 'string', 'max:255'],
            'db_port' => ['nullable', 'integer', 'between:1,65535'],
            'db_username' => ['nullable', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:4096'],
            'db_prefix' => ['nullable', 'regex:/^[A-Za-z0-9_]{0,32}$/'],
            'database' => ['nullable', 'array'],
            'database.driver' => ['nullable', 'string', 'in:sqlite,mysql'],
            'database.database' => ['nullable', 'string', 'max:255'],
            'database.host' => ['nullable', 'string', 'max:255'],
            'database.port' => ['nullable', 'integer', 'between:1,65535'],
            'database.username' => ['nullable', 'string', 'max:255'],
            'database.password' => ['nullable', 'string', 'max:4096'],
            'database.prefix' => ['nullable', 'regex:/^[A-Za-z0-9_]{0,32}$/'],
        ], [
            'username.required' => __('ptadmin::install.validation.username_required'),
            'password.required' => __('ptadmin::install.validation.password_required'),
            'password.min' => __('ptadmin::install.validation.password_min'),
            'password.max' => __('ptadmin::install.validation.password_max'),
        ]);
        if ($validator->fails()) {
            return AdminResponse::error(42200, __('ptadmin::install.validation.payload_invalid'), $validator->errors(), 422);
        }
        $validated = $validator->validated();

        try {
            $result = $this->installer->install($validated);
        } catch (InstallAlreadyRunningException $exception) {
            return AdminResponse::error(40901, 'Another PTAdmin installation is already running.', null, 409);
        } catch (InvalidInstallStateException $exception) {
            return AdminResponse::error(40902, $exception->getMessage(), null, 409);
        } catch (\Throwable) {
            return AdminResponse::error(50001, 'PTAdmin installation failed.', null, 500);
        }

        $status = ($result['result'] ?? null) === null ? 200 : 201;

        return AdminResponse::success('', $result, $status);
    }
}
