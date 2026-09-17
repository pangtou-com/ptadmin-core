<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Core\Http\AdminFrontendRouteRegistrar;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use PTAdmin\Admin\Schema\AdminSchemaSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;
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
        private AdminSchemaSynchronizer $adminSchemas,
        private SchemaSourceDiscovery $schemaSources,
        private SchemaFileSynchronizer $schemaSynchronizer,
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
        $nickname = trim((string) ($input['nickname'] ?? $username));
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
            $adminResources = $this->adminSchemas->sync();
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
            $state = $this->stateStore->writeState(array_merge($state, [
                'stage' => 'schema_ready',
                'admin_schema_resources' => $adminResources,
                'core_schema_resources' => array_column($coreSchemaResults, 'resource'),
                'failure' => null,
            ]));
            $this->progress($progress, 'schema_ready');

            $this->progress($progress, 'config_syncing');
            $configDefinitions = $this->seedConfigDefinitions();
            $state = $this->stateStore->writeState(array_merge($state, [
                'stage' => 'config_ready',
                'config_definitions' => $configDefinitions,
                'failure' => null,
            ]));
            $this->progress($progress, 'config_ready');

            $this->progress($progress, 'admin_creating');
            $result = DB::transaction(function () use ($username, $password, $nickname, $email, $mobile): array {
                $admin = $this->resolveFounder($username, $password, $nickname, $email, $mobile);

                $resources = $this->seedAuthorization($admin->getKey());

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

    private function seedConfigDefinitions(): int
    {
        $definitions = [
            [
                'group_code' => 'appearance',
                'config_key' => 'appearance',
                'title' => '后台外观',
                'description' => '后台主题和布局默认设置。',
                'schema' => [
                    'name' => 'appearance',
                    'title' => '后台外观',
                    'fields' => [
                        ['name' => 'theme_preset', 'type' => 'string', 'label' => '主题预设', 'default' => (string) config('ptadmin.theme.preset', 'classic-blue')],
                        ['name' => 'theme_mode', 'type' => 'string', 'label' => '主题模式', 'default' => (string) config('ptadmin.theme.mode', 'light'), 'options' => ['mode' => 'static', 'value_policy' => 'closed', 'items' => [['value' => 'light', 'label' => '浅色'], ['value' => 'dark', 'label' => '深色']]]],
                        ['name' => 'layout_navigation', 'type' => 'string', 'label' => '导航布局', 'default' => (string) config('ptadmin.layout.navigation', 'sidebar'), 'options' => ['mode' => 'static', 'value_policy' => 'closed', 'items' => [['value' => 'sidebar', 'label' => '侧边导航'], ['value' => 'topbar', 'label' => '顶部导航']]]],
                        ['name' => 'layout_density', 'type' => 'string', 'label' => '界面密度', 'default' => (string) config('ptadmin.layout.density', 'comfortable'), 'options' => ['mode' => 'static', 'value_policy' => 'closed', 'items' => [['value' => 'comfortable', 'label' => '舒适'], ['value' => 'compact', 'label' => '紧凑']]]],
                    ],
                ],
            ],
        ];
        foreach ($definitions as $definition) {
            $this->configs->syncDefinition($definition + ['owner_type' => 'core', 'owner_code' => 'ptadmin']);
        }

        return count($definitions);
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
        if (preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username) !== 1) {
            throw new InvalidInstallStateException('The administrator username is invalid.');
        }
        if (strlen($password) < 6 || strlen($password) > 4096) {
            throw new InvalidInstallStateException('管理员密码长度必须为 6 到 4096 个字符。');
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
     * 创建系统角色、首个管理员的角色关系和核心菜单资源。
     * @return array{role:string, resource_count:int}
     */
    private function seedAuthorization(int|string $adminId): array
    {
        $now = now();
        DB::table('admin_roles')->updateOrInsert(
            ['code' => 'founder'],
            [
                'name' => '创始人',
                'status' => true,
                'is_system' => true,
                'sort' => 0,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $roleId = (int) DB::table('admin_roles')->where('code', 'founder')->value('id');
        DB::table('admin_user_roles')->updateOrInsert(
            ['admin_id' => (int) $adminId, 'role_id' => $roleId],
            ['created_at' => $now, 'updated_at' => $now]
        );

        $resources = $this->coreResources();
        foreach ($resources as $resource) {
            DB::table('admin_resources')->updateOrInsert(
                ['resource_key' => $resource['resource_key']],
                $resource + ['deleted_at' => null, 'created_at' => $now, 'updated_at' => $now]
            );
            $resourceId = (int) DB::table('admin_resources')
                ->where('resource_key', $resource['resource_key'])
                ->value('id');
            DB::table('admin_grants')->updateOrInsert(
                [
                    'subject_type' => 'role',
                    'subject_id' => (string) $roleId,
                    'resource_id' => $resourceId,
                ],
                [
                    'effect' => 'allow',
                    'abilities_json' => json_encode(['*'], JSON_THROW_ON_ERROR),
                    'priority' => 100,
                    'expires_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        return ['role' => 'founder', 'resource_count' => count($resources)];
    }

    /** @return array<int, array<string, mixed>> */
    private function coreResources(): array
    {
        return [
            [
                'resource_key' => 'dashboard',
                'title' => '仪表盘',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/',
                'meta_json' => json_encode(['page_type' => 'dashboard', 'icon' => 'layout-dashboard'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 10,
            ],
            [
                'resource_key' => 'admin.users',
                'title' => '管理员',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/admin/users',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'admin.management_users', 'icon' => 'users'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 20,
            ],
            [
                'resource_key' => 'admin.roles',
                'title' => '角色权限',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/admin/roles',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'admin.management_roles', 'icon' => 'shield-check'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 30,
            ],
            [
                'resource_key' => 'admin.menus',
                'title' => '菜单管理',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/admin/menus',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'admin.management_menus', 'icon' => 'list-tree'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 35,
            ],
            [
                'resource_key' => 'admin.logs',
                'title' => '日志中心',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/admin/logs',
                'meta_json' => json_encode(['page_type' => 'logs.center', 'icon' => 'scroll-text'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 36,
            ],
            [
                'resource_key' => 'admin.tenants',
                'title' => '租户管理',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/admin/tenants',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'admin.management_tenants', 'icon' => 'building-2'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 40,
            ],
            [
                'resource_key' => 'admin.organizations',
                'title' => '组织管理',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/admin/organizations',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'admin.management_organizations', 'icon' => 'network'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 41,
            ],
            [
                'resource_key' => 'admin.departments',
                'title' => '部门管理',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/admin/departments',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'admin.management_departments', 'icon' => 'network'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 42,
            ],
            [
                'resource_key' => 'admin.notifications',
                'title' => '通知场景',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/admin/notifications',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'admin.notification_scenes', 'icon' => 'bell'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 43,
            ],
            [
                'resource_key' => 'admin.notification_templates',
                'title' => '通知模板',
                'type' => 'page',
                'parent_key' => 'admin.notifications',
                'path' => '/admin/notifications/templates',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'admin.notification_templates', 'icon' => 'file-text'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 44,
            ],
            ...array_map(static fn (array $resource): array => [
                'resource_key' => $resource['key'],
                'title' => $resource['title'],
                'type' => 'system',
                'parent_key' => $resource['parent'],
                'path' => '',
                'meta_json' => json_encode([], JSON_THROW_ON_ERROR),
                'is_menu' => false,
                'status' => true,
                'sort' => $resource['sort'],
            ], [
                ['key' => 'admin.management_users', 'title' => '管理员数据管理', 'parent' => 'admin.users', 'sort' => 21],
                ['key' => 'admin.management_roles', 'title' => '角色数据管理', 'parent' => 'admin.roles', 'sort' => 31],
                ['key' => 'admin.management_menus', 'title' => '权限资源数据管理', 'parent' => 'admin.menus', 'sort' => 36],
                ['key' => 'admin.login_logs', 'title' => '登录日志查询', 'parent' => 'admin.logs', 'sort' => 37],
                ['key' => 'admin.operation_records', 'title' => '操作记录查询', 'parent' => 'admin.logs', 'sort' => 38],
                ['key' => 'admin.management_tenants', 'title' => '租户数据管理', 'parent' => 'admin.tenants', 'sort' => 45],
                ['key' => 'admin.management_organizations', 'title' => '组织数据管理', 'parent' => 'admin.organizations', 'sort' => 46],
                ['key' => 'admin.management_departments', 'title' => '部门数据管理', 'parent' => 'admin.departments', 'sort' => 47],
                ['key' => 'admin.dashboard_widgets', 'title' => '仪表盘组件配置', 'parent' => 'dashboard', 'sort' => 48],
                ['key' => 'admin.notification_scenes', 'title' => '通知场景数据管理', 'parent' => 'admin.notifications', 'sort' => 49],
                ['key' => 'admin.notification_messages', 'title' => '通知消息查询', 'parent' => 'admin.notifications', 'sort' => 50],
                ['key' => 'admin.notification_receipts', 'title' => '通知回执查询', 'parent' => 'admin.notifications', 'sort' => 51],
                ['key' => 'admin.notification_deliveries', 'title' => '通知投递记录查询', 'parent' => 'admin.notifications', 'sort' => 52],
            ]),
            [
                'resource_key' => 'schema.resources',
                'title' => 'Schema 资源',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/schema/resources',
                'meta_json' => json_encode(['page_type' => 'schema.resource', 'resource_key' => 'schema.resource', 'icon' => 'blocks'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 40,
            ],
            [
                'resource_key' => 'admin.config',
                'title' => '系统配置',
                'type' => 'page',
                'parent_key' => '',
                'path' => '',
                'meta_json' => json_encode([], JSON_THROW_ON_ERROR),
                'is_menu' => false,
                'status' => true,
                'sort' => 50,
            ],
            [
                'resource_key' => 'tasks',
                'title' => '任务中心',
                'type' => 'page',
                'parent_key' => '',
                'path' => '/tasks',
                'meta_json' => json_encode(['page_type' => 'task.center', 'icon' => 'list-checks'], JSON_THROW_ON_ERROR),
                'is_menu' => true,
                'status' => true,
                'sort' => 60,
            ],
            [
                'resource_key' => 'core.tasks',
                'title' => '后台任务',
                'type' => 'system',
                'parent_key' => '',
                'path' => '',
                'meta_json' => json_encode([], JSON_THROW_ON_ERROR),
                'is_menu' => false,
                'status' => true,
                'sort' => 65,
            ],
            ...array_map(static fn (string $key): array => [
                'resource_key' => $key,
                'title' => '任务权限 '.$key,
                'type' => 'system',
                'parent_key' => 'core.tasks',
                'path' => '',
                'meta_json' => json_encode([], JSON_THROW_ON_ERROR),
                'is_menu' => false,
                'status' => true,
                'sort' => 70,
            ], ['core.update', 'admin.frontend', 'addon.manage', 'addon.download', 'addon.install', 'addon.update', 'addon.uninstall', 'addon.frontend', 'addon.enable', 'addon.disable']),
        ];
    }
}
