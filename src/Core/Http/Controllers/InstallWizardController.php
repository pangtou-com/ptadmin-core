<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use PTAdmin\Core\Install\InstallRequirementService;
use PTAdmin\Core\Install\InstallDatabaseDrivers;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Core\Install\InstallStateStore;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class InstallWizardController extends Controller
{
    private const AGREEMENT_TTL_SECONDS = 3600;

    public function __construct(
        private InstallStateStore $state,
        private Application $app,
    ) {
    }

    public function welcome(Request $request): View
    {
        $this->assertNotInstalled();

        return view('ptadmin-install::welcome', $this->viewData(0, [
            'installErrorMessage' => $this->errorMessage((string) $request->query('error', '')),
            'redirect' => $this->installRedirect((string) $request->query('redirect', '')),
        ]));
    }

    public function accept(Request $request): RedirectResponse
    {
        $this->assertNotInstalled();
        $directory = dirname($this->agreementPath());
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new InvalidInstallStateException('Unable to create the installation state directory.');
        }
        file_put_contents($this->agreementPath(), (string) time(), LOCK_EX);
        chmod($this->agreementPath(), 0600);

        return redirect()->to(
            $this->installRedirect((string) $request->input('redirect', '')) ?: route('ptadmin.install.requirements')
        );
    }

    public function requirements(): View|RedirectResponse
    {
        $this->assertNotInstalled();
        if (!$this->agreementAccepted()) {
            return $this->agreementRedirect(route('ptadmin.install.requirements'));
        }

        $requirements = $this->app->make(InstallRequirementService::class);
        $results = $requirements->results();
        $failedItems = $requirements->failures();

        return view('ptadmin-install::requirements', $this->viewData(1, [
            'results' => $results,
            'allPassed' => $failedItems === [],
            'failedItems' => $failedItems,
        ]));
    }

    public function account(Request $request): View|RedirectResponse
    {
        $this->assertNotInstalled();
        if (!$this->agreementAccepted()) {
            return $this->agreementRedirect(route('ptadmin.install.account'));
        }
        if ($this->app->make(InstallRequirementService::class)->hasFailures()) {
            return redirect()->route('ptadmin.install.requirements', ['error' => 'requirements']);
        }

        return view('ptadmin-install::env', $this->viewData(2, [
            'appUrl' => $request->root(),
            'databaseOptions' => $this->app->make(InstallDatabaseDrivers::class)->options(),
            'databaseDriver' => $this->app->make(InstallDatabaseDrivers::class)->defaultDriver(),
        ]));
    }

    public function stream(Request $request): StreamedResponse
    {
        $this->assertNotInstalled();
        $input = $request->only([
            'username', 'password', 'password_confirmation', 'nickname', 'email', 'mobile',
            'app_name', 'app_url', 'ptadmin_web_prefix', 'db_connection', 'db_database',
            'db_host', 'db_port', 'db_username', 'db_password', 'db_prefix',
        ]);
        if (!$request->filled('app_url')) {
            $input['app_url'] = $request->root();
        }

        return response()->stream(function () use ($input): void {
            try {
                if (!$this->agreementAccepted()) {
                    $this->send('error', __('ptadmin::install.stream.agreement_required'));
                    return;
                }
                if ($this->app->make(InstallRequirementService::class)->hasFailures()) {
                    $this->send('error', __('ptadmin::install.stream.requirements_failed'));
                    return;
                }

                $validator = Validator::make($input, [
                    'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{4,36}$/'],
                    'password' => ['required', 'string', 'min:6', 'max:36', 'confirmed'],
                    'nickname' => ['required', 'string', 'max:100'],
                    'email' => ['nullable', 'email', 'max:255'],
                    'mobile' => ['nullable', 'string', 'max:30'],
                    'app_name' => ['nullable', 'string', 'max:100'],
                    'app_url' => ['nullable', 'url', 'max:2048'],
                    'ptadmin_web_prefix' => ['nullable', 'string', 'max:128'],
                    'db_connection' => ['nullable', 'string', 'in:sqlite,mysql,pgsql'],
                    'db_database' => ['nullable', 'string', 'max:255'],
                    'db_host' => ['nullable', 'string', 'max:255'],
                    'db_port' => ['nullable', 'integer', 'between:1,65535'],
                    'db_username' => ['nullable', 'string', 'max:255'],
                    'db_password' => ['nullable', 'string', 'max:4096'],
                    'db_prefix' => ['nullable', 'regex:/^[A-Za-z0-9_]{0,32}$/'],
                ], [
                    'username.required' => __('ptadmin::install.validation.username_required'),
                    'password.required' => __('ptadmin::install.validation.password_required'),
                    'password.min' => __('ptadmin::install.validation.password_min'),
                    'password.max' => __('ptadmin::install.validation.password_max'),
                    'password.confirmed' => __('ptadmin::install.validation.password_confirmed'),
                    'nickname.required' => __('ptadmin::install.validation.nickname_required'),
                ]);
                if ($validator->fails()) {
                    $this->send('error', $validator->errors()->first());
                    return;
                }

                $this->send('process', __('ptadmin::install.logs.install_starting'));
                $result = $this->app->make(InstallService::class)->install(
                    $validator->validated(),
                    function (string $stage, array $context): void {
                        $this->sendProgress($stage, $context);
                    }
                );
                $webPrefix = (string) ($result['marker']['web_prefix'] ?? '');
                $this->send('success', __('ptadmin::install.logs.install_success'), [
                    'admin_url' => url('/'.$webPrefix).'/',
                ]);
            } catch (InvalidInstallStateException $exception) {
                $this->send('error', $exception->getMessage());
            } catch (Throwable $exception) {
                Log::error('PTAdmin installation wizard failed.', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $this->send('error', __('ptadmin::install.stream.general_failed'));
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function viewData(int $step, array $extra = []): array
    {
        return $extra + [
            'step' => $step,
            'tabs' => [
                ['title' => __('ptadmin::install.steps.protocol')],
                ['title' => __('ptadmin::install.steps.requirements')],
                ['title' => __('ptadmin::install.steps.environment')],
                ['title' => __('ptadmin::install.steps.finish')],
            ],
        ];
    }

    private function assertNotInstalled(): void
    {
        if ($this->state->isInstalled()) {
            abort(404);
        }
    }

    private function agreementAccepted(): bool
    {
        if (!is_file($this->agreementPath())) {
            return false;
        }
        $acceptedAt = (int) file_get_contents($this->agreementPath());
        if ($acceptedAt <= 0 || $acceptedAt + self::AGREEMENT_TTL_SECONDS < time()) {
            @unlink($this->agreementPath());
            return false;
        }

        return true;
    }

    private function agreementPath(): string
    {
        return $this->state->directory().'/agreement.lock';
    }

    private function agreementRedirect(string $target): RedirectResponse
    {
        return redirect()->route('ptadmin.install.welcome', [
            'redirect' => $this->installRedirect($target),
            'error' => 'protocol',
        ]);
    }

    private function installRedirect(string $target): string
    {
        $path = (string) (parse_url($target, PHP_URL_PATH) ?? '');
        $installPath = '/__ptadmin__/install';

        return str_starts_with($path, $installPath) ? $path : '';
    }

    private function errorMessage(string $error): string
    {
        return match ($error) {
            'protocol' => __('ptadmin::install.errors.protocol'),
            'requirements' => __('ptadmin::install.errors.requirements'),
            default => '',
        };
    }

    /** @param array<string, mixed> $context */
    private function sendProgress(string $stage, array $context): void
    {
        $message = __('ptadmin::install.logs.'.$stage, $context);
        $this->send('process', $message === 'ptadmin::install.logs.'.$stage ? $stage : $message);

    }

    /** @param array<string, mixed> $data */
    private function send(string $type, string $message, array $data = []): void
    {
        echo json_encode(compact('type', 'message', 'data'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
