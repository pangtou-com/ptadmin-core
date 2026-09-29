<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Core\Config\ConfigException;
use PTAdmin\Core\Dictionary\DictionarySynchronizer;
use PTAdmin\Notice\NoticeSynchronizer;
use PTAdmin\Core\Http\AdminFrontendRouteRegistrar;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use RuntimeException;

/**
 * 编排首次安装所需的数据库、身份、管理员和核心权限资源。
 * 所有写入都由 InstallStateMachine 持锁执行，失败时由状态机记录脱敏状态。
 */
final class InstallService
{
    public function __construct(
        private InstallStateMachine $stateMachine,
        private InstallStateStore $stateStore,
        private EnvironmentFile $environment,
        private InstallDatabaseConfigurator $database,
        private AdminFrontendRouteRegistrar $frontendRoutes,
        private AdminFrontendPublisher $frontend,
        private ConfigService $configs,
        private DictionarySynchronizer $dictionaries,
        private SchemaSourceDiscovery $schemaSources,
        private SchemaFileSynchronizer $schemaSynchronizer,
        private MenuSynchronizer $menus,
        private RuntimeCacheStore $runtimeCache,
        private ?NoticeSynchronizer $notices = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function install(array $input, ?callable $progress = null): array
    {
        $input = $this->normalizeInput($input);
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $nickname = trim((string) ($input['nickname'] ?? '超级管理员'));
        $email = trim((string) ($input['email'] ?? ''));
        $mobile = trim((string) ($input['mobile'] ?? ''));
        $requestedWebPrefix = trim((string) ($input['ptadmin_web_prefix'] ?? $input['web_prefix'] ?? ''));
        $appName = trim((string) ($input['app_name'] ?? config('app.name', 'PTAdmin')));
        $appUrl = trim((string) ($input['app_url'] ?? config('app.url', 'http://localhost')));

        $this->assertInput($username, $password, $nickname, $email, $mobile, $requestedWebPrefix, $appName, $appUrl);

        return $this->stateMachine->install(function (array $state) use ($username, $password, $nickname, $email, $mobile, $appName, $appUrl, $input, $progress): array {
            $webPrefix = (string) ($state['web_prefix'] ?? '');
            if ((string) config('app.key', '') === '') {
                throw new InvalidInstallStateException('Laravel APP_KEY is not configured.');
            }
            $database = $this->database->configure($input);
            $this->progress($progress, 'database_migrating');
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            if ($exitCode !== 0) {
                throw new RuntimeException('PTAdmin database migration failed.');
            }

            $state = $this->stateStore->writeState(array_merge($state, [
                'stage' => 'database_ready',
                'failure' => null,
            ]));
            $this->progress($progress, 'database_ready');

            $this->progress($progress, 'schema_syncing');
            $coreSchemaResults = $this->schemaSynchronizer->sync($this->schemaSources->discoverCore());
            $blockedSchemas = array_values(array_filter(
                $coreSchemaResults,
                static fn (array $result): bool => $result['status'] === 'blocked'
            ));
            if ($blockedSchemas !== []) {
                $blocked = $blockedSchemas[0];
                throw new RuntimeException(sprintf(
                    'PTAdmin bundled Schema publication was blocked: %s (%s).',
                    $blocked['resource'],
                    $blocked['risk']
                ));
            }
            $this->notices?->syncCore();
            $state = $this->stateStore->writeState(array_merge($state, [
                'stage' => 'schema_ready',
                'core_schema_resources' => array_column($coreSchemaResults, 'resource'),
                'failure' => null,
            ]));
            $this->progress($progress, 'schema_ready');

            $this->progress($progress, 'config_syncing');
            config(['app.name' => $appName]);
            $configDefinitions = $this->syncConfigDefinitions();
            $site = $this->configs->get('core', 'ptadmin', 'site');
            if (($site['effective_values']['url'] ?? '') === '') {
                $this->configs->put('core', 'ptadmin', 'site', ['url' => $appUrl]);
            }
            $state = $this->stateStore->writeState(array_merge($state, [
                'stage' => 'config_ready',
                'config_definitions' => $configDefinitions,
                'failure' => null,
            ]));
            $this->progress($progress, 'config_ready');

            $dictionaryDefinitions = $this->syncDictionaryDefinitions();

            $this->progress($progress, 'admin_creating');
            $result = DB::transaction(function () use ($username, $password, $nickname, $email, $mobile): array {
                $admin = $this->resolveFounder($username, $password, $nickname, $email, $mobile);
                $menuResult = $this->menus->syncCore();
                $resources = ['resource_count' => count($menuResult['resource_keys'])];

                return [
                    'admin' => [
                        'id' => (int) $admin->getKey(),
                        'username' => (string) $admin->username,
                        'nickname' => (string) $admin->nickname,
                    ],
                    'resources' => $resources,
                ];
            });
            $this->progress($progress, 'admin_ready');

            $this->progress($progress, 'frontend_publishing');
            $frontend = $this->frontend->publishForInstall($webPrefix);
            $state = $this->stateStore->writeState(array_merge($state, [
                'stage' => 'frontend_ready',
                'frontend_version' => $frontend['frontend_version'],
                'failure' => null,
            ]));
            $this->progress($progress, 'frontend_ready', [
                'version' => $frontend['frontend_version'],
            ]);

            $this->progress($progress, 'environment_writing');
            $this->environment->put('PTADMIN_WEB_PREFIX', $webPrefix);
            $this->environment->put('APP_NAME', $appName);
            $this->environment->put('APP_URL', $appUrl);
            foreach ($this->database->environmentValues($database) as $key => $value) {
                $this->environment->put($key, (string) $value);
            }
            config(['ptadmin.web_prefix' => $webPrefix]);
            config(['app.name' => $appName, 'app.url' => $appUrl]);
            $this->frontendRoutes->register($webPrefix);
            $this->progress($progress, 'environment_ready');

            $this->progress($progress, 'runtime_finalizing');
            $this->resetRuntimeCaches();
            $this->ensureStorageLink();
            $this->progress($progress, 'runtime_ready');

            return $result + [
                'config_definitions' => $configDefinitions,
                'dictionary_definitions' => $dictionaryDefinitions,
                'frontend' => $frontend,
                'web_prefix' => $webPrefix,
                'database' => [
                    'driver' => $database['driver'],
                    'database' => $database['database'],
                    'prefix' => $database['prefix'],
                ],
            ];
        }, $requestedWebPrefix !== '' ? $requestedWebPrefix : null);
    }

    /**
     * @param callable(string,array<string,mixed>):void|null $progress
     * @param array<string,mixed> $context
     */
    private function progress(?callable $progress, string $stage, array $context = []): void
    {
        if (null !== $progress) {
            $progress($stage, $context);
        }
    }

    public function syncConfigDefinitions(): int
    {
        $definitions = $this->loadCoreConfigDefinitions();
        try {
            $this->configs->deactivateDefinition('core', 'ptadmin', 'appearance');
        } catch (ConfigException $exception) {
            if ($exception->errorCode !== 'config_not_found') {
                throw $exception;
            }
        }
        foreach ($definitions as $definition) {
            $this->configs->syncDefinition($definition + ['owner_type' => 'core', 'owner_code' => 'ptadmin']);
        }

        return count($definitions);
    }

    /** @return array<int, array<string, mixed>> */
    private function loadCoreConfigDefinitions(): array
    {
        $paths = glob(__DIR__.'/../../../resources/config.*.json') ?: [];
        sort($paths, SORT_STRING);
        $definitions = [];
        foreach ($paths as $path) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Core configuration resource JSON is invalid: '.$path, 0, $exception);
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException('Core configuration resource must be a JSON object: '.$path);
            }
            if (!isset($decoded['schema']) || !is_array($decoded['schema'])) {
                throw new RuntimeException('Core configuration resource requires a schema object: '.$path);
            }
            $definitions[] = $this->applyCoreConfigDefaults($decoded);
        }

        return $definitions;
    }

    /** @param array<string, mixed> $definition
     *  @return array<string, mixed>
     */
    private function applyCoreConfigDefaults(array $definition): array
    {
        $configKey = (string) ($definition['config_key'] ?? '');
        $runtimeDefaults = match ($configKey) {
            'site' => [
                'title' => (string) config('app.name', 'PTAdmin'),
            ],
            'mail' => [
                'host' => (string) config('mail.mailers.smtp.host', '127.0.0.1'),
                'port' => (int) config('mail.mailers.smtp.port', 2525),
                'encryption' => (string) (config('mail.mailers.smtp.encryption') ?? ''),
                'username' => (string) (config('mail.mailers.smtp.username') ?? ''),
                'password' => (string) (config('mail.mailers.smtp.password') ?? ''),
                'from_address' => (string) config('mail.from.address', 'hello@example.com'),
                'from_name' => (string) config('mail.from.name', 'PTAdmin'),
            ],
            'upload' => [
                'public_disk' => (string) config('ptadmin.storage.public_disk', 'public'),
                'private_disk' => (string) config('ptadmin.storage.private_disk', 'local'),
                'public_directory' => (string) config('ptadmin.storage.public_directory', 'assets'),
                'private_directory' => (string) config('ptadmin.storage.private_directory', 'private'),
                'max_upload_kilobytes' => (int) config('ptadmin.storage.max_upload_kilobytes', 10240),
            ],
            default => [],
        };
        $fields = (array) ($definition['schema']['fields'] ?? []);
        foreach ($fields as &$field) {
            $name = (string) ($field['name'] ?? '');
            if (array_key_exists($name, $runtimeDefaults)) {
                $field['default'] = $runtimeDefaults[$name];
            }
        }
        unset($field);
        $definition['schema']['fields'] = $fields;

        return $definition;
    }

    /** 在数据库迁移后幂等同步 Core 内置字典声明。 */
    public function syncDictionaryDefinitions(): int
    {
        return $this->dictionaries->syncCore();
    }

    /** @param array<string, mixed> $input */
    private function normalizeInput(array $input): array
    {
        if (isset($input['database']) && is_array($input['database'])) {
            foreach ([
                'driver' => 'db_connection',
                'database' => 'db_database',
                'host' => 'db_host',
                'port' => 'db_port',
                'username' => 'db_username',
                'password' => 'db_password',
                'prefix' => 'db_prefix',
            ] as $source => $target) {
                if (!array_key_exists($target, $input) && array_key_exists($source, $input['database'])) {
                    $input[$target] = $input['database'][$source];
                }
            }
        }
        if (!array_key_exists('ptadmin_web_prefix', $input) && array_key_exists('web_prefix', $input)) {
            $input['ptadmin_web_prefix'] = $input['web_prefix'];
        }

        return $input;
    }

    private function resolveFounder(
        string $username,
        string $password,
        string $nickname,
        string $email,
        string $mobile
    ): Admin {
        /** @var Admin|null $existing */
        $existing = Admin::query()->where('username', $username)->first();
        if (Admin::query()->exists()) {
            if (null === $existing
                || !(bool) $existing->is_founder
                || !(bool) $existing->status
                || !Hash::check($password, (string) $existing->password)) {
                throw new InvalidInstallStateException('PTAdmin administrator accounts already exist.');
            }

            return $existing;
        }

        $admin = new Admin();
        $admin->forceFill([
            'username' => $username,
            'nickname' => $nickname,
            'password' => Hash::make($password),
            'email' => $email !== '' ? $email : null,
            'mobile' => $mobile !== '' ? $mobile : null,
            'status' => true,
            'is_founder' => true,
        ]);
        $admin->save();

        return $admin;
    }

    private function assertInput(
        string $username,
        string $password,
        string $nickname,
        string $email,
        string $mobile,
        string $webPrefix,
        string $appName,
        string $appUrl
    ): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{4,36}$/', $username) !== 1) {
            throw new InvalidInstallStateException('The administrator username is invalid.');
        }
        if (mb_strlen($password) < 6 || mb_strlen($password) > 36) {
            throw new InvalidInstallStateException('管理员密码长度必须为 6 到 36 个字符。');
        }
        if ($nickname === '' || mb_strlen($nickname) > 100) {
            throw new InvalidInstallStateException('The administrator nickname is invalid.');
        }
        if ($email !== '' && (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidInstallStateException('The administrator email is invalid.');
        }
        if (strlen($mobile) > 30) {
            throw new InvalidInstallStateException('The administrator mobile number is invalid.');
        }
        if ($webPrefix !== '' && !WebPrefixGenerator::isValid($webPrefix)) {
            throw new InvalidInstallStateException('The PTAdmin web prefix is invalid or reserved.');
        }
        if ($appName === '' || mb_strlen($appName) > 100) {
            throw new InvalidInstallStateException('The application name is invalid.');
        }
        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false || strlen($appUrl) > 2048) {
            throw new InvalidInstallStateException('The application URL is invalid.');
        }
    }

    private function resetRuntimeCaches(): void
    {
        $this->runtimeCache->clear();
        foreach (['cache:clear', 'config:clear', 'event:clear', 'route:clear', 'view:clear'] as $command) {
            Artisan::call($command);
        }
    }

    private function ensureStorageLink(): void
    {
        $publicStorage = public_path('storage');
        if (is_link($publicStorage)) {
            return;
        }
        if (file_exists($publicStorage)) {
            throw new RuntimeException('PTAdmin cannot create the public storage link because the target already exists.');
        }

        $storageRoot = storage_path('app/public');
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0755, true) && !is_dir($storageRoot)) {
            throw new RuntimeException('PTAdmin cannot create the public storage directory.');
        }
        if (!symlink($storageRoot, $publicStorage) || !is_link($publicStorage)) {
            throw new RuntimeException('PTAdmin storage link creation failed.');
        }
    }

    /**
     * 在数据库迁移完成后幂等同步后台内置权限资源。
     *
     * @return array{resource_count:int}
     */
    public function syncAuthorizationResources(): array
    {
        $founderId = Admin::query()
            ->where('is_founder', true)
            ->where('status', true)
            ->orderBy('id')
            ->value('id');
        if (null === $founderId) {
            throw new RuntimeException('未找到可用的创始人账号，无法同步后台权限资源。');
        }

        $resourceKeys = $this->menus->syncCore()['resource_keys'];

        return ['resource_count' => count($resourceKeys)];
    }
}
