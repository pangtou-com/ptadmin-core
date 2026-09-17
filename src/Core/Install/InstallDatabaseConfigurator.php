<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Illuminate\Database\DatabaseManager;
use PDO;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use Throwable;

/**
 * 将安装表单中的数据库设置转换为 Laravel 连接配置，并在迁移前验证连接。
 * 首版只开放 Laravel 标准的 SQLite 和 MySQL，其他驱动待单独验证后再加入。
 */
final class InstallDatabaseConfigurator
{
    /** @var list<string> PTAdmin 首次迁移会创建的表，用于阻止覆盖既有安装。 */
    private const PTADMIN_TABLES = [
        'schema_resources',
        'schema_resource_versions',
        'schema_field_projections',
        'schema_publish_tasks',
        'schema_audit_events',
        'ptadmin_sessions',
        'admins',
        'admin_roles',
        'admin_resources',
        'admin_user_roles',
        'admin_grants',
        'admin_tenants',
        'admin_organizations',
        'admin_departments',
        'admin_user_org_relations',
        'admin_dashboard_widgets',
        'assets',
        'notification_scenes',
        'notification_templates',
        'notification_scene_routes',
        'notification_messages',
        'notification_receipts',
        'notification_deliveries',
        'ptadmin_config_definitions',
        'ptadmin_config_values',
        'admin_login_logs',
        'admin_operation_records',
    ];

    public function __construct(private DatabaseManager $database)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{driver:string,database:string,host:string,port:string,username:string,password:string,prefix:string}
     */
    public function configure(array $input): array
    {
        $driver = strtolower(trim((string) ($input['db_connection'] ?? $input['database_driver'] ?? config('ptadmin.install.database_default', 'mysql'))));
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new InvalidInstallStateException('安装数据库仅支持 SQLite 和 MySQL。');
        }

        $current = (array) config('database.connections.'.$driver, []);
        $database = trim((string) ($input['db_database'] ?? ($driver === 'sqlite' ? 'ptadmin.db' : 'ptadmin')));
        $prefix = (string) ($input['db_prefix'] ?? 'pt_');
        if (preg_match('/[\x00-\x1F\x7F]/', $database) === 1) {
            throw new InvalidInstallStateException('数据库名称或 SQLite 文件路径包含非法字符。');
        }
        if (preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix) !== 1) {
            throw new InvalidInstallStateException('数据表前缀只能包含字母、数字和下划线。');
        }

        if ($driver === 'sqlite') {
            $database = $database === '' ? 'ptadmin.db' : $database;
            $databasePath = $this->sqlitePath($database);
            if ($databasePath !== ':memory:' && !is_file($databasePath)) {
                $directory = dirname($databasePath);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new InvalidInstallStateException('无法创建 SQLite 数据库目录。');
                }
                if (false === touch($databasePath)) {
                    throw new InvalidInstallStateException('无法创建 SQLite 数据库文件。');
                }
            }
            $settings = [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => $prefix,
                'foreign_key_constraints' => true,
            ];
        } else {
            $host = trim((string) ($input['db_host'] ?? ($current['host'] ?? '127.0.0.1')));
            $port = trim((string) ($input['db_port'] ?? ($current['port'] ?? '3306')));
            $username = (string) ($input['db_username'] ?? ($current['username'] ?? ''));
            $password = (string) ($input['db_password'] ?? ($current['password'] ?? ''));
            if (preg_match('/[\x00-\x1F\x7F]/', $host.$username.$password) === 1 || str_contains($host, ';')) {
                throw new InvalidInstallStateException('MySQL 连接信息包含非法字符。');
            }
            if ($database === '' || $host === '' || $username === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new InvalidInstallStateException('MySQL 主机、端口、数据库名称和账户不能为空。');
            }
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database) !== 1) {
                throw new InvalidInstallStateException('MySQL 数据库名称只能包含字母、数字、下划线和短横线。');
            }
            $this->ensureMysqlDatabase($host, $port, $database, $username, $password);
            $settings = [
                'driver' => 'mysql',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => $prefix,
                'strict' => true,
                'engine' => null,
            ];
        }

        $config = (array) config('database');
        $config['default'] = $driver;
        $config['connections'] = array_merge((array) ($config['connections'] ?? []), [$driver => $settings]);
        config(['database' => $config]);
        $this->database->purge();

        try {
            $this->database->connection($driver)->getPdo();
            $this->assertNoConflictingTables($driver, $prefix);
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidInstallStateException) {
                throw $exception;
            }
            throw new InvalidInstallStateException('数据库连接失败：'.$exception->getMessage(), previous: $exception);
        }

        return [
            'driver' => $driver,
            'database' => $driver === 'sqlite' ? $this->environmentDatabaseValue($database) : $database,
            'host' => (string) ($settings['host'] ?? ''),
            'port' => (string) ($settings['port'] ?? ''),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'prefix' => $prefix,
        ];
    }

    /** @return array<string, mixed> */
    public function environmentValues(array $settings): array
    {
        $values = [
            'DB_CONNECTION' => $settings['driver'],
            'DB_DATABASE' => $settings['database'],
            'DB_HOST' => $settings['host'],
            'DB_PORT' => $settings['port'],
            'DB_USERNAME' => $settings['username'],
            'DB_PASSWORD' => $settings['password'],
            'DB_SOCKET' => '',
            'DATABASE_URL' => '',
            'DB_PREFIX' => $settings['prefix'],
        ];
        return $values;
    }

    private function sqlitePath(string $database): string
    {
        if ($database === ':memory:' || str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $database) === 1) {
            return $database;
        }

        return base_path($database);
    }

    private function environmentDatabaseValue(string $database): string
    {
        if ($database === ':memory:' || !str_starts_with($database, DIRECTORY_SEPARATOR)) {
            return $database;
        }

        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($database, $base)) {
            return substr($database, strlen($base));
        }

        return $database;
    }

    private function ensureMysqlDatabase(string $host, string $port, string $database, string $username, string $password): void
    {
        // 托管环境中的安装账户通常只能访问已经创建好的数据库，没有 CREATE DATABASE 权限；
        // 先尝试直接连接目标库，成功时不再要求额外的服务器级权限。
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            return;
        } catch (Throwable) {
            // 目标数据库可能尚未创建，继续使用服务器连接创建它。
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $quotedDatabase = '`'.str_replace('`', '``', $database).'`';
            $pdo->exec('CREATE DATABASE IF NOT EXISTS '.$quotedDatabase.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $exception) {
            throw new InvalidInstallStateException('MySQL 数据库连接或创建失败：'.$exception->getMessage(), previous: $exception);
        }
    }

    private function assertNoConflictingTables(string $driver, string $prefix): void
    {
        if ($driver === 'sqlite' && (string) config('database.connections.sqlite.database') === ':memory:') {
            return;
        }

        $connection = $this->database->connection($driver);
        $tables = $driver === 'sqlite'
            ? array_map(static fn (object $row): string => (string) $row->name, $connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
            : array_map(static fn (object $row): string => (string) array_values(get_object_vars($row))[0], $connection->select('show tables'));

        foreach ($tables as $table) {
            $unprefixed = $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
            if (in_array($unprefixed, self::PTADMIN_TABLES, true)) {
                throw new InvalidInstallStateException(sprintf('数据库中已存在 PTAdmin 数据表：%s。请使用空数据库或更换表前缀。', $table));
            }
        }
    }
}
